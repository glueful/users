<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Http\DTOs;

/**
 * Documentation-only request body for POST /auth/reset-password. Reflected for
 * the OpenAPI document; the handler reads the raw request.
 */
final class ResetPasswordInputData
{
    /** The single-use token returned by verify-otp with purpose=password_reset. */
    public string $reset_token = '';

    /** The new password. */
    public string $password = '';
}
