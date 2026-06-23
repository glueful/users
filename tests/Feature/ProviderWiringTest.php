<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\UsersServiceProvider;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/**
 * Provider wiring: route registration, config-gated lookup route, and the users.view permission guard.
 *
 * NOTE ON ISOLATION: the plan called for `@runInSeparateProcess` to defeat
 * `ServiceProvider::loadRoutesFrom()`'s function-`static $loaded` realpath cache (which persists
 * for the whole PHP process and is NOT reset by `RouteManifest::reset()`, so a second boot()
 * silently skips re-loading a file a fresh router never received). That mechanism is unusable here:
 * `Framework::boot()` is incompatible with PHPUnit's child-process result serialization — a bare
 * isolated test passes, but one that boots the framework dies with "child process ended
 * unexpectedly". So instead we keep every test in-process and order-independent:
 *
 *  - Route-FILE presence (/me, /users/{uuid}) is asserted by requiring the file directly into a
 *    fresh Router (`loadRouteFile()`), which bypasses `loadRoutesFrom()`'s static cache entirely —
 *    so these hold under any test order.
 *  - The real `boot()` GATING branch (load lookup only when enabled) is exercised via absence
 *    (always robust: a fresh router never matched /users) and via the enabled case being the sole
 *    loader of `user-lookup.php` through `loadRoutesFrom()`, so it is always the first to populate
 *    that file's static cache entry regardless of order.
 *
 * We deliberately do NOT assert /me presence through `boot()`: `routes/users.php` is loaded by
 * every boot() call, so under a randomized order another test's boot() can populate the
 * static cache first, leaving this test's fresh router without it. The direct-file test covers /me.
 */
final class ProviderWiringTest extends AppTestCase
{
    /** Require a route file directly into a fresh router, bypassing loadRoutesFrom()'s static cache. */
    private function loadRouteFile(string $relativePath): Router
    {
        $router = $this->app->getContainer()->get(Router::class);
        (static function (Router $router, string $file): void {
            require $file;
        })($router, dirname(__DIR__, 2) . $relativePath);
        return $router;
    }

    private function bootProvider(): Router
    {
        $container = $this->app->getContainer();
        $provider = new UsersServiceProvider($container);
        $provider->register($this->context);
        $provider->boot($this->context);
        return $container->get(Router::class);
    }

    public function test_me_route_file_registers_route(): void
    {
        $this->bootApp();
        $router = $this->loadRouteFile('/routes/users.php');
        self::assertNotNull($router->match(Request::create('/me', 'GET')), '/me registered by routes/users.php');
    }

    public function test_lookup_route_file_registers_route(): void
    {
        $this->bootApp();
        $router = $this->loadRouteFile('/routes/user-lookup.php');
        self::assertNotNull(
            $router->match(Request::create('/users/u-1', 'GET')),
            '/users/{uuid} registered by routes/user-lookup.php'
        );
    }

    public function test_password_reset_routes_are_rate_limited(): void
    {
        $this->bootApp();
        $router = $this->loadRouteFile('/routes/account.php');

        foreach (['/auth/forgot-password', '/auth/reset-password'] as $path) {
            $match = $router->match(Request::create($path, 'POST'));
            self::assertIsArray($match, $path . ' registered');
            $route = $match['route'];
            self::assertContains('rate_limit', $route->getMiddleware(), $path . ' has rate_limit middleware');
            self::assertNotSame([], $route->getRateLimitConfig(), $path . ' has builder rate limit config');
        }
    }

    public function test_register_gates_lookup_off_by_default(): void
    {
        // Absence is order-independent: a freshly-booted router only ever matches /users if THIS
        // boot() loaded user-lookup.php, which it must not when the lookup is disabled.
        $this->bootApp(); // no app config/users.php → shipped default (lookup disabled)
        $router = $this->bootProvider();
        self::assertNull($router->match(Request::create('/users/u-1', 'GET')), 'lookup gated off by default');
    }

    // NB: `/users/{uuid}` presence is asserted order-independently by
    // test_lookup_route_file_registers_route (direct file load). A register()-based presence test
    // for routes/user-lookup.php would be order-dependent now that the Phase 2 list tests also boot
    // that file via boot() — loadRoutesFrom()'s static $loaded cache makes only the first loader
    // win. The boot() conditional-load path is covered by test_list_route_present_when_both_flags
    // (sole register()-loader of routes/user-list.php) plus the absence tests.

    public function test_provider_declares_no_catalog_permissions(): void
    {
        // The user-read slug is `users.view`, a framework CORE_PERMISSION already declared in the
        // catalog. The extension must NOT re-declare it (that raises DuplicatePermissionException);
        // it only guards on it via #[RequiresPermission('users.view')].
        $this->bootApp();
        $provider = new UsersServiceProvider($this->app->getContainer());
        $slugs = array_map(static fn($p) => $p->slug(), $provider->permissions());
        self::assertNotContains('users.view', $slugs);
        self::assertSame([], $slugs);
    }

    public function test_two_factor_routes_follow_auth_config_gate(): void
    {
        $this->bootApp(['auth.php' => "['two_factor'=>['enabled'=>true]]"]);
        $router = $this->bootProvider();

        // boot() versions all users routes via api_prefix(), so the 2FA route is at <prefix>/2fa/enable.
        $prefix = api_prefix($this->context);
        self::assertNotNull($router->match(Request::create($prefix . '/2fa/enable', 'POST')));
    }

    public function test_show_method_carries_requires_permission(): void
    {
        $rm = new \ReflectionMethod(\Glueful\Extensions\Users\Controllers\UserController::class, 'show');
        $attrs = $rm->getAttributes(\Glueful\Auth\Attributes\RequiresPermission::class);
        self::assertCount(1, $attrs);
        self::assertSame('users.view', $attrs[0]->newInstance()->name);
    }

    public function test_user_list_route_file_registers_route(): void
    {
        $this->bootApp();
        $router = $this->loadRouteFile('/routes/user-list.php');
        self::assertNotNull($router->match(Request::create('/users', 'GET')), '/users registered by routes/user-list.php');
    }

    public function test_list_route_absent_unless_both_flags(): void
    {
        // lookup on, list off → no /users
        $this->bootApp(['users.php' => "['user_lookup'=>['enabled'=>true,'list'=>['enabled'=>false]]]"]);
        $router = $this->bootProvider();
        $prefix = api_prefix($this->context);
        self::assertNull($router->match(Request::create($prefix . '/users', 'GET')), 'list gated off when list.enabled=false');
    }

    public function test_list_route_present_when_both_flags(): void
    {
        $this->bootApp(['users.php' => "['user_lookup'=>['enabled'=>true,'list'=>['enabled'=>true]]]"]);
        $router = $this->bootProvider();
        $prefix = api_prefix($this->context);
        self::assertNotNull($router->match(Request::create($prefix . '/users', 'GET')), 'list registered when both flags on');
    }

    public function test_index_method_carries_requires_permission(): void
    {
        $rm = new \ReflectionMethod(\Glueful\Extensions\Users\Controllers\UserController::class, 'index');
        $attrs = $rm->getAttributes(\Glueful\Auth\Attributes\RequiresPermission::class);
        self::assertCount(1, $attrs);
        self::assertSame('users.view', $attrs[0]->newInstance()->name);
    }

    public function test_provider_registers_all_controllers(): void
    {
        // The router resolves a route handler via container->get($class) with no autowire fallback,
        // so every routed controller MUST be a registered service or its route 500s ("not found").
        $services = UsersServiceProvider::services();
        foreach ([
            \Glueful\Extensions\Users\Controllers\AccountController::class,
            \Glueful\Extensions\Users\Controllers\TwoFactorController::class,
            \Glueful\Extensions\Users\Controllers\UserController::class,
        ] as $controller) {
            self::assertArrayHasKey($controller, $services, "{$controller} must be registered in services()");
        }
    }
}
