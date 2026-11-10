<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentAssets;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Http\Controllers\ScriptController;
use Eolica\LaravelCookieConsent\Service;
use Illuminate\Http\Request;
use Illuminate\Routing\UrlGenerator;

covers(CookieConsentAssets::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');
});

it('encodes the configuration of the policy', function (): void {
    $policy = new CookiePolicy()
        ->id('shop')
        ->lifetime(CarbonInterval::days(10))
        ->cookie(name: 'shop_consent', domain: '.tienda.test')
        ->analytics(static fn(Category $category) => $category->cookie(
            name: '_ga',
            purpose: 'Distinguishes users.',
        )->localStorage(name: '_ga_state', purpose: 'Keeps the state.'));

    expect(app(CookieConsentAssets::class)->config($policy))
        ->toBe(
            '{"policy":"shop","revision":"'
            . $policy->getRevision()
            . '","lifetime":864000,'
            . '"cookie":{"name":"shop_consent","domain":".tienda.test"},'
            . '"endpoint":"http://localhost/cookie-consent",'
            . '"googleConsent":null,'
            . '"services":{},'
            . '"categories":['
            . '{"key":"necessary","necessary":true,"cookies":[{"name":"shop_consent","type":"cookie"}]},'
            . '{"key":"analytics","necessary":false,"cookies":['
            . '{"name":"_ga","type":"cookie"},{"name":"_ga_state","type":"local_storage"}]}]}',
        );
});

it('encodes the google consent signals of the categories', function (): void {
    $policy = new CookiePolicy()
        ->googleConsentMode()
        ->analytics(static fn() => null);

    expect(app(CookieConsentAssets::class)->config($policy))
        ->toContain(
            '"googleConsent":{"ad_storage":[],"ad_user_data":[],"ad_personalization":[],"analytics_storage":["analytics"],'
            . '"functionality_storage":[],"personalization_storage":[],"security_storage":["necessary"]},',
        );
});

it('encodes the category of every service', function (): void {
    $policy = new CookiePolicy()
        ->necessary(static fn(Category $category) => $category->service('stripe', static fn() => null))
        ->analytics(static fn(Category $category) => $category->service('google-analytics', static fn(Service $service) => $service->provider(
            'Google',
        )->cookie(name: '_ga', purpose: 'Distinguishes users.'))->service('hotjar', static fn() => null));

    expect(app(CookieConsentAssets::class)->config($policy))
        ->toContain(
            '"services":{"stripe":"necessary","google-analytics":"analytics","hotjar":"analytics"},',
            '{"key":"analytics","necessary":false,"cookies":[{"name":"_ga","type":"cookie"}]}',
        );
});

it('blocks the scripts of the services', function (): void {
    $policy = new CookiePolicy()
        ->analytics(static fn(Category $category) => $category->service('google-analytics', static fn(Service $service) => $service->script(
            src: static fn(Request $request): string => "/gtag.js?host={$request->getHost()}",
            attributes: [
                'async' => true,
                'src' => '/unblocked.js',
                'id' => 'gtag',
            ],
        )->script(content: 'gtag("js", new Date());'))->service('hotjar', static fn() => null))
        ->marketing(static fn(Category $category) => $category->service('meta', static fn(Service $service) => $service->script(
            content: static fn(Request $request): string => "fbq('{$request->getHost()}');",
            attributes: ['type' => 'module', 'nonce' => 'ignored'],
        )));

    expect(app(CookieConsentAssets::class)->scripts(
        $policy,
        Request::create('https://tienda.test'),
        nonce: 'abc',
    ))->toBe([
        [
            'attributes' => [
                'async' => true,
                'src' => null,
                'id' => 'gtag',
                'type' => 'text/plain',
                'nonce' => 'abc',
                'data-cookie-consent' => 'google-analytics',
                'data-src' => '/gtag.js?host=tienda.test',
                'data-type' => null,
            ],
            'content' => null,
        ],
        [
            'attributes' => [
                'type' => 'text/plain',
                'src' => null,
                'nonce' => 'abc',
                'data-cookie-consent' => 'google-analytics',
                'data-src' => null,
                'data-type' => null,
            ],
            'content' => 'gtag("js", new Date());',
        ],
        [
            'attributes' => [
                'type' => 'text/plain',
                'nonce' => 'abc',
                'src' => null,
                'data-cookie-consent' => 'meta',
                'data-src' => null,
                'data-type' => 'module',
            ],
            'content' => "fbq('tienda.test');",
        ],
    ]);
});

it('blocks the scripts without a nonce', function (): void {
    $policy = new CookiePolicy()->analytics(static fn(Category $category) => $category->service('hotjar', static fn(Service $service) => $service->script(
        src: '/hotjar.js',
    )));

    expect(
        app(CookieConsentAssets::class)->scripts($policy, Request::create('https://tienda.test'))[0]['attributes'],
    )->toBe([
        'type' => 'text/plain',
        'src' => null,
        'nonce' => null,
        'data-cookie-consent' => 'hotjar',
        'data-src' => '/hotjar.js',
        'data-type' => null,
    ]);
});

it('encodes the lifetime with calendar months', function (): void {
    $policy = new CookiePolicy()->lifetime(CarbonInterval::months(12));

    expect(app(CookieConsentAssets::class)->config($policy))->toContain('"lifetime":31536000,');
});

it('escapes the html characters in the configuration', function (): void {
    $policy = new CookiePolicy()->analytics(static fn(Category $category) => $category->localStorage(
        name: '</script>&\'"',
        purpose: 'Breaks the HTML.',
    ));

    expect(app(CookieConsentAssets::class)->config($policy))
        ->toContain(
            '{"name":'
                . json_encode(
                    '</script>&\'"',
                    JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
                ),
        );
});

it('generates the versioned script url', function (): void {
    expect(app(CookieConsentAssets::class)->scriptUrl())
        ->toBe('http://localhost/cookie-consent/cookie-consent.js?id=' . ScriptController::version());
});

it('keeps the base path of the application in the urls', function (): void {
    app(UrlGenerator::class)->setRequest(Request::create('https://tienda.test/shop/cart', server: [
        'SCRIPT_FILENAME' => '/var/www/shop/index.php',
        'SCRIPT_NAME' => '/shop/index.php',
        'PHP_SELF' => '/shop/index.php',
    ]));

    $assets = app(CookieConsentAssets::class);

    expect($assets->scriptUrl())
        ->toBe('https://tienda.test/shop/cookie-consent/cookie-consent.js?id=' . ScriptController::version())
        ->and($assets->config(new CookiePolicy()))
        ->toContain('"endpoint":"https://tienda.test/shop/cookie-consent"');
});

it('reads the styles', function (): void {
    expect(app(CookieConsentAssets::class)->styles())
        ->toBe(file_get_contents(__DIR__ . '/../resources/css/cookie-consent.css'))
        ->toContain('.cookie-consent {');
});
