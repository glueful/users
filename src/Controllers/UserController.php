<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Controllers;

use Glueful\Auth\Attributes\RequiresPermission;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Controllers\BaseController;
use Glueful\Extensions\Users\Support\ProfileResponder;
use Glueful\Http\Response;
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
    #[RequiresPermission('users.read')]
    public function show(string $uuid, Request $request): Response
    {
        $payload = $this->responder->build($uuid, 'users', $request);
        if ($payload === null) {
            return $this->notFound('User not found');
        }
        return $this->success($payload);
    }

    /** GET /users — paginated list of users + nested public profile. */
    #[RequiresPermission('users.read')]
    public function index(Request $request): Response
    {
        $ctx = $this->getContext();
        $defaultPer = (int) config($ctx, 'users.user_lookup.list.per_page.default', 25);
        $maxPer = (int) config($ctx, 'users.user_lookup.list.per_page.max', 100);

        $q = $request->query->all();
        $page = (isset($q['page']) && is_numeric($q['page'])) ? max(1, (int) $q['page']) : 1;
        $perPage = (isset($q['per_page']) && is_numeric($q['per_page'])) ? (int) $q['per_page'] : $defaultPer;
        $perPage = max(1, min($perPage, $maxPer));

        return $this->success($this->responder->buildList('users', $request, $page, $perPage));
    }
}
