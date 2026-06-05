# Design: `/me` and `/users/{uuid}` profile endpoints (glueful/users)

- **Date:** 2026-06-05
- **Status:** Draft — awaiting review
- **Scope:** glueful/users extension (not framework core)
- **Rev 2 (2026-06-05):** two field-selection corrections from review — (a) nested allow-lists require `fromRequestAdvanced()` (basic `applyWhitelist` and `Projector` strict-guard validate **root names** only, so dot-paths like `profile.first_name` get dropped/misjudged); (b) the default (no-`?fields`) `$merged` must be built from **endpoint-specific column sets**, not `getProfile()` (which returns `photo_uuid`, outside the allow-list).
- **Rev 3 (2026-06-05):** exposable columns are now **config-driven** (`config/users.php`) so dev-added custom `profile` columns (via migration) can be exposed by opt-in — with **separate `me` vs `users` lists** (self can expose more than other-user), a hard code-level **denylist** that's always stripped, and **column introspection** so config typos are ignored rather than erroring. No auto-include.
- **Rev 4 (2026-06-05):** four review fixes — (a) **single source of truth** for the lookup gate: the provider conditionally loads a separate `routes/user-lookup.php` based on merged config (route files can't read config), so app overrides apply; (b) `getTableColumns()` returns `array<int,array{name,…}>` → **normalize with `array_column(…, 'name')`** before intersecting; (c) **drop the GraphQL-syntax claim** — v1 supports REST dot-paths (`profile.first_name`) only; (d) define **empty-effective-set behavior** (no `select([])`): empty profile set → `"profile": null`; account set always force-includes `uuid`.
- **Rev 5 (2026-06-05):** four review polish items — (a) pseudocode now **skips `findProfileRow()` when `$profileCols === []`**; (b) **`photo_uuid` policy made explicit**: absent by default (not shipped), opt-in possible (not denylisted) — it's content metadata, unlike structural `user_uuid`; (c) added a **forced-`uuid`** test (empty `account_fields` still returns identity); (d) moved the **`config.manager` precedence** check into build step 1 as a blocking verification task.
- **Rev 6 (2026-06-05):** four final review fixes — (a) **null `findAccountRow()` → 404** before spreading (both endpoints; never spread null); (b) **`ACCOUNT_DENYLIST = ['password', 'deleted_at']`** (soft-delete marker is structural for `users` too); (c) reworded the forced-`uuid` guarantee to **default-response-only** (explicit field selection may still omit `uuid`); (d) corrected stale "outside the allow-lists" → **"outside the shipped default lists"** for `photo_uuid` (it's opt-in-able).
- **Rev 7 (2026-06-05):** **implementation supersedes the `fromRequestAdvanced` projection decision.** During plan-writing, `FieldSelector::fromRequestAdvanced` + `Projector` proved unsuitable for this shape (a `profile.first_name` whitelist doesn't authorize the `profile` root, and an all-pruned selector makes `Projector` return the *full* payload). The endpoints use a small in-extension **`PayloadProjector`** (REST dot-paths, one-level `profile.*` nesting, prune-only). **Contract change:** field selection is **prune-only and never raises a 400** — the `maxDepth`/`maxFields`-over-limit-→-400 promises in the sections below are **withdrawn** for these endpoints (output is inherently bounded: one-level nesting + the small config-derived allow-list). The `fromRequestAdvanced`/`Projector` mechanism references below are historical; see the implementation plan for the authoritative approach.

## Context

Post-1.50.0, framework core is provider-agnostic: it owns identity primitives (`UserIdentity`, `UserProviderInterface`) but **not** the concrete user store. The `users` and `profiles` tables, and any "read my account" surface, belong to the **glueful/users** extension.

Today there is no first-class way to read the authenticated user's full account (user row + profile). Apps hand-roll it, which risks leaking sensitive columns (the `users` table has a `password` column) and produces inconsistent shapes. We add two read endpoints that return merged user + profile data and let callers pick fields via the framework's field-selection feature.

## Goals

- `GET /me` — the authenticated principal's user + nested profile, field-selectable. Always available.
- `GET /users/{uuid}` — another user's user + nested profile (narrower fields). Auth + permission + config gated.
- Leverage field selection so callers fetch only what they want.
- **Support dev-added custom `profile` columns** (added via migration) through explicit **config opt-in**, separately for self (`/me`) vs other-user (`/users/{uuid}`) audiences.
- Never expose `password` or other secrets. Defense in depth: config allow-list + hard denylist + column introspection + explicit query projection.

## Non-goals

- Writing/updating the account (covered by existing account-lifecycle flows).
- Listing/searching users (`GET /users` collection) — out of scope.
- Changing framework core. Both endpoints live in the extension.
- Making the `FieldSelectionMiddleware` envelope-aware (noted as a future framework follow-up, not part of this work).

## Key technical decision: manual projection, not the field middleware

The framework's `FieldSelectionMiddleware` decodes the **entire** response body and projects from the **root** — i.e. it sees `success` / `message` / `data`, not the payload (`src/Routing/Middleware/FieldSelectionMiddleware.php:87-100`). So a caller sending `?fields=id,email` against a `Response::success([...])` envelope would match root keys, not the user fields. The middleware is **opt-in** (registered as the `field_selection` alias / activated by the `#[Fields]` attribute), **not global**.

**Decision:** these routes do **not** use `#[Fields]` or the `field_selection` middleware. The controller builds the **default safe payload** from explicit column sets, then projects that array with a small in-extension **`PayloadProjector`** (REST dot-paths, one-level `profile.*` nesting, prune-only), then wraps it:

```php
// Effective columns are resolved per request from config ∩ real columns − denylist
// (see "Configuration & column resolution"), for the endpoint's audience ('me' here).
[$accountCols, $profileCols] = $this->effectiveColumns(audience: 'me');
$allowList = $this->selectionAllowList($accountCols, $profileCols); // account ∪ "profile.$f"

// $merged is the DEFAULT safe shape (what callers get with no ?fields) — built from the
// effective columns, NOT from getProfile(). Skip the profile query when there are no
// exposable profile columns (never call findProfileRow() with []).
$account  = $this->users->findAccountRow($uuid, $accountCols);   // ?array — selects only $accountCols
if ($account === null) {
    // /users/{uuid}: 404 (unknown/soft-deleted). /me: the authenticated principal no longer
    // resolves to a row → 404 (treat as auth-state error); never spread null.
    return $this->notFound();
}
$profile  = $profileCols !== []
    ? $this->users->findProfileRow($uuid, $profileCols)          // null if no profile row
    : null;                                                      // no exposable profile cols → null
$merged   = [...$account, 'profile' => $profile];

// Local projector. ?fields[]=… makes Symfony return an array → normalize to "no selection".
$fields   = $request->query->get('fields');
$fields   = is_string($fields) ? $fields : null;
$payload  = $this->projector->project($merged, $allowList, $fields);  // prune within $allowList
return Response::success($payload);                                   // wrap AFTER projecting
```

Why a local `PayloadProjector`, not the framework field stack (`FieldSelector::fromRequestAdvanced` + `Projector`):

- **The framework stack mishandles this shape.** A whitelist of `profile.first_name` doesn't authorize the `profile` **root**, so nested paths drop; and when every requested field prunes away, `Projector` treats the empty selector as "no selection" and returns the **full** payload — breaking the prune contract for `?fields=password`/`bogus`/unconfigured-`profile.phone`. (`FieldSelectionMiddleware` also projects the whole envelope, not the payload — another reason it can't be used here.)
- **`PayloadProjector` semantics.** Parse REST dot-paths; include requested **allowed** root scalars and requested **allowed** `profile.<child>` children; omit everything else. Return the **full default** only when no `fields` is provided; an all-disallowed selection yields `{}` (not the full payload). One level of nesting under `profile` only.
- **Prune-only — never a 400.** No `maxDepth`/`maxFields` over-limit error: nesting is one level by construction and output is bounded by the small config-derived allow-list. (If a louder "reject unknown fields" contract is wanted later, add a dot-path validation pass before projecting; out of scope for v1.)
- **Supported syntax (v1): REST dot-paths** — `?fields=id,email,profile.first_name`. No GraphQL-style `profile(first_name)`.

(A future framework improvement could teach `FieldSelectionMiddleware` to unwrap and project under `data{}`; that would let routes use the declarative `#[Fields]` attribute instead of manual projection. Explicitly out of scope here.)

## Architecture / components

### New files (in glueful/users)

| File | Purpose |
|------|---------|
| `routes/users.php` | Registers `GET /me` (always). Loaded unconditionally from `UsersServiceProvider::register()`. |
| `routes/user-lookup.php` | Registers `GET /users/{uuid}`. Loaded **only when** `config('users.user_lookup.enabled')` is true (the provider gates the `loadRoutesFrom`, since it has `$context`). |
| `src/Controllers/UserController.php` | `extends Glueful\Controllers\BaseController` (for `$currentUser` / `RequestUserContext`). Methods `me()` and `show(string $uuid)`. Computes effective columns/allow-list from config + introspection. |
| `config/users.php` | Exposable column lists (`account_fields`/`profile_fields`, each split `me` vs `users`) + `user_lookup.enabled`. Merged via the provider; devs override in the app's `config/users.php`. |

### Changed files

| File | Change |
|------|--------|
| `src/UsersServiceProvider.php` | `register()`: `mergeConfig('users', …)` **first**, then `loadRoutesFrom('routes/users.php')` (always), then `if ((bool) config($context, 'users.user_lookup.enabled', false)) loadRoutesFrom('routes/user-lookup.php')`. This makes **config the single source of truth** and respects app overrides (the provider reads merged config and has `$context`; the route files do not). Add a `permissions()` hook declaring `users.read`. |
| `src/Repositories/UserRepository.php` | Add explicit-column readers: `findAccountRow(string $uuid, array $columns): ?array` and `findProfileRow(string $uuid, array $columns): ?array` (select only the passed columns). No `SELECT *`. |

### Why `UserController` (not `AccountController`)

`AccountController` is lifecycle (verify-email/OTP/password) and does **not** extend `BaseController`. `/me` needs the authenticated principal, which `BaseController` exposes as `protected ?UserIdentity $currentUser` via `RequestUserContext` (mirrors `TwoFactorController`). New controller keeps responsibilities clean.

## Configuration & column resolution

Exposable columns are config-driven so apps can opt their custom `profile` columns in, separately per audience. Shipped defaults (`config/users.php`, merged via the provider; apps override in their own `config/users.php`):

```php
return [
    // GET /users/{uuid} master switch — route is not registered when false.
    'user_lookup' => [
        'enabled' => env('USERS_USER_LOOKUP_ENABLED', false),
    ],

    // Columns exposable per audience: 'me' = the caller's own record (generous),
    // 'users' = another user via /users/{uuid} (conservative). Apps APPEND here.
    'account_fields' => [
        'me'    => ['id', 'uuid', 'username', 'email', 'status', 'email_verified_at', 'two_factor_enabled', 'created_at', 'updated_at'],
        'users' => ['id', 'uuid', 'username'],
    ],
    'profile_fields' => [
        'me'    => ['first_name', 'last_name', 'photo_url'],          // e.g. add 'phone', 'timezone'
        'users' => ['first_name', 'last_name', 'photo_url'],
    ],
];
```

A dev who adds a `phone` column to `profiles` (via migration) exposes it through `/me` by appending `'phone'` to `profile_fields.me` — and decides independently whether `/users/{uuid}` shows it. **No auto-include:** an operational/internal column (`risk_score`, `internal_metadata`, …) stays server-side unless explicitly listed.

**Effective columns** (computed per request, per table, per audience):

```php
// getTableColumns() returns array<int, array{name,type,...}> — normalize to names first.
$realColumns = array_column($schema->getTableColumns($table), 'name');
$effective   = array_values(array_diff(
    array_intersect($configured, $realColumns),   // config ∩ real columns
    self::DENYLIST[$table]                          // − hard denylist
));
```

- **Hard denylist** (code constants, NOT config-overridable — the security floor):
  `ACCOUNT_DENYLIST = ['password', 'deleted_at']`; `PROFILE_DENYLIST = ['user_uuid', 'deleted_at']`.
  Even if an app misconfigures one of these into a list, it is stripped. The denylist covers **structural/linkage** columns (credentials, FK/principal linkage, soft-delete marker) that should never be client data.
- **`photo_uuid` policy: absent by default, opt-in possible.** It is *not* in the shipped `profile_fields` lists (so it never appears by default — hence the regression tests assert its absence **under default config**), but it is *not* denylisted either: it is content metadata (a blob reference the principal owns), so an app may add `'photo_uuid'` to a `profile_fields` audience if it needs the blob id. (Contrast `user_uuid`, which is structural linkage and hard-denied.)
- **Introspection** (`SchemaBuilderInterface::getTableColumns()` → normalized via `array_column(..., 'name')`; result cached per request): a configured name that isn't a real column is silently skipped — config typos never cause `SELECT`-of-unknown-column errors.
- The **field-selection allow-list** handed to `PayloadProjector::project()` is derived from the effective sets: `effectiveAccount ∪ { "profile.$f" | $f ∈ effectiveProfile }`. So `ME_FIELDS`/`USER_FIELDS` are **computed at runtime from config**, not hardcoded constants.
- The default `$merged` is built by selecting exactly the effective columns (account row + nested `profile`), so the no-`?fields` response and the selectable surface are the same config-derived set.
- **Empty effective sets (never issue `select([])`):**
  - *Empty effective **profile** columns* (config ∩ real − denylist is `[]`): the controller **skips the profile query entirely** and sets **`"profile": null`** — identical to the "no profile row" case. The repository never runs `findProfileRow()` with an empty list.
  - *Empty effective **account** columns*: treated as a **misconfiguration**. The resolver always force-includes `uuid` in the account **query** set (so no `select([])` is issued and the **default** response is never identity-less). Note this is a query-level floor only — explicit field selection may still omit `uuid` (e.g. `?fields=profile.first_name`). Document that `account_fields.<audience>` is expected to be non-empty.
- **Config precedence:** `mergeConfig('users', …)` ships these as **defaults** that an app's own `config/users.php` is intended to override (the framework's config merge is a deep-merge). **Implementation note:** confirm the `config.manager` merge order treats app config as winning over extension defaults; if it doesn't, have the provider read the app value with the shipped array as the fallback default. Either way, route gate and controller read the **same** merged value, so availability and exposed fields stay consistent.

## Endpoint: `GET /me`

- **Auth:** required (`auth` middleware). If `$this->currentUser === null` → 401.
- **Handler:** resolve `$uuid = $this->currentUser->uuid()`; compute the effective `me`-audience account + profile columns (config ∩ real columns − denylist); read the account row — **if `findAccountRow()` returns `null`** (authenticated principal no longer resolves to a row, e.g. deleted mid-session) **→ 404** (never spread null); else nest profile under `profile` (or `null`); project against the derived `me` allow-list; wrap.
- **Audience:** `me` (the generous lists in `config/users.php`). Default shipped surface: `account_fields.me` + `profile_fields.me`.
- **Query:** standard field selection, e.g. `?fields=id,email,username,profile.first_name,profile.photo_url` (and any custom profile column the app added to `profile_fields.me`, e.g. `profile.phone`).
- **Response (no `?fields`, default config):**
  ```json
  {
    "success": true,
    "message": "Success",
    "data": {
      "id": 1,
      "uuid": "…",
      "username": "jdoe",
      "email": "jdoe@example.com",
      "status": "active",
      "email_verified_at": "…",
      "two_factor_enabled": false,
      "created_at": "…",
      "updated_at": "…",
      "profile": { "first_name": "Jane", "last_name": "Doe", "photo_url": "…" }
    }
  }
  ```
- **No profile row:** `"profile": null`.
- **Errors:** 401 unauthenticated; 404 if the authenticated principal no longer resolves to a row (`findAccountRow()` null). Disallowed/unknown requested fields are pruned (not an error). **Field selection raises no 400** (Rev 7: prune-only; no `maxDepth`/`maxFields` over-limit error).

## Endpoint: `GET /users/{uuid}`

- **Gating (all three):**
  1. `auth` middleware (must be authenticated).
  2. **Permission** `users.read` — enforced via `#[RequiresPermission('users.read')]` on `show()` + the `gate_permissions` middleware (which reflects the handler's attribute and calls `PermissionManager::can()`).
  3. **Config flag**, default **off** — `config('users.user_lookup.enabled')`. **Single source of truth:** the **provider** reads merged config (it has `$context`) and only loads `routes/user-lookup.php` when true; the route files never gate themselves. When off, the route is never registered (404). The shipped default is env-backed (`env('USERS_USER_LOOKUP_ENABLED', false)`) so ops can flip it without a config file; an explicit `config/users.php` value is intended to win (see the config-precedence implementation note).
- **Handler:** read the target `uuid`; 404 if not found or soft-deleted; compute the effective `users`-audience columns (config ∩ real columns − denylist); nest profile; project against the derived `users` allow-list; wrap.
- **Audience:** `users` (the conservative lists). Default shipped surface: `account_fields.users` (`id, uuid, username`) + `profile_fields.users` (`first_name, last_name, photo_url`). Deliberately excludes `email`, `status`, `email_verified_at`, `two_factor_enabled`, timestamps unless an app opts them in for this audience.
- **Route registration sketch (gating lives in the provider, not the route files):**
  ```php
  // UsersServiceProvider::register()
  $this->mergeConfig('users', require __DIR__ . '/../config/users.php');   // defaults; app overrides win
  $this->loadRoutesFrom(__DIR__ . '/../routes/users.php');                 // /me — always
  if ((bool) config($context, 'users.user_lookup.enabled', false)) {
      $this->loadRoutesFrom(__DIR__ . '/../routes/user-lookup.php');       // /users/{uuid} — gated
  }

  // routes/users.php
  $router->get('/me', [UserController::class, 'me'])->middleware('auth')->name('users.me');

  // routes/user-lookup.php  (loaded only when enabled, so it registers unconditionally here)
  $router->get('/users/{uuid}', [UserController::class, 'show'])
      ->middleware(['auth', 'gate_permissions'])
      ->where('uuid', '[A-Za-z0-9_-]+')
      ->name('users.show');
  ```
- **Errors:** 401 unauthenticated; 403 missing `users.read`; 404 unknown user / lookup disabled.

## Permission declaration (1.50 catalog)

`UsersServiceProvider::permissions()` declares the permission so it appears in `permissions:list` / `permissions:diff` and is enforceable/grantable through Aegis:

```php
use Glueful\Permissions\Catalog\Permission;

public function permissions(): array
{
    return [
        Permission::define('users.read')
            ->label('Read users')
            ->description('Read another user\'s account and public profile via GET /users/{uuid}')
            ->category('users')
            ->managedBy('glueful/users'),
    ];
}
```

## Data access & security (defense in depth)

Four independent layers protect the response (see "Configuration & column resolution" for the resolution algorithm):

1. **Config allow-list.** Only columns listed for the audience are candidates. Nothing is exposed by default beyond the shipped lists; custom columns require explicit opt-in.
2. **Hard denylist** (`ACCOUNT_DENYLIST=['password','deleted_at']`, `PROFILE_DENYLIST=['user_uuid','deleted_at']`) — stripped from the effective set even if misconfigured.
3. **Explicit query projection.** `findAccountRow()`/`findProfileRow()` select only the effective columns — never `SELECT *`, so `password` never leaves the DB layer. Built from `getTableColumns()` ∩ config, so an unknown configured name is skipped (no SQL error).
4. **Field-selection prune.** Within the effective set, `PayloadProjector` narrows to what the caller asked for (prune-only; never widens).

Other notes:

- **Do not reuse `getProfile()` for the default shape.** It projects a fixed list including `photo_uuid` (outside the shipped default lists, so it would leak for apps that never opted it in) and would slip through the empty-selector fast-path. Use `findProfileRow($uuid, $effectiveProfileColumns)`.
- **Default shape == selectable surface.** The empty-selector fast-path returns `$merged` unchanged, which is safe precisely because `$merged` is already the effective (config-derived, denylisted) shape.
- **Two queries, no N+1** (account row + one profile row). A future collection endpoint would use `getProfilesForUsers()` for bulk.
- **`/users/{uuid}` exposes a conservative subset** and is off by default — "read any user" is an explicit operator opt-in (permission + config flag).

## Testing strategy

Framework/extension library tests (PHPUnit 10, lightweight SQLite `Connection` harness — per the extension's existing tests):

- **`/me` happy path:** authed principal → returns merged user+profile; `profile: null` when absent.
- **Default shape:** with default config and no `?fields` → response equals the configured effective columns; **`photo_uuid` is absent under default config** (regression guard for the `getProfile()` trap; per the opt-in policy it's absent because it's not in the shipped lists, not because it's denylisted).
- **Field selection:** `?fields=id,email` returns only those; `?fields=profile.first_name` returns the nested subset (proves `PayloadProjector` authorizes one-level `profile.<child>` dot-paths); **`password` is never present** even if named.
- **Custom field opt-in:** add a `phone` column to a test `profiles` table + put `'phone'` in `profile_fields.me` → `/me?fields=profile.phone` returns it; with `'phone'` absent from config → it is pruned (not exposed) even though the column exists. This is the core "custom fields" guarantee.
- **Audience split:** a column in `profile_fields.me` but not `profile_fields.users` appears in `/me` and is absent from `/users/{uuid}`.
- **Denylist is absolute:** putting `password` in `account_fields.me` (misconfig) still never appears and is never selected.
- **Introspection tolerance:** a configured column that doesn't exist on the table is skipped — no SQL error, response still succeeds. (Asserts the `array_column(getTableColumns(), 'name')` normalization.)
- **Empty effective profile set:** configure `profile_fields.me = []` (or only denylisted/non-existent names) → `findProfileRow()` is **not** called with `[]`; response has `"profile": null`. `/me` still returns the account object.
- **Forced `uuid` on empty account set:** configure `account_fields.me = []` (or only invalid names) → no `select([])`, and the **default** (no-`?fields`) response still carries `uuid`. (Field selection that omits `uuid` may still drop it — that's expected; the floor is query-level only.)
- **Disallowed/unknown field is pruned, not 400:** `?fields=password` / `?fields=bogus` → omitted, request succeeds. No `maxDepth`/`maxFields` over-limit error (prune-only; see Rev 7). Also cover an **array `fields` param** (`?fields[]=email`) → normalized to "no selection" (full default), no `TypeError`.
- **`/me` unauthenticated:** 401.
- **`/me` principal vanished:** authenticated session but `findAccountRow()` returns `null` (row deleted) → **404**, no null-spread error.
- **`/users/{uuid}` gating:** route absent when flag off (404); flag on but missing `users.read` → 403; with permission → conservative set only (under default config, asserts `email`/`status`/`photo_uuid` absent — `photo_uuid` by the opt-in policy, not denylist).
- **Repository:** `findAccountRow()`/`findProfileRow()` only return their passed columns.
- Reuse the login-response shaper test style for controller wiring where practical.

## Build order

1. `config/users.php` + provider `mergeConfig('users', …)`; `UserRepository::findAccountRow()` + `findProfileRow()` (explicit columns) + a column-resolution helper (config ∩ `getTableColumns()` − denylist) + tests (the safe readers + resolver are the security foundation).
   - **Verification task (blocking):** confirm `config.manager`'s merge precedence — an app `config/users.php` value must **win** over the extension default after `mergeConfig`. Write a test that sets an app-level `users.user_lookup.enabled`/field-list override and asserts the merged value reflects the app, not the default. The provider-gated `/users/{uuid}` route and the field lists both depend on this; if precedence is defaults-win, switch the provider to read the app value with the shipped array as an explicit fallback.
2. `UserController::me()` + `routes/users.php` (`/me` only) + provider route wiring + tests (incl. custom-field opt-in + denylist).
3. `permissions()` declaration for `users.read`.
4. `UserController::show()` + config-gated `/users/{uuid}` route + `gate_permissions` + tests (incl. audience split).
5. README: document both endpoints, field-selection syntax, the `config/users.php` lists (with the custom-column opt-in workflow tying back to "Extending Users"), the `USERS_USER_LOOKUP_ENABLED` flag, and `users.read`.

Both endpoints can ship in one PR ("add both now"); the order above is the safe internal sequence.

## Future work (out of scope)

- **Envelope-aware `FieldSelectionMiddleware`** (project under `data{}`), which would let these routes use the declarative `#[Fields]` attribute instead of manual projection — and benefit every endpoint.
- **`GET /users` collection** (paginated, filtered) reusing `getProfilesForUsers()`, with the same config-driven `users`-audience field resolution.
- **Custom `users`-table columns** via the same `account_fields` mechanism (already structurally supported; not a focus since `users` is the identity spine and `profiles` is the extensible table).

## Open questions

None blocking. Decisions locked: manual projection via the local `PayloadProjector` (REST dot-path allow-lists, one-level `profile.*` nesting; disallowed fields **pruned**, never 400); exposable columns **config-driven** in `config/users.php` with separate `me`/`users` audience lists, a hard denylist, and `getTableColumns()` introspection (custom `profile` columns opt-in; no auto-include); default `$merged` from the effective columns (no `getProfile()`; `password`/`deleted_at` never exposable; `photo_uuid` not in shipped defaults but opt-in-able); `/users/{uuid}` gated by permission `users.read` (declared in the catalog) **and** the `user_lookup.enabled` config flag (default off).
