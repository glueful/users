<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\TwoFactor;

use Glueful\Auth\JWTService;
use Glueful\Auth\LoginResponseShaper;
use Glueful\Auth\TokenManager;
use Glueful\Auth\TwoFactor\ChallengeTokenIssuer;
use Glueful\Auth\TwoFactor\JtiBlocklist;
use Glueful\Cache\Drivers\ArrayCacheDriver;
use Glueful\Extensions\Users\Controllers\TwoFactorController;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Glueful\Extensions\Users\TwoFactor\TwoFactorService;
use Glueful\Notifications\Services\NotificationService;
use Symfony\Component\HttpFoundation\Request;
use Symfony\Component\HttpFoundation\Response;

/**
 * A cookie-transport login (`auth_transport: "cookie"`) that hits the 2FA gate is finished by
 * /2fa/verify, which always answered with tokens in the body and set no cookies. It now takes
 * the same `auth_transport` field and, while the transport is enabled, sets the session cookies.
 */
final class TwoFactorVerifyCookieTransportTest extends AppTestCase
{
    private ?string $pin = null;

    protected function setUp(): void
    {
        if (!method_exists(LoginResponseShaper::class, 'shapeForCookies')) {
            self::markTestSkipped('Cookie-transport logins need glueful/framework 1.91+.');
        }
    }

    private function verify(bool $transportEnabled, ?string $transport): Response
    {
        $this->bootApp([
            'auth.php' => "['session_cookie' => ['enabled' => " . ($transportEnabled ? 'true' : 'false')
                . ", 'secure' => false]]",
        ]);
        $uuid = $this->seedUser('u-2fa', 'twofa@example.com');
        $this->db()->table('users')->where('uuid', '=', $uuid)->update(['two_factor_enabled' => 1]);

        $service = $this->service();
        $challenge = $service->beginLogin(['uuid' => $uuid, 'email' => 'twofa@example.com']);

        $body = ['challenge_token' => $challenge['token'], 'code' => (string) $this->pin];
        if ($transport !== null) {
            $body['auth_transport'] = $transport;
        }
        $request = Request::create('/2fa/verify', 'POST', [], [], [], [
            'CONTENT_TYPE' => 'application/json',
        ], (string) json_encode($body));

        $controller = new TwoFactorController(
            $this->context,
            $service,
            container($this->context)->get(LoginResponseShaper::class)
        );

        return $controller->verify($request);
    }

    private function service(): TwoFactorService
    {
        $cache = new ArrayCacheDriver();
        $blocklist = new JtiBlocklist($cache);

        $notifications = $this->createMock(NotificationService::class);
        $notifications->method('send')->willReturnCallback(
            function (string $type, $notifiable, string $subject, array $data = []): array {
                $this->pin = (string) ($data['pin'] ?? '');
                return ['status' => 'sent'];
            }
        );

        $tokens = $this->createMock(TokenManager::class);
        $tokens->method('createUserSession')->willReturnCallback(
            static fn(array $user): array => [
                'access_token' => JWTService::generate(['sub' => $user['uuid'], 'sid' => 'sid-1', 'ver' => 1], 900),
                'refresh_token' => 'refresh-sid-1',
                'expires_in' => 900,
                'token_type' => 'Bearer',
                'session_uuid' => 'sid-1',
                'user' => $user,
            ]
        );

        return new TwoFactorService(
            $this->context,
            $this->db(),
            $cache,
            $notifications,
            new ChallengeTokenIssuer($blocklist, 300),
            $blocklist,
            $tokens,
            masterEnabled: true,
        );
    }

    /** @return array<string, string> cookie name => value */
    private function cookies(Response $response): array
    {
        $cookies = [];
        foreach ($response->headers->getCookies() as $cookie) {
            $cookies[$cookie->getName()] = (string) $cookie->getValue();
        }
        return $cookies;
    }

    /** @return array<string, mixed> */
    private function data(Response $response): array
    {
        $decoded = json_decode((string) $response->getContent(), true);
        self::assertIsArray($decoded);
        return is_array($decoded['data'] ?? null) ? $decoded['data'] : [];
    }

    public function testCookieTransportCompletesTheLoginWithSessionCookies(): void
    {
        $response = $this->verify(transportEnabled: true, transport: 'cookie');

        $cookies = $this->cookies($response);
        self::assertArrayHasKey('gf_session', $cookies);
        self::assertSame('refresh-sid-1', $cookies['gf_refresh'] ?? null);

        $data = $this->data($response);
        self::assertArrayNotHasKey('access_token', $data);
        self::assertArrayNotHasKey('refresh_token', $data);
        self::assertSame('u-2fa', $data['user']['uuid'] ?? null);
    }

    public function testWithoutTheFieldVerifyKeepsTheBearerResponse(): void
    {
        $response = $this->verify(transportEnabled: true, transport: null);

        self::assertSame([], $this->cookies($response));
        self::assertSame('refresh-sid-1', $this->data($response)['refresh_token'] ?? null);
    }

    public function testCookieRequestIsIgnoredWhileTheTransportIsDisabled(): void
    {
        $response = $this->verify(transportEnabled: false, transport: 'cookie');

        self::assertSame([], $this->cookies($response));
        self::assertArrayHasKey('access_token', $this->data($response));
    }
}
