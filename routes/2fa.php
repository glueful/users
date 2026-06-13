<?php

/**
 * glueful/users email-PIN 2FA routes — loaded via UsersServiceProvider::register().
 *
 * Loaded by UsersServiceProvider::register() only when config('auth.two_factor.enabled')
 * is true; otherwise /2fa/* routes do not exist (404).
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\TwoFactorController;

/**
 * @route POST /2fa/enable
 * @summary Enable Two-Factor Authentication
 * @description Begins 2FA enrollment for the authenticated user: emails a 6-digit
 *   PIN and returns a short-lived challenge_token. Submit both to POST /2fa/verify
 *   to complete enrollment.
 * @tag Authentication
 * @requiresAuth true
 * @response 200 application/json "Two-factor code sent" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     challenge_token:string="Short-lived token to submit with the PIN",
 *     expires_in:integer="Seconds until the challenge_token expires",
 *     delivered_to:string="Masked email the PIN was sent to"
 *   },
 * }
 * @response 401 "Authentication required"
 * @response 429 "Too many requests"
 */
$router->post('/2fa/enable', [TwoFactorController::class, 'enable'])
    ->rateLimit(3, 1)
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.enable');

/**
 * @route POST /2fa/verify
 * @summary Verify Two-Factor Code
 * @description Verifies the emailed PIN against a challenge_token. No auth header is
 *   required — the challenge_token authenticates the request. For a login challenge it
 *   completes login and returns the full session (identical to POST /auth/login); for an
 *   enrollment challenge it returns just {success, message}.
 * @tag Authentication
 * @requestBody challenge_token:string="Token returned by /auth/login or /2fa/enable" code:string="6-digit PIN from the email" {required=challenge_token,code}
 * @response 200 application/json "Verification successful" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:{
 *     access_token:string="JWT access token",
 *     token_type:string="Bearer",
 *     expires_in:integer="Token expiration in seconds",
 *     refresh_token:string="JWT refresh token",
 *     user:{
 *       id:string="User unique identifier",
 *       email:string="Email address",
 *       username:string="Username",
 *       updated_at:integer="Last update timestamp (Unix epoch)"
 *     }
 *   },
 * }
 * @response 401 "Invalid or expired verification"
 * @response 429 "Too many requests"
 */
$router->post('/2fa/verify', [TwoFactorController::class, 'verify'])
    ->rateLimit(5, 1)
    ->middleware('rate_limit')
    ->name('2fa.verify');

/**
 * @route POST /2fa/disable
 * @summary Disable Two-Factor Authentication
 * @description Disables 2FA for the authenticated user. Requires a recent 2FA
 *   verification on the current session (within the configured freshness window);
 *   otherwise re-elevation is required.
 * @tag Authentication
 * @requiresAuth true
 * @response 200 application/json "Two-factor authentication disabled" {
 *   success:boolean="true",
 *   message:string="Success message",
 *   data:array="Empty payload"
 * }
 * @response 401 "Authentication required"
 * @response 403 "Recent two-factor verification is required to perform this action"
 * @response 429 "Too many requests"
 */
$router->post('/2fa/disable', [TwoFactorController::class, 'disable'])
    ->rateLimit(3, 1)
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.disable');
