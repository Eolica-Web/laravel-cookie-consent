<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\Cookie;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Service;
use Eolica\LaravelCookieConsent\StorageType;
use Eolica\LaravelCookieConsent\View\Components\CookiePolicyComponent;
use Eolica\LaravelCookieConsent\View\Components\Declaration;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\View\Compilers\BladeCompiler;

covers(Declaration::class, CookiePolicyComponent::class);

beforeEach(function (): void {
    registerPolicy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->policyUrl('/cookies')
        ->analytics(static fn(Category $category) => $category
            ->service('google-analytics', static fn(Service $service) => $service->provider(
                'Google',
                'https://policies.google.com/privacy',
            )->cookie(name: '_ga', purpose: 'Distinguishes users.', duration: CarbonInterval::years(2)))
            ->service('hotjar', static fn(Service $service) => $service->provider('Hotjar')->localStorage(
                name: 'analytics_state',
                purpose: 'Keeps the state.',
            ))
            ->service('matomo', static fn(Service $service) => $service->add(
                new Cookie(
                    '__cf_bm',
                    'Blocks bots.',
                    provider: 'Cloudflare',
                    providerUrl: 'https://www.cloudflare.com/privacypolicy/',
                ),
            )))
        ->category('chat', static fn(Category $category) => $category
            ->label('Chat')
            ->description('Talk to us.')
            ->service('crisp', static fn(Service $service) => $service->provider(
                'Crisp',
                'https://crisp.chat/en/privacy/',
            ))));

    app()->instance('request', Request::create('https://tienda.test'));
});

function renderDeclaration(string $blade = '<x-cookie-consent::declaration />'): string
{
    return trim((string) preg_replace('/\s+/', replacement: ' ', subject: BladeCompiler::render($blade)));
}

function declaration(): Declaration
{
    return app(Declaration::class);
}

it('renders the cookies of every category', function (): void {
    expect(renderDeclaration())
        ->toStartWith('<div class="cookie-consent-declaration"> <section class="cookie-consent-declaration__category">')
        ->toContain(
            '<h3 class="cookie-consent-declaration__title"> Necessary <span class="cookie-consent-declaration__badge">Always active</span> </h3>',
            '<p class="cookie-consent-declaration__description">Required for the website to work properly. They cannot be disabled.</p>',
            '<tr> <td><code>cookie_consent_shop</code></td> <td> This website </td> <td>Stores your cookie consent choices.</td> <td>1 year</td> </tr>',
            '<h3 class="cookie-consent-declaration__title"> Analytics </h3>',
            '<th scope="col">Name</th> <th scope="col">Provider</th> <th scope="col">Purpose</th> <th scope="col">Duration</th>',
            '<tr> <td><code>_ga</code></td> <td> <a class="cookie-consent__link" href="https://policies.google.com/privacy">Google</a> </td> <td>Distinguishes users.</td> <td>2 years</td> </tr>',
            '<tr> <td><code>analytics_state</code></td> <td> Hotjar </td> <td>Keeps the state.</td> <td>Persistent</td> </tr>',
            '<p class="cookie-consent-declaration__description">Required for the website to work properly. They cannot be disabled.</p> <div class="cookie-consent-declaration__table">',
            '<tr> <td><code>__cf_bm</code></td> <td> <a class="cookie-consent__link" href="https://www.cloudflare.com/privacypolicy/">Cloudflare</a> </td> <td>Blocks bots.</td> <td>Session</td> </tr>',
            '<p class="cookie-consent-declaration__providers"> Providers: <a class="cookie-consent__link" href="https://policies.google.com/privacy">Google</a>, Hotjar, <a class="cookie-consent__link" href="https://www.cloudflare.com/privacypolicy/">Cloudflare</a> </p>',
            '<h3 class="cookie-consent-declaration__title"> Chat </h3> <p class="cookie-consent-declaration__description">Talk to us.</p> <p class="cookie-consent-declaration__providers"> Providers: <a class="cookie-consent__link" href="https://crisp.chat/en/privacy/">Crisp</a> </p> <p class="cookie-consent-declaration__empty">This category does not use any cookies.</p>',
        )
        ->toEndWith(
            '<button class="cookie-consent-declaration__button" type="button" data-cookie-consent-action="show-preferences">Change cookie preferences</button> </div>',
        );
});

it('renders the translated declaration', function (): void {
    app()->setLocale('es');

    expect(renderDeclaration())
        ->toContain(
            'Necesarias <span class="cookie-consent-declaration__badge">Siempre activas</span>',
            '<th scope="col">Nombre</th> <th scope="col">Proveedor</th> <th scope="col">Finalidad</th> <th scope="col">Duración</th>',
            '<td> Esta web </td>',
            '<td>2 años</td>',
            '<td>Persistente</td>',
            'Esta categoría no usa ninguna cookie.',
            'Proveedores:',
            'Cambiar preferencias de cookies',
        );
});

it('merges the given attributes', function (): void {
    expect(renderDeclaration('<x-cookie-consent::declaration class="prose" id="cookies" />'))
        ->toStartWith('<div class="cookie-consent-declaration prose" id="cookies">');
});

it('renders nothing without a policy', function (): void {
    app()->instance('request', Request::create('https://blog.test'));

    expect(renderDeclaration())->toBe('');
});

it('renders the slot with the methods of the component', function (): void {
    expect(renderDeclaration(<<<'BLADE'
        <x-cookie-consent::declaration>
            <a href="{{ $component->policyUrl() }}">Policy</a>
            @foreach ($component->categories() as $category)
                <h2>{{ $component->label($category) }}: {{ $component->description($category) }}</h2>
                @foreach ($category->getCookies() as $cookie)
                    <p>{{ $cookie->name }}, {{ $component->provider($cookie) }}, {{ $component->purpose($cookie) }}, {{ $component->duration($cookie) }}</p>
                @endforeach
            @endforeach
        </x-cookie-consent::declaration>
        BLADE))
        ->toBe(
            '<a href="/cookies">Policy</a> '
            . '<h2>Necessary: Required for the website to work properly. They cannot be disabled.</h2> '
            . '<p>cookie_consent_shop, This website, Stores your cookie consent choices., 1 year</p> '
            . '<h2>Analytics: Help us understand how the website is used so we can improve it.</h2> '
            . '<p>_ga, Google, Distinguishes users., 2 years</p> '
            . '<p>analytics_state, Hotjar, Keeps the state., Persistent</p> '
            . '<p>__cf_bm, Cloudflare, Blocks bots., Session</p> '
            . '<h2>Chat: Talk to us.</h2>',
        );
});

it('does not evaluate the slot without a policy', function (): void {
    app()->instance('request', Request::create('https://blog.test'));

    expect(renderDeclaration(<<<'BLADE'
        <x-cookie-consent::declaration>
            @php(throw new RuntimeException('The slot was evaluated.'))
        </x-cookie-consent::declaration>
        BLADE))->toBe('');
});

it('renders the default view without slot data', function (): void {
    expect(declaration()->render()([]))->toEqual(app(Factory::class)->make('cookie-consent::declaration'));
});

it('lists the categories of the policy', function (): void {
    expect(array_map(static fn(Category $category): string => $category->getKey(), declaration()->categories()))
        ->toBe(['necessary', 'analytics', 'chat'])
        ->and(declaration()->policyUrl())
        ->toBe('/cookies');
});

it('lists no categories without a policy', function (): void {
    app()->instance('request', Request::create('https://blog.test'));

    expect(declaration()->categories())
        ->toBe([])
        ->and(declaration()->policyUrl())
        ->toBeNull()
        ->and(declaration()->shouldRender())
        ->toBeFalse();
});

it('uses the key when the translation is not a string', function (): void {
    $category = new Category('stats')->label('cookie-consent::declaration.table');

    expect(declaration()->label($category))->toBe('cookie-consent::declaration.table');
});

it('describes the durations with exact units', function (DateInterval $duration, string $locale, string $text): void {
    app()->setLocale($locale);

    expect(declaration()->duration(new Cookie('_ga', 'Distinguishes users.', duration: $duration)))->toBe($text);
})->with([
    'years' => [CarbonInterval::years(2), 'en', '2 years'],
    'months over a year' => [CarbonInterval::months(13), 'en', '1 year 1 month'],
    'months almost two years' => [CarbonInterval::months(23), 'en', '1 year 11 months'],
    'days over a year' => [CarbonInterval::days(365), 'en', '365 days'],
    'minutes over a month' => [CarbonInterval::minutes(43_200), 'en', '30 days'],
    'minutes over an hour' => [CarbonInterval::minutes(90), 'en', '1 hour 30 minutes'],
    'hours over a day' => [CarbonInterval::hours(25), 'en', '1 day 1 hour'],
    'seconds' => [CarbonInterval::seconds(61), 'en', '1 minute 1 second'],
    'seconds almost a day' => [CarbonInterval::seconds(86_399), 'en', '23 hours 59 minutes 59 seconds'],
    'weeks' => [CarbonInterval::weeks(2), 'en', '14 days'],
    'years and time' => [new DateInterval('P1Y6MT90M'), 'en', '1 year 6 months 1 hour 30 minutes'],
    'translated' => [CarbonInterval::days(365), 'es', '365 días'],
]);

it('describes the duration of the cookies', function (Cookie $cookie, string $duration): void {
    expect(declaration()->duration($cookie))->toBe($duration);
})->with([
    'with a duration' => [
        new Cookie('_ga', 'Distinguishes users.', duration: CarbonInterval::months(13)),
        '1 year 1 month',
    ],
    'session cookie' => [new Cookie('session', 'Identifies the session.'), 'Session'],
    'local storage' => [new Cookie('state', 'Keeps the state.', StorageType::LocalStorage), 'Persistent'],
    'session storage' => [new Cookie('tab', 'Keeps the tab.', StorageType::SessionStorage), 'Session'],
]);
