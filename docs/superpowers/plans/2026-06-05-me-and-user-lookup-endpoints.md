# `/me` and `/users/{uuid}` Endpoints — Implementation Plan

> **For agentic workers:** REQUIRED SUB-SKILL: Use superpowers:subagent-driven-development (recommended) or superpowers:executing-plans to implement this plan task-by-task. Steps use checkbox (`- [ ]`) syntax for tracking.

**Goal:** Add `GET /me` (always) and `GET /users/{uuid}` (permission + config gated) to the glueful/users extension, returning merged user + nested profile with config-driven, field-selectable, safe-by-default columns.

**Architecture:** Security logic is decomposed into pure, injectable units, testable without the auth stack: `ProfileFieldResolver` (config ∩ real-columns − denylist → effective columns + allow-list), `PayloadProjector` (REST dot-path projection with correct nested + prune semantics), explicit-column repository readers, and a `ProfileResponder` (uuid + audience + Request → projected payload). The controller is a thin auth wrapper. Config defaults ship in `config/users.php` and are registered with `ServiceProvider::mergeConfig()` (the standard extension pattern).

**One framework reality this plan works around (verified):**
1. **Field selection must be done locally.** The framework's `FieldSelectionMiddleware` projects the whole envelope, and `FieldSelector::fromRequestAdvanced` + `Projector` mishandle this shape: a whitelist of `profile.first_name` doesn't authorize the `profile` root (so nested paths drop), and if every requested field prunes away, `Projector` treats the empty selector as "no selection" and returns the **full** payload — breaking the prune contract. We use a small in-extension `PayloadProjector` instead.

> **Framework requirement: `glueful/framework ^1.50.1`.** `ServiceProvider::mergeConfig()` was a silent no-op before 1.50.1 (it delegated to an unregistered `config.manager` service); it was fixed in 1.50.1 to populate `config()` via `ApplicationContext::mergeConfigDefaults()` (app config still wins). This plan relies on that fix, so the extension requires `^1.50.1`.

**Tech Stack:** PHP 8.3, Glueful framework `^1.50.1`, PHPUnit 10, SQLite test harness. Permissions via `Glueful\Permissions\Catalog\Permission`.

**Spec:** `docs/superpowers/specs/2026-06-05-me-and-user-lookup-endpoints-design.md`

---

## File Structure

| File | Responsibility |
|------|----------------|
| `config/users.php` (new) | Shipped config defaults (`user_lookup.enabled`, `account_fields`, `profile_fields`). Registered via `mergeConfig('users', …)`; apps override by copying it into their own `config/`. |
| `src/Support/ProfileFieldResolver.php` (new) | **Pure**: config ∩ real columns − denylist, force-include `uuid`, build dot-path allow-list. |
| `src/Support/PayloadProjector.php` (new) | **Pure**: project the default payload to requested REST dot-paths within an allow-list; prune disallowed; full default only when no `fields`. |
| `src/Support/ProfileResponder.php` (new) | `(uuid, audience, Request) → ?array`: resolve columns (introspection), read rows, merge nested, project. |
| `src/Repositories/UserRepository.php` (modify) | Add `findAccountRow`/`findProfileRow` — explicit columns, `whereNull('deleted_at')`. |
| `src/Controllers/UserController.php` (new) | `me()` (auth wrapper) + `show(uuid)` (`#[RequiresPermission('users.read')]`). |
| `routes/users.php` (new) | `GET /me`. |
| `routes/user-lookup.php` (new) | `GET /users/{uuid}` (loaded only when enabled). |
| `src/UsersServiceProvider.php` (modify) | Register new services, `mergeConfig('users', …)`, conditional route loading (config-gated), `permissions()`. |
| `tests/Support/AppTestCase.php` (new) | Boots framework on temp SQLite, runs migrations, seeds. |
| `tests/Unit/ProfileFieldResolverTest.php` (new) | Pure resolver tests. |
| `tests/Unit/PayloadProjectorTest.php` (new) | Pure projector tests. |
| `tests/Feature/ConfigModelTest.php` (new) | Shipped-default + app-override verification. |
| `tests/Feature/UserRepositoryReadersTest.php` (new) | Seeded-DB reader tests. |
| `tests/Feature/ProfileResponderTest.php` (new) | Booted-app responder tests (no auth). |
| `tests/Feature/ProviderWiringTest.php` (new) | `register()` route loading / gating / permission tests. |
| `README.md` (modify) | Document endpoints. |

**Namespaces:** code `Glueful\Extensions\Users\…` → `src/`; tests `…\Tests\…` → `tests/`.
**Run one test:** `composer test -- --filter=test_name` (from `extensions/users/`).

---

## Task 1: Shared booted-app test base

**Files:**
- Create: `tests/Support/AppTestCase.php`

- [ ] **Step 1: Write the base class**

```php
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
```

- [ ] **Step 2: Confirm the query-builder signatures used by the seed helpers + readers**

Search the whole `src/Database` tree (these live on `src/Database/QueryBuilder.php`, **not** under `src/Database/Query/`):

Run: `grep -rn "public function insert\|public function update\|public function whereNull" vendor/glueful/framework/src/Database/QueryBuilder.php`
Expected (confirmed against v1.50.1): `whereNull(string $column): static` (≈ line 249) and `insert(array $data): int` (≈ line 622); `update(array $data)` likewise on the builder. If a signature differs, adjust `seedUser`/`seedProfile` and the Task 5 readers to the real one.

- [ ] **Step 3: Commit**

```bash
git add tests/Support/AppTestCase.php
git commit -m "test(users): booted-app SQLite test base with seed helpers"
```

---

## Task 2: `config/users.php` defaults + config-model verification

Ship the config defaults and verify they reach `config()` via `mergeConfig()` (framework `^1.50.1`, where `mergeConfig()` was fixed), with an app `config/users.php` overriding.

**Files:**
- Create: `config/users.php`
- Test: `tests/Feature/ConfigModelTest.php`

- [ ] **Step 1: Write the test**

`mergeConfig('users', …)` delegates to `ApplicationContext::mergeConfigDefaults()` (1.50.1+); the test calls that seam directly to simulate the provider registering its defaults.

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Tests\Support\AppTestCase;

final class ConfigModelTest extends AppTestCase
{
    /** @return array<string,mixed> */
    private function shipped(): array
    {
        return require dirname(__DIR__, 2) . '/config/users.php';
    }

    public function test_shipped_defaults_reach_config_via_merge(): void
    {
        $this->bootApp(); // no app config/users.php
        $this->context->mergeConfigDefaults('users', $this->shipped());

        self::assertFalse((bool) config($this->context, 'users.user_lookup.enabled', null), 'lookup ships disabled');
        self::assertSame(
            ['first_name', 'last_name', 'photo_url'],
            config($this->context, 'users.profile_fields.me', null)
        );
    }

    public function test_app_config_overrides_shipped_default(): void
    {
        // App ships config/users.php enabling lookup; file wins over merged defaults.
        $this->bootApp(['users.php' => "['user_lookup'=>['enabled'=>true]]"]);
        $this->context->mergeConfigDefaults('users', $this->shipped());

        self::assertTrue((bool) config($this->context, 'users.user_lookup.enabled', null), 'app config wins');
        // A key the app did NOT set still resolves from the merged defaults:
        self::assertSame(['id', 'uuid', 'username'], config($this->context, 'users.account_fields.users', null));
    }
}
```

- [ ] **Step 2: Run it**

Run: `composer test -- --filter=ConfigModelTest`
Expected: FAIL while `config/users.php` does not exist yet (the `require` errors). If you're resuming and the file already exists, the test should already pass — skip to Step 4.

- [ ] **Step 3: Implement `config/users.php`**

```php
<?php

declare(strict_types=1);

/*
 * glueful/users — profile endpoint configuration. Registered via UsersServiceProvider::register()
 * with mergeConfig('users', …). Copy this file into your app's config/ to override.
 */
return [
    // GET /users/{uuid} master switch.
    'user_lookup' => [
        'enabled' => env('USERS_USER_LOOKUP_ENABLED', false),
    ],
    // Exposable columns per audience. Apps APPEND custom profile columns here.
    'account_fields' => [
        'me' => ['id', 'uuid', 'username', 'email', 'status', 'email_verified_at', 'two_factor_enabled', 'created_at', 'updated_at'],
        'users' => ['id', 'uuid', 'username'],
    ],
    'profile_fields' => [
        'me' => ['first_name', 'last_name', 'photo_url'],
        'users' => ['first_name', 'last_name', 'photo_url'],
    ],
];
```

- [ ] **Step 4: Run to verify pass**

Run: `composer test -- --filter=ConfigModelTest`
Expected: PASS (2 tests). Requires framework `^1.50.1` for `ApplicationContext::mergeConfigDefaults()`; if the method is missing, the extension is resolving an older framework — bump the constraint.

- [ ] **Step 5: Commit**

```bash
git add config/users.php tests/Feature/ConfigModelTest.php
git commit -m "feat(users): shipped config defaults registered via mergeConfig"
```

---

## Task 3: `ProfileFieldResolver` (pure)

**Files:**
- Create: `src/Support/ProfileFieldResolver.php`
- Test: `tests/Unit/ProfileFieldResolverTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Unit;

use Glueful\Extensions\Users\Support\ProfileFieldResolver;
use PHPUnit\Framework\TestCase;

final class ProfileFieldResolverTest extends TestCase
{
    private ProfileFieldResolver $resolver;
    /** @var list<string> */
    private array $usersCols = ['id', 'uuid', 'username', 'email', 'password', 'status', 'created_at', 'updated_at', 'deleted_at'];
    /** @var list<string> */
    private array $profilesCols = ['uuid', 'user_uuid', 'first_name', 'last_name', 'photo_url', 'photo_uuid', 'status', 'deleted_at'];

    protected function setUp(): void
    {
        $this->resolver = new ProfileFieldResolver();
    }

    public function test_intersects_with_real_columns_and_strips_account_denylist(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['id', 'email', 'password', 'nonexistent'], 'profile' => ['first_name']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertContains('id', $r['account']);
        self::assertContains('email', $r['account']);
        self::assertNotContains('password', $r['account']);
        self::assertNotContains('nonexistent', $r['account']);
    }

    public function test_account_denylist_includes_deleted_at(): void
    {
        $r = $this->resolver->resolve(['account' => ['uuid', 'deleted_at'], 'profile' => []], $this->usersCols, $this->profilesCols);
        self::assertNotContains('deleted_at', $r['account']);
    }

    public function test_profile_denylist_strips_user_uuid_and_deleted_at(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['uuid'], 'profile' => ['first_name', 'user_uuid', 'deleted_at']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertSame(['first_name'], $r['profile']);
    }

    public function test_forces_uuid_when_account_config_empty(): void
    {
        $r = $this->resolver->resolve(['account' => [], 'profile' => []], $this->usersCols, $this->profilesCols);
        self::assertSame(['uuid'], $r['account']);
    }

    public function test_builds_dot_path_allow_list(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['id', 'email'], 'profile' => ['first_name', 'photo_url']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertContains('id', $r['allow']);
        self::assertContains('email', $r['allow']);
        self::assertContains('profile.first_name', $r['allow']);
        self::assertContains('profile.photo_url', $r['allow']);
    }

    public function test_empty_profile_yields_no_profile_paths(): void
    {
        $r = $this->resolver->resolve(['account' => ['uuid'], 'profile' => []], $this->usersCols, $this->profilesCols);
        self::assertSame([], $r['profile']);
        self::assertSame(['uuid'], $r['allow']);
    }

    public function test_photo_uuid_is_opt_in_not_denylisted(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['uuid'], 'profile' => ['first_name', 'photo_uuid']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertContains('photo_uuid', $r['profile']);
        self::assertContains('profile.photo_uuid', $r['allow']);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter=ProfileFieldResolverTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `ProfileFieldResolver`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

/**
 * Pure resolution of exposable columns. effective = (configured ∩ real columns) − denylist;
 * account force-includes `uuid`. No DB / container — fully unit-testable.
 */
final class ProfileFieldResolver
{
    private const ACCOUNT_DENYLIST = ['password', 'deleted_at'];
    private const PROFILE_DENYLIST = ['user_uuid', 'deleted_at'];

    /**
     * @param array{account?: list<string>, profile?: list<string>} $configured
     * @param list<string> $realAccountColumns normalized column names of `users`
     * @param list<string> $realProfileColumns normalized column names of `profiles`
     * @return array{account: list<string>, profile: list<string>, allow: list<string>}
     */
    public function resolve(array $configured, array $realAccountColumns, array $realProfileColumns): array
    {
        $account = $this->effective($configured['account'] ?? [], $realAccountColumns, self::ACCOUNT_DENYLIST);
        if (!in_array('uuid', $account, true) && in_array('uuid', $realAccountColumns, true)) {
            $account[] = 'uuid';
        }
        $profile = $this->effective($configured['profile'] ?? [], $realProfileColumns, self::PROFILE_DENYLIST);

        $allow = $account;
        foreach ($profile as $field) {
            $allow[] = 'profile.' . $field;
        }

        return [
            'account' => array_values($account),
            'profile' => array_values($profile),
            'allow' => array_values($allow),
        ];
    }

    /**
     * @param list<string> $configured
     * @param list<string> $realColumns
     * @param list<string> $denylist
     * @return list<string>
     */
    private function effective(array $configured, array $realColumns, array $denylist): array
    {
        return array_values(array_diff(array_intersect($configured, $realColumns), $denylist));
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `composer test -- --filter=ProfileFieldResolverTest`
Expected: PASS (7 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/ProfileFieldResolver.php tests/Unit/ProfileFieldResolverTest.php
git commit -m "feat(users): pure ProfileFieldResolver"
```

---

## Task 4: `PayloadProjector` (pure REST dot-path projection)

Correct nested + prune semantics, independent of the framework projector.

**Files:**
- Create: `src/Support/PayloadProjector.php`
- Test: `tests/Unit/PayloadProjectorTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Unit;

use Glueful\Extensions\Users\Support\PayloadProjector;
use PHPUnit\Framework\TestCase;

final class PayloadProjectorTest extends TestCase
{
    private PayloadProjector $p;
    /** @var array<string,mixed> */
    private array $merged;
    /** @var list<string> */
    private array $allow = ['id', 'email', 'uuid', 'profile.first_name', 'profile.photo_url'];

    protected function setUp(): void
    {
        $this->p = new PayloadProjector();
        $this->merged = [
            'id' => 1,
            'uuid' => 'u-1',
            'email' => 'jane@example.com',
            'profile' => ['first_name' => 'Jane', 'photo_url' => 'p.png'],
        ];
    }

    public function test_null_fields_returns_full_default(): void
    {
        self::assertSame($this->merged, $this->p->project($this->merged, $this->allow, null));
    }

    public function test_empty_fields_string_returns_full_default(): void
    {
        self::assertSame($this->merged, $this->p->project($this->merged, $this->allow, ''));
    }

    public function test_selects_root_scalars(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'id,email');
        self::assertSame(['id' => 1, 'email' => 'jane@example.com'], $out);
    }

    public function test_selects_nested_child(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'email,profile.first_name');
        self::assertSame(['email' => 'jane@example.com', 'profile' => ['first_name' => 'Jane']], $out);
    }

    public function test_bare_profile_returns_whole_profile(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'profile');
        self::assertSame(['profile' => ['first_name' => 'Jane', 'photo_url' => 'p.png']], $out);
    }

    public function test_disallowed_root_is_pruned_to_empty(): void
    {
        self::assertSame([], $this->p->project($this->merged, $this->allow, 'password'));
        self::assertSame([], $this->p->project($this->merged, $this->allow, 'bogus'));
    }

    public function test_disallowed_nested_child_is_pruned(): void
    {
        // 'profile.phone' not in allow → pruned (empty), NOT full payload.
        self::assertSame([], $this->p->project($this->merged, $this->allow, 'profile.phone'));
    }

    public function test_mixed_allowed_and_disallowed_keeps_only_allowed(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'email,password,profile.first_name,profile.phone');
        self::assertSame(['email' => 'jane@example.com', 'profile' => ['first_name' => 'Jane']], $out);
    }

    public function test_null_profile_with_nested_request_yields_no_profile_key(): void
    {
        $merged = ['uuid' => 'u-1', 'profile' => null];
        self::assertSame([], $this->p->project($merged, $this->allow, 'profile.first_name'));
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter=PayloadProjectorTest`
Expected: FAIL — class not found.

- [ ] **Step 3: Implement `PayloadProjector`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

/**
 * Projects the default payload to the requested REST dot-path `fields`, constrained by an
 * allow-list. Supports one level of nesting under `profile`. Unknown/disallowed fields are
 * omitted (pruned). When no `fields` is provided, the full default payload is returned —
 * distinguishing "no selection" from "everything pruned" (which yields an empty object).
 *
 * Field-selection contract (v1): **prune-only — never raises a 400.** This replaces the framework
 * field stack, so there is no `maxDepth`/`maxFields` enforcement and no structural over-limit
 * error. The output is inherently bounded: nesting is at most one level (`profile.<child>`) by
 * construction, and the result is constrained to the (small, config-derived) allow-list regardless
 * of how many fields are requested. `expand` is not supported (profile is always inline) and is
 * ignored.
 */
final class PayloadProjector
{
    /**
     * @param array<string,mixed> $merged default shape: account scalars + 'profile' => array|null
     * @param list<string> $allow account field names + 'profile.<child>' entries
     * @param string|null $fields raw ?fields query value (null/'' = not provided → full default)
     * @return array<string,mixed>
     */
    public function project(array $merged, array $allow, ?string $fields): array
    {
        if ($fields === null || $fields === '') {
            return $merged;
        }

        $allowedRoots = [];
        $allowedProfileChildren = [];
        foreach ($allow as $entry) {
            if (str_starts_with($entry, 'profile.')) {
                $allowedProfileChildren[substr($entry, strlen('profile.'))] = true;
            } else {
                $allowedRoots[$entry] = true;
            }
        }

        $requested = array_filter(
            array_map('trim', explode(',', $fields)),
            static fn(string $s): bool => $s !== ''
        );

        $out = [];
        foreach ($requested as $path) {
            if ($path === 'profile') {
                if (array_key_exists('profile', $merged)) {
                    $out['profile'] = $merged['profile'];
                }
                continue;
            }
            if (str_starts_with($path, 'profile.')) {
                $child = substr($path, strlen('profile.'));
                $profile = $merged['profile'] ?? null;
                if (isset($allowedProfileChildren[$child]) && is_array($profile) && array_key_exists($child, $profile)) {
                    $out['profile'][$child] = $profile[$child];
                }
                continue;
            }
            if (isset($allowedRoots[$path]) && array_key_exists($path, $merged)) {
                $out[$path] = $merged[$path];
            }
        }

        return $out;
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `composer test -- --filter=PayloadProjectorTest`
Expected: PASS (9 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/PayloadProjector.php tests/Unit/PayloadProjectorTest.php
git commit -m "feat(users): pure PayloadProjector (correct nested + prune semantics)"
```

---

## Task 5: Repository readers (explicit columns + soft-delete)

**Files:**
- Modify: `src/Repositories/UserRepository.php`
- Test: `tests/Feature/UserRepositoryReadersTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;

final class UserRepositoryReadersTest extends AppTestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        $this->repo = new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
        $this->seedUser('u-1', 'jane@example.com', 'jane');
        $this->seedProfile('u-1');
    }

    public function test_find_account_row_returns_only_requested_columns(): void
    {
        $row = $this->repo->findAccountRow('u-1', ['uuid', 'email']);
        self::assertNotNull($row);
        self::assertSame(['uuid', 'email'], array_keys($row));
        self::assertArrayNotHasKey('password', $row);
    }

    public function test_find_account_row_unknown_uuid_is_null(): void
    {
        self::assertNull($this->repo->findAccountRow('nope', ['uuid']));
    }

    public function test_find_account_row_empty_columns_is_null(): void
    {
        self::assertNull($this->repo->findAccountRow('u-1', []));
    }

    public function test_find_account_row_excludes_soft_deleted(): void
    {
        $this->db()->table('users')->where(['uuid' => 'u-1'])->update(['deleted_at' => '2026-01-01 00:00:00']);
        self::assertNull($this->repo->findAccountRow('u-1', ['uuid']), 'soft-deleted user is not found');
    }

    public function test_find_profile_row_returns_only_requested_columns(): void
    {
        $row = $this->repo->findProfileRow('u-1', ['first_name', 'photo_url']);
        self::assertNotNull($row);
        self::assertSame(['first_name', 'photo_url'], array_keys($row));
        self::assertArrayNotHasKey('photo_uuid', $row);
    }

    public function test_find_profile_row_no_profile_is_null(): void
    {
        $this->seedUser('u-2', 'no@profile.com', 'noprof');
        self::assertNull($this->repo->findProfileRow('u-2', ['first_name']));
    }

    public function test_find_profile_row_excludes_soft_deleted(): void
    {
        $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->update(['deleted_at' => '2026-01-01 00:00:00']);
        self::assertNull($this->repo->findProfileRow('u-1', ['first_name']), 'soft-deleted profile is not found');
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter=UserRepositoryReadersTest`
Expected: FAIL — methods not defined.

- [ ] **Step 3: Add the readers**

Add to `src/Repositories/UserRepository.php` (next to `getProfile()`):

```php
    /**
     * Read a single non-deleted user row selecting ONLY the given columns (never SELECT *).
     *
     * @param list<string> $columns
     * @return array<string,mixed>|null
     */
    public function findAccountRow(string $uuid, array $columns): ?array
    {
        if ($columns === []) {
            return null;
        }
        $rows = $this->db->table('users')
            ->select($columns)
            ->where(['uuid' => $uuid])
            ->whereNull('deleted_at')
            ->limit(1)
            ->get();

        return $rows !== [] ? $rows[0] : null;
    }

    /**
     * Read a single non-deleted profile row selecting ONLY the given columns.
     *
     * @param list<string> $columns
     * @return array<string,mixed>|null
     */
    public function findProfileRow(string $uuid, array $columns): ?array
    {
        if ($columns === []) {
            return null;
        }
        $rows = $this->db->table('profiles')
            ->select($columns)
            ->where(['user_uuid' => $uuid])
            ->whereNull('deleted_at')
            ->limit(1)
            ->get();

        return $rows !== [] ? $rows[0] : null;
    }
```

> If Task 1 Step 2 found that `whereNull()` is not on the builder, replace `->whereNull('deleted_at')` with the confirmed equivalent (e.g. `->where('deleted_at', '=', null)`); keep the soft-delete test as the contract.

- [ ] **Step 4: Run to verify pass**

Run: `composer test -- --filter=UserRepositoryReadersTest`
Expected: PASS (6 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Repositories/UserRepository.php tests/Feature/UserRepositoryReadersTest.php
git commit -m "feat(users): explicit-column readers with soft-delete scoping"
```

---

## Task 6: `ProfileResponder`

**Files:**
- Create: `src/Support/ProfileResponder.php`
- Test: `tests/Feature/ProfileResponderTest.php`

- [ ] **Step 1: Write the failing tests**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Support\PayloadProjector;
use Glueful\Extensions\Users\Support\ProfileFieldResolver;
use Glueful\Extensions\Users\Support\ProfileResponder;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProfileResponderTest extends AppTestCase
{
    /**
     * Boots a FRESH app whose config/users.php is the given array (config is read at boot),
     * seeds u-1 + profile, returns a responder. Extra seeding/altering must happen AFTER.
     *
     * @param array<string,mixed> $usersConfig
     */
    private function makeResponder(array $usersConfig): ProfileResponder
    {
        $this->bootApp(['users.php' => var_export($usersConfig, true)]);
        $this->seedUser('u-1', 'jane@example.com', 'jane');
        $this->seedProfile('u-1');

        $repo = new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
        return new ProfileResponder($this->context, $repo, new ProfileFieldResolver(), new PayloadProjector());
    }

    /** @return array<string,mixed> */
    private function defaultConfig(): array
    {
        return [
            'account_fields' => [
                'me' => ['id', 'uuid', 'username', 'email', 'status', 'created_at', 'updated_at'],
                'users' => ['id', 'uuid', 'username'],
            ],
            'profile_fields' => [
                'me' => ['first_name', 'last_name', 'photo_url'],
                'users' => ['first_name', 'last_name', 'photo_url'],
            ],
        ];
    }

    public function test_default_shape_nests_profile_and_omits_photo_uuid(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me'));
        self::assertNotNull($out);
        self::assertSame('jane@example.com', $out['email']);
        self::assertSame('Jane', $out['profile']['first_name']);
        self::assertArrayNotHasKey('photo_uuid', $out['profile']);
        self::assertArrayNotHasKey('password', $out);
    }

    public function test_field_selection_narrows_payload(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields=id,email'));
        self::assertSame(['id', 'email'], array_keys($out));
    }

    public function test_nested_dot_path_selection(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields=email,profile.first_name'));
        self::assertSame(['email' => 'jane@example.com', 'profile' => ['first_name' => 'Jane']], $out);
    }

    public function test_password_request_prunes_to_empty(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields=password'));
        self::assertSame([], $out, 'disallowed-only selection prunes to empty, not full payload');
    }

    public function test_array_fields_param_is_handled_without_error(): void
    {
        // ?fields[]=email makes Symfony's query->get('fields') return an array; it must be
        // normalized to null (treated as "no selection") rather than TypeError-ing.
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields[]=email'));
        self::assertArrayHasKey('profile', $out, 'array fields param → full default, no error');
    }

    public function test_profile_null_when_no_profile_row(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $this->seedUser('u-2', 'no@profile.com', 'noprof');
        $out = $r->build('u-2', 'me', Request::create('/me'));
        self::assertNull($out['profile']);
    }

    public function test_empty_profile_config_yields_null_profile(): void
    {
        $cfg = $this->defaultConfig();
        $cfg['profile_fields']['me'] = [];
        $r = $this->makeResponder($cfg);
        $out = $r->build('u-1', 'me', Request::create('/me'));
        self::assertNull($out['profile']);
        self::assertSame('jane@example.com', $out['email']);
    }

    public function test_unknown_uuid_returns_null(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        self::assertNull($r->build('nope', 'me', Request::create('/me')));
    }

    public function test_forced_uuid_when_account_config_empty(): void
    {
        $cfg = $this->defaultConfig();
        $cfg['account_fields']['me'] = [];
        $r = $this->makeResponder($cfg);
        $out = $r->build('u-1', 'me', Request::create('/me'));
        self::assertArrayHasKey('uuid', $out);
    }

    public function test_custom_profile_column_opt_in(): void
    {
        $cfg = $this->defaultConfig();
        $cfg['profile_fields']['me'][] = 'phone';
        $r = $this->makeResponder($cfg);

        $this->db()->getSchemaBuilder()->alterTable('profiles', function ($t) {
            $t->addColumn('phone', 'string', ['length' => 32, 'nullable' => true]);
        });
        $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->update(['phone' => '+1-555-0100']);

        $out = $r->build('u-1', 'me', Request::create('/me?fields=profile.phone'));
        self::assertSame(['phone' => '+1-555-0100'], $out['profile']);
    }

    public function test_custom_column_not_configured_is_pruned(): void
    {
        $r = $this->makeResponder($this->defaultConfig()); // 'phone' not configured

        $this->db()->getSchemaBuilder()->alterTable('profiles', function ($t) {
            $t->addColumn('phone', 'string', ['length' => 32, 'nullable' => true]);
        });
        $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->update(['phone' => '+1-555-0100']);

        $out = $r->build('u-1', 'me', Request::create('/me?fields=profile.phone'));
        self::assertSame([], $out, 'unconfigured custom column prunes to empty');
    }

    public function test_audience_split_users_narrower_than_me(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $me = $r->build('u-1', 'me', Request::create('/me'));
        $users = $r->build('u-1', 'users', Request::create('/users/u-1'));
        self::assertArrayHasKey('email', $me);
        self::assertArrayNotHasKey('email', $users);
    }
}
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter=ProfileResponderTest`
Expected: FAIL — class `ProfileResponder` not found.

- [ ] **Step 3: Implement `ProfileResponder`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the merged user + nested-profile payload for an audience ('me' | 'users'), applying
 * config-driven column resolution (config registered via the provider's mergeConfig('users', …);
 * app config/users.php overrides) and REST field selection.
 * Returns null when the account row does not exist (caller maps to 404).
 */
final class ProfileResponder
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly UserRepository $users,
        private readonly ProfileFieldResolver $resolver,
        private readonly PayloadProjector $projector,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function build(string $uuid, string $audience, Request $request): ?array
    {
        $schema = Connection::fromContext($this->context)->getSchemaBuilder();
        $realAccount = array_column($schema->getTableColumns('users'), 'name');
        $realProfile = array_column($schema->getTableColumns('profiles'), 'name');

        // Config defaults are registered by the provider via mergeConfig('users', …); an app's
        // config/users.php overrides per key. [] is a safe last-resort if neither applies.
        $configured = [
            'account' => (array) config($this->context, "users.account_fields.$audience", []),
            'profile' => (array) config($this->context, "users.profile_fields.$audience", []),
        ];

        $eff = $this->resolver->resolve($configured, $realAccount, $realProfile);

        $account = $this->users->findAccountRow($uuid, $eff['account']);
        if ($account === null) {
            return null;
        }

        $profile = $eff['profile'] !== []
            ? $this->users->findProfileRow($uuid, $eff['profile'])
            : null;

        $merged = [...$account, 'profile' => $profile];

        // Symfony can return an array for ?fields[]=…; PayloadProjector expects ?string.
        $fields = $request->query->get('fields');
        $fields = is_string($fields) ? $fields : null;

        return $this->projector->project($merged, $eff['allow'], $fields);
    }
}
```

- [ ] **Step 4: Run to verify pass**

Run: `composer test -- --filter=ProfileResponderTest`
Expected: PASS (11 tests).

- [ ] **Step 5: Commit**

```bash
git add src/Support/ProfileResponder.php tests/Feature/ProfileResponderTest.php
git commit -m "feat(users): ProfileResponder — resolve/read/merge/project"
```

---

## Task 7: `UserController::me` + `/me` route + provider wiring

**Files:**
- Create: `src/Controllers/UserController.php`
- Create: `routes/users.php`
- Modify: `src/UsersServiceProvider.php`
- Test: `tests/Feature/ProviderWiringTest.php`

- [ ] **Step 1: Create `src/Controllers/UserController.php`**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Controllers;

use Glueful\Auth\Attributes\RequiresPermission;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Users\Support\ProfileResponder;
use Glueful\Http\Response;
use Symfony\Component\HttpFoundation\Request;

final class UserController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private readonly ProfileResponder $responder,
    ) {
        parent::__construct($context);
    }

    /** GET /me — authenticated principal's account + nested profile. */
    public function me(Request $request): Response
    {
        $uuid = $this->currentUser?->uuid();
        if ($uuid === null) {
            return $this->unauthorized('Authentication required');
        }
        $payload = $this->responder->build($uuid, 'me', $request);
        if ($payload === null) {
            return $this->notFound('User not found');
        }
        return $this->success($payload);
    }

    /** GET /users/{uuid} — another user's account + public profile. */
    #[RequiresPermission('users.read')]
    public function show(string $uuid, Request $request): Response
    {
        $payload = $this->responder->build($uuid, 'users', $request);
        if ($payload === null) {
            return $this->notFound('User not found');
        }
        return $this->success($payload);
    }
}
```

- [ ] **Step 2: Create `routes/users.php`**

```php
<?php

/**
 * glueful/users profile route — GET /me (always). Loaded from UsersServiceProvider::register().
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\UserController;

$router->get('/me', [UserController::class, 'me'])
    ->middleware('auth')
    ->name('users.me');
```

- [ ] **Step 3: Wire `UsersServiceProvider`**

(a) Add imports:

```php
use Glueful\Permissions\Catalog\Permission;
use Glueful\Extensions\Users\Support\PayloadProjector;
use Glueful\Extensions\Users\Support\ProfileFieldResolver;
use Glueful\Extensions\Users\Support\ProfileResponder;
```

(b) Add to the `services()` array (after the `TwoFactorService` entry):

```php
            ProfileFieldResolver::class => ['class' => ProfileFieldResolver::class, 'shared' => true, 'autowire' => true],
            PayloadProjector::class => ['class' => PayloadProjector::class, 'shared' => true, 'autowire' => true],
            ProfileResponder::class => ['class' => ProfileResponder::class, 'shared' => true, 'autowire' => true],
```

(c) Replace `register()`:

```php
    public function register(ApplicationContext $context): void
    {
        // Register shipped config defaults (requires framework ^1.50.1, where mergeConfig() was
        // fixed). An app's config/users.php overrides per key.
        $this->mergeConfig('users', require __DIR__ . '/../config/users.php');

        $this->loadRoutesFrom(__DIR__ . '/../routes/account.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/2fa.php');
        $this->loadRoutesFrom(__DIR__ . '/../routes/users.php');

        if ((bool) config($context, 'users.user_lookup.enabled', false)) {
            $this->loadRoutesFrom(__DIR__ . '/../routes/user-lookup.php');
        }

        $this->loadMigrationsFrom(__DIR__ . '/../migrations', MigrationPriority::IDENTITY, 'glueful/users');
    }
```

- [ ] **Step 4: Write the provider-wiring test (real `register()` path)**

```php
<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\UsersServiceProvider;
use Glueful\Routing\Router;
use Symfony\Component\HttpFoundation\Request;

/**
 * IMPORTANT: every test that loads routes via register() MUST run in a separate process.
 * `ServiceProvider::loadRoutesFrom()` keeps a function-`static $loaded` realpath cache
 * (ServiceProvider.php) that persists for the whole PHP process and is NOT cleared by
 * `RouteManifest::reset()`. Without process isolation, the first register() loads the route
 * files and every later test gets a fresh (empty) router but the static cache skips re-loading
 * — making route-presence assertions order-dependent and flaky. `@runInSeparateProcess` +
 * `@preserveGlobalState disabled` gives each test a fresh static.
 */
final class ProviderWiringTest extends AppTestCase
{
    private function registerProvider(): Router
    {
        $container = $this->app->getContainer();
        $provider = new UsersServiceProvider($container);
        $provider->register($this->context);
        return $container->get(Router::class);
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_register_loads_me_route(): void
    {
        $this->bootApp();
        $router = $this->registerProvider();
        self::assertNotNull($router->match(Request::create('/me', 'GET')), '/me registered by register()');
    }

    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_lookup_route_absent_by_default(): void
    {
        $this->bootApp(); // no app config/users.php → shipped default (disabled)
        $router = $this->registerProvider();
        self::assertNull($router->match(Request::create('/users/u-1', 'GET')), 'lookup gated off');
    }
}
```

> Confirm the `ServiceProvider` constructor arg by checking `framework/src/Extensions/ServiceProvider.php` (existing tests use `new class($container) extends ServiceProvider`, so it takes the container). `Router::match()` is `match(Request $request): ?array`. The non-route tests added in Tasks 8–9 (`permissions()`, the `#[RequiresPermission]` reflection check) do **not** load routes, so they don't need process isolation — but the **enabled-lookup** test in Task 9 does (it calls `register()`).

- [ ] **Step 5: Run the suite**

Run: `composer test`
Expected: PASS. Resolve any autowire error for `ProfileResponder` by confirming `UserRepository` (registered), `ProfileFieldResolver`/`PayloadProjector` (registered), and `ApplicationContext` are resolvable.

- [ ] **Step 6: Commit**

```bash
git add src/Controllers/UserController.php routes/users.php src/UsersServiceProvider.php tests/Feature/ProviderWiringTest.php
git commit -m "feat(users): GET /me endpoint + provider wiring"
```

---

## Task 8: Declare the `users.read` permission

**Files:**
- Modify: `src/UsersServiceProvider.php`
- Test: `tests/Feature/ProviderWiringTest.php`

- [ ] **Step 1: Write the failing test (append to `ProviderWiringTest`)**

```php
    public function test_provider_declares_users_read_permission(): void
    {
        $this->bootApp();
        $provider = new UsersServiceProvider($this->app->getContainer());
        $slugs = array_map(static fn($p) => $p->slug(), $provider->permissions());
        self::assertContains('users.read', $slugs);
    }
```

- [ ] **Step 2: Run to verify failure**

Run: `composer test -- --filter=test_provider_declares_users_read_permission`
Expected: FAIL — `permissions()` returns `[]`.

- [ ] **Step 3: Add the `permissions()` hook**

```php
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
```

- [ ] **Step 4: Run to verify pass**

Run: `composer test -- --filter=test_provider_declares_users_read_permission`
Expected: PASS.

- [ ] **Step 5: Commit**

```bash
git add src/UsersServiceProvider.php tests/Feature/ProviderWiringTest.php
git commit -m "feat(users): declare users.read permission"
```

---

## Task 9: `GET /users/{uuid}` — gated route

`show()` already exists (Task 7). Add the gated route file + gating/attribute tests.

**Files:**
- Create: `routes/user-lookup.php`
- Test: `tests/Feature/ProviderWiringTest.php`

- [ ] **Step 1: Create `routes/user-lookup.php`**

```php
<?php

/**
 * glueful/users user-lookup route — GET /users/{uuid}. Loaded by UsersServiceProvider::register()
 * ONLY when config('users.user_lookup.enabled') is true, so it registers unconditionally here.
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\UserController;

$router->get('/users/{uuid}', [UserController::class, 'show'])
    ->middleware(['auth', 'gate_permissions'])
    ->where('uuid', '[A-Za-z0-9_-]+')
    ->name('users.show');
```

- [ ] **Step 2: Write the gating + attribute tests (append to `ProviderWiringTest`)**

```php
    /**
     * @runInSeparateProcess
     * @preserveGlobalState disabled
     */
    public function test_lookup_route_present_when_enabled(): void
    {
        // Separate process: loadRoutesFrom()'s static realpath cache would otherwise make this
        // order-dependent (see the ProviderWiringTest class docblock).
        $this->bootApp(['users.php' => "['user_lookup'=>['enabled'=>true]]"]);
        $provider = new UsersServiceProvider($this->app->getContainer());
        $provider->register($this->context);
        $router = $this->app->getContainer()->get(Router::class);
        self::assertNotNull($router->match(Request::create('/users/u-1', 'GET')), 'lookup registered when enabled');
    }

    // No route loading → no process isolation needed.
    public function test_show_method_carries_requires_permission(): void
    {
        $rm = new \ReflectionMethod(\Glueful\Extensions\Users\Controllers\UserController::class, 'show');
        $attrs = $rm->getAttributes(\Glueful\Auth\Attributes\RequiresPermission::class);
        self::assertCount(1, $attrs);
        self::assertSame('users.read', $attrs[0]->newInstance()->name);
    }
```

- [ ] **Step 3: Run the tests**

Run: `composer test -- --filter=ProviderWiringTest`
Expected: PASS. (Field/audience behavior of `show()` is covered by `ProfileResponderTest` via the `users` audience; live permission enforcement is the framework's `gate_permissions` middleware.)

- [ ] **Step 4: Commit**

```bash
git add routes/user-lookup.php tests/Feature/ProviderWiringTest.php
git commit -m "feat(users): gated GET /users/{uuid} route"
```

---

## Task 10: Documentation

**Files:**
- Modify: `README.md`

- [ ] **Step 1: Add an "Account read endpoints" section after "API Endpoints"**

````markdown
### Account read endpoints

- `GET /me` — the authenticated principal's account + nested `profile` (auth required, always on).
- `GET /users/{uuid}` — another user's account + public profile. **Off by default** (`USERS_USER_LOOKUP_ENABLED=true`), requires the `users.read` permission.

**Field selection (REST dot-paths):**

```bash
GET /me                                   # full default shape
GET /me?fields=id,email                   # only those
GET /me?fields=email,profile.first_name   # nested subset
```

Disallowed/unknown fields are pruned (omitted). Requesting only disallowed fields returns an empty object — not the full payload.

**Exposable columns are config-driven** (`config/users.php`) — separately for `me` and `users` audiences. Add a custom `profiles` column (via migration), then opt it in:

```php
'profile_fields' => [
    'me'    => ['first_name', 'last_name', 'photo_url', 'phone'], // exposed to self
    'users' => ['first_name', 'last_name', 'photo_url'],          // not to others
],
```

`password` and `deleted_at` are never exposable (hard denylist); `photo_uuid` is absent by default but can be opted in. To override defaults, copy the package's `config/users.php` into your app's `config/` and edit it.
````

- [ ] **Step 2: Commit**

```bash
git add README.md
git commit -m "docs(users): document /me and /users/{uuid} endpoints"
```

---

## Final verification

- [ ] **Run the full suite + static analysis**

Run: `composer test && composer analyse`
Expected: all PASS; PHPStan clean. Fix any types on the new files.

- [ ] **Spec coverage cross-check** — default shape (no `photo_uuid`), nested dot-path, prune-to-empty (password/bogus/unconfigured), custom-field opt-in, audience split, introspection tolerance, empty profile → null, forced `uuid`, soft-delete/unknown → null → 404, lookup gating present/absent, `users.read` declaration + `RequiresPermission` attribute, config model (shipped default + app override).

---

## Self-review notes

- **Config via standard `mergeConfig`** (requires framework `^1.50.1`, where it was fixed): the provider registers `config/users.php` defaults; the responder reads `config('users.*', [])`; an app's `config/users.php` overrides per key.
- **No reliance on framework field projection**: `PayloadProjector` gives correct nested + prune semantics (the `fromRequestAdvanced`/`Projector` combo drops nested paths and returns the full payload when everything prunes).
- **Type consistency:** `ProfileFieldResolver::resolve()` → `['account','profile','allow']`; `PayloadProjector::project(merged, allow, ?fields)`; `ProfileResponder::build()` → `?array`; readers `findAccountRow`/`findProfileRow`; controller maps `null → notFound()`.
- **APIs confirmed against v1.50.1:** `Router::match(Request): ?array`; `AlterTableBuilder::addColumn(string,string,array)`; `QueryBuilder::whereNull(string): static` and `insert(array): int` (on `src/Database/QueryBuilder.php`, not `src/Database/Query/`); `Permission::define()->label()->description()->category()->managedBy()`; `ApplicationContext::mergeConfigDefaults()` + `config($ctx,$key,$default)` (config defaults via `mergeConfig`, app `config/users.php` overrides).
- **`ProviderWiringTest` route-loading tests run in separate processes** — `loadRoutesFrom()`'s function-`static $loaded` realpath cache persists per process and isn't reset by `RouteManifest::reset()`, so repeated `register()` calls would otherwise be order-dependent.
