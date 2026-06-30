<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;

/**
 * updateProfile() runs with user_uuid as the working primary key, so BaseRepository::create() won't
 * generate the row's own uuid — the repository must set it itself (profiles.uuid is NOT NULL + UNIQUE).
 */
final class UserRepositoryProfileWriteTest extends AppTestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        $this->repo = new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
        $this->seedUser('u-1', 'jane@example.com', 'jane');
    }

    public function test_creating_a_profile_generates_its_own_uuid(): void
    {
        // No profile exists yet for u-1, so updateProfile() creates one.
        self::assertTrue($this->repo->updateProfile('u-1', ['first_name' => 'Jane']));

        $row = $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->get()[0] ?? null;
        self::assertNotNull($row);
        self::assertNotEmpty($row['uuid'], 'a new profile row gets a generated uuid');
        self::assertSame('u-1', $row['user_uuid']);
    }

    public function test_updating_an_existing_profile_keeps_its_uuid(): void
    {
        self::assertTrue($this->repo->updateProfile('u-1', ['first_name' => 'Jane']));
        $created = $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->get()[0];

        self::assertTrue($this->repo->updateProfile('u-1', ['last_name' => 'Doe']));
        $updated = $this->db()->table('profiles')->where(['user_uuid' => 'u-1'])->get()[0];

        self::assertSame($created['uuid'], $updated['uuid'], 'the profile uuid is stable across updates');
        self::assertSame('Doe', $updated['last_name']);
    }
}
