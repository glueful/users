<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Support\PayloadProjector;
use Glueful\Extensions\Users\Support\ProfileFieldResolver;
use Glueful\Extensions\Users\Support\ProfileResponder;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Symfony\Component\HttpFoundation\Request;

final class UserListResponderTest extends AppTestCase
{
    private ProfileResponder $responder;

    /** @param array<string,mixed> $usersConfig */
    private function boot(array $usersConfig = []): void
    {
        $base = [
            'account_fields' => ['users' => ['id', 'uuid', 'username']],
            'profile_fields' => ['users' => ['first_name', 'last_name', 'photo_url']],
            'user_lookup' => ['enabled' => true, 'list' => ['enabled' => true, 'allow_email_filter' => false]],
        ];
        $this->bootApp(['users.php' => var_export(array_replace_recursive($base, $usersConfig), true)]);
        $repo = new UserRepository($this->app->getContainer()->get('database'), null, $this->context);
        $this->responder = new ProfileResponder($this->context, $repo, new ProfileFieldResolver(), new PayloadProjector());

        $this->seedUser('u-1', 'a@x.com', 'alice');
        $this->seedProfile('u-1', 'Jane', 'Adams');           // first_name Jane
        $this->seedUser('u-2', 'b@x.com', 'bob');             // no profile
        $this->seedUser('u-3', 'c@x.com', 'carol');
        $this->seedProfile('u-3', 'Jane', 'Carter');          // first_name Jane, will be soft-deleted
        $this->db()->table('profiles')->where(['user_uuid' => 'u-3'])->update(['deleted_at' => '2026-01-01 00:00:00']);
    }

    private function req(string $qs = ''): Request
    {
        return Request::create('/users' . ($qs !== '' ? "?$qs" : ''), 'GET');
    }

    public function test_plain_list_includes_all_three_with_profile_nulled_for_absent_and_deleted(): void
    {
        $this->boot();
        $out = $this->responder->buildList('users', $this->req(), 1, 25);
        $byUuid = array_column($out['data'], null, 'uuid');

        self::assertCount(3, $out['data']);
        self::assertSame('Jane', $byUuid['u-1']['profile']['first_name']);
        self::assertNull($byUuid['u-2']['profile'], 'no-profile → null');
        self::assertNull($byUuid['u-3']['profile'], 'soft-deleted profile → null');
        self::assertArrayNotHasKey('email', $byUuid['u-1'], 'users audience excludes email');
    }

    public function test_search_does_not_match_via_soft_deleted_profile(): void
    {
        $this->boot();
        $out = $this->responder->buildList('users', $this->req('search=Jane'), 1, 25);
        $uuids = array_column($out['data'], 'uuid');
        self::assertContains('u-1', $uuids, 'active Jane matches');
        self::assertNotContains('u-3', $uuids, 'soft-deleted Jane must NOT match (no leak)');
    }

    public function test_filter_excludes_soft_deleted_profile(): void
    {
        $this->boot();
        $out = $this->responder->buildList('users', $this->req('filter[profile][first_name]=Jane'), 1, 25);
        $uuids = array_column($out['data'], 'uuid');
        self::assertSame(['u-1'], $uuids);
    }

    public function test_per_item_field_projection(): void
    {
        $this->boot();
        $out = $this->responder->buildList('users', $this->req('fields=username,profile.first_name'), 1, 25);
        $byUuid = array_column($out['data'], null, 'username');
        self::assertSame(['username', 'profile'], array_keys($byUuid['alice']));
        self::assertSame(['first_name' => 'Jane'], $byUuid['alice']['profile']);
    }

    public function test_pagination_metadata_is_flat_at_top_level(): void
    {
        $this->boot();
        $out = $this->responder->buildList('users', $this->req(), 1, 2);
        self::assertSame(1, $out['current_page']);
        self::assertSame(2, $out['per_page']);
        self::assertSame(3, $out['total']);
        self::assertSame(2, $out['last_page']);
        self::assertTrue($out['has_more'], 'page 1 of 2 → there is another page');
        self::assertCount(2, $out['data']);
    }

    public function test_sort_not_affected_by_soft_deleted_profile_value(): void
    {
        $this->boot();
        // Tight active cluster (Maa < Mac) plus a soft-deleted 'Mab' that WOULD sort between them.
        $this->seedUser('s-a', 'sa@x.com', 'usera');
        $this->seedProfile('s-a', 'Maa', 'X');
        $this->seedUser('s-b', 'sb@x.com', 'userb');
        $this->seedProfile('s-b', 'Mac', 'X');
        $this->seedUser('s-c', 'sc@x.com', 'userc');
        $this->seedProfile('s-c', 'Mab', 'X');
        $this->db()->table('profiles')->where(['user_uuid' => 's-c'])->update(['deleted_at' => '2026-01-01 00:00:00']);

        $out = $this->responder->buildList('users', $this->req('sort=first_name&per_page=100'), 1, 100);
        $uuids = array_column($out['data'], 'uuid');
        $pa = array_search('s-a', $uuids, true);
        $pb = array_search('s-b', $uuids, true);
        $pc = array_search('s-c', $uuids, true);
        self::assertNotFalse($pa);
        self::assertNotFalse($pb);
        self::assertNotFalse($pc);

        // Engine-independent: if the deleted 'Mab' leaked into ordering, s-c would sit BETWEEN s-a
        // (Maa) and s-b (Mac). The CASE guard nulls its sort key, pushing it to a NULL end instead.
        $between = $pc > min($pa, $pb) && $pc < max($pa, $pb);
        self::assertFalse($between, 'soft-deleted profile value must not affect sort order');
        self::assertLessThan($pb, $pa, 'active users keep their real relative order (Maa before Mac)');
    }
}
