<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Feature;

use Glueful\Database\Migrations\MigrationManager;
use Glueful\Extensions\Users\Repositories\UserRepository;
use Glueful\Extensions\Users\Services\EmailVerification;
use Glueful\Extensions\Users\Tests\Support\AppTestCase;
use Glueful\Notifications\Contracts\Notifiable;
use Glueful\Notifications\Contracts\NotificationChannel;
use Glueful\Notifications\Services\NotificationDispatcher;

/** The password-reset mail can be sent through a caller's own template. */
final class PasswordResetTemplateTest extends AppTestCase
{
    /** @var list<array<string,mixed>> */
    private array $sent = [];

    protected function setUp(): void
    {
        parent::setUp();
        $this->bootApp();
        // The notification pipeline records every send; the users migrations alone lack its tables.
        (new MigrationManager(
            dirname(__DIR__, 2) . '/vendor/glueful/framework/migrations/notifications',
            null,
            $this->context,
        ))->migrate();
        new UserRepository($this->db(), null, $this->context);
        $this->db()->table('users')->insert([
            'uuid' => 'u-tpl',
            'username' => 'template',
            'email' => 'template@example.com',
            'password' => 'hash',
            'status' => 'active',
            'two_factor_enabled' => 0,
        ]);

        $sent = &$this->sent;
        $channel = new class ($sent) implements NotificationChannel {
            /** @param list<array<string,mixed>> $sent */
            public function __construct(private array &$sent)
            {
            }

            public function getChannelName(): string
            {
                return 'email';
            }

            public function send(Notifiable $notifiable, array $data): bool
            {
                $this->sent[] = $data;
                return true;
            }

            public function format(array $data, Notifiable $notifiable): array
            {
                return $data;
            }

            public function isAvailable(): bool
            {
                return true;
            }

            public function getConfig(): array
            {
                return [];
            }
        };
        container($this->context)->get(NotificationDispatcher::class)->getChannelManager()->replaceChannel($channel);
    }

    public function testTheResetMailUsesTheBuiltInTemplateByDefault(): void
    {
        EmailVerification::sendPasswordResetEmail('template@example.com', $this->context);

        self::assertSame('password-reset', $this->lastTemplate());
    }

    public function testTheResetMailUsesTheNamedTemplate(): void
    {
        EmailVerification::sendPasswordResetEmail('template@example.com', $this->context, 'account.password_reset');

        self::assertSame('account.password_reset', $this->lastTemplate());
    }

    private function lastTemplate(): ?string
    {
        self::assertNotSame([], $this->sent, 'no mail reached the email channel');
        $data = $this->sent[count($this->sent) - 1];

        return $data['template_name'] ?? ($data['data']['template_name'] ?? null);
    }
}
