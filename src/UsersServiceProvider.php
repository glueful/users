<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users;

use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Database\Migrations\MigrationPriority;
use Glueful\Permissions\Catalog\Permission;
use Glueful\Extensions\Users\Support\PayloadProjector;
use Glueful\Extensions\Users\Support\ProfileFieldResolver;
use Glueful\Extensions\Users\Support\ProfileResponder;

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
            // 2FA owns users.two_factor_enabled state. Built via a static factory (prod-safe) that
            // resolves the core token-mechanic deps (ChallengeTokenIssuer/JtiBlocklist) + config.
            // Aliased to the core contract so AuthController resolves us via the interface — core
            // never names this concrete class.
            TwoFactor\TwoFactorService::class => [
                'factory' => [TwoFactor\TwoFactorServiceFactory::class, 'create'],
                'shared' => true,
                'alias' => [TwoFactorServiceInterface::class],
            ],
            ProfileFieldResolver::class => ['class' => ProfileFieldResolver::class, 'shared' => true, 'autowire' => true],
            PayloadProjector::class => ['class' => PayloadProjector::class, 'shared' => true, 'autowire' => true],
            ProfileResponder::class => ['class' => ProfileResponder::class, 'shared' => true, 'autowire' => true],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // Register shipped config defaults (requires framework ^1.50.1, where mergeConfig() was
        // fixed). An app's config/users.php overrides per key.
        $this->mergeConfig('users', require __DIR__ . '/../config/users.php');
    }

    /** @return list<\Glueful\Permissions\Catalog\Permission> */
    public function permissions(): array
    {
        return [
            Permission::define('users.read')
                ->label('Read users')
                ->description("Read another user's account and public profile via GET /users/{uuid}")
                ->category('users')
                ->managedBy('glueful/users'),
        ];
    }

    public function boot(ApplicationContext $context): void
    {
        // Identity/auth schema must migrate before app + dependent extensions.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::IDENTITY, 'glueful/users');

        $this->loadRoutesFrom(__DIR__ . '/../routes/account.php');
        if ((bool) config($context, 'auth.two_factor.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/2fa.php');
        }
        $this->loadRoutesFrom(__DIR__ . '/../routes/users.php');

        if ((bool) config($context, 'users.user_lookup.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/user-lookup.php');

            if ((bool) config($context, 'users.user_lookup.list.enabled', false)) {
                $this->loadRoutesFrom(__DIR__ . '/../routes/user-list.php');
            }
        }

        $this->discoverCommands('Glueful\\Extensions\\Users\\Console', __DIR__ . '/Console');
    }
}
