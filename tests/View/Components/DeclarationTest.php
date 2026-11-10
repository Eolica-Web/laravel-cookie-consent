<?php

declare(strict_types=1);

use Carbon\CarbonInterval;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\Cookie;
use Eolica\LaravelCookieConsent\CookieConsentManager;
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
    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->id('shop')
        ->domain('tienda.test')
        ->policyUrl('/cookies')
        ->analytics(static fn(Category $category) => $category->service('google-analytics', static fn(Service $service) => $service->provider(
            'Google',
            'https://policies.google.com/privacy',
        )->cookie(
            name: '_ga',
            purpose: 'Distinguishes users.',
            duration: CarbonInterval::years(2),
        ))->service('hotjar', static fn(Service $service) => $service->provider('Hotjar')->localStorage(
            name: 'analytics_state',
            purpose: 'Keeps the state.',
        )))
        ->category('chat', static fn(Category $category) => $category->label('Chat')->description('Talk to us.')));

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
            '<h3 class="cookie-consent-declaration__title"> Chat </h3> <p class="cookie-consent-declaration__description">Talk to us.</p> <p class="cookie-consent-declaration__empty">This category does not use any cookies.</p>',
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
