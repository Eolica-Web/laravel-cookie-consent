<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\Cookie;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\CookiePolicyProvider;
use Illuminate\Container\Container;
use Illuminate\Foundation\Application;
use Illuminate\Http\Request;

covers(CookiePolicyProvider::class);

function shopPolicyProvider(Application $app, int &$calls = 0): CookiePolicyProvider
{
    return new class($app, $calls) extends CookiePolicyProvider {
        public function __construct(
            $app,
            private int &$calls,
        ) {
            parent::__construct($app);
        }

        public function policy(CookiePolicy $policy): CookiePolicy
        {
            $this->calls++;

            return $policy->id('shop')->domain('tienda.test');
        }
    };
}

it('registers its policy in the manager that is already resolved', function (): void {
    app(CookieConsentManager::class);
    app()->register(shopPolicyProvider(app()));

    expect(array_keys(app(CookieConsentManager::class)->getPolicies()))->toBe(['shop']);
});

it('registers its policy once the manager is resolved', function (): void {
    $calls = 0;
    $current = app();
    $app = new Application();
    Container::setInstance($current);
    $app->singleton(CookieConsentManager::class);

    shopPolicyProvider($app, $calls)->register();

    expect($calls)
        ->toBe(0)
        ->and(array_keys($app->make(CookieConsentManager::class)->getPolicies()))
        ->toBe(['shop'])
        ->and($calls)
        ->toBe(1);
});

it('registers the policy that it returns', function (): void {
    app()->register(new class(app()) extends CookiePolicyProvider {
        public function policy(CookiePolicy $policy): CookiePolicy
        {
            return $this->basePolicy()->id('shop');
        }

        private function basePolicy(): CookiePolicy
        {
            return new CookiePolicy()->domain('tienda.test');
        }
    });

    expect(
        app(CookieConsentManager::class)->getPolicy('shop')->matches(Request::create('https://tienda.test')),
    )->toBeTrue();
});

it('does not let the providers override how the policy is registered', function (): void {
    expect(new ReflectionMethod(CookiePolicyProvider::class, 'register')->isFinal())->toBeTrue();
});

it('declares the session and XSRF-TOKEN cookies of the application', function (
    mixed $lifetime,
    mixed $expireOnClose,
    ?CarbonInterval $sessionDuration,
    CarbonInterval $csrfDuration,
): void {
    config()->set('session.cookie', 'shop_session');
    config()->set('session.lifetime', $lifetime);
    config()->set('session.expire_on_close', $expireOnClose);

    app()->register(new class(app()) extends CookiePolicyProvider {
        public function policy(CookiePolicy $policy): CookiePolicy
        {
            return $policy->id('shop')->necessary(fn(Category $category) => $category->add(
                $this->sessionCookie(),
                $this->csrfCookie(),
            ));
        }
    });

    expect(app(CookieConsentManager::class)->getPolicy('shop')->getCategories()['necessary']->getCookies())->toEqual([
        new Cookie('cookie_consent_shop', 'cookie-consent::cookies.consent', duration: CarbonInterval::months(12)),
        new Cookie('shop_session', 'cookie-consent::cookies.session', duration: $sessionDuration),
        new Cookie('XSRF-TOKEN', 'cookie-consent::cookies.csrf', duration: $csrfDuration),
    ]);
})->with([
    'with the session lifetime' => [90, false, CarbonInterval::minutes(90), CarbonInterval::minutes(90)],
    'with a session lifetime from a string' => ['90', false, CarbonInterval::minutes(90), CarbonInterval::minutes(90)],
    'with the whole minutes of a decimal session lifetime' => [
        '90.5',
        false,
        CarbonInterval::minutes(90),
        CarbonInterval::minutes(90),
    ],
    'with the default lifetime when it is not a number' => [
        'abc',
        false,
        CarbonInterval::minutes(120),
        CarbonInterval::minutes(120),
    ],
    'without duration when the session expires on close' => [90, true, null, CarbonInterval::minutes(90)],
    'without duration when the session expires on close from a string' => [
        90,
        '1',
        null,
        CarbonInterval::minutes(90),
    ],
    'with the session lifetime when it does not expire on close from a string' => [
        90,
        '0',
        CarbonInterval::minutes(90),
        CarbonInterval::minutes(90),
    ],
]);
