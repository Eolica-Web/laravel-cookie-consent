<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\Cookie;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\GoogleConsent;
use Illuminate\Http\Request;

covers(CookiePolicy::class);

it('has defaults', function (): void {
    $policy = new CookiePolicy();

    expect($policy->getId())
        ->toBe('default')
        ->and($policy->getVersion())
        ->toBeNull()
        ->and($policy->getLifetime())
        ->toEqual(CarbonInterval::months(12))
        ->and($policy->getCookieName())
        ->toBe('cookie_consent')
        ->and($policy->getCookieDomain())
        ->toBeNull()
        ->and(array_keys($policy->getCategories()))
        ->toBe(['necessary']);
});

it('rejects invalid ids', function (string $id): void {
    expect(fn() => new CookiePolicy()->id($id))
        ->toThrow(
            InvalidArgumentException::class,
            "The policy id [{$id}] must start with a lowercase letter and only contain lowercase letters, numbers, dashes and underscores.",
        );
})->with(['', 'Shop', '1shop', 'my.shop', "shop\n"]);

it('configures the policy', function (): void {
    $policy = new CookiePolicy()
        ->id('shop')
        ->version('2026-11-01')
        ->lifetime(CarbonInterval::months(6))
        ->cookie(name: 'shop_consent', domain: '.tienda.test');

    expect($policy->getId())
        ->toBe('shop')
        ->and($policy->getVersion())
        ->toBe('2026-11-01')
        ->and($policy->getLifetime())
        ->toEqual(CarbonInterval::months(6))
        ->and($policy->getCookieName())
        ->toBe('shop_consent')
        ->and($policy->getCookieDomain())
        ->toBe('.tienda.test');
});

it('names the consent cookie after the policy id', function (): void {
    expect(new CookiePolicy()->id('shop')->getCookieName())
        ->toBe('cookie_consent_shop')
        ->and(
            new CookiePolicy()
                ->cookie(name: 'shop_consent')
                ->id('shop')
                ->getCookieName(),
        )
        ->toBe('shop_consent');
});

it('keeps the cookie options that are not given', function (): void {
    $policy = new CookiePolicy()
        ->cookie(name: 'shop_consent', domain: '.tienda.test')
        ->cookie(name: 'other_consent')
        ->cookie(domain: '.other.test');

    expect($policy->getCookieName())->toBe('other_consent')->and($policy->getCookieDomain())->toBe('.other.test');
});

it('accepts cookie names with letters, numbers, dashes and underscores', function (): void {
    expect(
        new CookiePolicy()
            ->cookie(name: 'Shop-Consent_2')
            ->getCookieName(),
    )
        ->toBe('Shop-Consent_2');
});

it('rejects invalid cookie names', function (string $name): void {
    expect(fn() => new CookiePolicy()->cookie(name: $name))
        ->toThrow(
            InvalidArgumentException::class,
            "The consent cookie name [{$name}] must only contain letters, numbers, dashes and underscores.",
        );
})->with(['', 'shop.consent', 'shop consent', 'shop[consent]', 'shop;consent', "shop_consent\n"]);

it('keeps the categories in the order they were defined, with the necessary one first', function (): void {
    $policy = new CookiePolicy()
        ->marketing(fn() => null)
        ->category('chat', fn() => null)
        ->analytics(fn() => null)
        ->preferences(fn() => null)
        ->necessary(fn() => null);

    expect(array_keys($policy->getCategories()))
        ->toBe(['necessary', 'marketing', 'chat', 'analytics', 'preferences'])
        ->and(array_map(fn(Category $category): string => $category->getKey(), $policy->getCategories()))
        ->toBe(array_combine(array_keys($policy->getCategories()), array_keys($policy->getCategories())));
});

it('configures the same category every time', function (): void {
    $policy = new CookiePolicy()
        ->analytics(fn(Category $category) => $category->cookie(name: '_ga', purpose: 'Distinguishes users.'))
        ->category('analytics', fn(Category $category) => $category->label('Analítica'));

    $analytics = $policy->getCategories()['analytics'];

    expect($analytics->getLabel())
        ->toBe('Analítica')
        ->and($analytics->getCookies())
        ->toEqual([new Cookie('_ga', 'Distinguishes users.')]);
});

it('declares the consent cookie first in the necessary category', function (): void {
    $policy = new CookiePolicy()
        ->necessary(fn(Category $category) => $category->cookie(name: 'locale', purpose: 'Remembers the language.'))
        ->lifetime(CarbonInterval::months(6))
        ->cookie(name: 'shop_consent');

    expect($policy->getCategories()['necessary']->getCookies())->toEqual([
        new Cookie('shop_consent', 'cookie-consent::cookies.consent', duration: CarbonInterval::months(6)),
        new Cookie('locale', 'Remembers the language.'),
    ]);
});

it('does not add the consent cookie to the defined category', function (): void {
    $policy = new CookiePolicy();

    $policy->getCategories();

    expect($policy->getCategories()['necessary']->getCookies())->toHaveCount(1);
});

it('identifies the revision by the category keys', function (): void {
    $policy = new CookiePolicy()->analytics(fn() => null);

    expect($policy->getRevision())
        ->toBe(substr(hash(algo: 'xxh128', data: 'analytics,necessary'), offset: 0, length: 8));
});

it('prefixes the revision with the version', function (): void {
    $policy = new CookiePolicy()
        ->version('2026-11-01')
        ->analytics(fn() => null);

    expect($policy->getRevision())
        ->toBe('2026-11-01.' . substr(hash(algo: 'xxh128', data: 'analytics,necessary'), offset: 0, length: 8));
});

it('keeps the revision when the categories are reordered or their contents change', function (): void {
    $policy = new CookiePolicy()
        ->analytics(fn() => null)
        ->marketing(fn() => null);

    $reordered = new CookiePolicy()
        ->marketing(fn(Category $category) => $category->label('Publicidad'))
        ->analytics(fn(Category $category) => $category->cookie(name: '_ga', purpose: 'Distinguishes users.'));

    expect($reordered->getRevision())->toBe($policy->getRevision());
});

it('changes the revision when a category is added', function (): void {
    expect(
        new CookiePolicy()
            ->analytics(fn() => null)
            ->getRevision(),
    )
        ->not
        ->toBe(new CookiePolicy()->getRevision());
});

it('identifies the revision by the category and service keys', function (): void {
    $policy = new CookiePolicy()
        ->analytics(fn(Category $category) => $category->service('hotjar', fn() => null)->service(
            'google-analytics',
            fn() => null,
        ))
        ->marketing(fn(Category $category) => $category->service('meta', fn() => null));

    expect($policy->getRevision())
        ->toBe(substr(
            hash(algo: 'xxh128', data: 'analytics:google-analytics+hotjar,marketing:meta,necessary'),
            offset: 0,
            length: 8,
        ));
});

it('changes the revision when a service is added or moved', function (): void {
    $policy = new CookiePolicy()
        ->analytics(fn(Category $category) => $category->service('hotjar', fn() => null))
        ->marketing(fn() => null);

    $added = new CookiePolicy()
        ->analytics(fn(Category $category) => $category->service('hotjar', fn() => null)->service(
            'clarity',
            fn() => null,
        ))
        ->marketing(fn() => null);

    $moved = new CookiePolicy()
        ->analytics(fn() => null)
        ->marketing(fn(Category $category) => $category->service('hotjar', fn() => null));

    expect($added->getRevision())
        ->not->toBe($policy->getRevision())->and($moved->getRevision())
        ->not->toBe($policy->getRevision());
});

it('rejects a service that is already defined in another category', function (): void {
    expect(fn() => new CookiePolicy()
        ->analytics(fn(Category $category) => $category->service('google', fn() => null))
        ->marketing(fn(Category $category) => $category->service('meta', fn() => null)->service(
            'google',
            fn() => null,
        )))->toThrow(InvalidArgumentException::class, 'The service [google] is already defined in the category [analytics].');
});

it('configures the same service of a category every time', function (): void {
    $policy = new CookiePolicy()
        ->analytics(fn(Category $category) => $category->service('hotjar', fn() => null))
        ->analytics(fn(Category $category) => $category->service('hotjar', fn() => null));

    expect(array_keys($policy->getCategories()['analytics']->getServices()))->toBe(['hotjar']);
});

it('knows whether a service is defined', function (): void {
    $policy = new CookiePolicy()
        ->analytics(fn(Category $category) => $category->service('hotjar', fn() => null))
        ->marketing(fn(Category $category) => $category->service('meta', fn() => null));

    expect($policy->hasService('hotjar'))
        ->toBeTrue()
        ->and($policy->hasService('meta'))
        ->toBeTrue()
        ->and($policy->hasService('analytics'))
        ->toBeFalse();
});

it('is the default one without conditions', function (): void {
    expect(new CookiePolicy()->isDefault())->toBeTrue();
});

it('is not the default one with conditions', function (CookiePolicy $policy): void {
    expect($policy->isDefault())->toBeFalse();
})->with([
    'domain' => fn() => new CookiePolicy()->domain('tienda.test'),
    'condition' => fn() => new CookiePolicy()->when(fn() => true),
    'unmarked' => fn() => new CookiePolicy()
        ->domain('tienda.test')
        ->default()
        ->default(false),
]);

it('is the default one when marked, even with conditions', function (): void {
    expect(
        new CookiePolicy()
            ->domain('tienda.test')
            ->default()
            ->isDefault(),
    )->toBeTrue();
});

it('does not match without conditions', function (): void {
    expect(new CookiePolicy()->matches(Request::create('https://tienda.test')))->toBeFalse();
});

it('matches any of its domains', function (string $url, bool $matches): void {
    $policy = new CookiePolicy()
        ->domain('tienda.test', '*.Tienda.test')
        ->domain('shop.test');

    expect($policy->matches(Request::create($url)))->toBe($matches);
})->with([
    ['https://tienda.test',           true],
    ['https://www.tienda.test/cart',  true],
    ['https://shop.test',             true],
    ['https://TIENDA.test',           true],
    ['https://blog.test',             false],
    ['https://tienda.test.blog.test', false],
]);

it('matches when every condition passes', function (bool $first, bool $second, bool $matches): void {
    $policy = new CookiePolicy()
        ->when(fn(Request $request): bool => $first && $request->is('shop/*'))
        ->when(fn(): bool => $second);

    expect($policy->matches(Request::create('https://tienda.test/shop/cart')))
        ->toBe($matches)
        ->and($policy->matches(Request::create('https://tienda.test/blog')))
        ->toBeFalse();
})->with([
    [true, true, true],
    [true, false, false],
    [false, true, false],
]);

it('matches when both the domain and the conditions pass', function (): void {
    $policy = new CookiePolicy()
        ->domain('tienda.test')
        ->when(fn(Request $request): bool => $request->is('shop/*'));

    expect($policy->matches(Request::create('https://tienda.test/shop/cart')))
        ->toBeTrue()
        ->and($policy->matches(Request::create('https://blog.test/shop/cart')))
        ->toBeFalse()
        ->and($policy->matches(Request::create('https://tienda.test/blog')))
        ->toBeFalse();
});

it('knows whether a category is defined', function (): void {
    $policy = new CookiePolicy()->analytics(fn() => null);

    expect($policy->hasCategory('necessary'))
        ->toBeTrue()
        ->and($policy->hasCategory('analytics'))
        ->toBeTrue()
        ->and($policy->hasCategory('marketing'))
        ->toBeFalse();
});

it('has no policy url by default', function (): void {
    expect(new CookiePolicy()->getPolicyUrl())->toBeNull();
});

it('returns the given policy url', function (): void {
    expect(
        new CookiePolicy()
            ->policyUrl('/cookies')
            ->getPolicyUrl(),
    )
        ->toBe('/cookies');
});

it('resolves the policy url when it is requested', function (): void {
    $calls = 0;

    $policy = new CookiePolicy()->policyUrl(static function () use (&$calls): string {
        $calls++;

        return '/cookies';
    });

    expect($calls)->toBe(0)->and($policy->getPolicyUrl())->toBe('/cookies')->and($calls)->toBe(1);
});

it('does not use the google consent mode by default', function (): void {
    expect(new CookiePolicy()->getGoogleConsent())
        ->toBeNull()
        ->and(
            new CookiePolicy()
                ->googleConsentMode()
                ->googleConsentMode(false)
                ->getGoogleConsent(),
        )
        ->toBeNull();
});

it('maps every google consent signal to the categories that grant it', function (): void {
    $policy = new CookiePolicy()
        ->googleConsentMode()
        ->analytics(static fn() => null)
        ->marketing(static fn() => null)
        ->category('ads', static fn(Category $category) => $category->googleConsent(GoogleConsent::AdStorage));

    expect($policy->getGoogleConsent())->toBe([
        'ad_storage' => ['marketing', 'ads'],
        'ad_user_data' => ['marketing'],
        'ad_personalization' => ['marketing'],
        'analytics_storage' => ['analytics'],
        'functionality_storage' => [],
        'personalization_storage' => [],
        'security_storage' => ['necessary'],
    ]);
});
