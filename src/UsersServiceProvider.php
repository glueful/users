<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users;

use Glueful\Extensions\ServiceProvider;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Auth\Contracts\TwoFactorServiceInterface;
use Glueful\Database\Migrations\MigrationPriority;
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

            // Controllers must be registered: the router resolves a route's [Controller::class, 'method']
            // handler via container->get($class) with no autowire fallback, so an unregistered controller
            // throws "Service not found" (500) the moment its route is hit.
            Controllers\AccountController::class =>
                ['class' => Controllers\AccountController::class, 'shared' => true, 'autowire' => true],
            Controllers\TwoFactorController::class =>
                ['class' => Controllers\TwoFactorController::class, 'shared' => true, 'autowire' => true],
            Controllers\UserController::class =>
                ['class' => Controllers\UserController::class, 'shared' => true, 'autowire' => true],
        ];
    }

    public function register(ApplicationContext $context): void
    {
        // Register shipped config defaults (requires framework ^1.50.1, where mergeConfig() was
        // fixed). An app's config/users.php overrides per key.
        $this->mergeConfig('users', require __DIR__ . '/../config/users.php');
    }

    // No permissions() override: the user-read slug is `users.view`, a framework CORE_PERMISSION
    // already declared in the catalog under "glueful/framework" (and seeded by Aegis). Re-declaring
    // it here would raise a DuplicatePermissionException. The endpoints still guard on it via
    // #[RequiresPermission('users.view')].

    public function boot(ApplicationContext $context): void
    {
        // Identity/auth schema must migrate before app + dependent extensions.
        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::IDENTITY, 'glueful/users');

        // Version all of this extension's API routes (e.g. /v1/auth/*, /v1/2fa/*, /v1/me) so they
        // sit at the same prefix as the framework's own routes. api_prefix() honours
        // API_USE_PREFIX / API_VERSION_IN_PATH, exactly as RouteManifest does when it wraps the
        // framework's api_routes; each route file keeps its own sub-prefix, nested under this one.
        if ($this->app->has(\Glueful\Routing\Router::class)) {
            $router = $this->app->get(\Glueful\Routing\Router::class);
            $router->group(['prefix' => api_prefix($context)], function () use ($context): void {
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
            });
        }

        $this->discoverCommands('Glueful\\Extensions\\Users\\Console', __DIR__ . '/Console');
    }
}
