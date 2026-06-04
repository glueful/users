<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests;

use Glueful\Application;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Database\Migrations\MigrationManager;
use Glueful\Framework;
use Glueful\Routing\RouteManifest;
use PHPUnit\Framework\TestCase;

/**
 * Applies the glueful/users migrations on a fresh SQLite db and asserts the identity/auth schema
 * is created in dependency order (FKs resolve).
 */
final class MigrationsTest extends TestCase
{
    private string $appPath;
    private Application $app;
    private ApplicationContext $context;

    protected function setUp(): void
    {
        parent::setUp();
        RouteManifest::reset();
        $this->appPath = sys_get_temp_dir() . '/glueful-usermigr-' . uniqid();
        $cfg = $this->appPath . '/config';
        mkdir($cfg, 0755, true);
        file_put_contents($cfg . '/app.php', "<?php\nreturn ['name'=>'T','env'=>'testing'];");
        // File-based (not :memory:) so MigrationManager's connection and the verification
        // connection share the db by path (:memory: is per-connection).
        file_put_contents(
            $cfg . '/database.php',
            "<?php\nreturn ['engine'=>'sqlite','sqlite'=>['primary'=>'" . $this->appPath . "/t.sqlite'],"
            . "'pooling'=>['enabled'=>false]];"
        );
        file_put_contents($cfg . '/cache.php', "<?php\nreturn ['enabled'=>true,'default'=>'array','stores'=>['array'=>['driver'=>'array']]];");
        file_put_contents($cfg . '/security.php', "<?php\nreturn ['csrf'=>['enabled'=>false]];");
        file_put_contents($cfg . '/session.php', "<?php\nreturn ['jwt_key'=>'test'];");

        $this->app = Framework::create($this->appPath)->boot(allowReboot: true);
        $this->context = $this->app->getContainer()->get(ApplicationContext::class);
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

    public function test_migrations_apply_and_create_identity_schema(): void
    {
        // Apply the extension's migrations (as the main path) on the fresh db. Priority/source
        // ordering is covered separately by the framework's MigrationOrderingTest.
        $mm = new MigrationManager(dirname(__DIR__) . '/migrations', null, $this->context);
        $mm->migrate();

        $schema = Connection::fromContext($this->context)->getSchemaBuilder();
        foreach (['users', 'profiles', 'auth_sessions', 'auth_refresh_tokens', 'api_keys'] as $table) {
            self::assertTrue($schema->hasTable($table), "$table should exist");
        }
        self::assertTrue($schema->hasColumn('users', 'two_factor_enabled'));
        self::assertTrue($schema->hasColumn('api_keys', 'user_uuid'));
    }
}
