<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Controllers;

use Glueful\Auth\Attributes\RequiresPermission;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Users\Support\ProfileResponder;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Glueful\Routing\Attributes\QueryParam;
use Symfony\Component\HttpFoundation\Request;

final class UserController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private readonly ProfileResponder $responder,
    ) {
        parent::__construct($context);
    }

    /** GET /me — authenticated principal's account + nested profile. */
    #[ApiOperation(
        summary: 'Get Current User',
        description: 'Returns the authenticated principal\'s account plus a nested `profile` object. '
            . 'Supports REST dot-path field selection via `?fields=` (e.g. `?fields=id,email`, '
            . '`?fields=email,profile.first_name`); unknown/disallowed fields are pruned. Exposable '
            . 'columns are config-driven (`config/users.php`, `me` audience); `password`/`deleted_at` '
            . 'are never exposed.',
        tags: ['Users'],
    )]
    #[ApiResponse(200, description: 'Current user account and profile')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(404, description: 'User not found')]
    public function me(Request $request): Response
    {
        $uuid = $this->currentUser?->uuid();
        if ($uuid === null) {
            return $this->unauthorized('Authentication required');
        }
        $payload = $this->responder->build($uuid, 'me', $request);
        if ($payload === null) {
            return $this->notFound('User not found');
        }
        return $this->success($payload);
    }

    /** GET /users/{uuid} — another user's account + public profile. */
    #[ApiOperation(
        summary: 'Get User by UUID',
        description: 'Returns another user\'s account plus their public `profile`. Off by default — '
            . 'enabled via `USERS_USER_LOOKUP_ENABLED=true` (or `config/users.php`) — and requires the '
            . '`users.view` permission. Supports REST dot-path field selection via `?fields=`; unknown/'
            . 'disallowed fields are pruned. Exposable columns are config-driven (`config/users.php`, '
            . '`users` audience), which is intentionally narrower than the `me` audience.',
        tags: ['Users'],
    )]
    #[ApiResponse(200, description: 'User account and public profile')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Missing the users.view permission')]
    #[ApiResponse(404, description: 'User not found')]
    #[RequiresPermission('users.view')]
    public function show(string $uuid, Request $request): Response
    {
        $payload = $this->responder->build($uuid, 'users', $request);
        if ($payload === null) {
            return $this->notFound('User not found');
        }
        return $this->success($payload);
    }

    /** GET /users — paginated list of users + nested public profile. */
    #[ApiOperation(
        summary: 'List Users',
        description: 'Paginated list of users + nested public profile (the `users` audience). Off by '
            . 'default; enabled via `USERS_USER_LIST_ENABLED=true`. Requires the `users.view` permission. '
            . 'Supports `?page`/`?per_page` (clamped), per-item `?fields=`, and `?filter[...]`/`?sort`/'
            . '`?search` over username + profile name (email only when `allow_email_filter`). Soft-deleted '
            . 'profiles never affect membership or order.',
        tags: ['Users'],
    )]
    #[QueryParam('page', 'integer', description: 'Page number for pagination (default: 1)')]
    #[QueryParam('per_page', 'integer', description: 'Items per page (clamped to configured max)')]
    #[ApiResponse(200, description: 'Paginated users')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Missing the users.view permission')]
    #[RequiresPermission('users.view')]
    public function index(Request $request): Response
    {
        $ctx = $this->getContext();
        $defaultPer = (int) config($ctx, 'users.user_lookup.list.per_page.default', 25);
        $maxPer = (int) config($ctx, 'users.user_lookup.list.per_page.max', 100);

        $q = $request->query->all();
        $page = (isset($q['page']) && is_numeric($q['page'])) ? max(1, (int) $q['page']) : 1;
        $perPage = (isset($q['per_page']) && is_numeric($q['per_page'])) ? (int) $q['per_page'] : $defaultPer;
        $perPage = max(1, min($perPage, $maxPer));

        // Flat pagination envelope (matches Aegis /rbac/roles and the framework's paginate() shape):
        // `data` holds the rows and the pagination meta is hoisted to the response root.
        $result = $this->responder->buildList('users', $request, $page, $perPage);
        return Response::successWithMeta($result['data'], $result, 'Users retrieved successfully');
    }
}
