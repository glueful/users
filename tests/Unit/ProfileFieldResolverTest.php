<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Unit;

use Glueful\Extensions\Users\Support\ProfileFieldResolver;
use PHPUnit\Framework\TestCase;

final class ProfileFieldResolverTest extends TestCase
{
    private ProfileFieldResolver $resolver;
    /** @var list<string> */
    private array $usersCols = [
        'id',
        'uuid',
        'username',
        'email',
        'password',
        'status',
        'created_at',
        'updated_at',
        'deleted_at',
        'two_factor_secret',
        'remember_token',
        'provider_id',
    ];
    /** @var list<string> */
    private array $profilesCols = ['uuid', 'user_uuid', 'first_name', 'last_name', 'photo_url', 'photo_uuid', 'status', 'deleted_at'];

    protected function setUp(): void
    {
        $this->resolver = new ProfileFieldResolver();
    }

    public function test_intersects_with_real_columns_and_strips_account_denylist(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['id', 'email', 'password', 'nonexistent'], 'profile' => ['first_name']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertContains('id', $r['account']);
        self::assertContains('email', $r['account']);
        self::assertNotContains('password', $r['account']);
        self::assertNotContains('nonexistent', $r['account']);
    }

    public function test_account_denylist_includes_deleted_at(): void
    {
        $r = $this->resolver->resolve(['account' => ['uuid', 'deleted_at'], 'profile' => []], $this->usersCols, $this->profilesCols);
        self::assertNotContains('deleted_at', $r['account']);
    }

    public function test_account_denylist_includes_secret_and_provider_fields(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['uuid', 'two_factor_secret', 'remember_token', 'provider_id'], 'profile' => []],
            $this->usersCols,
            $this->profilesCols
        );

        self::assertSame(['uuid'], $r['account']);
    }

    public function test_profile_denylist_strips_user_uuid_and_deleted_at(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['uuid'], 'profile' => ['first_name', 'user_uuid', 'deleted_at']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertSame(['first_name'], $r['profile']);
    }

    public function test_forces_uuid_when_account_config_empty(): void
    {
        $r = $this->resolver->resolve(['account' => [], 'profile' => []], $this->usersCols, $this->profilesCols);
        self::assertSame(['uuid'], $r['account']);
    }

    public function test_builds_dot_path_allow_list(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['id', 'email'], 'profile' => ['first_name', 'photo_url']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertContains('id', $r['allow']);
        self::assertContains('email', $r['allow']);
        self::assertContains('profile.first_name', $r['allow']);
        self::assertContains('profile.photo_url', $r['allow']);
    }

    public function test_empty_profile_yields_no_profile_paths(): void
    {
        $r = $this->resolver->resolve(['account' => ['uuid'], 'profile' => []], $this->usersCols, $this->profilesCols);
        self::assertSame([], $r['profile']);
        self::assertSame(['uuid'], $r['allow']);
    }

    public function test_photo_uuid_is_opt_in_not_denylisted(): void
    {
        $r = $this->resolver->resolve(
            ['account' => ['uuid'], 'profile' => ['first_name', 'photo_uuid']],
            $this->usersCols,
            $this->profilesCols
        );
        self::assertContains('photo_uuid', $r['profile']);
        self::assertContains('profile.photo_uuid', $r['allow']);
    }
}
