<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests;

use Glueful\Application;
use Glueful\Auth\PasswordHasher;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * usernameExists() / emailExists() with an exclude uuid: "does anyone ELSE hold this value".
 * Regression for the array-form where() bug that made every excluded check report free.
 */
final class UserRepositoryExistsTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->bootFramework();
        $this->createSchemaInline();
    }

    protected function tearDown(): void
    {
        if (isset($this->appPath) && is_dir($this->appPath)) {
            $it = new \RecursiveIteratorIterator(
                new \RecursiveDirectoryIterator($this->appPath, \FilesystemIterator::SKIP_DOTS),
                \RecursiveIteratorIterator::CHILD_FIRST
            );
            foreach ($it as $f) {
                $f->isDir() ? rmdir($f->getPathname()) : unlink($f->getPathname());
            }
            rmdir($this->appPath);
        }
        parent::tearDown();
    }

    public function test_exists_checks_honour_the_exclude_uuid(): void
    {
        $repo = new UserRepository();
        $amy = $repo->create([
            'username' => 'amy',
            'email' => 'amy@x.test',
            'password' => (new PasswordHasher())->hash('secret-123'),
            'status' => 'active',
        ]);
        $bob = $repo->create([
            'username' => 'bob',
            'email' => 'bob@x.test',
            'password' => (new PasswordHasher())->hash('secret-123'),
            'status' => 'active',
        ]);

        // No exclude: plain existence.
        self::assertTrue($repo->usernameExists('amy'));
        self::assertTrue($repo->emailExists('bob@x.test'));
        self::assertFalse($repo->usernameExists('carol'));

        // Excluding the owner: the value is theirs, so it is free for them.
        self::assertFalse($repo->usernameExists('amy', $amy));
        self::assertFalse($repo->emailExists('amy@x.test', $amy));

        // Excluding someone else: the value is still taken.
        self::assertTrue($repo->usernameExists('amy', $bob), 'amy belongs to another user');
        self::assertTrue($repo->emailExists('amy@x.test', $bob));
    }

    private function bootFramework(): void
    {
        $this->appPath = sys_get_temp_dir() . '/glueful-userexists-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name'=>'T','env'=>'testing','debug'=>true];");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>':memory:'],'pooling'=>['enabled'=>false]];"
        );
        file_put_contents(
            $cfg . '/cache.php',
            "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];"
        );
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");
        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
    }

    private function createSchemaInline(): void
    {
        $connection = $this->app->getContainer()->get('database');
        new UserRepository($connection, null, $this->context);
        $connection->getPDO()->exec('
            CREATE TABLE users (
                uuid VARCHAR(12) PRIMARY KEY,
                username VARCHAR(255),
                email VARCHAR(255),
                password VARCHAR(255),
                status VARCHAR(32) DEFAULT "active",
                created_at TIMESTAMP NULL,
                deleted_at TIMESTAMP NULL
            )
        ');
    }
}
