<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Tests\Support\AppTestCase;

final class ConfigModelTest extends AppTestCase
{
    /** @return array<string,mixed> */
    private function shipped(): array
    {
        return require dirname(__DIR__, 2) . '/config/users.php';
    }

    public function test_shipped_defaults_reach_config_via_merge(): void
    {
        $this->bootApp(); // no app config/users.php
        $this->context->mergeConfigDefaults('users', $this->shipped());

        self::assertFalse((bool) config($this->context, 'users.user_lookup.enabled', null), 'lookup ships disabled');
        self::assertSame(
            ['first_name', 'last_name', 'photo_url'],
            config($this->context, 'users.profile_fields.me', null)
        );
    }

    public function test_app_config_overrides_shipped_default(): void
    {
        // App ships config/users.php enabling lookup; file wins over merged defaults.
        $this->bootApp(['users.php' => "['user_lookup'=>['enabled'=>true]]"]);
        $this->context->mergeConfigDefaults('users', $this->shipped());

        self::assertTrue((bool) config($this->context, 'users.user_lookup.enabled', null), 'app config wins');
        // A key the app did NOT set still resolves from the merged defaults:
        self::assertSame(['id', 'uuid', 'username'], config($this->context, 'users.account_fields.users', null));
    }

    public function test_list_defaults_present_and_disabled(): void
    {
        $this->bootApp();
        $this->context->mergeConfigDefaults('users', $this->shipped());

        self::assertFalse((bool) config($this->context, 'users.user_lookup.list.enabled', null), 'list ships disabled');
        self::assertFalse((bool) config($this->context, 'users.user_lookup.list.allow_email_filter', null), 'email filter ships off');
        self::assertSame(25, config($this->context, 'users.user_lookup.list.per_page.default', null));
        self::assertSame(100, config($this->context, 'users.user_lookup.list.per_page.max', null));
        self::assertSame('-created_at', config($this->context, 'users.user_lookup.list.default_sort', null));
    }
}
