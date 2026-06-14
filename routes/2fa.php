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

// Email-PIN 2FA: enable, verify, disable
$router->post('/2fa/enable', [TwoFactorController::class, 'enable'])
    ->rateLimit(3, 1)
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.enable');

$router->post('/2fa/verify', [TwoFactorController::class, 'verify'])
    ->rateLimit(5, 1)
    ->middleware('rate_limit')
    ->name('2fa.verify');

$router->post('/2fa/disable', [TwoFactorController::class, 'disable'])
    ->rateLimit(3, 1)
    ->middleware(['auth', 'rate_limit'])
    ->name('2fa.disable');
