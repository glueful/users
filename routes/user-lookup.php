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

$router->get('/users/{uuid}', [UserController::class, 'show'])
    ->middleware(['auth', 'gate_permissions'])
    ->where('uuid', '[A-Za-z0-9_-]+')
    ->name('users.show');
