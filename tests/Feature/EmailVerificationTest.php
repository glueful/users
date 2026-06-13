<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Services\EmailVerification;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Glueful\Security\OTP;

final class EmailVerificationTest extends AppTestCase
{
    private string $storagePath;

    protected function setUp(): void
    {
        parent::setUp();
        $this->storagePath = sys_get_temp_dir() . '/glueful-users-otp-' . uniqid('', true);
        $this->bootApp([
            'app.php' => "['name'=>'T','env'=>'testing','paths'=>['storage_path'=>'{$this->storagePath}']]",
        ]);
        new UserRepository($this->db(), null, $this->context);
        $this->seedUser('u-otp', 'jane@example.com', 'jane');
    }

    public function testVerifyOtpReadsAndConsumesFileFallback(): void
    {
        $key = 'email_verification:' . $this->cacheEmail('jane@example.com');
        $file = $this->storagePath . '/cache/' . md5($key) . '.tmp';
        mkdir(dirname($file), 0755, true);
        file_put_contents($file, json_encode([
            'otp' => OTP::hashOTP('123456'),
            'timestamp' => time(),
            'expiry' => time() + 900,
        ], JSON_THROW_ON_ERROR));

        $verifier = new EmailVerification(context: $this->context);

        self::assertTrue($verifier->verifyOTP('jane@example.com', '123456'));
        self::assertFileDoesNotExist($file);
    }

    private function cacheEmail(string $email): string
    {
        return str_replace(['/', '+', '='], ['_', '-', ''], base64_encode($email));
    }
}
