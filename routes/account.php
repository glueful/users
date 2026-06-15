<?php

/**
 * glueful/users account-lifecycle routes — loaded via UsersServiceProvider::register().
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Routing\Router;
use Glueful\Extensions\Users\Controllers\AccountController;

$router->group(['prefix' => '/auth'], function (Router $router) {
    // Email verification + OTP
    $router->post('/verify-email', [AccountController::class, 'verifyEmail']);

    $router->post('/verify-otp', [AccountController::class, 'verifyOtp'])
        ->middleware('rate_limit:3,60'); // 3 attempts per minute

    $router->post('/resend-otp', [AccountController::class, 'resendOtp'])
        ->middleware('rate_limit:2,120'); // 2 attempts per 2 minutes (stricter for resend)

    // Password recovery
    $router->post('/forgot-password', [AccountController::class, 'forgotPassword'])
        ->rateLimit(3, 15)
        ->middleware('rate_limit');

    $router->post('/reset-password', [AccountController::class, 'resetPassword'])
        ->rateLimit(5, 15)
        ->middleware('rate_limit');
});
