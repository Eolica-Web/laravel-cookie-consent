<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\CookiePolicyProvider;

covers(CookiePolicyProvider::class);

it('registers its policy in the manager when booted', function (): void {
    app()->register(new class(app()) extends CookiePolicyProvider {
        public function policy(CookiePolicy $policy): CookiePolicy
        {
            return $policy->id('shop')->domain('tienda.test');
        }
    });

    expect(array_keys(app(CookieConsentManager::class)->getPolicies()))->toBe(['shop']);
});
