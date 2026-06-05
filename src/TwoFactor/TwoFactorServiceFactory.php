<?php

declare(strict_types=1);

namespace Glueful\Extensions\Users\TwoFactor;

use Psr\Container\ContainerInterface;
use Glueful\Bootstrap\ApplicationContext;
use Glueful\Cache\CacheStore;
use Glueful\Notifications\Services\NotificationService;
use Glueful\Auth\TwoFactor\ChallengeTokenIssuer;
use Glueful\Auth\TwoFactor\JtiBlocklist;
use Glueful\Auth\TokenManager;

/**
 * Builds TwoFactorService from the container + config. A static factory (not a closure) so it is
 * production-safe in the services() DSL. Token-mechanic deps (ChallengeTokenIssuer, JtiBlocklist)
 * stay in core and are resolved across the package boundary.
 */
final class TwoFactorServiceFactory
{
    public static function create(ContainerInterface $c): TwoFactorService
    {
        $context = $c->get(ApplicationContext::class);
        $cfg = static fn(string $key, mixed $default): mixed =>
            function_exists('config') ? config($context, $key, $default) : $default;

        return new TwoFactorService(
            $context,
            $c->get('database'),
            $c->get(CacheStore::class),
            $c->get(NotificationService::class),
            $c->get(ChallengeTokenIssuer::class),
            $c->get(JtiBlocklist::class),
            $c->get(TokenManager::class),
            (int) $cfg('auth.two_factor.pin_length', 6),
            (int) $cfg('auth.two_factor.pin_ttl', 300),
            (int) $cfg('auth.two_factor.disable_freshness', 300),
            (string) $cfg('auth.two_factor.template_name', 'two-factor-pin'),
            (bool) $cfg('auth.two_factor.enabled', false)
        );
    }
}
