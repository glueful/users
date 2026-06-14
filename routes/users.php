<?php

/**
 * glueful/users profile route — GET /me (always on). Loaded via UsersServiceProvider::register().
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\UserController;

$router->get('/me', [UserController::class, 'me'])
    ->middleware('auth')
    ->name('users.me');
