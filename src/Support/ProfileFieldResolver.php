<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

/**
 * Pure resolution of exposable columns. effective = (configured ∩ real columns) − denylist;
 * account force-includes `uuid`. No DB / container — fully unit-testable.
 */
final class ProfileFieldResolver
{
    private const ACCOUNT_DENYLIST = ['password', 'deleted_at'];
    private const PROFILE_DENYLIST = ['user_uuid', 'deleted_at'];

    /**
     * @param array{account?: list<string>, profile?: list<string>} $configured
     * @param list<string> $realAccountColumns normalized column names of `users`
     * @param list<string> $realProfileColumns normalized column names of `profiles`
     * @return array{account: list<string>, profile: list<string>, allow: list<string>}
     */
    public function resolve(array $configured, array $realAccountColumns, array $realProfileColumns): array
    {
        $account = $this->effective($configured['account'] ?? [], $realAccountColumns, self::ACCOUNT_DENYLIST);
        if (!in_array('uuid', $account, true) && in_array('uuid', $realAccountColumns, true)) {
            $account[] = 'uuid';
        }
        $profile = $this->effective($configured['profile'] ?? [], $realProfileColumns, self::PROFILE_DENYLIST);

        $allow = $account;
        foreach ($profile as $field) {
            $allow[] = 'profile.' . $field;
        }

        return [
            'account' => array_values($account),
            'profile' => array_values($profile),
            'allow' => array_values($allow),
        ];
    }

    /**
     * @param list<string> $configured
     * @param list<string> $realColumns
     * @param list<string> $denylist
     * @return list<string>
     */
    private function effective(array $configured, array $realColumns, array $denylist): array
    {
        return array_values(array_diff(array_intersect($configured, $realColumns), $denylist));
    }
}
