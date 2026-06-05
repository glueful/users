<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Database\Connection;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Symfony\Component\HttpFoundation\Request;

/**
 * Builds the merged user + nested-profile payload for an audience ('me' | 'users'), applying
 * config-driven column resolution (config registered via the provider's mergeConfig('users', …);
 * app config/users.php overrides) and REST field selection.
 * Returns null when the account row does not exist (caller maps to 404).
 */
final class ProfileResponder
{
    public function __construct(
        private readonly ApplicationContext $context,
        private readonly UserRepository $users,
        private readonly ProfileFieldResolver $resolver,
        private readonly PayloadProjector $projector,
    ) {
    }

    /**
     * @return array<string,mixed>|null
     */
    public function build(string $uuid, string $audience, Request $request): ?array
    {
        $schema = Connection::fromContext($this->context)->getSchemaBuilder();
        $realAccount = array_column($schema->getTableColumns('users'), 'name');
        $realProfile = array_column($schema->getTableColumns('profiles'), 'name');

        // Config defaults are registered by the provider via mergeConfig('users', …); an app's
        // config/users.php overrides per key. [] is a safe last-resort if neither applies.
        $configured = [
            'account' => (array) config($this->context, "users.account_fields.$audience", []),
            'profile' => (array) config($this->context, "users.profile_fields.$audience", []),
        ];

        $eff = $this->resolver->resolve($configured, $realAccount, $realProfile);

        $account = $this->users->findAccountRow($uuid, $eff['account']);
        if ($account === null) {
            return null;
        }

        $profile = $eff['profile'] !== []
            ? $this->users->findProfileRow($uuid, $eff['profile'])
            : null;

        $merged = [...$account, 'profile' => $profile];

        // Normalize ?fields to ?string. Read via all() (not query->get(), which throws a
        // BadRequestException on a non-scalar value in Symfony 7.x): ?fields[]=… yields an array,
        // which we treat as "no selection" (null → full default) rather than erroring.
        $fields = $request->query->all()['fields'] ?? null;
        $fields = is_string($fields) ? $fields : null;

        return $this->projector->project($merged, $eff['allow'], $fields);
    }

    /**
     * Build a paginated list of users (audience 'users') + nested basic profile.
     *
     * @return array{items: list<array<string,mixed>>, pagination: array<string,mixed>}
     */
    public function buildList(string $audience, Request $request, int $page, int $perPage): array
    {
        $schema = Connection::fromContext($this->context)->getSchemaBuilder();
        $realAccount = array_column($schema->getTableColumns('users'), 'name');
        $realProfile = array_column($schema->getTableColumns('profiles'), 'name');

        $configured = [
            'account' => (array) config($this->context, "users.account_fields.$audience", []),
            'profile' => (array) config($this->context, "users.profile_fields.$audience", []),
        ];
        $eff = $this->resolver->resolve($configured, $realAccount, $realProfile);

        $allowEmail = (bool) config($this->context, 'users.user_lookup.list.allow_email_filter', false);
        $filter = new UsersListQueryFilter($request, $allowEmail);

        $result = $this->users->paginateUsersWithProfiles($eff['account'], $eff['profile'], $filter, $page, $perPage);

        $fields = $request->query->all()['fields'] ?? null;
        $fields = is_string($fields) ? $fields : null;

        $items = [];
        foreach ($result['data'] as $row) {
            $merged = $this->reconstructRow($row);
            $items[] = $this->projector->project($merged, $eff['allow'], $fields);
        }

        return [
            'items' => $items,
            'pagination' => [
                'page' => $result['current_page'],
                'per_page' => $result['per_page'],
                'total' => $result['total'],
                'total_pages' => $result['last_page'],
                'has_more' => $result['has_more'],
            ],
        ];
    }

    /**
     * Split one flat aliased join row into account scalars + nested `profile`. The control columns
     * `_p_present` (null ⇒ no profile) and `_p_deleted_at` (non-null ⇒ soft-deleted) null the profile.
     *
     * @param array<string,mixed> $row
     * @return array<string,mixed>
     */
    private function reconstructRow(array $row): array
    {
        $present = $row['_p_present'] ?? null;
        $deleted = $row['_p_deleted_at'] ?? null;

        $account = [];
        $profile = [];
        foreach ($row as $k => $v) {
            if ($k === '_p_present' || $k === '_p_deleted_at') {
                continue;
            }
            if (str_starts_with($k, 'profile__')) {
                $profile[substr($k, strlen('profile__'))] = $v;
            } else {
                $account[$k] = $v;
            }
        }

        $nested = ($present === null || $deleted !== null) ? null : $profile;
        return [...$account, 'profile' => $nested];
    }
}
