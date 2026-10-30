<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Http\DTOs;

/**
 * Documentation-only request body for POST /auth/verify-otp. Reflected for the
 * OpenAPI document; the handler reads the raw request.
 */
final class VerifyOtpInputData
{
    /** The email the code was sent to. */
    public string $email = '';

    /** The one-time code from the email. */
    public string $otp = '';

    /** "password_reset" to exchange the code for a reset_token; omit for email verification. */
    public ?string $purpose = null;
}
