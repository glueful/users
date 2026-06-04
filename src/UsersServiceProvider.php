<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users;

use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Database\Migrations\MigrationPriority;

final class UsersServiceProvider extends ServiceProvider
{
    /** @return array<class-string, array<string,mixed>> */
    public static function services(): array
    {
        return [
            Repositories\UserRepository::class => [
                'class' => Repositories\UserRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            // UserProvider needs UserRepository injected — the bare ['class' => ...] form would
            // call `new UserProvider()` with no args and fatal. Pass it explicitly (matches the
            // Aegis RoleService pattern: 'arguments' => ['@' . Dep::class]). PasswordHasher
            // defaults to null inside UserProvider, so it need not be listed.
            // The 'alias' key lives on the SERVICE definition (collectAliases() adds the listed
            // ids as aliases OF this service id) — NOT on a separate interface entry. So the
            // interface is declared here, and there is one shared UserProvider instance.
            UserProvider::class => [
                'class' => UserProvider::class,
                'arguments' => ['@' . Repositories\UserRepository::class],
                'shared' => true,
                'alias' => [UserProviderInterface::class],
            ],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        $this->loadRoutesFrom(__DIR__ . '/routes.php');
        // Identity/auth schema must migrate before app + dependent extensions.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::IDENTITY, 'glueful/users');
    }

    public function boot(ApplicationContext $context): void
    {
        $this->discoverCommands('Glueful\\Extensions\\Users\\Console', __DIR__ . '/Console');
    }
}
