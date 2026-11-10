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
        ->cookie(name: 'shop_consent')
        ->analytics(static fn(Category $category) => $category
            ->cookie(name: '_ga*', purpose: 'Distinguishes users.')
            ->localStorage(name: 'analytics_state', purpose: 'Keeps the analytics state.')
            ->service('stats', static fn(Service $service) => $service->script(
                content: 'window.policyRuns = (window.policyRuns ?? 0) + 1;',
            )))
        ->marketing(static fn(Category $category) => $category->service('ads', static fn() => null)));

    $router = app(Router::class);

    $router->get('/', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
            <body>
                <script type="text/plain" data-cookie-consent="stats">
                    window.inlineRuns = (window.inlineRuns ?? 0) + 1;
                </script>
                <script type="text/plain" data-cookie-consent="stats" data-src="/analytics.js"></script>
                <script type="text/plain" data-cookie-consent="ads">
                    window.marketingRuns = (window.marketingRuns ?? 0) + 1;
                </script>
            </body>
        </html>
        BLADE));

    $router->get(
        '/analytics.js',
        static fn(): Response => new Response('window.externalRuns = (window.externalRuns ?? 0) + 1;', headers: [
            'Content-Type' => 'text/javascript',
        ]),
    );
});

function waitForExternalScript(): string
{
    return '() => new Promise((resolve) => { const check = () => window.externalRuns ? resolve(window.externalRuns) : setTimeout(check, 10); check(); })';
}

function consentCookieField(string $field): string
{
    return "() => JSON.parse(decodeURIComponent(document.cookie.split('; ').find((cookie) => cookie.startsWith('shop_consent=')).slice(13))).{$field}";
}

it('needs consent and blocks the scripts without it', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    expect($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeTrue()
        ->and($page->script('() => window.CookieConsent.allows("necessary")'))
        ->toBeTrue()
        ->and($page->script('() => window.CookieConsent.allows("analytics")'))
        ->toBeFalse()
        ->and($page->script('() => [window.inlineRuns, window.externalRuns, window.marketingRuns]'))
        ->toBe([null, null, null]);
});

it('activates the scripts of the accepted categories and stores the consent', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeFalse()
        ->and($page->script(waitForExternalScript()))
        ->toBe(1)
        ->and($page->script('() => [window.inlineRuns, window.marketingRuns]'))
        ->toBe([1, null]);

    $id = $page->script(consentCookieField('id'));
    $policy = app(CookieConsentManager::class)->getPolicy('default');

    expect($id)
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/')
        ->and($page->script(consentCookieField('policy')))
        ->toBe('default')
        ->and($page->script(consentCookieField('revision')))
        ->toBe($policy->getRevision())
        ->and($page->script(consentCookieField('categories')))
        ->toBe(['necessary', 'analytics'])
        ->and($page->script('() => window.CookieConsent.consent.id'))
        ->toBe($id);
});

it('keeps the consent and activates the scripts on the next visits', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.acceptAll()');
    $page->refresh();

    expect($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeFalse()
        ->and($page->script(waitForExternalScript()))
        ->toBe(1)
        ->and($page->script('() => [window.inlineRuns, window.marketingRuns]'))
        ->toBe([1, 1]);
});

it('keeps the scripts blocked when rejected', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.rejectAll()');

    expect($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeFalse()
        ->and($page->script('() => window.CookieConsent.allows("analytics")'))
        ->toBeFalse()
        ->and($page->script('() => [window.inlineRuns, window.externalRuns, window.marketingRuns]'))
        ->toBe([null, null, null])
        ->and($page->script(consentCookieField('categories')))
        ->toBe(['necessary']);
});

it('ignores categories that are not in the policy', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics", "unknown"])');

    expect($page->script('() => window.CookieConsent.consent.categories'))->toBe(['necessary', 'analytics']);
});

it('does not push the google consent signals without the google consent mode', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.acceptAll()');

    expect($page->script('() => window.dataLayer === undefined'))->toBeTrue();
});

it('tells whether a category is necessary', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    expect($page->script(<<<'JS'
        () => ['necessary', 'analytics', 'unknown'].map((category) => window.CookieConsent.isNecessary(category))
        JS))->toBe([true, false, false]);
});

it('dispatches the updated event', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $detail = $page->script(<<<'JS'
        () => new Promise((resolve) => {
            document.addEventListener('cookie-consent:updated', (event) => resolve(event.detail.categories), { once: true });
            window.CookieConsent.accept(['marketing']);
        })
        JS);

    expect($detail)->toBe(['necessary', 'marketing']);
});

it('dispatches the show preferences event', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    expect($page->script(<<<'JS'
        () => new Promise((resolve) => {
            document.addEventListener('cookie-consent:show-preferences', () => resolve(true), { once: true });
            window.CookieConsent.showPreferences();
        })
        JS))->toBeTrue();
});

it('runs callbacks when their category is allowed', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            window.calls = [];
            window.CookieConsent.whenAllowed('necessary', () => window.calls.push('necessary'));
            window.CookieConsent.whenAllowed('analytics', () => window.calls.push('analytics'));
            window.CookieConsent.whenAllowed('marketing', () => window.calls.push('marketing'));
        }
        JS);

    expect($page->script('() => window.calls'))->toBe(['necessary']);

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script('() => window.calls'))->toBe(['necessary', 'analytics']);

    $page->script('() => window.CookieConsent.accept(["analytics", "marketing"])');

    expect($page->script('() => window.calls'))->toBe(['necessary', 'analytics', 'marketing']);
});

it('activates blocked scripts added later only once per source', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script(waitForExternalScript());

    $page->script(<<<'JS'
        () => {
            const inline = document.createElement('script');
            inline.type = 'text/plain';
            inline.dataset.cookieConsent = 'stats';
            inline.textContent = 'window.inlineRuns++;';

            const external = document.createElement('script');
            external.type = 'text/plain';
            external.dataset.cookieConsent = 'stats';
            external.dataset.src = '/analytics.js';

            const container = document.createElement('div');
            container.append(inline, external);
            document.body.append(container);
        }
        JS);

    $page->wait(0.2);

    expect($page->script('() => [window.inlineRuns, window.externalRuns]'))->toBe([2, 1]);
});

it('removes the cookies of the revoked categories and reloads the page', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script(<<<'JS'
        () => {
            document.cookie = '_ga=1; path=/';
            document.cookie = '_ga_ABC=1; path=/';
            document.cookie = 'other=1; path=/';
            localStorage.setItem('analytics_state', '1');
            localStorage.setItem('other_state', '1');
            window.beforeReload = true;
        }
        JS);

    $page->script('() => window.CookieConsent.rejectAll()');
    $page->wait(0.5);

    expect($page->script('() => window.beforeReload'))
        ->toBeNull()
        ->and($page->script('() => window.CookieConsent.allows("analytics")'))
        ->toBeFalse()
        ->and($page->script('() => [window.inlineRuns, window.externalRuns]'))
        ->toBe([null, null])
        ->and($page->script("() => document.cookie.split('; ').map((cookie) => cookie.split('=')[0]).sort()"))
        ->toBe(['other', 'shop_consent'])
        ->and($page->script('() => Object.keys(localStorage)'))
        ->toBe(['other_state']);
});

it('reloads the page when the consent can not be stored in time', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script(<<<'JS'
        () => {
            window.beforeReload = true;
            AbortSignal.timeout = (milliseconds) => {
                sessionStorage.setItem('timeout', String(milliseconds));

                return AbortSignal.abort(new DOMException('The operation timed out.', 'TimeoutError'));
            };
            window.fetch = (url, options) => new Promise((resolve, reject) => {
                options.signal.aborted ? reject(options.signal.reason) : options.signal.addEventListener('abort', () => reject(options.signal.reason));
            });
        }
        JS);

    $page->script('() => { window.CookieConsent.rejectAll(); }');
    $page->wait(0.5);

    expect($page->script('() => window.beforeReload'))
        ->toBeNull()
        ->and($page->script("() => sessionStorage.getItem('timeout')"))
        ->toBe('5000')
        ->and($page->script('() => window.CookieConsent.allows("analytics")'))
        ->toBeFalse();
});

it('removes the cookies of the revoked categories on every path of the page', function (): void {
    app(Router::class)->get('/blog/post', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
        </html>
        BLADE));

    $page = visit('/blog/post')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script(<<<'JS'
        () => {
            document.cookie = '_ga=1; path=/';
            document.cookie = '_ga_blog=1; path=/blog';
            document.cookie = '_ga_slash=1; path=/blog/';
            document.cookie = '_ga_post=1; path=/blog/post';
            document.cookie = 'other=1; path=/blog';
        }
        JS);

    $page->script('() => window.CookieConsent.rejectAll()');
    $page->wait(0.5);

    expect($page->script("() => document.cookie.split('; ').map((cookie) => cookie.split('=')[0]).sort()"))->toBe([
        'other',
        'shop_consent',
    ]);
});

it('reloads the page to ask for consent again when the policy changed', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            window.beforeReload = true;
            window.fetch = async () => new Response('{"message":"The policy has changed."}', { status: 409 });
        }
        JS);

    $page->script('() => window.CookieConsent.acceptAll()');
    $page->wait(0.5);

    expect($page->script('() => window.beforeReload'))
        ->toBeNull()
        ->and($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeTrue()
        ->and($page->script("() => document.cookie.includes('shop_consent=')"))
        ->toBeFalse()
        ->and($page->script('() => [window.inlineRuns, window.externalRuns, window.marketingRuns]'))
        ->toBe([null, null, null]);
});

it('needs consent again with a consent for another revision', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            const consent = { id: '', policy: 'default', revision: 'old', categories: ['necessary', 'analytics'], at: Math.floor(Date.now() / 1000) };
            document.cookie = `shop_consent=${encodeURIComponent(JSON.stringify(consent))}; path=/`;
        }
        JS);

    $page->refresh();

    expect($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeTrue()
        ->and($page->script('() => window.inlineRuns'))
        ->toBeNull();
});

it('activates the scripts rendered by the component', function (): void {
    $router = app(Router::class);

    $router->get('/component', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
            <body>
                <x-cookie-consent::script service="stats">
                    window.inlineRuns = (window.inlineRuns ?? 0) + 1;
                </x-cookie-consent::script>
                <x-cookie-consent::script service="stats" src="/analytics.mjs" type="module" />
            </body>
        </html>
        BLADE));

    $router->get(
        '/analytics.mjs',
        static fn(): Response => new Response('window.externalRuns = typeof import.meta.url === "string" ? 1 : 0;', headers: [
            'Content-Type' => 'text/javascript',
        ]),
    );

    $page = visit('/component')->assertNoJavaScriptErrors();

    expect($page->script('() => window.inlineRuns'))->toBeNull();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script(waitForExternalScript()))->toBe(1)->and($page->script('() => window.inlineRuns'))->toBe(1);
});

it('activates the scripts of the services of the policy', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    expect($page->script('() => window.policyRuns'))->toBeNull();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script('() => window.policyRuns'))->toBe(1);
});

it('keeps blocked the scripts of unknown services', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.acceptAll()');

    $page->script(<<<'JS'
        () => {
            for (const service of ['analytics', 'necessary', 'constructor', 'unknown']) {
                const script = document.createElement('script');
                script.type = 'text/plain';
                script.dataset.cookieConsent = service;
                script.textContent = `(window.unknownRuns ??= []).push('${service}');`;
                document.body.append(script);
            }
        }
        JS);

    $page->wait(0.2);

    expect($page->script('() => window.unknownRuns'))->toBeNull();
});
