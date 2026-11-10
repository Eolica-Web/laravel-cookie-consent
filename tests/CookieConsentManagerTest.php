<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Illuminate\Contracts\Encryption\Encrypter;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;

covers(CookieConsentManager::class);

it('has no policies by default', function (): void {
    expect(new CookieConsentManager()->getPolicies())->toBe([]);
});

it('registers the configured policies by id', function (): void {
    $manager = new CookieConsentManager();

    $manager->policy(fn(CookiePolicy $policy) => $policy->id('shop')->domain('tienda.test'));
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('blog'));

    $policies = $manager->getPolicies();

    expect(array_keys($policies))
        ->toBe(['shop', 'blog'])
        ->and($policies['shop']->getId())
        ->toBe('shop')
        ->and($policies['blog']->getId())
        ->toBe('blog')
        ->and($manager->getPolicy('shop'))
        ->toBe($policies['shop']);
});

it('fails to get a policy that is not registered', function (): void {
    expect(fn() => new CookieConsentManager()->getPolicy('shop'))
        ->toThrow(InvalidArgumentException::class, 'The cookie policy [shop] is not registered.');
});

it('fails to register two policies with the same id', function (): void {
    $manager = new CookieConsentManager();
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('shop')->domain('tienda.test'));

    expect(fn() => $manager->policy(fn(CookiePolicy $policy) => $policy->id('shop')->domain('shop.test')))
        ->toThrow(InvalidArgumentException::class, 'The cookie policy [shop] is already registered.');
});

it('fails to register two default policies', function (): void {
    $manager = new CookieConsentManager();
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('shop'));

    expect(fn() => $manager->policy(fn(CookiePolicy $policy) => $policy->id('blog')->domain('blog.test')->default()))
        ->toThrow(
            InvalidArgumentException::class,
            'The cookie policies [shop] and [blog] cannot both be the default one, give one of them a domain or a condition.',
        );
});

it('registers a default policy after policies with conditions', function (): void {
    $manager = new CookieConsentManager();
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('shop')->domain('tienda.test'));
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('blog'));

    expect($manager->resolvePolicy(Request::create('https://other.test'))?->getId())->toBe('blog');
});

it('resolves the first policy that matches the request', function (string $url, ?string $id): void {
    $manager = new CookieConsentManager();
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('shop')->domain('tienda.test'));
    $manager->policy(fn(CookiePolicy $policy) => $policy
        ->id('shop-cart')
        ->domain('tienda.test')
        ->when(fn() => true));
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('blog')->domain('blog.test'));

    expect($manager->resolvePolicy(Request::create($url))?->getId())->toBe($id);
})->with([
    ['https://tienda.test', 'shop'],
    ['https://blog.test',   'blog'],
    ['https://other.test',  null],
]);

it('resolves the matching policy before the default one', function (): void {
    $manager = new CookieConsentManager();
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('main'));
    $manager->policy(fn(CookiePolicy $policy) => $policy->id('shop')->domain('tienda.test'));

    expect($manager->resolvePolicy(Request::create('https://tienda.test'))?->getId())
        ->toBe('shop')
        ->and($manager->resolvePolicy(Request::create('https://other.test'))?->getId())
        ->toBe('main');
});

/**
 * @param list<string> $categories
 */
function requestWithConsent(string $url, CookiePolicy $policy, array $categories, ?string $revision = null): Request
{
    return Request::create($url, cookies: [
        $policy->getCookieName() => json_encode([
            'id' => '01JC0000000000000000000000',
            'policy' => $policy->getId(),
            'revision' => $revision ?? $policy->getRevision(),
            'categories' => $categories,
            'at' => CarbonImmutable::now()->getTimestamp(),
        ], JSON_THROW_ON_ERROR),
    ]);
}

function shopManager(): CookieConsentManager
{
    $manager = new CookieConsentManager();
    $manager->policy(fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->lifetime(CarbonInterval::days(10))
        ->cookie(name: 'shop_consent')
        ->analytics(fn() => null)
        ->marketing(fn() => null));

    return $manager;
}

it('excludes the consent cookies from encryption', function (): void {
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

    shopManager();

    expect(new EncryptCookies(app(Encrypter::class))->isDisabled('shop_consent'))->toBeTrue();
});

it('reads the valid consent of the request', function (): void {
    $manager = shopManager();

    $consent = $manager->getConsent(requestWithConsent(
        'https://tienda.test',
        $manager->getPolicy('shop'),
        ['analytics'],
    ));

    expect($consent?->policy)->toBe('shop')->and($consent?->categories)->toBe(['analytics']);
});

it('reads no consent without a policy, cookie or valid value', function (Request $request): void {
    expect(shopManager()->getConsent($request))->toBeNull();
})->with([
    'without policy' => fn() => Request::create('https://blog.test', cookies: ['shop_consent' => '{}']),
    'without cookie' => fn() => Request::create('https://tienda.test'),
    'invalid value' => fn() => Request::create('https://tienda.test', cookies: ['shop_consent' => '{']),
    'another revision' => fn() => requestWithConsent(
        'https://tienda.test',
        new CookiePolicy()->id('shop')->cookie(name: 'shop_consent'),
        ['analytics'],
    ),
]);

it('reads no consent once it expires', function (): void {
    $manager = shopManager();
    $request = requestWithConsent('https://tienda.test', $manager->getPolicy('shop'), ['analytics']);

    CarbonImmutable::setTestNow(CarbonImmutable::now()->addDays(10));

    expect($manager->getConsent($request))->toBeNull();

    CarbonImmutable::setTestNow();
});

it('allows the consented categories', function (): void {
    $manager = shopManager();
    $request = requestWithConsent('https://tienda.test', $manager->getPolicy('shop'), ['analytics']);

    expect($manager->allows($request, 'necessary'))
        ->toBeTrue()
        ->and($manager->allows($request, 'analytics'))
        ->toBeTrue()
        ->and($manager->allows($request, 'marketing'))
        ->toBeFalse();
});

it('allows only the necessary category without a valid consent', function (): void {
    $manager = shopManager();
    $request = Request::create('https://tienda.test');

    expect($manager->allows($request, 'necessary'))
        ->toBeTrue()
        ->and($manager->allows($request, 'analytics'))
        ->toBeFalse();
});

it('allows only the necessary category without a policy', function (): void {
    $manager = shopManager();
    $request = Request::create('https://blog.test');

    expect($manager->allows($request, 'necessary'))
        ->toBeTrue()
        ->and($manager->allows($request, 'analytics'))
        ->toBeFalse();
});

it('fails to check a category that is not defined in the policy', function (): void {
    expect(fn() => shopManager()->allows(Request::create('https://tienda.test'), 'preferences'))
        ->toThrow(
            InvalidArgumentException::class,
            'The category [preferences] is not defined in the cookie policy [shop].',
        );
});
