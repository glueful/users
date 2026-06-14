<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Http\DTOs;

use Glueful\Http\Contracts\HasResponseMessage;
use Glueful\Http\Contracts\ResponseData;

/**
 * Response payload for the OTP-dispatch endpoints (verify-email, resend-otp,
 * forgot-password): the target email plus the code's lifetime in seconds.
 *
 * The envelope message differs per endpoint, so it is carried per-instance via
 * HasResponseMessage (private — excluded from the serialized `data`). Byte-identical
 * to the prior Response::success(['email' => ..., 'expires_in' => ...], $message).
 */
final class OtpDispatchData implements ResponseData, HasResponseMessage
{
    public function __construct(
        public readonly string $email,
        public readonly int $expires_in,
        private readonly string $message,
    ) {
    }

    public function responseMessage(): string
    {
        return $this->message;
    }
}
