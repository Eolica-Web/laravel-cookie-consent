<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Illuminate\Support\ServiceProvider;

abstract class CookiePolicyProvider extends ServiceProvider
{
    abstract public function policy(CookiePolicy $policy): CookiePolicy;

    public function boot(): void
    {
        $this->app->make(CookieConsentManager::class)->policy($this->policy(...));
    }
}
