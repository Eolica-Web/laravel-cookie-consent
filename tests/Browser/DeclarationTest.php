<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Service;
use Illuminate\Routing\Router;
use Illuminate\View\Compilers\BladeCompiler;

beforeEach(function (): void {
    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->analytics(static fn(Category $category) => $category->service('google-analytics', static fn(Service $service) => $service->provider(
            'Google',
            'https://policies.google.com/privacy',
        )->cookie(name: '_ga', purpose: 'Distinguishes users.', duration: CarbonInterval::years(2))))
        ->marketing(static fn() => null));

    app(Router::class)->get('/cookies', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
            <body>
                <main>
                    <h2>Cookie policy</h2>
                    <x-cookie-consent::declaration />
                </main>
                <x-cookie-consent::banner />
            </body>
        </html>
        BLADE));
});

it('opens the preferences from the declaration', function (): void {
    $page = visit('/cookies')->assertNoJavaScriptErrors();

    $page->click('Change cookie preferences');

    expect($page->script('() => document.querySelector("[data-cookie-consent-preferences]").open'))->toBeTrue();
});
