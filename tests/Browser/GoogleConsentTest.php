<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Service;
use Illuminate\Http\Response;
use Illuminate\Routing\Router;
use Illuminate\View\Compilers\BladeCompiler;

beforeEach(function (): void {
    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->googleConsentMode()
        ->analytics(static fn(Category $category) => $category->service('google-analytics', static fn(Service $service) => $service->script(src: '/gtag.js', attributes: [
            'async' => true,
        ])->script(content: <<<'JS'
            window.dataLayer = window.dataLayer || [];
            function gtag() { dataLayer.push(arguments); }
            gtag('js', 'inline');
            JS)))
        ->marketing(static fn() => null));

    $router = app(Router::class);

    $router->get('/', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
            <body></body>
        </html>
        BLADE));

    $router->get(
        '/gtag.js',
        static fn(): Response => new Response("window.dataLayer.push(['gtag.js']);", headers: [
            'Content-Type' => 'text/javascript',
        ]),
    );
});

function dataLayer(): string
{
    return '() => (window.dataLayer ?? []).map((entry) => Array.from(entry))';
}

/**
 * @param list<string> $granted
 *
 * @return array<string, string>
 */
function signals(array $granted): array
{
    $signals = [
        'ad_storage',
        'ad_user_data',
        'ad_personalization',
        'analytics_storage',
        'functionality_storage',
        'personalization_storage',
        'security_storage',
    ];

    return array_combine($signals, array_map(static fn(string $signal): string => in_array(
        $signal,
        $granted,
        strict: true,
    )
            ? 'granted'
            : 'denied', $signals));
}

it('denies every signal but the necessary ones by default', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    expect($page->script(dataLayer()))->toBe([
        ['consent', 'default', signals(['security_storage'])],
    ]);
});

it('updates the signals before activating the google tags', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(0.2);

    expect($page->script(dataLayer()))->toBe([
        ['consent', 'default', signals(['security_storage'])],
        ['consent', 'update', signals(['security_storage', 'analytics_storage'])],
        ['js', 'inline'],
        ['gtag.js'],
    ]);
});

it('updates the signals with the stored consent on the next visits', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["marketing"])');
    $page->refresh();

    expect($page->script(dataLayer()))->toBe([
        ['consent', 'default', signals(['security_storage'])],
        ['consent', 'update', signals(['security_storage', 'ad_storage', 'ad_user_data', 'ad_personalization'])],
    ]);
});

it('denies the signals again when the policy changed', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            window.fetch = async () => new Response('{"message":"The policy has changed."}', { status: 409 });
        }
        JS);

    $page->script('() => window.CookieConsent.accept(["marketing"])');
    $page->wait(0.5);

    expect($page->script(dataLayer()))->toBe([
        ['consent', 'default', signals(['security_storage'])],
    ]);
});
