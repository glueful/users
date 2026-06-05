<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Support;

/**
 * Projects the default payload to the requested REST dot-path `fields`, constrained by an
 * allow-list. Supports one level of nesting under `profile`. Unknown/disallowed fields are
 * omitted (pruned). When no `fields` is provided, the full default payload is returned —
 * distinguishing "no selection" from "everything pruned" (which yields an empty object).
 *
 * Field-selection contract (v1): **prune-only — never raises a 400.** This replaces the framework
 * field stack, so there is no `maxDepth`/`maxFields` enforcement and no structural over-limit
 * error. The output is inherently bounded: nesting is at most one level (`profile.<child>`) by
 * construction, and the result is constrained to the (small, config-derived) allow-list regardless
 * of how many fields are requested. `expand` is not supported (profile is always inline) and is
 * ignored.
 */
final class PayloadProjector
{
    /**
     * @param array<string,mixed> $merged default shape: account scalars + 'profile' => array|null
     * @param list<string> $allow account field names + 'profile.<child>' entries
     * @param string|null $fields raw ?fields query value (null/'' = not provided → full default)
     * @return array<string,mixed>
     */
    public function project(array $merged, array $allow, ?string $fields): array
    {
        if ($fields === null || $fields === '') {
            return $merged;
        }

        $allowedRoots = [];
        $allowedProfileChildren = [];
        foreach ($allow as $entry) {
            if (str_starts_with($entry, 'profile.')) {
                $allowedProfileChildren[substr($entry, strlen('profile.'))] = true;
            } else {
                $allowedRoots[$entry] = true;
            }
        }

        $requested = array_filter(
            array_map('trim', explode(',', $fields)),
            static fn(string $s): bool => $s !== ''
        );

        $out = [];
        foreach ($requested as $path) {
            if ($path === 'profile') {
                if (array_key_exists('profile', $merged)) {
                    $out['profile'] = $merged['profile'];
                }
                continue;
            }
            if (str_starts_with($path, 'profile.')) {
                $child = substr($path, strlen('profile.'));
                $profile = $merged['profile'] ?? null;
                if (isset($allowedProfileChildren[$child]) && is_array($profile) && array_key_exists($child, $profile)) {
                    $out['profile'][$child] = $profile[$child];
                }
                continue;
            }
            if (isset($allowedRoots[$path]) && array_key_exists($path, $merged)) {
                $out[$path] = $merged[$path];
            }
        }

        return $out;
    }
}
