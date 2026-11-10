<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Service;
use Eolica\LaravelCookieConsent\View\Components\Banner;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Illuminate\View\Compilers\BladeCompiler;

covers(Banner::class);

beforeEach(function (): void {
    config()->set('session.cookie', 'shop_session');
    config()->set('session.lifetime', 120);
    config()->set('session.expire_on_close', false);

    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->policyUrl('/cookies')
        ->necessary(static fn(Category $category) => $category->session())
        ->analytics(static fn(Category $category) => $category
            ->service('google-analytics', static fn(Service $service) => $service->provider(
                'Google',
                'https://policies.google.com/privacy',
            )->cookie(name: '_ga', purpose: 'Distinguishes users.', duration: CarbonInterval::years(2)))
            ->localStorage(name: 'analytics_state', purpose: 'Keeps the state.')
            ->sessionStorage(name: 'analytics_tab', purpose: 'Keeps the tab.'))
        ->category('chat', static fn(Category $category) => $category->label('Chat')->description('Talk to us.')));
});

function renderBanner(string $blade = '<x-cookie-consent::banner />', string $url = 'https://tienda.test'): string
{
    app()->instance('request', Request::create($url));

    return BladeCompiler::render($blade);
}

it('renders the hidden banner with its actions', function (): void {
    $html = renderBanner();

    expect($html)
        ->toContain(
            '<div class="cookie-consent" data-cookie-consent-banner role="region" aria-labelledby="cookie-consent-title" hidden>',
        )
        ->toContain('<p class="cookie-consent__title" id="cookie-consent-title">We use cookies</p>')
        ->toContain('data-cookie-consent-action="reject-all">Reject all</button>')
        ->toContain('data-cookie-consent-action="show-preferences">Customize</button>')
        ->toContain('data-cookie-consent-action="accept-all">Accept all</button>')
        ->toContain('<a class="cookie-consent__link" href="/cookies">Cookie policy</a>');
});

it('renders the preferences of every category', function (): void {
    $html = renderBanner();

    expect($html)
        ->toContain(
            '<dialog class="cookie-consent-preferences" data-cookie-consent-preferences aria-labelledby="cookie-consent-preferences-title">',
        )
        ->toContain('data-cookie-consent-action="save">Save preferences</button>')
        ->toContain('data-cookie-consent-action="close-preferences" aria-label="Close">&times;</button>')
        ->toContain('id="cookie-consent-category-necessary" data-cookie-consent-category="necessary" checked disabled>')
        ->toContain(
            '<label class="cookie-consent-category__label" for="cookie-consent-category-necessary">Necessary</label>',
        )
        ->toContain('<span class="cookie-consent-category__badge">Always active</span>')
        ->toContain('id="cookie-consent-category-analytics" data-cookie-consent-category="analytics"  >')
        ->toContain('for="cookie-consent-category-analytics">Analytics</label>')
        ->toContain('Help us understand how the website is used so we can improve it.')
        ->toContain('for="cookie-consent-category-chat">Chat</label>')
        ->toContain('<p class="cookie-consent__text">Talk to us.</p>')
        ->and(substr_count($html, needle: 'Always active'))
        ->toBe(1);
});

it('renders the cookies of every category', function (): void {
    $html = Str::squish(renderBanner());

    expect($html)
        ->toContain('<summary>2 cookies</summary>')
        ->toContain('<summary>3 cookies</summary>')
        ->toContain(
            '<td><code>cookie_consent_shop</code></td> <td> This website </td> <td>Stores your cookie consent choices.</td> <td>1 year</td>',
        )
        ->toContain(
            '<td><code>shop_session</code></td> <td> This website </td> <td>Identifies your session on the website.</td> <td>2 hours</td>',
        )
        ->toContain(
            '<td><code>_ga</code></td> <td> <a class="cookie-consent__link" href="https://policies.google.com/privacy">Google</a> </td> <td>Distinguishes users.</td> <td>2 years</td>',
        )
        ->toContain(
            '<td><code>analytics_state</code></td> <td> This website </td> <td>Keeps the state.</td> <td>Persistent</td>',
        )
        ->toContain(
            '<td><code>analytics_tab</code></td> <td> This website </td> <td>Keeps the tab.</td> <td>Session</td>',
        )
        ->and(substr_count($html, needle: '<details'))
        ->toBe(2);
});

it('renders the texts in the application locale', function (): void {
    app()->setLocale('es');

    $html = Str::squish(renderBanner());

    expect($html)
        ->toContain('Usamos cookies')
        ->toContain('Rechazar todas')
        ->toContain(
            '<td><code>_ga</code></td> <td> <a class="cookie-consent__link" href="https://policies.google.com/privacy">Google</a> </td> <td>Distinguishes users.</td> <td>2 años</td>',
        );
});

it('renders the singular cookie count', function (): void {
    app(CookieConsentManager::class)->policy(
        static fn(CookiePolicy $policy) => $policy->id('blog')->domain('blog.test'),
    );

    expect(renderBanner(url: 'https://blog.test'))->toContain('<summary>1 cookie</summary>');
});

it('renders no policy link without a policy url', function (): void {
    app(CookieConsentManager::class)->policy(
        static fn(CookiePolicy $policy) => $policy->id('blog')->domain('blog.test'),
    );

    expect(renderBanner(url: 'https://blog.test'))->not->toContain('class="cookie-consent__link"');
});

it('renders the styles with the nonce', function (): void {
    expect(renderBanner('<x-cookie-consent::banner nonce="abc" />'))
        ->toStartWith('<style  nonce="abc" >')
        ->toContain('.cookie-consent {');
});

it('renders the styles without a nonce', function (): void {
    expect(renderBanner())->toStartWith('<style >');
});

it('renders no styles when disabled', function (): void {
    expect(renderBanner('<x-cookie-consent::banner :styles="false" />'))
        ->not
        ->toContain('<style')
        ->toStartWith('<div class="cookie-consent"');
});

it('merges the given attributes into the banner', function (): void {
    expect(renderBanner('<x-cookie-consent::banner :styles="false" class="is-dark" id="cookies" />'))
        ->toStartWith('<div class="cookie-consent is-dark" id="cookies" data-cookie-consent-banner');
});

it('renders nothing without a policy', function (): void {
    expect(renderBanner(url: 'https://other.test'))->toBe('');
});

it('renders the slot with the methods of the component and without the styles', function (): void {
    expect(Str::squish(renderBanner(<<<'BLADE'
        <x-cookie-consent::banner>
            <div data-cookie-consent-banner hidden>
                <a href="{{ $component->policyUrl() }}">Policy</a>
                @foreach ($component->categories() as $category)
                    <label><input type="checkbox" data-cookie-consent-category="{{ $category->getKey() }}"> {{ $component->label($category) }}</label>
                @endforeach
            </div>
        </x-cookie-consent::banner>
        BLADE)))
        ->toBe(
            '<div data-cookie-consent-banner hidden> <a href="/cookies">Policy</a> '
            . '<label><input type="checkbox" data-cookie-consent-category="necessary"> Necessary</label> '
            . '<label><input type="checkbox" data-cookie-consent-category="analytics"> Analytics</label> '
            . '<label><input type="checkbox" data-cookie-consent-category="chat"> Chat</label> </div>',
        );
});

it('does not evaluate the slot without a policy', function (): void {
    expect(renderBanner(<<<'BLADE'
        <x-cookie-consent::banner>
            @php(throw new RuntimeException('The slot was evaluated.'))
        </x-cookie-consent::banner>
        BLADE, url: 'https://other.test'))->toBe('');
});
