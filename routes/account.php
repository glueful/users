<?php

/**
 * glueful/users account-lifecycle routes — loaded via UsersServiceProvider::register().
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Routing\Router;
use Glueful\Extensions\Users\Controllers\AccountController;

$router->group(['prefix' => '/auth'], function (Router $router) {
    /**
     * @route POST /auth/verify-email
     * @summary Verify Email
     * @description Sends a verification code to the provided email
     * @tag Authentication
     * @requestBody email:string="Email address to verify" {required=email}
     * @response 200 application/json "Verification code has been sent to your email" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="OTP expiration time in seconds"
     *   },
     * }
     * @response 400 "Invalid email address"
     * @response 404 "Email not found"
     */
    $router->post('/verify-email', [AccountController::class, 'verifyEmail']);

    /**
     * @route POST /auth/verify-otp
     * @summary Verify OTP
     * @description Verifies the one-time password (OTP) sent to a user's email. When
     *   purpose=password_reset, returns a short-lived reset_token to submit to
     *   POST /auth/reset-password.
     * @tag Authentication
     * @requestBody email:string="Email address" otp:string="One-time password code" purpose:string="Optional purpose; use password_reset for reset flow" {required=email,otp}
     * @response 200 application/json "OTP verified successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     verified:boolean="true",
     *     verified_at:string="Verification timestamp",
     *     purpose:string="password_reset when verifying a reset OTP",
     *     reset_token:string="Single-use reset token when purpose=password_reset",
     *     expires_in:integer="Reset token expiration time in seconds"
     *   },
     * }
     * @response 400 "Invalid OTP"
     * @response 401 "OTP expired"
     */
    $router->post('/verify-otp', [AccountController::class, 'verifyOtp'])
        ->middleware('rate_limit:3,60'); // 3 attempts per minute

    /**
     * @route POST /auth/resend-otp
     * @summary Resend OTP
     * @description Resends the one-time password (OTP) to the user's email
     * @tag Authentication
     * @requestBody email:string="Email address to resend OTP to" {required=email}
     * @response 200 application/json "OTP resent successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="OTP expiration time in seconds"
     *   },
     * }
     * @response 400 "Invalid email address"
     * @response 404 "Email not found"
     */
    $router->post('/resend-otp', [AccountController::class, 'resendOtp'])
        ->middleware('rate_limit:2,120'); // 2 attempts per 2 minutes (stricter for resend)

    /**
     * @route POST /auth/forgot-password
     * @summary Forgot Password
     * @description Initiates the password reset process by sending a reset code
     * @tag Authentication
     * @requestBody email:string="Email address associated with account" {required=email}
     * @response 200 application/json "Password reset instructions sent to email" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     expires_in:integer="Reset code expiration time in seconds"
     *   },
     * }
     * @response 404 "Email not found"
     * @response 400 "Invalid email format"
     */
    $router->post('/forgot-password', [AccountController::class, 'forgotPassword'])
        ->rateLimit(3, 15)
        ->middleware('rate_limit');

    /**
     * @route POST /auth/reset-password
     * @summary Reset Password
     * @description Resets the user's password using the single-use reset_token returned
     *   by POST /auth/verify-otp with purpose=password_reset.
     * @tag Authentication
     * @requestBody reset_token:string="Single-use reset token" password:string="New password" {required=reset_token,password}
     * @response 200 application/json "Password has been reset successfully" {
     *   success:boolean="true",
     *   message:string="Success message",
     *   data:{
     *     email:string="Email address",
     *     updated_at:string="Password reset timestamp"
     *   },
     * }
     * @response 400 "Invalid password format"
     * @response 404 "Email not found"
     */
    $router->post('/reset-password', [AccountController::class, 'resetPassword'])
        ->rateLimit(5, 15)
        ->middleware('rate_limit');
});
