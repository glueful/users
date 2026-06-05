# Users (Identity & Accounts) Extension for Glueful

## Overview

Users is the **first-party identity store and account-lifecycle extension** for Glueful. It provides the concrete, swappable user store that sits behind Glueful's core authentication contracts — the `users` and `profiles` tables, credential verification, email verification / OTP, password reset, and optional email-PIN two-factor authentication.

Glueful core is provider-agnostic and ships **no** user store of its own. It authenticates through the `UserProviderInterface` contract and binds a fail-closed `NullUserProvider` by default — so **without a user store enabled, authentication is disabled by design**. This extension is that store. The Glueful **api-skeleton enables it by default**.

> Swap-friendly by design: any package that implements `UserProviderInterface` can replace this one. Users is simply the official, batteries-included implementation.

## Features

- **Provider-agnostic identity seam**: implements core's `UserProviderInterface` (lookup + credential verification, returning a canonical `UserIdentity`)
- **Account store**: `users` + `profiles` tables with UUID principals, soft-deletes, and unique constraints
- **Extensible from your app**: add custom profile columns, enrich the login response, or contribute identity claims without forking the extension (see [Extending Users](#extending-users))
- **Email verification / OTP**: send, verify, and resend one-time codes
- **Password reset**: forgot-password → reset-password via emailed code
- **Email-PIN two-factor authentication**: enroll, verify, and disable 2FA behind core's `TwoFactorServiceInterface` (opt-in via `TWO_FACTOR_ENABLED`)
- **SSO provisioning helpers**: `findOrCreateFromSaml()` / `findOrCreateFromLdap()` on the repository
- **Ordered migrations**: schema runs at `IDENTITY` priority (before app/dependent extensions), source-tracked as `glueful/users`
- **Admin CLI**: password reset and 2FA management commands

## Installation

### Installation (Recommended)

**Install via Composer**

```bash
composer require glueful/users

# Rebuild the extensions cache after adding new packages
php glueful extensions:cache
```

Composer discovers packages of type `glueful-extension`, but **installing does not auto-enable** them — the provider must be added to `config/extensions.php`'s `enabled` allow-list. The CLI does that for you:

```bash
# Enable (adds the provider FQCN to config/extensions.php + recompiles the cache)
php glueful extensions:enable users

# Disable (removes it) — note: disabling leaves core auth on the fail-closed NullUserProvider
php glueful extensions:disable users
```

In production, manage the `enabled` list in config and run `php glueful extensions:cache` in your deploy step.

Run database migrations to create the `users` and `profiles` tables:

```bash
php glueful migrate:run
```

### Email delivery dependency

The email-driven flows (verify-email, forgot-password, and the 2FA PIN) send through Glueful's notification system on the **`email`** channel. Users depends only on that channel *capability*, not on a specific extension — install any extension that registers an `email` channel. The official one is **`glueful/email-notification`**:

```bash
composer require glueful/email-notification
php glueful extensions:enable email-notification
```

If no `email` channel is registered, those sends return a clear `email_provider_not_configured` result (and are logged) instead of delivering — the rest of the account store still works.

### Local Development Installation

To develop the extension locally, register it as a Composer **path repository** in your app's `composer.json`, then require and enable it:

```jsonc
// composer.json
"repositories": [
    { "type": "path", "url": "extensions/users", "options": { "symlink": true } }
]
```

```bash
composer require glueful/users:@dev
php glueful extensions:enable users
```

Entries in `config/extensions.php` are plain string FQCNs (no `::class`) — prefer `extensions:enable` over editing by hand.

Run the migrations to create the necessary database tables:

```bash
php glueful migrate:run
```

### Verify Installation

Check status and details:

```bash
php glueful extensions:list
php glueful extensions:info users
php glueful extensions:diagnose
```

Post-install checklist:

- Run migrations (if not auto-run): `php glueful migrate:run`
- Enable an `email` channel for verification/reset/2FA emails (see above)
- Confirm core auth resolves the provider: a username/password `POST /auth/login` should succeed for a valid user
- Rebuild cache after Composer operations: `php glueful extensions:cache`

### Quick Start

The account-lifecycle endpoints are mounted under `/auth`. Example: the forgot-password → reset-password flow. Replace placeholders before running:

- `API_BASE` with your base URL (e.g., http://localhost:8000)
- `USER_EMAIL` with an existing user's email

```bash
API_BASE=http://localhost:8000
USER_EMAIL="user@example.com"

# 1) Request a reset code (emailed via the 'email' channel)
curl -s -X POST "$API_BASE/auth/forgot-password" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"$USER_EMAIL\"}" | jq .

# 2) Verify the emailed code
curl -s -X POST "$API_BASE/auth/verify-otp" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"$USER_EMAIL\", \"otp\": \"<CODE_FROM_EMAIL>\"}" | jq .

# 3) Set the new password (email + new password; the code is verified in step 2)
curl -s -X POST "$API_BASE/auth/reset-password" \
  -H "Content-Type: application/json" \
  -d "{\"email\": \"$USER_EMAIL\", \"password\": \"<NEW_PASSWORD>\"}" | jq .
```

### Quick Start (PHP)

The provider backs core login; you typically use it indirectly via `POST /auth/login`. To work with it programmatically:

```php
<?php
use Glueful\Auth\Contracts\UserProviderInterface;
use Glueful\Extensions\Users\Repositories\UserRepository;

// Resolve the identity provider through the CORE contract (never the concrete class)
$provider = container()->get(UserProviderInterface::class);

// Verify credentials — returns a canonical UserIdentity, or null on failure
$identity = $provider->verifyCredentials('user@example.com', 'secret');
if ($identity !== null) {
    echo $identity->uuid();
    echo $identity->email();
}

// Look up without credentials
$byUuid  = $provider->findByUuid('<USER_UUID>');
$byLogin = $provider->findByLogin('user@example.com'); // email or username

// Create a user via the repository
$repo = container()->get(UserRepository::class);
$uuid = $repo->create([
    'username' => 'jdoe',
    'email'    => 'jdoe@example.com',
    'password' => 'secret',
]);
```

## Database Schema

Migrations run at `IDENTITY` priority (before app and dependent extensions) under the source `glueful/users`.

**`users`**

| Column | Notes |
|--------|-------|
| `uuid` | Primary principal id (unique) |
| `username` | Unique |
| `email` | Unique |
| `password` | Hashed |
| `status` | Defaults to `active` |
| `two_factor_enabled` | Boolean; owned by the 2FA service |
| `email_verified_at` | Nullable timestamp |
| `created_at` / `updated_at` / `deleted_at` | Timestamps; `deleted_at` enables soft-delete |

**`profiles`**

| Column | Notes |
|--------|-------|
| `uuid` | Unique |
| `user_uuid` | FK → `users.uuid` (unique) |
| `first_name` / `last_name` | Name fields |
| `photo_uuid` / `photo_url` | Avatar (indexed `photo_uuid`) |
| `status` | Defaults to `active` |
| `created_at` / `updated_at` / `deleted_at` | Timestamps; soft-delete |

> The security spine (`auth_sessions`, `auth_refresh_tokens`, `api_keys`) is owned by **framework core**, not this extension.

## Working with Profiles

`profiles` is a separate table with a 1:1 relationship to `users` via `user_uuid`. A few things are intentional and worth knowing:

- **`create()` creates only the `users` row** — it does not create a profile. The profile row is created lazily the first time you call `updateProfile()`.
- **Profiles are not loaded at login by default.** The login response's `user` object is OIDC-shaped (`id`, `email`, `username`, …); `name`/`given_name`/`family_name`/`picture` are only included when profile data is supplied to the session shaper. Fetch the profile explicitly where you need it.
- **The built-in readers project a fixed set of columns** — `first_name`, `last_name`, `photo_uuid`, `photo_url` (see "Extending Users" for custom fields).

```php
<?php
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Database\Connection;

$repo = container()->get(UserRepository::class);

// Create a user AND its profile atomically
$uuid = container()->get(Connection::class)->transaction(function () use ($repo) {
    $uuid = $repo->create([
        'username' => 'jdoe',
        'email'    => 'jdoe@example.com',
        'password' => 'secret',
    ]);
    // Creates the profile row on first call
    $repo->updateProfile($uuid, [
        'first_name' => 'Jane',
        'last_name'  => 'Doe',
    ]);
    return $uuid;
});

// Read a single profile / bulk-read (avoids N+1)
$profile  = $repo->getProfile($uuid);                 // ['first_name','last_name','photo_uuid','photo_url']
$profiles = $repo->getProfilesForUsers([$uuid, '…']); // keyed by user_uuid
```

## Configuration

This extension has no config file of its own; it reads a small set of core config/env values.

**Two-factor authentication** (read by `TwoFactorServiceFactory`, under the `auth.two_factor.*` config keys):

| Key | Default | Purpose |
|-----|---------|---------|
| `auth.two_factor.enabled` | `false` | Service-level enable flag |
| `auth.two_factor.pin_length` | `6` | Emailed PIN length |
| `auth.two_factor.pin_ttl` | `300` | PIN / challenge lifetime (seconds) |
| `auth.two_factor.disable_freshness` | `300` | How recently 2FA must have been verified to disable it (seconds) |
| `auth.two_factor.template_name` | `two-factor-pin` | Notification template for the PIN email |

**Environment**

```env
# Master switch for the /2fa/* routes. When false (default), 2fa.php early-returns
# and the /2fa/* endpoints do not exist (404). Cast to a real boolean by env().
TWO_FACTOR_ENABLED=false
```

> Note: `TWO_FACTOR_ENABLED` gates whether the **routes** are registered; `auth.two_factor.enabled` gates the **service**. Enable both to use email-PIN 2FA over HTTP.

## API Endpoints

### Account lifecycle (prefix `/auth`)

- `POST /auth/verify-email` – Send an email-verification OTP
- `POST /auth/verify-otp` – Verify an emailed OTP (rate-limited 3/min)
- `POST /auth/resend-otp` – Resend an OTP (rate-limited 2 / 2 min)
- `POST /auth/forgot-password` – Begin password reset (emails a code)
- `POST /auth/reset-password` – Complete password reset with the code

> Login (`POST /auth/login`), logout, refresh, and session validation are **core** endpoints. This extension supplies the user store they authenticate against, not the login route itself.

### Two-factor authentication (prefix `/2fa`, only when `TWO_FACTOR_ENABLED=true`)

- `POST /2fa/enable` – Begin enrollment: emails a PIN, returns a short-lived `challenge_token` (auth required, rate-limited)
- `POST /2fa/verify` – Verify a PIN against a `challenge_token`. For a **login** challenge it completes login and returns the full session (identical to `POST /auth/login`); for an **enrollment** challenge it returns `{success, message}`
- `POST /2fa/disable` – Disable 2FA (auth required; needs a recent 2FA verification within `disable_freshness`)

### Account read endpoints

- `GET /me` — the authenticated principal's account + nested `profile` (auth required, always on).
- `GET /users/{uuid}` — another user's account + public profile. **Off by default** (`USERS_USER_LOOKUP_ENABLED=true`), requires the `users.read` permission.
- `GET /users` — paginated list of users + nested public profile. **Off by default** (requires both `USERS_USER_LOOKUP_ENABLED=true` and `USERS_USER_LIST_ENABLED=true`), requires the `users.read` permission.

```bash
GET /users?page=1&per_page=25                    # clamped: per_page max 100
GET /users?sort=-created_at                        # default; or username/first_name/last_name
GET /users?filter[profile][first_name]=Jane        # filter by profile field
GET /users?search=jane                             # username + profile names (email only if enabled)
GET /users?fields=username,profile.first_name      # per-item field selection
```

Email is filterable/searchable only when `USERS_USER_LIST_ALLOW_EMAIL_FILTER=true`. `status` is not filterable by default. Soft-deleted profiles never affect membership or ordering.

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

## CLI Commands

Auto-discovered from the extension's `Console/` directory (require an enabled extension):

- `php glueful security:reset-password` – Reset a user's password from the CLI
- `php glueful 2fa:status` – Show whether email 2FA is enabled for a user
- `php glueful 2fa:enable` – Force-enable email 2FA for a user (admin; skips the PIN challenge)
- `php glueful 2fa:disable` – Disable email 2FA for a user (admin)

## The Identity Seam

Core auth resolves this extension through interfaces only — it never names the concrete classes:

- **`UserProviderInterface`** → `Glueful\Extensions\Users\UserProvider` (aliased in `services()`). Methods: `findByUuid()`, `findByLogin()` (identifier-agnostic — email or username), `verifyCredentials()` → returns a canonical `UserIdentity` or `null`. Authentication-only: registration/provisioning/profile writes are **not** part of this contract.
- **`TwoFactorServiceInterface`** → `Glueful\Extensions\Users\TwoFactor\TwoFactorService` (built by a static factory; token-mechanic deps `ChallengeTokenIssuer`/`JtiBlocklist` stay in core). When no implementation is registered, core's `AuthController` skips 2FA entirely.

Roles/permissions and other post-auth facts are folded onto the `UserIdentity` by separate **claims providers** (e.g. the Aegis RBAC extension) via the `identity.claims_provider` tag — this extension does not own authorization.

## Extending Users

The store is intentionally minimal. Here is how to extend each layer from **your application** — no fork of the extension required.

### Add custom profile fields

The `profiles` schema ships with a small fixed set of columns. To add your own (e.g. `phone`, `bio`, `timezone`):

**1. Add an app migration that alters `profiles`** (use a later priority so it runs after this extension's `IDENTITY`-priority migration):

```php
// database/migrations/2026_..._add_phone_to_profiles.php — implements MigrationInterface
public function up(SchemaBuilderInterface $schema): void
{
    $schema->alterTable('profiles', function ($table) {
        // AlterTableBuilder::addColumn(string $column, string $type, array $options = [])
        $table->addColumn('phone', 'string', ['length' => 32, 'nullable' => true]);
        $table->addColumn('timezone', 'string', ['length' => 64, 'nullable' => true]);
    });
}
```

**2. Write the new fields** — `updateProfile()` passes the fields you give it straight through to the `profiles` table (it does not whitelist), so any column that exists is writable:

```php
$repo->updateProfile($uuid, [
    'first_name' => 'Jane',
    'phone'      => '+1-555-0100',
    'timezone'   => 'America/New_York',
]);
```

**3. Reading them — mind the fixed projection.** `getProfile()` / `getProfilesForUsers()` only `SELECT` the four default columns, so custom fields will **not** come back through them. Query the table directly (or maintain your own profile repository):

```php
use Glueful\Database\Connection;

$row = container()->get(Connection::class)
    ->table('profiles')
    ->where(['user_uuid' => $uuid])
    ->limit(1)
    ->get();
$profile = $row[0] ?? null; // includes your custom columns
```

> Heads-up: the read projection (`UserRepository::$userProfileFields`) is currently a private, fixed list — it is not yet configurable. If you need custom fields returned by the built-in readers, query `profiles` yourself for now. (Making that projection extensible is a good framework follow-up.)

### Surface fields in the login response

Login is a core endpoint, but the response is extensible via the `LoginResponseBuildingEvent`. Register a listener that loads what you need and merges it into the `user` object — no core edit:

```php
use Glueful\Events\Auth\LoginResponseBuildingEvent;
use Glueful\Events\EventService;

// e.g. in your AppServiceProvider::boot()
$events = container()->get(EventService::class);
$events->addListener(LoginResponseBuildingEvent::class, function (LoginResponseBuildingEvent $e) {
    $userId  = $e->getUser()['id'] ?? null;
    $profile = /* load profile/custom fields for $userId */;
    $e->mergeResponse(['user' => [
        'phone'    => $profile['phone']    ?? null,
        'timezone' => $profile['timezone'] ?? null,
    ]]);
});
```

### Add identity claims (roles, scopes, custom claims)

Post-auth facts that ride in the token/session (not necessarily the response body) belong on the `UserIdentity` via a **claims provider**. Implement `IdentityClaimsProviderInterface` and tag the service `identity.claims_provider`; the core `IdentityResolver` folds it in additively (it can change what a user *can do*, never *who they are*). This is how the Aegis RBAC extension contributes roles.

```php
use Glueful\Auth\Contracts\IdentityClaimsProviderInterface;
use Glueful\Auth\UserIdentity;

final class DepartmentClaimsProvider implements IdentityClaimsProviderInterface
{
    public function enrich(UserIdentity $identity): UserIdentity
    {
        return $identity->withClaims(['department' => /* lookup */ 'engineering']);
    }
}
// Register tagged: 'tags' => ['identity.claims_provider']
```

### Replace the user store entirely

Because core resolves auth through `UserProviderInterface`, you can swap this extension out: implement that interface (`findByUuid`, `findByLogin`, `verifyCredentials` → `UserIdentity`), alias your class to the interface in your provider's `services()`, and disable `glueful/users`. Core neither knows nor cares which implementation answers.

## Security Considerations

- UUID principals with no cross-package foreign keys to external stores
- Passwords are hashed; soft-deletes preserve audit history
- OTP/reset/2FA endpoints are rate-limited
- Disabling 2FA requires a recent re-verification (`disable_freshness`)
- Disabling this extension fails auth closed (core binds `NullUserProvider`) rather than opening access

## Requirements

- PHP 8.3 or higher
- Glueful 1.50.0 or higher
- MySQL, PostgreSQL, or SQLite database
- An `email` notification channel (e.g. `glueful/email-notification`) for verification, password-reset, and 2FA emails

## License

This extension is licensed under the same license as the Glueful framework.

## Support

For issues, feature requests, or questions, please create an issue in the repository.
