<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Carbon\CarbonInterval;
use Illuminate\Config\Repository;
use Illuminate\Support\ServiceProvider;

abstract class CookiePolicyProvider extends ServiceProvider
{
    abstract public function policy(CookiePolicy $policy): CookiePolicy;

    final public function register(): void
    {
        $this->callAfterResolving(CookieConsentManager::class, function (CookieConsentManager $manager): void {
            $manager->policy($this->policy(...));
        });
    }

    protected function sessionCookie(): Cookie
    {
        $config = $this->app->make(Repository::class);

        return new Cookie(
            name: $config->string('session.cookie'),
            purpose: 'cookie-consent::cookies.session',
            duration: $config->get('session.expire_on_close') ? null : $this->sessionLifetime($config),
        );
    }

    protected function csrfCookie(): Cookie
    {
        return new Cookie(
            name: 'XSRF-TOKEN',
            purpose: 'cookie-consent::cookies.csrf',
            duration: $this->sessionLifetime($this->app->make(Repository::class)),
        );
    }

    private function sessionLifetime(Repository $config): CarbonInterval
    {
        $lifetime = $config->get('session.lifetime');

        return CarbonInterval::minutes(is_numeric($lifetime) ? $lifetime : 120);
    }
}
