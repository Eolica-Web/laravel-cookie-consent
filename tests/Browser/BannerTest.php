<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Illuminate\Routing\Router;
use Illuminate\View\Compilers\BladeCompiler;

beforeEach(function (): void {
    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->analytics(static fn(Category $category) => $category->cookie(
            name: '_ga',
            purpose: 'Distinguishes users.',
        )->service('stats', static fn() => null))
        ->marketing(static fn() => null));

    $router = app(Router::class);

    $router->get('/', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
            <body>
                <footer>
                    <button type="button" data-cookie-consent-action="show-preferences">Cookie settings</button>
                </footer>
                <x-cookie-consent::banner />
                <x-cookie-consent::script service="stats">window.analyticsRuns = (window.analyticsRuns ?? 0) + 1;</x-cookie-consent::script>
            </body>
        </html>
        BLADE));

    $router->get('/custom', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
            <body>
                <div data-cookie-consent-banner hidden>
                    <label><input type="checkbox" data-cookie-consent-category="necessary"> Necessary</label>
                    <label><input type="checkbox" data-cookie-consent-category="analytics"> Analytics</label>
                    <label><input type="checkbox" data-cookie-consent-category="marketing"> Marketing</label>
                    <button type="button" data-cookie-consent-action="save">Save</button>
                </div>
            </body>
        </html>
        BLADE));
});

function isBannerVisible(): string
{
    return '() => !document.querySelector("[data-cookie-consent-banner]").hidden';
}

function isPreferencesOpen(): string
{
    return '() => document.querySelector("[data-cookie-consent-preferences]").open';
}

it('shows the banner without consent', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    expect($page->script(isBannerVisible()))->toBeTrue()->and($page->script(isPreferencesOpen()))->toBeFalse();
});

it('accepts every category from the banner', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors()->click('Accept all');

    expect($page->script(isBannerVisible()))
        ->toBeFalse()
        ->and($page->script('() => window.CookieConsent.consent.categories'))
        ->toBe(['necessary', 'analytics', 'marketing'])
        ->and($page->script('() => window.analyticsRuns'))
        ->toBe(1);
});

it('rejects every category from the banner', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors()->click('Reject all');

    expect($page->script(isBannerVisible()))
        ->toBeFalse()
        ->and($page->script('() => window.CookieConsent.consent.categories'))
        ->toBe(['necessary'])
        ->and($page->script('() => window.analyticsRuns'))
        ->toBeNull();
});

it('saves the chosen categories from the preferences', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors()->click('Customize');

    expect($page->script(isPreferencesOpen()))
        ->toBeTrue()
        ->and($page->script(
            '() => [...document.querySelectorAll("[data-cookie-consent-category]")].map((input) => [input.checked, input.disabled])',
        ))
        ->toBe([[true, true], [false, false], [false, false]]);

    $page->click('#cookie-consent-category-analytics')->click('Save preferences');

    expect($page->script(isPreferencesOpen()))
        ->toBeFalse()
        ->and($page->script(isBannerVisible()))
        ->toBeFalse()
        ->and($page->script('() => window.CookieConsent.consent.categories'))
        ->toBe(['necessary', 'analytics'])
        ->and($page->script('() => window.analyticsRuns'))
        ->toBe(1);
});

it('accepts every category from the preferences', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors()->click('Customize');

    $page->script(
        '() => document.querySelector("[data-cookie-consent-preferences] [data-cookie-consent-action=accept-all]").click()',
    );

    expect($page->script(isPreferencesOpen()))
        ->toBeFalse()
        ->and($page->script('() => window.CookieConsent.consent.categories'))
        ->toBe(['necessary', 'analytics', 'marketing']);
});

it('closes the preferences without saving', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors()->click('Customize')->click('[aria-label="Close"]');

    expect($page->script(isPreferencesOpen()))
        ->toBeFalse()
        ->and($page->script(isBannerVisible()))
        ->toBeTrue()
        ->and($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeTrue();
});

it('hides the banner on the next visits and shows the current choice in the preferences', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors()->click('Customize');

    $page->click('#cookie-consent-category-marketing')->click('Save preferences');
    $page->refresh();

    expect($page->script(isBannerVisible()))->toBeFalse();

    $page->click('Cookie settings');

    expect($page->script(isPreferencesOpen()))
        ->toBeTrue()
        ->and($page->script(
            '() => [...document.querySelectorAll("[data-cookie-consent-category]")].map((input) => input.checked)',
        ))
        ->toBe([true, false, true]);
});

it('updates the banner when the consent changes through the api', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.showPreferences()');

    expect($page->script(isPreferencesOpen()))->toBeTrue();

    $page->script('() => window.CookieConsent.accept(["marketing"])');

    expect($page->script(isBannerVisible()))
        ->toBeFalse()
        ->and($page->script(isPreferencesOpen()))
        ->toBeFalse()
        ->and($page->script('() => document.getElementById("cookie-consent-category-marketing").checked'))
        ->toBeTrue();
});

it('saves the categories of a custom banner', function (): void {
    $page = visit('/custom')->assertNoJavaScriptErrors();

    expect($page->script(isBannerVisible()))
        ->toBeTrue()
        ->and($page->script(
            '() => [...document.querySelectorAll("[data-cookie-consent-category]")].map((input) => [input.checked, input.disabled])',
        ))
        ->toBe([[true, true], [false, false], [false, false]]);

    $page->click('Marketing')->click('Save');

    expect($page->script(isBannerVisible()))
        ->toBeFalse()
        ->and($page->script('() => window.CookieConsent.consent.categories'))
        ->toBe(['necessary', 'marketing']);
});

it('renders the banners added after the page loaded', function (): void {
    $page = visit('/custom')->assertNoJavaScriptErrors()->click('Save');

    $page->script(<<<'JS'
        () => document.body.insertAdjacentHTML('beforeend', `
            <div id="late" data-cookie-consent-banner>
                <input type="checkbox" id="late-marketing" data-cookie-consent-category="marketing">
            </div>
        `)
        JS);

    $page->wait(0.1);

    expect($page->script('() => document.getElementById("late").hidden'))
        ->toBeTrue()
        ->and($page->script('() => document.getElementById("late-marketing").checked'))
        ->toBeFalse();
});
