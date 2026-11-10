<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Http\Controllers\ScriptController;
use Eolica\LaravelCookieConsent\Service;
use Eolica\LaravelCookieConsent\View\Components\Scripts;
use Illuminate\Http\Request;
use Illuminate\View\Compilers\BladeCompiler;

covers(Scripts::class);

beforeEach(function (): void {
    CarbonImmutable::setTestNow('2026-11-10 12:00:00');

    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->lifetime(CarbonInterval::days(10))
        ->cookie(name: 'shop_consent', domain: '.tienda.test')
        ->analytics(static fn(Category $category) => $category
            ->cookie(name: '_ga', purpose: 'Distinguishes users.')
            ->localStorage(name: '</script>', purpose: 'Breaks the HTML.')
            ->service('google-analytics', static fn(Service $service) => $service->script(src: '/gtag.js?id=G-X&a=1', attributes: [
                'async' => true,
            ])->script(
                content: static fn(Request $request): string => "gtag('config', '{$request->getHost()}', { a: 1 < 2 });",
            ))));
});

function renderScripts(string $url, string $blade = '<x-cookie-consent::scripts />'): string
{
    app()->instance('request', Request::create($url));

    return BladeCompiler::render($blade);
}

it('renders the configuration and the script', function (): void {
    $version = ScriptController::version();
    $revision = app(CookieConsentManager::class)->getPolicy('shop')->getRevision();

    expect(renderScripts('https://tienda.test/cart'))
        ->toBe(
            '<script type="application/json" data-cookie-consent-config>'
            . '{"policy":"shop","revision":"'
            . $revision
            . '","lifetime":864000,'
            . '"cookie":{"name":"shop_consent","domain":".tienda.test"},'
            . '"endpoint":"https://tienda.test/cookie-consent",'
            . '"googleConsent":null,'
            . '"services":{"google-analytics":"analytics"},'
            . '"categories":['
            . '{"key":"necessary","necessary":true,"cookies":[{"name":"shop_consent","type":"cookie"}]},'
            . '{"key":"analytics","necessary":false,"cookies":['
            . '{"name":"_ga","type":"cookie"},{"name":'
            . json_encode('</script>', JSON_HEX_TAG | JSON_UNESCAPED_SLASHES)
            . ',"type":"local_storage"}]}]}'
            . "</script>\n"
            . '<script type="module" src="https://tienda.test/cookie-consent/cookie-consent.js?id='
            . $version
            . '" data-navigate-once=""></script>'
            . "\n"
            . '<script async="async" type="text/plain" data-cookie-consent="google-analytics" data-src="/gtag.js?id=G-X&amp;a=1"></script>'
            . "\n"
            . '<script type="text/plain" data-cookie-consent="google-analytics">gtag(\'config\', \'tienda.test\', { a: 1 < 2 });</script>'
            . "\n",
        );
});

it('renders the given attributes in the script', function (): void {
    expect(renderScripts('https://tienda.test', blade: '<x-cookie-consent::scripts data-turbo-track="reload" />'))
        ->toContain('data-navigate-once="" data-turbo-track="reload"></script>');
});

it('renders the nonce in every script', function (): void {
    expect(renderScripts('https://tienda.test', blade: '<x-cookie-consent::scripts :nonce="\'a&b\'" />'))
        ->toContain(
            '<script type="module" src="https://tienda.test/cookie-consent/cookie-consent.js?id='
            . ScriptController::version()
            . '" nonce="a&amp;b" data-navigate-once=""></script>',
            '<script async="async" type="text/plain" nonce="a&amp;b" data-cookie-consent="google-analytics"',
            '<script type="text/plain" nonce="a&amp;b" data-cookie-consent="google-analytics">',
        );
});

it('renders nothing without a policy', function (): void {
    expect(renderScripts('https://blog.test'))->toBe('');
});
