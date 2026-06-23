# Changelog

All notable changes to the Glueful Users (Identity & Accounts) Extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

## [2.1.2] - 2026-06-23

### Fixed
- **`/me`, `/users`, `/users/{uuid}`, `/auth/*` and `/2fa/*` no longer 500 with "Service … not found".**
  None of the extension's controllers (`AccountController`, `TwoFactorController`, `UserController`) were
  registered in `UsersServiceProvider::services()`. The framework router resolves a route's
  `[Controller::class, 'method']` handler via `container->get($class)` with **no autowire fallback**, so
  hitting any of these routes threw a container "not found" (500) the moment the controller had to be
  built. All three are now registered (`autowire => true`). This surfaced once the permission gate
  started passing (framework 1.61.2) — previously the 403 masked it.

### Fixed

- Remove the extension's own `users.view` catalog declaration. `users.view` is a framework
  `CORE_PERMISSION` already registered in the permission catalog under `glueful/framework`, so
  declaring it again in `UsersServiceProvider::permissions()` raised a `DuplicatePermissionException`
  on boot (breaking `generate:openapi` and app start). The endpoints still guard on `users.view` via
  `#[RequiresPermission]`; only the redundant declaration is gone.

## [2.1.0] - 2026-06-23

### Changed

- **The user-read permission slug is renamed `users.read` → `users.view`** to follow the framework's
  `category.action` naming convention (`PermissionStandards::PERMISSION_USERS_VIEW`) and align with the
  slug Aegis already seeds. The `GET /users/{uuid}` and `GET /users` endpoints now require
  `users.view`, and the catalog declaration in `UsersServiceProvider::permissions()` declares
  `users.view`. Note: re-grant any role that previously held `users.read`.

## [2.0.0] - 2026-06-23

### Changed

- **BREAKING: all extension routes are now versioned under the API prefix** — `/auth/*` →
  `/v1/auth/*`, `/me` → `/v1/me`, `/users*` → `/v1/users*`, `/2fa/*` → `/v1/2fa/*`.
  `UsersServiceProvider::boot()` now wraps its route loading in `api_prefix()`, matching how the
  framework versions its own routes (`RouteManifest`) and honouring `API_USE_PREFIX` /
  `API_VERSION_IN_PATH`. Previously these routes registered raw, leaving account/2FA/user
  endpoints unversioned while the framework's own `/v1/auth/login` was versioned. Update any
  client to the versioned paths.

## [1.1.1] - 2026-06-16

### Fixed

- Register migration paths during provider boot so `migrate:run` sees the identity
  schema through the same CLI lifecycle used by other extension migrations.
- Register route files during provider boot so users routes follow the same runtime
  wiring lifecycle as other route-owning extensions.

## [1.1.0] - 2026-06-14

### Added

- Typed response DTOs `TwoFactorChallengeData` and `OtpDispatchData`, returned from the
  two-factor challenge and OTP-dispatch endpoints. Response envelopes remain byte-identical
  via `HasResponseMessage`.

### Changed

- Migrated OpenAPI documentation to the framework 1.57.0 reflect generator. Route
  documentation (summaries, query parameters, request-body fields and response codes)
  is now expressed as typed `#[ApiOperation]`, `#[QueryParam]` and `#[ApiResponse]`
  attributes on the controller methods; the now-inert route-file docblocks were removed.
  Docs-only — no runtime behaviour changes.
- Raised the minimum framework requirement to `^1.57.0`.

## [1.0.1] - 2026-06-13

### Fixed

- Require a purpose-bound, single-use reset token for `POST /auth/reset-password`; `POST /auth/verify-otp` now returns that token when called with `purpose=password_reset`, closing the email-only password reset takeover path.
- Revoke active framework sessions for the user after a successful password reset.
- Add route-level rate limits to `POST /auth/forgot-password` and `POST /auth/reset-password`.
- Cap failed 2FA PIN attempts per challenge and consume the challenge after repeated wrong codes.
- Read and consume file-based OTP fallback records during OTP verification.
- Guard password reset token consumption with an atomic consumed marker and log when session revocation cannot run because the session store is not bound.
- Hard-deny additional sensitive account fields (`two_factor_secret`, `remember_token`, `provider_id`) from profile projection.
- Align 2FA route registration and service defaults on `auth.two_factor.enabled`.
- Keep SAML/LDAP provisioning writes within the canonical `users` and `profiles` schemas.

## [1.0.0] - 2026-06-05

### Added

#### Account read endpoints

- **`GET /me`** — the authenticated principal's account + nested `profile`, always on (auth required). Returns config-driven, safe-by-default columns with REST dot-path field selection (`?fields=id,email`, `?fields=email,profile.first_name`).
- **`GET /users/{uuid}`** — another user's account + public profile. Off by default (`USERS_USER_LOOKUP_ENABLED=true`); requires the `users.read` permission. Uses the narrower `users` audience (no email by default).
- **`GET /users`** — paginated list of users + nested public profile. Off by default (requires both `USERS_USER_LOOKUP_ENABLED=true` and `USERS_USER_LIST_ENABLED=true`); requires `users.read`. Supports `?page`/`?per_page` (clamped, max 100), per-item `?fields=`, and `?filter[...]`/`?sort`/`?search` over username + profile name. Email filtering/search is gated behind `USERS_USER_LIST_ALLOW_EMAIL_FILTER`; `status` is not filterable by default. Soft-deleted profiles can never affect membership or ordering.
- **`users.read` permission** declared in the framework permission catalog (`UsersServiceProvider::permissions()`).
- **Config (`config/users.php`)** — registered via `mergeConfig('users', …)` (requires `glueful/framework ^1.50.1`): per-audience exposable columns (`account_fields`/`profile_fields` for `me` vs `users`), the `user_lookup.enabled` gate, and the `user_lookup.list` block (`enabled`, `allow_email_filter`, `per_page`, `default_sort`). Apps override by copying the file into their own `config/`.

##### Internals

- `ProfileFieldResolver` — pure column resolution: configured ∩ real columns − hard denylist (`password`/`deleted_at`; `user_uuid` for profiles), forces `uuid`, builds the dot-path allow-list.
- `PayloadProjector` — local, prune-only REST dot-path projection with one-level `profile.*` nesting (no framework field middleware; disallowed/unknown fields are omitted, never a 400; an all-disallowed selection yields `{}`, not the full payload).
- `ProfileResponder` — resolves columns, reads explicit-column rows, merges nested profile, and projects (`build()` for single, `buildList()` for paginated).
- `UsersListQueryFilter` — `QueryFilter` subclass mapping public field names to qualified columns and guarding every profile predicate with `profiles.deleted_at IS NULL` (custom `filterProfile*()` methods, `whereRaw` per-branch search guards, `orderByRaw(CASE …)` sort guards).
- `UserRepository::findAccountRow()`/`findProfileRow()` (explicit columns, soft-delete scoped) and `paginateUsersWithProfiles()` (aliased single LEFT JOIN, no N+1).

#### Identity & account lifecycle (foundation)

- First-party **user store** (`users` + `profiles` tables, migrated at `IDENTITY` priority) and `UserRepository`.
- **Identity seam** — `UserProvider` adapts the store to the core `UserProviderInterface` (aliased so core auth resolves it).
- **Account lifecycle endpoints** (`/auth` prefix) — email verification (OTP) and password recovery (forgot/reset), extracted from core `AuthController`.
- **Email-PIN two-factor authentication** (`/2fa`, enabled via `auth.two_factor.enabled`) — `enable`/`verify`/`disable`, backed by `TwoFactorService` implementing the core `TwoFactorServiceInterface`, plus `twofactor:*` CLI commands.

### Notes

- Pre-1.0 extension; nothing released yet. Requires `glueful/framework ^1.50.1` (for the `mergeConfig()` fix). `glueful/email-notification` is a soft suggest (registers the `email` channel used by password-reset/verification delivery).
