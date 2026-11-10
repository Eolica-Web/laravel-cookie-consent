<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Cookie;

covers(Cookie::class);

it('is first party without a provider', function (): void {
    expect(new Cookie(name: 'theme', purpose: 'Remembers the theme.')->isThirdParty())->toBeFalse();
});

it('is third party with a provider', function (): void {
    expect(new Cookie(name: '_ga', purpose: 'Distinguishes users.', provider: 'Google')->isThirdParty())->toBeTrue();
});

it('links to the privacy policy of the provider', function (): void {
    expect(new Cookie(
        name: '_ga',
        purpose: 'Distinguishes users.',
        provider: 'Google',
        providerUrl: 'https://policies.google.com/privacy',
    )->providerUrl)
        ->toBe('https://policies.google.com/privacy');
});

it('needs a provider to link to its privacy policy', function (): void {
    expect(static fn() => new Cookie(
        name: 'theme',
        purpose: 'Remembers the theme.',
        providerUrl: 'https://example.com',
    ))
        ->toThrow(InvalidArgumentException::class, 'The cookie [theme] needs a provider to have a provider url.');
});
