<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

use Glueful\Api\Filtering\QueryFilter;
use Glueful\Api\Filtering\ParsedSort;
use Glueful\Api\Filtering\Operators\OperatorRegistry;
use Symfony\Component\HttpFoundation\Request;

/**
 * QueryFilter for GET /users over a `users LEFT JOIN profiles` query. Public field names are mapped
 * to table-qualified columns, and every profile predicate is guarded with `profiles.deleted_at IS
 * NULL` so a soft-deleted profile can never drive membership or ordering. `$searchable` is kept in
 * PUBLIC names so core's `?search_fields=` intersection works; qualification happens at emit time.
 */
final class UsersListQueryFilter extends QueryFilter
{
    protected ?string $defaultSort = '-created_at';

    /** public sort field => qualified column */
    private const SORT_MAP = [
        'username' => 'users.username',
        'created_at' => 'users.created_at',
        'first_name' => 'profiles.first_name',
        'last_name' => 'profiles.last_name',
    ];
    private const PROFILE_SORT = ['first_name', 'last_name'];

    public function __construct(Request $request, private bool $allowEmailFilter = false)
    {
        parent::__construct($request);

        $this->sortable = ['username', 'created_at', 'first_name', 'last_name'];
        $this->filterable = ['profile.first_name', 'profile.last_name'];
        $this->searchable = ['username', 'profile.first_name', 'profile.last_name'];

        if ($this->allowEmailFilter) {
            $this->filterable[] = 'email';
            $this->searchable[] = 'email';
        }
    }

    public function filterProfileFirstName(mixed $value, string $operator): void
    {
        OperatorRegistry::get($operator)->apply($this->query, 'profiles.first_name', $value);
        $this->query->whereNull('profiles.deleted_at');
    }

    public function filterProfileLastName(mixed $value, string $operator): void
    {
        OperatorRegistry::get($operator)->apply($this->query, 'profiles.last_name', $value);
        $this->query->whereNull('profiles.deleted_at');
    }

    public function filterEmail(mixed $value, string $operator): void
    {
        OperatorRegistry::get($operator)->apply($this->query, 'users.email', $value);
    }

    /**
     * Override: qualify each searchable field and AND a `profiles.deleted_at IS NULL` guard onto
     * every PROFILE branch (the username branch stays unguarded so a username match still surfaces a
     * soft-deleted-profile user — with the profile nulled later). Built as one raw OR group so the
     * per-branch guard binds correctly.
     */
    protected function applySearch(string $search): void
    {
        $fields = $this->getSearchableFields(); // public names ∩ ?search_fields
        $conds = [];
        $bind = [];
        foreach ($fields as $public) {
            $qualified = $this->mapField($public);
            if ($qualified === null) {
                continue;
            }
            if (str_starts_with($public, 'profile.')) {
                $conds[] = "($qualified LIKE ? AND profiles.deleted_at IS NULL)";
            } else {
                $conds[] = "$qualified LIKE ?";
            }
            $bind[] = "%{$search}%";
        }
        if ($conds === []) {
            return;
        }
        $this->query->whereRaw('(' . implode(' OR ', $conds) . ')', $bind);
    }

    /**
     * Override: core sorts by the parsed field directly. Map public → qualified, and emit profile
     * sorts via a CASE so a soft-deleted profile contributes a NULL sort key (no ordering leak).
     */
    protected function applySort(ParsedSort $sort): void
    {
        if (!$this->isSortable($sort->field)) {
            return;
        }
        $col = self::SORT_MAP[$sort->field] ?? null;
        if ($col === null) {
            return;
        }
        $dir = $sort->direction === ParsedSort::DIRECTION_DESC ? 'DESC' : 'ASC';

        if (in_array($sort->field, self::PROFILE_SORT, true)) {
            $this->query->orderByRaw("CASE WHEN profiles.deleted_at IS NULL THEN $col END $dir");
        } else {
            $this->query->orderBy($col, $dir);
        }
    }

    private function mapField(string $public): ?string
    {
        return match ($public) {
            'username' => 'users.username',
            'email' => 'users.email',
            'profile.first_name' => 'profiles.first_name',
            'profile.last_name' => 'profiles.last_name',
            default => null,
        };
    }
}
