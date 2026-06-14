<?php

/**
 * glueful/users user-list route — GET /users (paginated). Loaded by UsersServiceProvider::register()
 * ONLY when user_lookup.enabled AND user_lookup.list.enabled are both true (config-gated in
 * register() where the context is available), so it registers unconditionally here.
 *
 * @var \Glueful\Routing\Router $router
 */

use Glueful\Extensions\Users\Controllers\UserController;

$router->get('/users', [UserController::class, 'index'])
    ->middleware(['auth', 'gate_permissions'])
    ->name('users.index');
