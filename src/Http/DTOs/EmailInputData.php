<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Http\DTOs;

/**
 * Documentation-only request body for the endpoints that take just an email:
 * verify-email, resend-otp and forgot-password. The handlers read the raw
 * request, so this class is reflected for the OpenAPI document and never hydrated.
 */
final class EmailInputData
{
    /** The account's email address. */
    public string $email = '';
}
