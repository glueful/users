<?php

/**
 * glueful/users user-list route — GET /users (paginated). Loaded by UsersServiceProvider::register()
 * ONLY when user_lookup.enabled AND user_lookup.list.enabled are both true (config-gated in
 * register() where the context is available), so it registers unconditionally here.
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\UserController;

/**
 * @route GET /users
 * @summary List Users
 * @description Paginated list of users + nested public profile (the `users` audience). Off by
 *   default; enabled via `USERS_USER_LIST_ENABLED=true`. Requires the `users.read` permission.
 *   Supports `?page`/`?per_page` (clamped), per-item `?fields=`, and `?filter[...]`/`?sort`/`?search`
 *   over username + profile name (email only when `allow_email_filter`). Soft-deleted profiles never
 *   affect membership or order.
 * @tag Users
 * @requiresAuth true
 * @response 200 application/json "Paginated users" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     items:array="Projected user payloads (account + nested profile|null)",
 *     pagination:{ page:integer, per_page:integer, total:integer, total_pages:integer, has_more:boolean }
 *   },
 * }
 * @response 401 "Authentication required"
 * @response 403 "Missing the users.read permission"
 */
$router->get('/users', [UserController::class, 'index'])
    ->middleware(['auth', 'gate_permissions'])
    ->name('users.index');
