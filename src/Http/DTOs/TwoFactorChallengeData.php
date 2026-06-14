<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Http\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for POST /2fa/enable — the emitted 2FA enrollment challenge.
 *
 * Byte-identical to the prior Response::success([...], 'Two-factor code sent').
 */
final class TwoFactorChallengeData implements ResponseData, HasResponseMessage
{
    public function __construct(
        public readonly string $challenge_token,
        public readonly int $expires_in,
        public readonly string $delivered_to,
        private readonly string $message = 'Two-factor code sent',
    ) {
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
