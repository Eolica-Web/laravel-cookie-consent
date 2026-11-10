<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\Cookie;
use Eolica\LaravelCookieConsent\GoogleConsent;
use Eolica\LaravelCookieConsent\Service;
use Eolica\LaravelCookieConsent\StorageType;

covers(Category::class);

it('rejects invalid keys', function (string $key): void {
    expect(fn() => new Category($key))
        ->toThrow(
            InvalidArgumentException::class,
            "The category key [{$key}] must start with a lowercase letter and only contain lowercase letters, numbers, dashes and underscores.",
        );
})->with(['', 'Analytics', '1analytics', 'web.analytics', 'web analytics', "analytics\n"]);

it('accepts valid keys', function (string $key): void {
    expect(new Category($key)->getKey())->toBe($key);
})->with(['a', 'analytics', 'live-chat', 'live_chat', 'chat2']);

it('uses translation keys for the label and description by default', function (): void {
    $category = new Category('analytics');

    expect($category->getLabel())
        ->toBe('cookie-consent::categories.analytics.label')
        ->and($category->getDescription())
        ->toBe('cookie-consent::categories.analytics.description');
});

it('uses the given label and description', function (): void {
    $category = new Category('analytics')
        ->label('Analítica')
        ->description('Medimos el uso.');

    expect($category->getLabel())->toBe('Analítica')->and($category->getDescription())->toBe('Medimos el uso.');
});

it('knows whether it is the necessary category', function (string $key, bool $necessary): void {
    expect(new Category($key)->isNecessary())->toBe($necessary);
})->with([
    ['necessary', true],
    ['analytics', false],
]);

it('declares cookies and storage keys in order', function (): void {
    $duration = CarbonInterval::years(2);

    expect(
        new Category('preferences')
            ->cookie(name: 'theme', purpose: 'Remembers the theme.', duration: $duration)
            ->localStorage(name: 'draft', purpose: 'Keeps the draft.')
            ->sessionStorage(name: 'tab', purpose: 'Keeps the tab.')
            ->getCookies(),
    )->toEqual([
        new Cookie('theme', 'Remembers the theme.', duration: $duration),
        new Cookie('draft', 'Keeps the draft.', StorageType::LocalStorage),
        new Cookie('tab', 'Keeps the tab.', StorageType::SessionStorage),
    ]);
});

it('declares the cookies of its services in order', function (): void {
    $category = new Category('analytics')
        ->cookie(name: 'first', purpose: 'Comes first.')
        ->service('google-analytics', static fn(Service $service) => $service->provider(
            'Google',
            'https://policies.google.com/privacy',
        )->cookie(name: '_ga', purpose: 'Distinguishes users.'))
        ->cookie(name: 'middle', purpose: 'Comes in the middle.')
        ->service('hotjar', static fn(Service $service) => $service->provider('Hotjar')->sessionStorage(
            name: 'hotjar_tab',
            purpose: 'Keeps the tab.',
        ))
        ->service('google-analytics', static fn(Service $service) => $service->localStorage(
            name: '_ga_state',
            purpose: 'Keeps the state.',
        ));

    expect($category->getCookies())
        ->toEqual([
            new Cookie('first', 'Comes first.'),
            new Cookie(
                '_ga',
                'Distinguishes users.',
                provider: 'Google',
                providerUrl: 'https://policies.google.com/privacy',
            ),
            new Cookie(
                '_ga_state',
                'Keeps the state.',
                StorageType::LocalStorage,
                provider: 'Google',
                providerUrl: 'https://policies.google.com/privacy',
            ),
            new Cookie('middle', 'Comes in the middle.'),
            new Cookie('hotjar_tab', 'Keeps the tab.', StorageType::SessionStorage, provider: 'Hotjar'),
        ])
        ->and(array_keys($category->getServices()))
        ->toBe(['google-analytics', 'hotjar']);
});

it('knows whether a service is defined', function (): void {
    $category = new Category('analytics')->service('hotjar', static fn() => null);

    expect($category->hasService('hotjar'))->toBeTrue()->and($category->hasService('google-analytics'))->toBeFalse();
});

it('declares cookies without duration', function (): void {
    expect(
        new Category('preferences')
            ->cookie(name: 'theme', purpose: 'Remembers the theme.')
            ->localStorage(name: 'draft', purpose: 'Keeps the draft.')
            ->sessionStorage(name: 'tab', purpose: 'Keeps the tab.')
            ->getCookies(),
    )->toEqual([
        new Cookie('theme', 'Remembers the theme.'),
        new Cookie('draft', 'Keeps the draft.', StorageType::LocalStorage),
        new Cookie('tab', 'Keeps the tab.', StorageType::SessionStorage),
    ]);
});

it('declares the session cookie of the application', function (): void {
    config()->set('session.cookie', 'shop_session');
    config()->set('session.lifetime', 90);
    config()->set('session.expire_on_close', false);

    expect(new Category('necessary')->session()->getCookies())->toEqual([
        new Cookie('shop_session', 'cookie-consent::cookies.session', duration: CarbonInterval::minutes(90)),
    ]);
});

it('declares the session cookie without duration when it expires on close', function (): void {
    config()->set('session.cookie', 'shop_session');
    config()->set('session.expire_on_close', true);

    expect(new Category('necessary')->session()->getCookies())->toEqual([
        new Cookie('shop_session', 'cookie-consent::cookies.session'),
    ]);
});

it('declares the XSRF-TOKEN cookie with the session lifetime, even when the session expires on close', function (): void {
    config()->set('session.lifetime', 90);
    config()->set('session.expire_on_close', true);

    expect(new Category('necessary')->csrf()->getCookies())->toEqual([
        new Cookie('XSRF-TOKEN', 'cookie-consent::cookies.csrf', duration: CarbonInterval::minutes(90)),
    ]);
});

it('returns a copy with a leading cookie', function (): void {
    $category = new Category('necessary')->csrf();
    $consent = new Cookie('cookie_consent', 'cookie-consent::cookies.consent');

    $copy = $category->withLeadingCookie($consent);

    expect($copy->getCookies())
        ->toEqual([$consent, ...$category->getCookies()])
        ->and($category->getCookies())
        ->not->toContain($consent);
});

it('maps the standard categories to the google consent signals', function (string $key, array $signals): void {
    expect(new Category($key)->getGoogleConsent())->toBe($signals);
})->with([
    'necessary' => ['necessary', [GoogleConsent::SecurityStorage]],
    'preferences' => ['preferences', [GoogleConsent::FunctionalityStorage, GoogleConsent::PersonalizationStorage]],
    'analytics' => ['analytics', [GoogleConsent::AnalyticsStorage]],
    'marketing' => [
        'marketing',
        [GoogleConsent::AdStorage, GoogleConsent::AdUserData, GoogleConsent::AdPersonalization],
    ],
    'custom' => ['chat', []],
]);

it('maps the given google consent signals', function (): void {
    $signals = [GoogleConsent::AdStorage, GoogleConsent::AdUserData];

    expect(
        new Category('ads')
            ->googleConsent(...$signals)
            ->getGoogleConsent(),
    )
        ->toBe($signals)
        ->and(new Category('marketing')->googleConsent()->getGoogleConsent())
        ->toBe([])
        ->and(new Category('ads')->googleConsent(...['first' => GoogleConsent::AdStorage])->getGoogleConsent())
        ->toBe([GoogleConsent::AdStorage]);
});

it('keeps the google consent signals in the copy with a leading cookie', function (): void {
    expect(
        new Category('ads')
            ->googleConsent(GoogleConsent::AdStorage)
            ->withLeadingCookie(new Cookie('consent', 'Stores the consent.'))
            ->getGoogleConsent(),
    )->toBe([GoogleConsent::AdStorage]);
});
