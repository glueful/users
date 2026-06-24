<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

use Glueful\Auth\Contracts\UserRecordEnricherInterface;
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

        $record = $this->projector->project($merged, $eff['allow'], $fields);
        return $this->applyEnrichers([$record])[0] ?? $record;
    }

    /**
     * Build a paginated list of users (audience 'users') + nested basic profile.
     *
     * Returns the framework's flat pagination shape: the projected rows in `data`, plus the
     * pagination meta (`current_page`, `per_page`, `total`, `last_page`, `has_more`, `from`, `to`, …)
     * at the top level.
     *
     * @return array{data: list<array<string,mixed>>, current_page: int, per_page: int, total: int, last_page: int, has_more: bool}
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

        // Return the framework's flat pagination shape: the projected rows replace the raw rows in
        // `data`, and the pagination meta (current_page/per_page/total/last_page/has_more/from/to/…)
        // stays at the top level. The controller hands `data` + this whole array to
        // Response::successWithMeta(), which hoists the meta keys beside `data` in the envelope —
        // matching every other paginated endpoint (e.g. Aegis /rbac/roles).
        $result['data'] = $this->applyEnrichers($items);
        return $result;
    }

    /**
     * Fold any registered user-record enrichers' fields into these rows (matched by `uuid`), e.g.
     * Aegis attaching `roles`. The identity store stays decoupled: it merges whatever extensions
     * tagged `users.record_enricher` contribute and no-ops when none are registered.
     *
     * @param list<array<string,mixed>> $rows
     * @return list<array<string,mixed>>
     */
    private function applyEnrichers(array $rows): array
    {
        if ($rows === [] || !$this->context->hasContainer()) {
            return $rows;
        }
        $container = $this->context->getContainer();
        if (!$container->has('users.record_enricher')) {
            return $rows;
        }
        $enrichers = $container->get('users.record_enricher');
        if (!is_array($enrichers) || $enrichers === []) {
            return $rows;
        }

        $uuids = [];
        foreach ($rows as $row) {
            if (isset($row['uuid']) && is_string($row['uuid'])) {
                $uuids[] = $row['uuid'];
            }
        }
        if ($uuids === []) {
            return $rows;
        }

        // Union the contributions of every enricher, then merge into the matching rows.
        $extra = [];
        foreach ($enrichers as $enricher) {
            if (!$enricher instanceof UserRecordEnricherInterface) {
                continue;
            }
            foreach ($enricher->enrich($uuids) as $uuid => $fields) {
                $extra[$uuid] = array_merge($extra[$uuid] ?? [], $fields);
            }
        }
        foreach ($rows as $i => $row) {
            $uuid = $row['uuid'] ?? null;
            if (is_string($uuid) && isset($extra[$uuid])) {
                $rows[$i] = array_merge($row, $extra[$uuid]);
            }
        }
        return $rows;
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
