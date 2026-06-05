<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;

final class UserRepositoryReadersTest extends AppTestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        $this->repo = new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
        $this->seedUser('u-1', 'jane@example.com', 'jane');
        $this->seedProfile('u-1');
    }

    public function test_find_account_row_returns_only_requested_columns(): void
    {
        $row = $this->repo->findAccountRow('u-1', ['uuid', 'email']);
        self::assertNotNull($row);
        self::assertSame(['uuid', 'email'], array_keys($row));
        self::assertArrayNotHasKey('password', $row);
    }

    public function test_find_account_row_unknown_uuid_is_null(): void
    {
        self::assertNull($this->repo->findAccountRow('nope', ['uuid']));
    }

    public function test_find_account_row_empty_columns_is_null(): void
    {
        self::assertNull($this->repo->findAccountRow('u-1', []));
    }

    public function test_find_account_row_excludes_soft_deleted(): void
    {
        $this->db()->table('users')->where(['uuid' => 'u-1'])->update(['deleted_at' => '2026-01-01 00:00:00']);
        self::assertNull($this->repo->findAccountRow('u-1', ['uuid']), 'soft-deleted user is not found');
    }

    public function test_find_profile_row_returns_only_requested_columns(): void
    {
        $row = $this->repo->findProfileRow('u-1', ['first_name', 'photo_url']);
        self::assertNotNull($row);
        self::assertSame(['first_name', 'photo_url'], array_keys($row));
        self::assertArrayNotHasKey('photo_uuid', $row);
    }

    public function test_find_profile_row_no_profile_is_null(): void
    {
        $this->seedUser('u-2', 'no@profile.com', 'noprof');
        self::assertNull($this->repo->findProfileRow('u-2', ['first_name']));
    }

    public function test_find_profile_row_excludes_soft_deleted(): void
    {
        $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->update(['deleted_at' => '2026-01-01 00:00:00']);
        self::assertNull($this->repo->findProfileRow('u-1', ['first_name']), 'soft-deleted profile is not found');
    }
}
