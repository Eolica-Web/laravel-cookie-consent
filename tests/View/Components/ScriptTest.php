<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\View\Components\Script;
use Illuminate\Http\Request;
use Illuminate\View\Compilers\BladeCompiler;
use Illuminate\View\ViewException;

covers(Script::class);

beforeEach(function (): void {
    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->analytics(static fn(Category $category) => $category->service('google-analytics', static fn() => null)));
});

function renderScript(string $blade, string $url = 'https://tienda.test'): string
{
    app()->instance('request', Request::create($url));

    return BladeCompiler::render($blade);
}

it('renders a blocked inline script', function (): void {
    expect(renderScript(
        '<x-cookie-consent::script service="google-analytics">gtag("config", "G-1");</x-cookie-consent::script>',
    ))
        ->toBe('<script type="text/plain" data-cookie-consent="google-analytics">gtag("config", "G-1");</script>'
        . "\n");
});

it('renders a blocked external script with its attributes', function (): void {
    expect(renderScript(
        '<x-cookie-consent::script service="google-analytics" src="https://example.test/a.js?a=1&b=2" type="module" async nonce="abc" />',
    ))
        ->toBe(
            '<script type="text/plain" data-cookie-consent="google-analytics" data-src="https://example.test/a.js?a=1&amp;b=2" '
            . 'data-type="module" async="async" nonce="abc"></script>'
            . "\n",
        );
});

it('renders the script without a policy', function (): void {
    expect(renderScript('<x-cookie-consent::script service="chat" />', url: 'https://blog.test'))
        ->toBe('<script type="text/plain" data-cookie-consent="chat"></script>' . "\n");
});

it('fails to render a service that is not defined in the policy', function (): void {
    expect(static fn() => renderScript('<x-cookie-consent::script service="analytics" />'))
        ->toThrow(ViewException::class, 'The service [analytics] is not defined in the cookie policy [shop].');
});
