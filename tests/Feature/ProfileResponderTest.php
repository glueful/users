<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Support\PayloadProjector;
use Glueful\Extensions\Users\Support\ProfileFieldResolver;
use Glueful\Extensions\Users\Support\ProfileResponder;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class ProfileResponderTest extends AppTestCase
{
    /**
     * Boots a FRESH app whose config/users.php is the given array (config is read at boot),
     * seeds u-1 + profile, returns a responder. Extra seeding/altering must happen AFTER.
     *
     * @param array<string,mixed> $usersConfig
     */
    private function makeResponder(array $usersConfig): ProfileResponder
    {
        $this->bootApp(['users.php' => var_export($usersConfig, true)]);
        $this->seedUser('u-1', 'jane@example.com', 'jane');
        $this->seedProfile('u-1');

        $repo = new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
        return new ProfileResponder($this->context, $repo, new ProfileFieldResolver(), new PayloadProjector());
    }

    /** @return array<string,mixed> */
    private function defaultConfig(): array
    {
        return [
            'account_fields' => [
                'me' => ['id', 'uuid', 'username', 'email', 'status', 'created_at', 'updated_at'],
                'users' => ['id', 'uuid', 'username'],
            ],
            'profile_fields' => [
                'me' => ['first_name', 'last_name', 'photo_url'],
                'users' => ['first_name', 'last_name', 'photo_url'],
            ],
        ];
    }

    public function test_default_shape_nests_profile_and_omits_photo_uuid(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me'));
        self::assertNotNull($out);
        self::assertSame('jane@example.com', $out['email']);
        self::assertSame('Jane', $out['profile']['first_name']);
        self::assertArrayNotHasKey('photo_uuid', $out['profile']);
        self::assertArrayNotHasKey('password', $out);
    }

    public function test_field_selection_narrows_payload(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields=id,email'));
        self::assertSame(['id', 'email'], array_keys($out));
    }

    public function test_nested_dot_path_selection(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields=email,profile.first_name'));
        self::assertSame(['email' => 'jane@example.com', 'profile' => ['first_name' => 'Jane']], $out);
    }

    public function test_password_request_prunes_to_empty(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields=password'));
        self::assertSame([], $out, 'disallowed-only selection prunes to empty, not full payload');
    }

    public function test_array_fields_param_is_handled_without_error(): void
    {
        // ?fields[]=email makes Symfony's query->get('fields') return an array; it must be
        // normalized to null (treated as "no selection") rather than TypeError-ing.
        $r = $this->makeResponder($this->defaultConfig());
        $out = $r->build('u-1', 'me', Request::create('/me?fields[]=email'));
        self::assertArrayHasKey('profile', $out, 'array fields param → full default, no error');
    }

    public function test_profile_null_when_no_profile_row(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $this->seedUser('u-2', 'no@profile.com', 'noprof');
        $out = $r->build('u-2', 'me', Request::create('/me'));
        self::assertNull($out['profile']);
    }

    public function test_empty_profile_config_yields_null_profile(): void
    {
        $cfg = $this->defaultConfig();
        $cfg['profile_fields']['me'] = [];
        $r = $this->makeResponder($cfg);
        $out = $r->build('u-1', 'me', Request::create('/me'));
        self::assertNull($out['profile']);
        self::assertSame('jane@example.com', $out['email']);
    }

    public function test_unknown_uuid_returns_null(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        self::assertNull($r->build('nope', 'me', Request::create('/me')));
    }

    public function test_forced_uuid_when_account_config_empty(): void
    {
        $cfg = $this->defaultConfig();
        $cfg['account_fields']['me'] = [];
        $r = $this->makeResponder($cfg);
        $out = $r->build('u-1', 'me', Request::create('/me'));
        self::assertArrayHasKey('uuid', $out);
    }

    public function test_custom_profile_column_opt_in(): void
    {
        $cfg = $this->defaultConfig();
        $cfg['profile_fields']['me'][] = 'phone';
        $r = $this->makeResponder($cfg);

        $this->db()->getSchemaBuilder()->alterTable('profiles', function ($t) {
            $t->addColumn('phone', 'string', ['length' => 32, 'nullable' => true]);
        });
        $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->update(['phone' => '+1-555-0100']);

        $out = $r->build('u-1', 'me', Request::create('/me?fields=profile.phone'));
        self::assertSame(['phone' => '+1-555-0100'], $out['profile']);
    }

    public function test_custom_column_not_configured_is_pruned(): void
    {
        $r = $this->makeResponder($this->defaultConfig()); // 'phone' not configured

        $this->db()->getSchemaBuilder()->alterTable('profiles', function ($t) {
            $t->addColumn('phone', 'string', ['length' => 32, 'nullable' => true]);
        });
        $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->update(['phone' => '+1-555-0100']);

        $out = $r->build('u-1', 'me', Request::create('/me?fields=profile.phone'));
        self::assertSame([], $out, 'unconfigured custom column prunes to empty');
    }

    public function test_audience_split_users_narrower_than_me(): void
    {
        $r = $this->makeResponder($this->defaultConfig());
        $me = $r->build('u-1', 'me', Request::create('/me'));
        $users = $r->build('u-1', 'users', Request::create('/users/u-1'));
        self::assertArrayHasKey('email', $me);
        self::assertArrayNotHasKey('email', $users);
    }
}
