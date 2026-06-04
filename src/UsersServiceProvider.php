<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users;

use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Extensions\Users\Repositories\UserRepository;

final class UsersServiceProvider extends ServiceProvider
{
    /** @return array<class-string, array<string,mixed>> */
    public static function services(): array
    {
        return [
            UserRepository::class => [
                'class' => UserRepository::class,
                'shared' => true,
                'autowire' => true,
            ],
            // UserProvider needs UserRepository injected; the interface core consumes is an
            // alias of the single UserProvider service (collectAliases()) — one shared instance.
            UserProvider::class => [
                'class' => UserProvider::class,
                'arguments' => ['@' . UserRepository::class],
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
