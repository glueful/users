# Changelog

All notable changes to the Glueful Users (Identity & Accounts) Extension will be documented in this file.

The format is based on [Keep a Changelog](https://keepachangelog.com/en/1.1.0/),
and this project adheres to [Semantic Versioning](https://semver.org/spec/v2.0.0.html).

## [Unreleased]

### Fixed

- Require a purpose-bound, single-use reset token for `POST /auth/reset-password`; `POST /auth/verify-otp` now returns that token when called with `purpose=password_reset`, closing the email-only password reset takeover path.
- Revoke active framework sessions for the user after a successful password reset.
- Add route-level rate limits to `POST /auth/forgot-password` and `POST /auth/reset-password`.
- Cap failed 2FA PIN attempts per challenge and consume the challenge after repeated wrong codes.
- Read and consume file-based OTP fallback records during OTP verification.
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
