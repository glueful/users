<?php

declare(strict_types=1);

/*
 * glueful/users — profile endpoint configuration. Registered via UsersServiceProvider::register()
 * with mergeConfig('users', …). Copy this file into your app's config/ to override.
 */
return [
    // GET /users/{uuid} master switch.
    'user_lookup' => [
        'enabled' => env('USERS_USER_LOOKUP_ENABLED', false),
        // GET /users collection — a larger surface than a known-UUID lookup, so it has its own gate.
        'list' => [
            'enabled' => env('USERS_USER_LIST_ENABLED', false),
            'allow_email_filter' => env('USERS_USER_LIST_ALLOW_EMAIL_FILTER', false),
            'per_page' => ['default' => 25, 'max' => 100],
            'default_sort' => '-created_at', // QueryFilter '-' prefix = DESC
        ],
    ],
    // Exposable columns per audience. Apps APPEND custom profile columns here.
    'account_fields' => [
        'me' => ['id', 'uuid', 'username', 'email', 'status', 'email_verified_at', 'two_factor_enabled', 'created_at', 'updated_at'],
        'users' => ['id', 'uuid', 'username'],
    ],
    'profile_fields' => [
        'me' => ['first_name', 'last_name', 'photo_url'],
        'users' => ['first_name', 'last_name', 'photo_url'],
    ],
];
