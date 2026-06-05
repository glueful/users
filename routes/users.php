<?php

/**
 * glueful/users profile route — GET /me (always on). Loaded via UsersServiceProvider::register().
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\UserController;

/**
 * @route GET /me
 * @summary Get Current User
 * @description Returns the authenticated principal's account plus a nested `profile` object.
 *   Supports REST dot-path field selection via `?fields=` (e.g. `?fields=id,email`,
 *   `?fields=email,profile.first_name`); unknown/disallowed fields are pruned. Exposable
 *   columns are config-driven (`config/users.php`, `me` audience); `password`/`deleted_at`
 *   are never exposed.
 * @tag Users
 * @requiresAuth true
 * @response 200 application/json "Current user account and profile" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     id:integer="Auto-increment id",
 *     uuid:string="User UUID",
 *     username:string="Username",
 *     email:string="Email address",
 *     status:string="Account status",
 *     email_verified_at:string="Email verification timestamp (nullable)",
 *     two_factor_enabled:boolean="Whether 2FA is enabled",
 *     created_at:string="Created timestamp",
 *     updated_at:string="Updated timestamp",
 *     profile:{
 *       first_name:string="First name",
 *       last_name:string="Last name",
 *       photo_url:string="Profile photo URL"
 *     }
 *   },
 * }
 * @response 401 "Authentication required"
 * @response 404 "User not found"
 */
$router->get('/me', [UserController::class, 'me'])
    ->middleware('auth')
    ->name('users.me');
