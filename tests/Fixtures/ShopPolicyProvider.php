<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Tests\Fixtures;

use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\CookiePolicyProvider;

final class ShopPolicyProvider extends CookiePolicyProvider
{
    public function policy(CookiePolicy $policy): CookiePolicy
    {
        return $policy->id('shop')->domain('tienda.test')->middleware('web', 'throttle:10,1');
    }
}
