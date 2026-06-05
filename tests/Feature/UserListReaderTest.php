<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Support\UsersListQueryFilter;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class UserListReaderTest extends AppTestCase
{
    private UserRepository $repo;

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        $this->repo = new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
        $this->seedUser('u-1', 'a@x.com', 'alice');
        $this->seedProfile('u-1', 'Alice', 'Adams');
        $this->seedUser('u-2', 'b@x.com', 'bob'); // no profile
    }

    private function filter(array $query = []): UsersListQueryFilter
    {
        return new UsersListQueryFilter(Request::create('/users', 'GET', $query));
    }

    public function test_returns_pagination_envelope_and_aliased_rows(): void
    {
        $res = $this->repo->paginateUsersWithProfiles(['uuid', 'username'], ['first_name', 'last_name'], $this->filter(), 1, 25);

        self::assertArrayHasKey('data', $res);
        self::assertSame(2, $res['total']);
        $row = $res['data'][0];
        self::assertArrayHasKey('uuid', $row);
        self::assertArrayHasKey('profile__first_name', $row);
        self::assertArrayHasKey('_p_present', $row);
        self::assertArrayHasKey('_p_deleted_at', $row);
    }

    public function test_user_without_profile_has_null_present_marker(): void
    {
        $res = $this->repo->paginateUsersWithProfiles(['uuid'], ['first_name'], $this->filter(), 1, 25);
        $byUuid = array_column($res['data'], null, 'uuid');
        self::assertNull($byUuid['u-2']['_p_present'], 'no-profile user → null present marker');
        self::assertNotNull($byUuid['u-1']['_p_present']);
    }
}
