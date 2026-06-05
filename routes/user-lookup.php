<?php

/**
 * glueful/users user-lookup route — GET /users/{uuid}.
 *
 * Loaded by UsersServiceProvider::register() ONLY when config('users.user_lookup.enabled')
 * is true (default false; set USERS_USER_LOOKUP_ENABLED=true or override config/users.php).
 * Because the gate is config-driven (app-overridable) it is evaluated in register() where the
 * ApplicationContext is available — so this route lives in its own file that register() either
 * loads or skips. When skipped, /users/{uuid} does not exist (404).
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\UserController;

/**
 * @route GET /users/{uuid}
 * @summary Get User by UUID
 * @description Returns another user's account plus their public `profile`. Off by default —
 *   enabled via `USERS_USER_LOOKUP_ENABLED=true` (or `config/users.php`) — and requires the
 *   `users.read` permission. Supports REST dot-path field selection via `?fields=`; unknown/
 *   disallowed fields are pruned. Exposable columns are config-driven (`config/users.php`,
 *   `users` audience), which is intentionally narrower than the `me` audience.
 * @tag Users
 * @requiresAuth true
 * @response 200 application/json "User account and public profile" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     id:integer="Auto-increment id",
 *     uuid:string="User UUID",
 *     username:string="Username",
 *     profile:{
 *       first_name:string="First name",
 *       last_name:string="Last name",
 *       photo_url:string="Profile photo URL"
 *     }
 *   },
 * }
 * @response 401 "Authentication required"
 * @response 403 "Missing the users.read permission"
 * @response 404 "User not found"
 */
$router->get('/users/{uuid}', [UserController::class, 'show'])
    ->middleware(['auth', 'gate_permissions'])
    ->where('uuid', '[A-Za-z0-9_-]+')
    ->name('users.show');
