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

    public function test_auth_lookup_readers_exclude_soft_deleted_users(): void
    {
        $this->db()->getPDO()->exec("UPDATE users SET deleted_at = '2026-01-01 00:00:00' WHERE uuid = 'u-1'");
        $deletedAt = $this->db()->getPDO()
            ->query("SELECT deleted_at FROM users WHERE uuid = 'u-1' LIMIT 1")
            ->fetchColumn();
        self::assertSame('2026-01-01 00:00:00', $deletedAt);

        self::assertNull($this->repo->findByUuid('u-1'), 'soft-deleted user is not found by uuid');
        self::assertNull($this->repo->findByEmail('jane@example.com'), 'soft-deleted user is not found by email');
        self::assertNull($this->repo->findByUsername('jane'), 'soft-deleted user is not found by username');
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

    public function test_saml_provisioning_writes_only_canonical_user_and_profile_columns(): void
    {
        $user = $this->repo->findOrCreateFromSaml([
            'email' => 'saml@example.com',
            'name' => 'Sam Example',
            'first_name' => 'Sam',
            'last_name' => 'Example',
            'saml_idp' => 'company-idp',
        ]);

        self::assertIsArray($user);
        self::assertSame('saml@example.com', $user['email']);
        self::assertNotEmpty($user['email_verified_at']);

        $profile = $this->repo->findProfileRow((string) $user['uuid'], ['first_name', 'last_name']);
        self::assertSame(['first_name' => 'Sam', 'last_name' => 'Example'], $profile);
    }

    public function test_ldap_provisioning_writes_only_canonical_user_and_profile_columns(): void
    {
        $user = $this->repo->findOrCreateFromLdap([
            'email' => 'ldap@example.com',
            'name' => 'Lee Example',
            'first_name' => 'Lee',
            'last_name' => 'Example',
            'ldap_server' => 'corp-ldap',
        ]);

        self::assertIsArray($user);
        self::assertSame('ldap@example.com', $user['email']);
        self::assertNotEmpty($user['email_verified_at']);

        $profile = $this->repo->findProfileRow((string) $user['uuid'], ['first_name', 'last_name']);
        self::assertSame(['first_name' => 'Lee', 'last_name' => 'Example'], $profile);
    }
}
