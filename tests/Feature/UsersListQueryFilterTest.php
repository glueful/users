<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Support\UsersListQueryFilter;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Glueful\Database\QueryBuilder;
use Symfony\Component\HttpFoundation\Request;

final class UsersListQueryFilterTest extends AppTestCase
{
    private function baseQuery(): QueryBuilder
    {
        return $this->db()->table('users')
            ->selectRaw('users.uuid AS uuid')
            ->leftJoin('profiles', 'profiles.user_uuid', '=', 'users.uuid')
            ->whereNull('users.deleted_at');
    }

    /** lowercased SQL with identifier quoting stripped, for robust substring checks */
    private function sql(QueryBuilder $qb): string
    {
        return strtolower(str_replace(['`', '"'], '', $qb->toSql()));
    }

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
    }

    public function test_profile_filter_is_guarded_and_qualified(): void
    {
        $req = Request::create('/users', 'GET', ['filter' => ['profile' => ['first_name' => 'Jane']]]);
        $sql = $this->sql((new UsersListQueryFilter($req))->apply($this->baseQuery()));
        self::assertStringContainsString('profiles.first_name', $sql);
        self::assertStringContainsString('profiles.deleted_at is null', $sql);
    }

    public function test_search_guards_profile_branches_but_not_username(): void
    {
        $req = Request::create('/users', 'GET', ['search' => 'jane']);
        $sql = $this->sql((new UsersListQueryFilter($req))->apply($this->baseQuery()));
        self::assertStringContainsString('users.username like', $sql);
        self::assertStringContainsString('profiles.first_name like', $sql);
        self::assertStringContainsString('profiles.deleted_at is null', $sql);
    }

    public function test_sort_maps_and_guards_profile_column(): void
    {
        $req = Request::create('/users', 'GET', ['sort' => 'first_name']);
        $sql = $this->sql((new UsersListQueryFilter($req))->apply($this->baseQuery()));
        self::assertStringContainsString('case when profiles.deleted_at is null then profiles.first_name', $sql);
    }

    public function test_default_sort_is_created_at_desc(): void
    {
        $req = Request::create('/users', 'GET');
        $sql = $this->sql((new UsersListQueryFilter($req))->apply($this->baseQuery()));
        self::assertStringContainsString('order by users.created_at desc', $sql);
    }

    public function test_email_filter_ignored_unless_allowed(): void
    {
        $req = Request::create('/users', 'GET', ['filter' => ['email' => 'a@b.c']]);
        $off = $this->sql((new UsersListQueryFilter($req, false))->apply($this->baseQuery()));
        self::assertStringNotContainsString('users.email', $off);

        $on = $this->sql((new UsersListQueryFilter($req, true))->apply($this->baseQuery()));
        self::assertStringContainsString('users.email', $on);
    }

    public function test_status_is_not_filterable(): void
    {
        $req = Request::create('/users', 'GET', ['filter' => ['status' => 'active']]);
        $sql = $this->sql((new UsersListQueryFilter($req))->apply($this->baseQuery()));
        self::assertStringNotContainsString('status', $sql);
    }
}
