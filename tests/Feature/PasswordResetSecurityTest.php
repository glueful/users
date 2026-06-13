<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Auth\PasswordHasher;
use Glueful\Cache\CacheStore;
use Glueful\Extensions\Users\Controllers\AccountController;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Services\EmailVerification;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Glueful\Security\OTP;
use Glueful\Validation\ValidationException;
use Symfony\Component\HttpFoundation\Request;

final class PasswordResetSecurityTest extends AppTestCase
{
    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp([
            'security.php' => "['auth' => ['generic_error_responses' => false]]",
        ]);
        new UserRepository($this->db(), null, $this->context);
    }

    public function testResetPasswordRequiresResetTokenInsteadOfEmailOnly(): void
    {
        $this->seedUserWithPassword('u-reset', 'victim@example.com', 'victim', 'old-secret');

        $controller = new AccountController($this->context);

        $this->expectException(ValidationException::class);
        $controller->resetPassword($this->jsonRequest([
            'email' => 'victim@example.com',
            'password' => 'new-secret',
        ]));

        $row = $this->db()->table('users')->where('uuid', '=', 'u-reset')->first();
        self::assertIsArray($row);
        self::assertTrue((new PasswordHasher())->verify('old-secret', (string) $row['password']));
    }

    public function testVerifiedPasswordResetOtpReturnsSingleUseTokenThatCanResetPassword(): void
    {
        $this->seedUserWithPassword('u-reset', 'victim@example.com', 'victim', 'old-secret');
        $cache = $this->cache();
        $cache->set('email_verification:' . $this->cacheEmail('victim@example.com'), [
            'otp' => OTP::hashOTP('123456'),
            'timestamp' => time(),
        ], 900);

        $controller = new AccountController($this->context);
        $verifyResponse = $controller->verifyOtp($this->jsonRequest([
            'email' => 'victim@example.com',
            'otp' => '123456',
            'purpose' => 'password_reset',
        ]));
        $verifyPayload = json_decode((string) $verifyResponse->getContent(), true);

        self::assertIsArray($verifyPayload);
        self::assertNotEmpty($verifyPayload['data']['reset_token'] ?? null);
        self::assertSame(900, $verifyPayload['data']['expires_in'] ?? null);

        $resetResponse = $controller->resetPassword($this->jsonRequest([
            'reset_token' => $verifyPayload['data']['reset_token'],
            'password' => 'new-secret',
        ]));
        $resetPayload = json_decode((string) $resetResponse->getContent(), true);

        self::assertTrue($resetPayload['success'] ?? false);
        $row = $this->db()->table('users')->where('uuid', '=', 'u-reset')->first();
        self::assertIsArray($row);
        self::assertTrue((new PasswordHasher())->verify('new-secret', (string) $row['password']));

        $this->expectException(ValidationException::class);
        $controller->resetPassword($this->jsonRequest([
            'reset_token' => $verifyPayload['data']['reset_token'],
            'password' => 'another-secret',
        ]));
    }

    public function testPasswordResetRevokesActiveSessionsForUser(): void
    {
        $this->seedUserWithPassword('u-reset', 'victim@example.com', 'victim', 'old-secret');
        $this->createAuthSessionsTable();
        $this->db()->table('auth_sessions')->insert([
            'uuid' => 'sess-reset',
            'user_uuid' => 'u-reset',
            'expires_at' => date('Y-m-d H:i:s', time() + 3600),
            'status' => 'active',
            'provider' => 'jwt',
            'remember_me' => 0,
        ]);
        $resetToken = $this->issueResetToken('victim@example.com', '123456');

        $controller = new AccountController($this->context);
        $controller->resetPassword($this->jsonRequest([
            'reset_token' => $resetToken,
            'password' => 'new-secret',
        ]));

        $session = $this->db()->table('auth_sessions')->where('uuid', '=', 'sess-reset')->first();
        self::assertIsArray($session);
        self::assertSame('revoked', $session['status']);
        self::assertNotEmpty($session['revoked_at']);
    }

    /**
     * @param array<string, mixed> $payload
     */
    private function jsonRequest(array $payload): Request
    {
        return Request::create('/', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], json_encode($payload, JSON_THROW_ON_ERROR));
    }

    private function seedUserWithPassword(string $uuid, string $email, string $username, string $password): void
    {
        $this->db()->table('users')->insert([
            'uuid' => $uuid,
            'username' => $username,
            'email' => $email,
            'password' => (new PasswordHasher())->hash($password),
            'status' => 'active',
            'two_factor_enabled' => 0,
        ]);
    }

    private function issueResetToken(string $email, string $otp): string
    {
        $this->cache()->set('email_verification:' . $this->cacheEmail($email), [
            'otp' => OTP::hashOTP($otp),
            'timestamp' => time(),
        ], 900);

        $verifier = new EmailVerification(context: $this->context, cache: $this->cache());
        $reset = $verifier->verifyPasswordResetOTP($email, $otp);
        self::assertIsArray($reset);
        return $reset['reset_token'];
    }

    private function createAuthSessionsTable(): void
    {
        $this->db()->getPDO()->exec(
            'CREATE TABLE auth_sessions (
                id INTEGER PRIMARY KEY AUTOINCREMENT,
                uuid VARCHAR(12) UNIQUE,
                user_uuid VARCHAR(12),
                ip_address VARCHAR(45) NULL,
                user_agent TEXT NULL,
                last_seen_at TIMESTAMP NULL,
                expires_at TIMESTAMP NOT NULL,
                revoked_at TIMESTAMP NULL,
                session_version INTEGER DEFAULT 1,
                status VARCHAR(20) DEFAULT "active",
                created_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                updated_at TIMESTAMP DEFAULT CURRENT_TIMESTAMP,
                provider TEXT DEFAULT "jwt",
                remember_me INTEGER DEFAULT 0
            )'
        );
    }

    private function cache(): CacheStore
    {
        $cache = $this->app->getContainer()->get(CacheStore::class);
        self::assertInstanceOf(CacheStore::class, $cache);
        return $cache;
    }

    private function cacheEmail(string $email): string
    {
        return str_replace(['/', '+', '='], ['_', '-', ''], base64_encode($email));
    }
}
