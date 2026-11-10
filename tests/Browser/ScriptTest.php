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
    registerPolicy(static fn(CookiePolicy $policy) => $policy
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

function orderedPage(string $scripts): void
{
    $router = app(Router::class);

    $router->get('/ordered', static fn(): string => BladeCompiler::render(<<<BLADE
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
            <body>
                {$scripts}
            </body>
        </html>
        BLADE));

    $router->get('/slow-lib.js', static function (): Response {
        usleep(300_000);

        return new Response('window.libRuns = (window.libRuns ?? 0) + 1; window.Lib = {};', headers: [
            'Content-Type' => 'text/javascript',
        ]);
    });
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

it('rejects categories that are not an array', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $result = $page->script(<<<'JS'
        () => window.CookieConsent.accept('marketing').then(() => 'accepted', (error) => `${error.name}: ${error.message}`)
        JS);

    expect($result)
        ->toBe('TypeError: The categories to accept must be an array.')
        ->and($page->script(
            '() => [window.CookieConsent.needsConsent, window.CookieConsent.allows("marketing"), window.marketingRuns ?? null]',
        ))
        ->toBe([true, false, null]);
});

it('does not let the page change the consent', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();
    $changed = <<<'JS'
        () => {
            const consent = window.CookieConsent.consent;

            try {
                consent.categories.push('marketing');
            } catch {
            }

            return [Object.isFrozen(consent), Object.isFrozen(consent.categories), consent.id !== '', window.CookieConsent.allows('marketing')];
        }
        JS;

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script($changed))->toBe([true, true, true, false]);

    $page->refresh();

    expect($page->script($changed))->toBe([true, true, true, false]);

    $page->script(<<<'JS'
        () => {
            window.fetch = () => new Promise(() => {});
            window.CookieConsent.accept(['analytics']);
        }
        JS);

    expect($page->script($changed))->toBe([true, true, false, false]);
});

it('keeps its own consent when the server responds with something else', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            window.fetch = async () => new Response('{"data":{"ok":true}}', { headers: { 'Content-Type': 'application/json' } });
        }
        JS);

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script(
        '() => [window.CookieConsent.allows("analytics"), window.CookieConsent.consent.categories, window.CookieConsent.needsConsent]',
    ))->toBe([true, ['necessary', 'analytics'], false]);

    $page->assertNoJavaScriptErrors();
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

it('runs the callbacks and the updated event after the scripts activated before them', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            window.seen = [];
            document.addEventListener('cookie-consent:updated', () => window.seen.push(['updated', window.inlineRuns ?? null, window.externalRuns ?? null]));
            window.CookieConsent.whenAllowed('analytics', () => window.seen.push(['callback', window.inlineRuns ?? null, window.externalRuns ?? null]));
            window.CookieConsent.accept(['analytics']);
        }
        JS);

    $page->script(waitForExternalScript());

    expect($page->script('() => window.seen'))->toBe([['updated', 1, null], ['callback', 1, 1]]);
});

it('keeps running the callbacks after one fails', function (): void {
    $page = visit('/');

    $page->script(<<<'JS'
        () => {
            window.calls = [];
            window.errors = [];
            window.addEventListener('error', (event) => window.errors.push(event.error.message));
            window.CookieConsent.whenAllowed('analytics', () => { throw new Error('broken'); });
            window.CookieConsent.whenAllowed('analytics', () => window.calls.push('analytics'));
            window.CookieConsent.whenAllowed('marketing', () => window.calls.push('marketing'));
        }
        JS);

    expect($page->script('() => window.CookieConsent.accept(["analytics"]).then(() => "accepted")'))
        ->toBe('accepted')
        ->and($page->script('() => [window.calls, window.errors]'))
        ->toBe([['analytics'], ['broken']]);

    $page->script('() => window.CookieConsent.accept(["analytics", "marketing"])');

    expect($page->script('() => window.calls'))->toBe(['analytics', 'marketing']);
});

it('does not run the callbacks whose signal is aborted', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            window.calls = [];
            const aborted = new AbortController();
            aborted.abort();
            const pending = new AbortController();
            window.CookieConsent.whenAllowed('necessary', () => window.calls.push('necessary aborted'), { signal: aborted.signal });
            window.CookieConsent.whenAllowed('analytics', () => window.calls.push('analytics aborted'), { signal: aborted.signal });
            window.CookieConsent.whenAllowed('analytics', () => window.calls.push('analytics pending'), { signal: pending.signal });
            window.CookieConsent.whenAllowed('analytics', () => window.calls.push('analytics'), { signal: new AbortController().signal });
            pending.abort();
        }
        JS);

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script('() => window.calls'))->toBe(['analytics']);
});

it('reports the errors of the callbacks of allowed categories', function (): void {
    $page = visit('/');

    $page->script(<<<'JS'
        () => {
            window.errors = [];
            window.addEventListener('error', (event) => window.errors.push(event.error.message));
        }
        JS);

    expect($page->script(<<<'JS'
        () => {
            try {
                window.CookieConsent.whenAllowed('necessary', () => { throw new Error('broken'); });

                return 'returned';
            } catch {
                return 'thrown';
            }
        }
        JS))->toBe('returned')->and($page->script('() => window.errors'))->toBe(['broken']);
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

it('sends the xsrf token when there is one', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            const fetch = window.fetch;

            window.tokens = [];
            window.fetch = (url, options) => {
                window.tokens.push(options.headers['X-XSRF-TOKEN'] ?? null);

                return fetch(url, options);
            };
        }
        JS);

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script("() => { document.cookie = 'XSRF-TOKEN=a%3Db; path=/'; }");
    $page->script('() => window.CookieConsent.accept(["analytics", "marketing"])');

    expect($page->script('() => window.tokens'))->toBe([null, 'a=b']);
});

it('stores the consent without an xsrf token that can not be decoded', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script("() => { document.cookie = 'XSRF-TOKEN=%E0%A4%A; path=/'; }");

    expect($page->script('() => window.CookieConsent.accept(["analytics"]).then(() => "accepted")'))
        ->toBe('accepted')
        ->and($page->script(consentCookieField('id')))
        ->toMatch('/^[0-9A-HJKMNP-TV-Z]{26}$/');

    $page->assertNoJavaScriptErrors();
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

it('starts with an element whose id is the name of the global', function (): void {
    orderedPage('<div id="CookieConsent"></div>');

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script('() => window.CookieConsent instanceof HTMLElement'))
        ->toBeFalse()
        ->and($page->script('() => window.policyRuns'))
        ->toBe(1);
});

it('starts once when the script runs again from another url', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script(<<<'JS'
        async () => {
            const cookieConsent = window.CookieConsent;
            let ready = 0;
            document.addEventListener('cookie-consent:ready', () => ready++);
            await import(document.querySelector('script[type="module"][src]').src + '&again');

            return [window.CookieConsent === cookieConsent, ready, window.inlineRuns, window.policyRuns];
        }
        JS))->toBe([true, 0, 1, 1]);
});

it('replaces the consent cookies of other domains and paths', function (): void {
    orderedPage('');

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script(<<<'JS'
        () => {
            const value = document.cookie.split('; ').find((cookie) => cookie.startsWith('shop_consent=')).slice(13);
            document.cookie = `shop_consent=${value}; path=/ordered`;
        }
        JS);

    $page->script('() => window.CookieConsent.rejectAll()');
    $page->wait(0.5);
    $page->refresh();

    expect($page->script('() => window.CookieConsent.allows("analytics")'))
        ->toBeFalse()
        ->and($page->script(
            "() => document.cookie.split('; ').filter((cookie) => cookie.startsWith('shop_consent=')).length",
        ))
        ->toBe(1);
});

it('keeps the cookies that an allowed category also declares', function (): void {
    app()->forgetInstance(CookieConsentManager::class);

    registerPolicy(static fn(CookiePolicy $policy) => $policy
        ->cookie(name: 'shop_consent')
        ->analytics(static fn(Category $category) => $category->cookie(
            name: '_ga*',
            purpose: 'Distinguishes users.',
        )->localStorage(name: 'state*', purpose: 'Keeps the state.'))
        ->marketing(static fn(
            Category $category,
        ) => $category->service('ads', static fn(Service $service) => $service->cookie(
            name: '_gac_*',
            purpose: 'Measures the ads.',
        )->localStorage(name: 'state_ads', purpose: 'Keeps the ads.'))));

    $page = visit('/')->assertNoJavaScriptErrors();

    $setUp = <<<'JS'
        () => {
            document.cookie = '_ga=1; path=/';
            document.cookie = '_gac_x=1; path=/';
            localStorage.setItem('state', '1');
            localStorage.setItem('state_ads', '1');
        }
        JS;
    $remaining = <<<'JS'
        () => [document.cookie.split('; ').map((cookie) => cookie.split('=')[0]).sort(), Object.keys(localStorage).sort()]
        JS;

    $page->script($setUp);
    $page->script('() => window.CookieConsent.accept(["marketing"])');

    expect($page->script($remaining))->toBe([['_gac_x', 'shop_consent'], ['state_ads']]);

    $page->script($setUp);
    $page->refresh();

    expect($page->script($remaining))->toBe([['_gac_x', 'shop_consent'], ['state_ads']]);
});

it('keeps the necessary cookies that a denied category also matches', function (): void {
    app()->forgetInstance(CookieConsentManager::class);

    registerPolicy(static fn(CookiePolicy $policy) => $policy
        ->cookie(name: 'shop_consent')
        ->analytics(static fn(Category $category) => $category->cookie(name: 'shop*', purpose: 'Tracks the shop.')));

    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script("() => { window.fetch = async () => new Response('{}'); }");
    $page->script('() => window.CookieConsent.rejectAll()');

    expect($page->script("() => document.cookie.includes('shop_consent=')"))->toBeTrue();

    $page->refresh();

    expect($page->script(
        "() => [window.CookieConsent.needsConsent, document.cookie.includes('shop_consent=')]",
    ))->toBe([false, true]);
});

it('runs each script after the external scripts before it', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js"></script>
        <script type="text/plain" data-cookie-consent="stats">window.libSeen = typeof window.Lib;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(1);

    expect($page->script('() => window.libSeen'))->toBe('object');

    $page->refresh()->wait(1);

    expect($page->script('() => window.libSeen'))->toBe('object');
});

it('runs the callbacks after the scripts activated before them when the page loads', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats">window.stub = () => null;</script>
        <script>
            document.addEventListener('cookie-consent:ready', () => {
                window.readySeen = typeof window.stub;
                window.CookieConsent.whenAllowed('analytics', () => window.callbackSeen = typeof window.stub);
            });
        </script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->refresh();

    expect($page->script('() => [window.readySeen, window.callbackSeen]'))->toBe(['function', 'function']);
});

it('runs the callbacks after the external scripts activated before them', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js"></script>
        <script>
            document.addEventListener('cookie-consent:ready', () => {
                window.seen = [];
                window.CookieConsent.whenAllowed('analytics', () => window.seen.push(typeof window.Lib));
            });
        </script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(1);

    expect($page->script('() => window.seen'))->toBe(['object']);

    $page->refresh()->wait(1);

    expect($page->script('() => window.seen'))->toBe(['object']);
});

it('does not run the callbacks whose signal is aborted while an external script loads', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js"></script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            window.calls = [];
            const controller = new AbortController();
            window.CookieConsent.accept(['analytics']);
            window.CookieConsent.whenAllowed('analytics', () => window.calls.push('aborted'), { signal: controller.signal });
            window.CookieConsent.whenAllowed('analytics', () => window.calls.push(typeof window.Lib));
            controller.abort();
        }
        JS);

    $page->wait(1);

    expect($page->script('() => window.calls'))->toBe(['object']);
});

it('does not wait for the async external scripts', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js" async></script>
        <script type="text/plain" data-cookie-consent="stats">window.libSeen = typeof window.Lib;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(1);

    expect($page->script('() => [window.libSeen, window.libRuns]'))->toBe(['undefined', 1]);
});

it('keeps running the scripts after an external script fails', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/missing.js"></script>
        <script type="text/plain" data-cookie-consent="stats">window.afterError = true;</script>
        HTML);

    $page = visit('/ordered');

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(0.5);

    expect($page->script('() => window.afterError'))->toBeTrue();
});

it('does not wait for the external scripts that the browser does not run', function (string $script): void {
    orderedPage(<<<HTML
        {$script}
        <script type="text/plain" data-cookie-consent="stats">window.afterSkipped = true;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(1);

    expect($page->script('() => [window.afterSkipped, window.libRuns]'))->toBe([true, null]);
})->with([
    'nomodule' => '<script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js" nomodule></script>',
    'a type that is not javascript' => '<script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js" data-type="text/x-template"></script>',
]);

it('loads a source after a script with that source that the browser does not run', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js" nomodule></script>
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js"></script>
        <script type="text/plain" data-cookie-consent="stats">window.libSeen = typeof window.Lib;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(1);

    expect($page->script('() => [window.libSeen, window.libRuns]'))->toBe(['object', 1]);
});

it('waits for an external script that is already loading', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js" async></script>
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js"></script>
        <script type="text/plain" data-cookie-consent="stats">window.libSeen = typeof window.Lib;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->wait(1);

    expect($page->script('() => [window.libSeen, window.libRuns]'))->toBe(['object', 1]);
});

it('skips the scripts removed while waiting for an external script', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js"></script>
        <div id="removed">
            <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js?removed"></script>
        </div>
        <script type="text/plain" data-cookie-consent="stats">window.afterRemoved = true;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => { window.CookieConsent.accept(["analytics"]); document.getElementById("removed").remove(); }');
    $page->wait(1);

    expect($page->script('() => [window.afterRemoved, window.libRuns]'))->toBe([true, 1]);
});

it('activates a removed script when it is added again', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js"></script>
        <div id="removed">
            <script type="text/plain" data-cookie-consent="stats" data-src="/slow-lib.js?removed"></script>
        </div>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script(
        '() => { window.CookieConsent.accept(["analytics"]); window.removed = document.getElementById("removed"); window.removed.remove(); }',
    );
    $page->wait(1);
    $page->script('() => { document.body.append(window.removed); }');
    $page->wait(1);

    expect($page->script('() => window.libRuns'))->toBe(2);
});

it('removes the cookies of the categories that are not allowed when the page loads', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            const consent = { id: '', policy: 'default', revision: 'old', categories: ['necessary', 'analytics'], at: Math.floor(Date.now() / 1000) };
            document.cookie = `shop_consent=${encodeURIComponent(JSON.stringify(consent))}; path=/`;
            document.cookie = '_ga=1; path=/';
            document.cookie = 'other=1; path=/';
            localStorage.setItem('analytics_state', '1');
        }
        JS);

    $page->refresh();

    expect($page->script('() => window.CookieConsent.needsConsent'))
        ->toBeTrue()
        ->and($page->script("() => document.cookie.split('; ').map((cookie) => cookie.split('=')[0]).sort()"))
        ->toBe(['other', 'shop_consent'])
        ->and($page->script('() => Object.keys(localStorage)'))
        ->toBe([]);
});

it('removes the cookies of the categories that are not allowed when rejecting without a consent', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<'JS'
        () => {
            document.cookie = '_ga=1; path=/';
            window.beforeReload = true;
        }
        JS);

    $page->script('() => window.CookieConsent.rejectAll()');

    expect($page->script('() => window.beforeReload'))
        ->toBeTrue()
        ->and($page->script("() => document.cookie.includes('_ga=')"))
        ->toBeFalse();
});

it('does not reload the page when the revoked categories ran nothing', function (): void {
    app(Router::class)->get('/empty', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
        </html>
        BLADE));

    $page = visit('/empty')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["marketing"])');
    $page->script('() => { window.beforeReload = true; }');
    $page->script('() => window.CookieConsent.rejectAll()');
    $page->wait(0.5);

    expect($page->script('() => window.beforeReload'))
        ->toBeTrue()
        ->and($page->script('() => window.CookieConsent.allows("marketing")'))
        ->toBeFalse();
});

it('reloads the page when a revoked category ran a callback', function (): void {
    app(Router::class)->get('/empty', static fn(): string => BladeCompiler::render(<<<'BLADE'
        <html>
            <head>
                <x-cookie-consent::scripts />
            </head>
        </html>
        BLADE));

    $page = visit('/empty')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.whenAllowed("marketing", () => { window.marketingRuns = 1; })');
    $page->script('() => window.CookieConsent.accept(["marketing"])');
    $page->script('() => { window.beforeReload = true; }');
    $page->script('() => window.CookieConsent.rejectAll()');
    $page->wait(0.5);

    expect($page->script('() => window.beforeReload'))->toBeNull();
});

it('removes the cookies set again before the page reloads', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script(<<<'JS'
        () => {
            const fetch = window.fetch;

            window.beforeReload = true;
            window.fetch = (url, options) => {
                document.cookie = '_ga=again; path=/';

                return fetch(url, options);
            };
        }
        JS);

    $page->script('() => window.CookieConsent.rejectAll()');
    $page->wait(0.5);

    expect($page->script('() => window.beforeReload'))
        ->toBeNull()
        ->and($page->script("() => document.cookie.includes('_ga=')"))
        ->toBeFalse();
});

it('reloads the page when a navigation brings the configuration of another policy', function (
    string $change,
    bool $reloads,
): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script(<<<JS
        () => {
            const config = JSON.parse(document.querySelector('script[data-cookie-consent-config]').textContent);
            const element = document.createElement('script');

            {$change}

            element.type = 'application/json';
            element.dataset.cookieConsentConfig = '';
            element.textContent = JSON.stringify(config);
            window.beforeReload = true;
            document.head.append(element);
        }
        JS);

    $page->wait(0.5);

    expect($page->script('() => window.beforeReload'))->toBe($reloads ? null : true);
})->with([
    'another policy' => ["config.policy = 'admin';", true],
    'another revision' => ["config.revision = 'new';", true],
    'the same policy' => ['', false],
]);

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

it('runs the escaped scripts of the services as they were declared', function (): void {
    app()->forgetInstance(CookieConsentManager::class);

    registerPolicy(static fn(CookiePolicy $policy) => $policy
        ->cookie(name: 'shop_consent')
        ->analytics(static fn(
            Category $category,
        ) => $category->service('stats', static fn(Service $service) => $service->script(
            content: 'window.html = "</script><!--<script>";',
        ))));

    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script('() => [window.html, window.inlineRuns]'))->toBe(['</script><!--<script>', 1]);
});

it('finds the runnable copy of a script by its id', function (): void {
    orderedPage(<<<'HTML'
        <script id="widget" type="text/plain" data-cookie-consent="stats">window.foundItself = document.getElementById('widget') === document.currentScript;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script('() => window.foundItself'))->toBeTrue();
});

it('does not activate the runnable copy of a script again when it looks blocked', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script(<<<'JS'
        () => {
            const original = document.body.querySelector('script[type="text/plain"][data-cookie-consent="stats"]:not([data-src])');
            const copy = original.previousElementSibling;

            copy.type = 'text/plain';
            copy.dataset.cookieConsent = 'stats';
            original.remove();
        }
        JS);
    $page->script('() => window.CookieConsent.accept(["analytics", "marketing"])');

    expect($page->script('() => [window.inlineRuns, window.policyRuns, window.marketingRuns]'))->toBe([1, 1, 1]);
});

it('marks the runnable copies to be run once by livewire navigations', function (): void {
    $page = visit('/')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script(<<<'JS'
        () => [...document.querySelectorAll('script[data-cookie-consent="stats"]')]
            .map((original) => original.previousElementSibling.hasAttribute('data-navigate-once'))
        JS))->toBe([true, true, true]);
});

it('runs the blocked scripts marked to be run once only once across livewire navigations', function (): void {
    orderedPage(<<<'HTML'
        <script type="text/plain" data-cookie-consent="stats" data-navigate-once nonce="first">window.onceRuns = (window.onceRuns ?? 0) + 1;</script>
        <script type="text/plain" data-cookie-consent="stats">window.everyRuns = (window.everyRuns ?? 0) + 1;</script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    $page->script('() => window.CookieConsent.accept(["analytics"])');

    expect($page->script(<<<'JS'
        async () => {
            const html = (await (await fetch('/ordered')).text()).replace('nonce="first"', 'nonce="second"');
            const body = new DOMParser().parseFromString(html, 'text/html').body;

            document.body.replaceWith(document.adoptNode(body));
            await new Promise((resolve) => setTimeout(resolve, 50));

            return [window.onceRuns, window.everyRuns];
        }
        JS))->toBe([1, 2]);
});

it('works when the browser blocks the storage', function (): void {
    orderedPage(<<<'HTML'
        <script>
            for (const name of ['localStorage', 'sessionStorage']) {
                Object.defineProperty(window, name, {
                    get() {
                        throw new DOMException('The operation is insecure.', 'SecurityError');
                    },
                });
            }
        </script>
        HTML);

    $page = visit('/ordered')->assertNoJavaScriptErrors();

    expect($page->script('() => window.CookieConsent.needsConsent'))->toBeTrue();

    $page->script('() => window.CookieConsent.accept(["analytics"])');
    $page->script('() => window.CookieConsent.rejectAll()');

    $page->assertNoJavaScriptErrors();
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
