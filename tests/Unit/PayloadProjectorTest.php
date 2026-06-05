<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\Tests\Unit;

use Glueful\Extensions\Users\Support\PayloadProjector;
use PHPUnit\Framework\TestCase;

final class PayloadProjectorTest extends TestCase
{
    private PayloadProjector $p;
    /** @var array<string,mixed> */
    private array $merged;
    /** @var list<string> */
    private array $allow = ['id', 'email', 'uuid', 'profile.first_name', 'profile.photo_url'];

    protected function setUp(): void
    {
        $this->p = new PayloadProjector();
        $this->merged = [
            'id' => 1,
            'uuid' => 'u-1',
            'email' => 'jane@example.com',
            'profile' => ['first_name' => 'Jane', 'photo_url' => 'p.png'],
        ];
    }

    public function test_null_fields_returns_full_default(): void
    {
        self::assertSame($this->merged, $this->p->project($this->merged, $this->allow, null));
    }

    public function test_empty_fields_string_returns_full_default(): void
    {
        self::assertSame($this->merged, $this->p->project($this->merged, $this->allow, ''));
    }

    public function test_selects_root_scalars(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'id,email');
        self::assertSame(['id' => 1, 'email' => 'jane@example.com'], $out);
    }

    public function test_selects_nested_child(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'email,profile.first_name');
        self::assertSame(['email' => 'jane@example.com', 'profile' => ['first_name' => 'Jane']], $out);
    }

    public function test_bare_profile_returns_whole_profile(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'profile');
        self::assertSame(['profile' => ['first_name' => 'Jane', 'photo_url' => 'p.png']], $out);
    }

    public function test_disallowed_root_is_pruned_to_empty(): void
    {
        self::assertSame([], $this->p->project($this->merged, $this->allow, 'password'));
        self::assertSame([], $this->p->project($this->merged, $this->allow, 'bogus'));
    }

    public function test_disallowed_nested_child_is_pruned(): void
    {
        // 'profile.phone' not in allow → pruned (empty), NOT full payload.
        self::assertSame([], $this->p->project($this->merged, $this->allow, 'profile.phone'));
    }

    public function test_mixed_allowed_and_disallowed_keeps_only_allowed(): void
    {
        $out = $this->p->project($this->merged, $this->allow, 'email,password,profile.first_name,profile.phone');
        self::assertSame(['email' => 'jane@example.com', 'profile' => ['first_name' => 'Jane']], $out);
    }

    public function test_null_profile_with_nested_request_yields_no_profile_key(): void
    {
        $merged = ['uuid' => 'u-1', 'profile' => null];
        self::assertSame([], $this->p->project($merged, $this->allow, 'profile.first_name'));
    }
}
