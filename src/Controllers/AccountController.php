<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Controllers;

use Glueful\Bootstrap\ApplicationContext;
use Glueful\Auth\Interfaces\SessionStoreInterface;
use Glueful\Http\Response;
use Glueful\Helpers\RequestHelper;
use Glueful\Auth\PasswordHasher;
use Glueful\Validation\ValidationException;
use Glueful\Http\Exceptions\Domain\AuthenticationException;
use Glueful\Extensions\Users\Services\EmailVerification;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Http\DTOs\OtpDispatchData;
use Glueful\Routing\Attributes\ApiOperation;
use Glueful\Routing\Attributes\ApiResponse;
use Symfony\Component\HttpFoundation\Request as SymfonyRequest;

/**
 * Account lifecycle endpoints (email verification + password recovery), extracted from the core
 * AuthController. Owns user-store writes (existence checks, password reset) via the Users
 * extension's UserRepository — core authentication does not.
 */
final class AccountController
{
    private EmailVerification $verifier;
    private UserRepository $users;
    private PasswordHasher $passwordHasher;
    private ApplicationContext $context;

    public function __construct(ApplicationContext $context)
    {
        $this->context = $context;
        $this->verifier = new EmailVerification(context: $this->context);
        $this->users = new UserRepository();
        $this->passwordHasher = new PasswordHasher();
    }

    /** Verify email for registration/password reset. */
    #[ApiOperation(
        summary: 'Verify Email',
        description: 'Sends a verification code to the provided email. Body: `email` (required).',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, OtpDispatchData::class, description: 'Verification code has been sent to your email')]
    #[ApiResponse(400, description: 'Invalid email address')]
    #[ApiResponse(404, description: 'Email not found')]
    public function verifyEmail(SymfonyRequest $request): OtpDispatchData
    {
        $postData = RequestHelper::getRequestData($request);
        if (!isset($postData['email'])) {
            throw ValidationException::forField('email', 'Email address is required');
        }

        $otp = $this->verifier->generateOTP();
        $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);
        if (!$result['success']) {
            throw ValidationException::forField('email', $result['message'] ?? 'Failed to send verification email');
        }

        return new OtpDispatchData(
            email: $postData['email'],
            expires_in: EmailVerification::OTP_EXPIRY_MINUTES * 60,
            message: 'Verification code has been sent to your email',
        );
    }

    /** Verify OTP code. @return mixed */
    #[ApiOperation(
        summary: 'Verify OTP',
        description: 'Verifies the one-time password (OTP) sent to a user\'s email. When '
            . 'purpose=password_reset, returns a short-lived reset_token to submit to '
            . 'POST /auth/reset-password. Body: `email` (required), `otp` (required), '
            . '`purpose` (optional; use password_reset for the reset flow).',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, description: 'OTP verified successfully')]
    #[ApiResponse(400, description: 'Invalid OTP')]
    #[ApiResponse(401, description: 'OTP expired')]
    public function verifyOtp(SymfonyRequest $request)
    {
        $postData = RequestHelper::getRequestData($request);
        if (!isset($postData['email']) || !isset($postData['otp'])) {
            throw ValidationException::forFields(['email' => 'Email is required', 'otp' => 'OTP is required']);
        }

        if (($postData['purpose'] ?? '') === 'password_reset') {
            $reset = $this->verifier->verifyPasswordResetOTP($postData['email'], $postData['otp']);
            if ($reset === null) {
                throw ValidationException::forField('otp', 'Invalid or expired OTP');
            }

            return Response::success([
                'email' => $postData['email'],
                'purpose' => 'password_reset',
                'reset_token' => $reset['reset_token'],
                'expires_in' => $reset['expires_in'],
            ], 'OTP verified successfully');
        }

        if (!$this->verifier->verifyOTP($postData['email'], $postData['otp'])) {
            throw ValidationException::forField('otp', 'Invalid or expired OTP');
        }

        return Response::success([
            'email' => $postData['email'],
            'verified' => true,
            'verified_at' => date('Y-m-d\TH:i:s\Z')
        ], 'OTP verified successfully');
    }

    /** Resend OTP code. */
    #[ApiOperation(
        summary: 'Resend OTP',
        description: 'Resends the one-time password (OTP) to the user\'s email. Body: `email` (required).',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, OtpDispatchData::class, description: 'OTP resent successfully')]
    #[ApiResponse(400, description: 'Invalid email address')]
    #[ApiResponse(404, description: 'Email not found')]
    public function resendOtp(SymfonyRequest $request): OtpDispatchData
    {
        $postData = RequestHelper::getRequestData($request);
        if (!isset($postData['email'])) {
            throw ValidationException::forField('email', 'Email address is required');
        }

        $otp = $this->verifier->generateOTP();
        $result = $this->verifier->sendVerificationEmail($postData['email'], $otp);
        if (!$result['success']) {
            throw ValidationException::forField('email', $result['message'] ?? 'Failed to send verification email');
        }

        return new OtpDispatchData(
            email: $postData['email'],
            expires_in: EmailVerification::OTP_EXPIRY_MINUTES * 60,
            message: 'Verification code has been resent to your email',
        );
    }

    /** Initiate password reset. */
    #[ApiOperation(
        summary: 'Forgot Password',
        description: 'Initiates the password reset process by sending a reset code. Body: `email` (required).',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, OtpDispatchData::class, description: 'Password reset instructions sent to email')]
    #[ApiResponse(400, description: 'Invalid email format')]
    #[ApiResponse(404, description: 'Email not found')]
    public function forgotPassword(SymfonyRequest $request): OtpDispatchData
    {
        $postData = RequestHelper::getRequestData($request);
        if (!isset($postData['email'])) {
            throw ValidationException::forField('email', 'Email address is required');
        }

        if (!$this->userExists($postData['email'])) {
            if ((bool) config($this->context, 'security.auth.generic_error_responses', true)) {
                return new OtpDispatchData(
                    email: $postData['email'],
                    expires_in: EmailVerification::OTP_EXPIRY_MINUTES * 60,
                    message: 'Password reset instructions have been sent to your email',
                );
            }
            throw ValidationException::forField('email', 'User not found with the provided email address');
        }

        $result = EmailVerification::sendPasswordResetEmail($postData['email'], $this->context);
        if (!$result['success']) {
            throw ValidationException::forField('email', $result['message'] ?? 'Failed to send reset email');
        }

        return new OtpDispatchData(
            email: $postData['email'],
            expires_in: EmailVerification::OTP_EXPIRY_MINUTES * 60,
            message: 'Password reset instructions have been sent to your email',
        );
    }

    /** Complete password reset. */
    #[ApiOperation(
        summary: 'Reset Password',
        description: 'Resets the user\'s password using the single-use reset_token returned by '
            . 'POST /auth/verify-otp with purpose=password_reset. Body: `reset_token` (required), '
            . '`password` (required).',
        tags: ['Authentication'],
    )]
    #[ApiResponse(200, description: 'Password has been reset successfully')]
    #[ApiResponse(400, description: 'Invalid password format')]
    #[ApiResponse(404, description: 'Email not found')]
    public function resetPassword(SymfonyRequest $request): Response
    {
        $postData = RequestHelper::getRequestData($request);
        if (!isset($postData['reset_token']) || !isset($postData['password'])) {
            throw ValidationException::forFields([
                'reset_token' => 'Reset token is required',
                'password' => 'New password is required',
            ]);
        }

        $reset = $this->verifier->consumePasswordResetToken((string) $postData['reset_token']);
        if ($reset === null) {
            throw ValidationException::forField('reset_token', 'Invalid or expired reset token');
        }

        $success = $this->users->setNewPassword(
            $reset['user_uuid'],
            $this->passwordHasher->hash($postData['password']),
            'uuid'
        );
        if (!$success) {
            throw new AuthenticationException('Failed to update password');
        }
        $this->revokeUserSessions($reset['user_uuid']);

        return Response::success([
            'updated_at' => date('Y-m-d\TH:i:s\Z')
        ], 'Password has been reset successfully');
    }

    private function userExists(string $email): bool
    {
        return is_array($this->users->findByEmail($email));
    }

    private function revokeUserSessions(string $userUuid): void
    {
        try {
            if (!$this->context->hasContainer()) {
                error_log('Skipped session revocation after password reset: container unavailable');
                return;
            }

            $container = $this->context->getContainer();
            if (!$container->has(SessionStoreInterface::class)) {
                error_log('Skipped session revocation after password reset: SessionStoreInterface is not bound');
                return;
            }

            /** @var SessionStoreInterface $sessionStore */
            $sessionStore = $container->get(SessionStoreInterface::class);
            $sessionStore->revokeAllForUser($userUuid);
        } catch (\Throwable $e) {
            error_log('Failed to revoke sessions after password reset: ' . $e->getMessage());
        }
    }
}
