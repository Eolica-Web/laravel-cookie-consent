<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Cookie;
use Eolica\LaravelCookieConsent\Script;
use Eolica\LaravelCookieConsent\Service;
use Eolica\LaravelCookieConsent\StorageType;
use Illuminate\Http\Request;

covers(Service::class);

it('rejects invalid keys', function (string $key): void {
    expect(fn() => new Service($key))
        ->toThrow(
            InvalidArgumentException::class,
            "The service key [{$key}] must start with a lowercase letter and only contain lowercase letters, numbers, dashes and underscores.",
        );
})->with(['', 'Hotjar', '1hotjar', 'google.analytics', 'google analytics', "hotjar\n"]);

it('accepts valid keys', function (string $key): void {
    expect(new Service($key)->getKey())->toBe($key);
})->with(['a', 'hotjar', 'google-analytics', 'google_analytics', 'ga4']);

it('has no provider by default', function (): void {
    $service = new Service('matomo')->cookie(name: '_pk_id', purpose: 'Distinguishes users.');

    expect($service->getProvider())
        ->toBeNull()
        ->and($service->getProviderUrl())
        ->toBeNull()
        ->and($service->getCookies())
        ->toEqual([new Cookie('_pk_id', 'Distinguishes users.')]);
});

it('declares its cookies with its provider', function (): void {
    $duration = CarbonInterval::years(2);

    $service = new Service('google-analytics')
        ->cookie(name: '_ga', purpose: 'Distinguishes users.', duration: $duration)
        ->localStorage(name: '_ga_state', purpose: 'Keeps the state.')
        ->sessionStorage(name: '_ga_tab', purpose: 'Keeps the tab.')
        ->provider('Google', 'https://policies.google.com/privacy');

    expect($service->getProvider())
        ->toBe('Google')
        ->and($service->getProviderUrl())
        ->toBe('https://policies.google.com/privacy')
        ->and($service->getCookies())
        ->toEqual([
            new Cookie(
                '_ga',
                'Distinguishes users.',
                StorageType::Cookie,
                $duration,
                'Google',
                'https://policies.google.com/privacy',
            ),
            new Cookie(
                '_ga_state',
                'Keeps the state.',
                StorageType::LocalStorage,
                provider: 'Google',
                providerUrl: 'https://policies.google.com/privacy',
            ),
            new Cookie(
                '_ga_tab',
                'Keeps the tab.',
                StorageType::SessionStorage,
                provider: 'Google',
                providerUrl: 'https://policies.google.com/privacy',
            ),
        ]);
});

it('declares a provider without url', function (): void {
    $service = new Service('hotjar')->provider('Hotjar');

    expect($service->getProvider())->toBe('Hotjar')->and($service->getProviderUrl())->toBeNull();
});

it('declares its scripts in order', function (): void {
    $request = Request::create('https://tienda.test');

    $scripts = new Service('google-analytics')
        ->script(src: '/gtag.js', attributes: ['async' => true])
        ->script(content: static fn(Request $request): string => "gtag('{$request->getHost()}');")
        ->getScripts();

    expect(array_map(static fn(Script $script): array => [
        $script->getSrc($request),
        $script->getContent($request),
        $script->attributes,
    ], $scripts))->toBe([
        ['/gtag.js', null, ['async' => true]],
        [null, "gtag('tienda.test');", []],
    ]);
});
