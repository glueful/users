<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Support;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * Boots the framework on a fresh file-based SQLite app and applies the glueful/users migrations.
 * File-based (not :memory:) so all connections share the db by path.
 */
abstract class AppTestCase extends TestCase
{
    protected string $appPath;
    protected Application $app;
    protected ApplicationContext $context;

    /** @param array<string,string> $extraConfig filename => php array literal (e.g. "['a'=>1]") */
    protected function bootApp(array $extraConfig = []): void
    {
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-users-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name'=>'T','env'=>'testing'];");
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>'" . $this->appPath . "/t.sqlite'],"
            . "'pooling'=>['enabled'=>false]];"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");
        foreach ($extraConfig as $name => $body) {
            file_put_contents($cfg . '/' . $name, "<?php\nreturn " . $body . ";");
        }

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);

        $mm = new MigrationManager(dirname(__DIR__, 2) . '/migrations', null, $this->context);
        $mm->migrate();
    }

    protected function db(): Connection
    {
        return Connection::fromContext($this->context);
    }

    protected function seedUser(string $uuid, string $email = 'u@example.com', string $username = 'jdoe'): string
    {
        $this->db()->table('users')->insert([
            'uuid' => $uuid,
            'username' => $username,
            'email' => $email,
            'password' => 'HASHED-SECRET',
            'status' => 'active',
            'two_factor_enabled' => 0,
        ]);
        return $uuid;
    }

    protected function seedProfile(string $userUuid, string $firstName = 'Jane', string $lastName = 'Doe'): void
    {
        $this->db()->table('profiles')->insert([
            'uuid' => 'p-' . $userUuid,
            'user_uuid' => $userUuid,
            'first_name' => $firstName,
            'last_name' => $lastName,
            'photo_url' => 'https://img/' . $userUuid . '.png',
            'photo_uuid' => 'ph-' . $userUuid,
            'status' => 'active',
        ]);
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
}
