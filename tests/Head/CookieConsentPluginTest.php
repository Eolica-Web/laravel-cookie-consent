<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentAssets;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Head\CookieConsentPlugin;
use Eolica\LaravelCookieConsent\Service;
use Illuminate\Http\Request;
use Seo\Head\Head;

covers(CookieConsentPlugin::class);

beforeEach(function (): void {
    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->analytics(static fn(Category $category) => $category->cookie(name: '_ga', purpose: 'Distinguishes users.'))
        ->marketing(static fn() => null));

    app()->instance('request', Request::create('https://tienda.test'));
});

/**
 * @return list<string>
 */
function renderHead(Head $head): array
{
    return explode(PHP_EOL, $head->render()['headTags']);
}

function assets(): CookieConsentAssets
{
    return app(CookieConsentAssets::class);
}

function configTag(): string
{
    $config = assets()->config(app(CookieConsentManager::class)->getPolicy('shop'));

    return "<script id=\"cookie-consent-config\" type=\"application/json\" data-cookie-consent-config>{$config}</script>";
}

it('adds the configuration and the script', function (): void {
    expect(renderHead(Head::create([CookieConsentPlugin::make()])))->toBe([
        '<script id="cookie-consent-script" type="module" src="'
            . assets()->scriptUrl()
            . '" data-navigate-once></script>',
        configTag(),
    ]);
});

it('adds the nonce to the script', function (): void {
    expect(renderHead(Head::create([CookieConsentPlugin::make(nonce: 'abc')]))[0])
        ->toBe(
            '<script id="cookie-consent-script" type="module" src="'
            . assets()->scriptUrl()
            . '" nonce="abc" data-navigate-once></script>',
        );
});

it('adds the styles when enabled', function (?string $nonce, string $tag): void {
    expect(Head::create([CookieConsentPlugin::make(styles: true, nonce: $nonce)])->render()['headTags'])
        ->toContain($tag . assets()->styles() . '</style>');
})->with([
    'without a nonce' => [null, '<style id="cookie-consent-styles">'],
    'with a nonce' => ['abc', '<style id="cookie-consent-styles" nonce="abc">'],
]);

it('adds the tags once when the plugin is added twice', function (): void {
    app()->forgetInstance(CookieConsentManager::class);

    app(
        CookieConsentManager::class,
    )->policy(static fn(CookiePolicy $policy) => $policy->analytics(static fn(Category $category) => $category->service('hotjar', static fn(Service $service) => $service->script(
        src: '/hotjar.js',
    ))));

    $tags = Head::create([
        CookieConsentPlugin::make(styles: true),
        CookieConsentPlugin::make(styles: true),
    ])->render()['headTags'];

    expect(substr_count($tags, needle: 'id="cookie-consent-'))
        ->toBe(3)
        ->and(substr_count($tags, needle: 'data-cookie-consent="hotjar"'))
        ->toBe(1);
});

it('adds the tags next to the other tags', function (): void {
    $head = Head::create([CookieConsentPlugin::make()]);
    $head->script(id: 'app', src: '/app.js');

    expect(renderHead($head))->toContain('<script id="app" src="/app.js"></script>', configTag());
});

it('adds nothing without a policy', function (): void {
    app()->instance('request', Request::create('https://blog.test'));

    expect(Head::create([CookieConsentPlugin::make(styles: true)])->render()['headTags'])->toBe('');
});

it('resolves the policy when rendering', function (): void {
    $head = Head::create([CookieConsentPlugin::make()]);

    app()->instance('request', Request::create('https://blog.test'));

    expect($head->render()['headTags'])->toBe('');
});

it('adds the blocked scripts of the services', function (): void {
    app()->forgetInstance(CookieConsentManager::class);

    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->analytics(static fn(Category $category) => $category->service('google-analytics', static fn(Service $service) => $service->script(src: '/gtag.js?id=G-X&a=1', attributes: [
            'async' => true,
        ])->script(content: static fn(Request $request): string => "gtag('config', '{$request->getHost()}');"))));

    expect(renderHead(Head::create([CookieConsentPlugin::make(nonce: 'abc')])))
        ->toContain(
            '<script async type="text/plain" nonce="abc" data-cookie-consent="google-analytics" data-src="/gtag.js?id=G-X&amp;a=1"></script>',
            '<script type="text/plain" nonce="abc" data-cookie-consent="google-analytics">gtag(\'config\', \'tienda.test\');</script>',
        );
});
