<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Controllers;

use Glueful\Controllers\BaseController;
use Glueful\Auth\LoginResponseShaper;
use Glueful\Auth\TwoFactor\Exceptions\InvalidChallengeTokenException;
use Glueful\Auth\TwoFactor\Exceptions\InvalidTwoFactorCodeException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorNotEnabledException;
use Glueful\Auth\TwoFactor\Exceptions\TwoFactorReelevationRequiredException;
use Glueful\Extensions\Users\TwoFactor\TwoFactorService;
use Glueful\Extensions\Users\Http\DTOs\TwoFactorChallengeData;
use Glueful\Auth\JWTService;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Helpers\RequestHelper;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Glueful\Http\Response;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request;

/**
 * Email-PIN 2FA endpoints: enable, verify, disable.
 *
 * - enable  (auth required): emails a PIN + returns a challenge_token.
 * - verify  (no auth — challenge_token authenticates): completes enrollment
 *           or completes login (full shaped login response).
 * - disable (auth required + recent 2FA verification on the current session).
 *
 * All actions return the framework Response envelope (Glueful\Http\Response).
 */
final class TwoFactorController extends BaseController
{
    public function __construct(
        ApplicationContext $context,
        private TwoFactorService $twoFactor,
        private LoginResponseShaper $loginResponseShaper,
    ) {
        parent::__construct($context);
    }

    #[ApiOperation(
        summary: 'Enable Two-Factor Authentication',
        description: 'Begins 2FA enrollment for the authenticated user: emails a 6-digit PIN and '
            . 'returns a short-lived challenge_token. Submit both to POST /2fa/verify to complete '
            . 'enrollment.',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, TwoFactorChallengeData::class, description: 'Two-factor code sent')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(429, description: 'Too many requests')]
    public function enable(Request $request): TwoFactorChallengeData
    {
        $userUuid = $this->userContext->getUserUuid();
        $user = $this->userContext->getUser();
        if ($userUuid === null || $user === null) {
            throw new AuthenticationException('Authentication required');
        }

        $challenge = $this->twoFactor->beginEnable($userUuid, (string) $user->email());

        return new TwoFactorChallengeData(
            challenge_token: $challenge['token'],
            expires_in: $challenge['expires_in'],
            delivered_to: $challenge['delivered_to'],
        );
    }

    #[ApiOperation(
        summary: 'Verify Two-Factor Code',
        description: 'Verifies the emailed PIN against a challenge_token. No auth header is required — '
            . 'the challenge_token authenticates the request. For a login challenge it completes login '
            . 'and returns the full session (identical to POST /auth/login); for an enrollment challenge '
            . 'it returns just {success, message}. Body: `challenge_token` (required), `code` (required, '
            . '6-digit PIN).',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, description: 'Verification successful')]
    #[ApiResponse(401, description: 'Invalid or expired verification')]
    #[ApiResponse(429, description: 'Too many requests')]
    public function verify(Request $request): Response
    {
        $payload = RequestHelper::getRequestData($request);
        $token = (string) ($payload['challenge_token'] ?? '');
        $code = (string) ($payload['code'] ?? '');

        try {
            $result = $this->twoFactor->verify($token, $code);
        } catch (
            InvalidChallengeTokenException
            | InvalidTwoFactorCodeException
            | TwoFactorNotEnabledException $e
        ) {
            // Internal exceptions distinguish failure modes for logging/tests.
            // On the wire, collapse to a single 401 when generic errors are on (default).
            if ((bool) config($this->getContext(), 'security.auth.generic_error_responses', true)) {
                throw new AuthenticationException('Invalid or expired verification');
            }
            throw $e;
        }

        if (($result['purpose'] ?? null) === 'login') {
            /** @var array<string, mixed> $session */
            $session = $result['session'] ?? [];
            return $this->loginResponseShaper->shape($request, $session);
        }

        // 'enabled' purpose → enrollment complete.
        return Response::success(null, 'Two-factor authentication enabled');
    }

    #[ApiOperation(
        summary: 'Disable Two-Factor Authentication',
        description: 'Disables 2FA for the authenticated user. Requires a recent 2FA verification on '
            . 'the current session (within the configured freshness window); otherwise re-elevation '
            . 'is required.',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, description: 'Two-factor authentication disabled')]
    #[ApiResponse(401, description: 'Authentication required')]
    #[ApiResponse(403, description: 'Recent two-factor verification is required to perform this action')]
    #[ApiResponse(429, description: 'Too many requests')]
    public function disable(Request $request): Response
    {
        $userUuid = $this->userContext->getUserUuid();
        if ($userUuid === null) {
            throw new AuthenticationException('Authentication required');
        }

        // Freshness lives in a session-scoped cache marker keyed by the issued
        // session's sid (TokenManager hard-codes the access-token payload to
        // sub/sid/ver, so a freshness claim can't ride on the token). Reading by
        // sid — not user_uuid — blocks a stolen token from a different session.
        $sid = $this->currentSessionId();
        if ($sid === '' || !$this->twoFactor->hasFreshVerification($sid)) {
            throw new TwoFactorReelevationRequiredException();
        }

        $this->twoFactor->disable($userUuid, $sid);
        return Response::success(null, 'Two-factor authentication disabled');
    }

    /**
     * Pull the `sid` claim out of the current request's access token. The token is
     * taken from RequestUserContext (which already extracted the bearer token during
     * initialize()) and decoded; the `sid` matches the freshness marker written by
     * TwoFactorService::verify().
     */
    private function currentSessionId(): string
    {
        $token = $this->userContext->getToken();
        if ($token === null || $token === '') {
            return '';
        }
        $claims = JWTService::decode($token);
        if (!is_array($claims)) {
            return '';
        }
        return (string) ($claims['sid'] ?? '');
    }
}
