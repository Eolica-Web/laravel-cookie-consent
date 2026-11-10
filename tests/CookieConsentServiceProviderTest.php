<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookieConsentServiceProvider;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\CookiePolicyProvider;
use Eolica\LaravelCookieConsent\Http\Controllers\ScriptController;
use Eolica\LaravelCookieConsent\Http\Controllers\StoreConsentController;
use Illuminate\Contracts\Console\Kernel as ConsoleKernel;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Contracts\View\Factory;
use Illuminate\Http\Request;
use Illuminate\Routing\Router;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

covers(CookieConsentServiceProvider::class);

it('registers the manager as a singleton', function (): void {
    expect(app(CookieConsentManager::class))->toBe(app(CookieConsentManager::class));
});

it('registers the routes', function (string $name, string $uri, array $methods, string $action): void {
    $route = app(Router::class)->getRoutes()->getByName($name);

    expect($route?->uri())
        ->toBe($uri)
        ->and($route?->methods())
        ->toBe($methods)
        ->and($route?->getActionName())
        ->toBe($action);
})->with([
    ['cookie-consent.store', 'cookie-consent', ['POST'], StoreConsentController::class],
    ['cookie-consent.script', 'cookie-consent/cookie-consent.js', ['GET', 'HEAD'], ScriptController::class],
]);

it('loads the views', function (): void {
    expect(app(Factory::class)->exists('cookie-consent::scripts'))->toBeTrue();
});

it('publishes the views', function (): void {
    $paths = ServiceProvider::pathsToPublish(CookieConsentServiceProvider::class, 'cookie-consent-views');

    expect(array_map(realpath(...), array_keys($paths)))
        ->toBe([realpath(__DIR__ . '/../resources/views')])
        ->and(array_values($paths))
        ->toBe([resource_path('views/vendor/cookie-consent')]);
});

it('registers the blade components', function (): void {
    expect(app(BladeCompiler::class)->getClassComponentNamespaces())
        ->toHaveKey('cookie-consent', 'Eolica\\LaravelCookieConsent\\View\\Components');
});

it('registers the commands', function (): void {
    expect(app(ConsoleKernel::class)->all())->toHaveKey('make:cookie-policy');
});

it('publishes the stub', function (): void {
    $paths = ServiceProvider::pathsToPublish(CookieConsentServiceProvider::class, 'cookie-consent-stubs');

    expect(array_map(realpath(...), array_keys($paths)))
        ->toBe([realpath(__DIR__ . '/../stubs/cookie-policy-provider.stub')])
        ->and(array_values($paths))
        ->toBe([base_path('stubs/cookie-policy-provider.stub')]);
});

it('loads the translations', function (string $locale, string $label): void {
    app()->setLocale($locale);

    expect(__('cookie-consent::categories.necessary.label'))->toBe($label);
})->with([
    ['en', 'Necessary'],
    ['es', 'Necesarias'],
]);

it('publishes the translations', function (): void {
    $paths = ServiceProvider::pathsToPublish(CookieConsentServiceProvider::class, 'cookie-consent-translations');

    expect(array_map(realpath(...), array_keys($paths)))
        ->toBe([realpath(__DIR__ . '/../lang')])
        ->and(array_values($paths))
        ->toBe([lang_path('vendor/cookie-consent')]);
});

it('reads the unencrypted consent cookie through the web middleware', function (): void {
    config()->set('app.key', 'base64:' . base64_encode(random_bytes(32)));

    app()->register(new class(app()) extends CookiePolicyProvider {
        public function policy(CookiePolicy $policy): CookiePolicy
        {
            return $policy->cookie(name: 'shop_consent')->analytics(static fn() => null);
        }
    });

    app(Router::class)
        ->get('/consent', static fn(Request $request, CookieConsentManager $manager): array => [
            'analytics' => $manager->allows($request, 'analytics'),
        ])
        ->middleware('web');

    $response = app(Kernel::class)->handle(Request::create('/consent', cookies: [
        'shop_consent' => json_encode([
            'id' => '01JC0000000000000000000000',
            'policy' => 'default',
            'revision' => app(CookieConsentManager::class)->getPolicy('default')->getRevision(),
            'categories' => ['analytics'],
            'at' => time(),
        ], JSON_THROW_ON_ERROR),
    ]));

    expect($response->getContent())->toBe('{"analytics":true}');
});

/**
 * @param array<mixed>|null $categories
 */
function renderWithConsent(string $blade, ?array $categories): string
{
    app(CookieConsentManager::class)->policy(static fn(CookiePolicy $policy) => $policy
        ->cookie(name: 'shop_consent')
        ->analytics(static fn() => null)
        ->marketing(static fn() => null));

    $revision = app(CookieConsentManager::class)->getPolicy('default')->getRevision();

    app()->instance('request', Request::create(
        'https://tienda.test',
        cookies: $categories === null
            ? []
            : [
                'shop_consent' => json_encode([
                    'id' => '01JC0000000000000000000000',
                    'policy' => 'default',
                    'revision' => $revision,
                    'categories' => $categories,
                    'at' => time(),
                ], JSON_THROW_ON_ERROR),
            ],
    ));

    return trim(BladeCompiler::render($blade));
}

it('renders the consent directive when the category is allowed', function (?array $categories, string $output): void {
    expect(renderWithConsent("@consent('analytics') yes @else no @endconsent", $categories))->toBe($output);
})->with([
    'allowed' => [['necessary', 'analytics'], 'yes'],
    'not allowed' => [['necessary', 'marketing'], 'no'],
    'without consent' => [null, 'no'],
]);

it('renders the necessary category without consent', function (): void {
    expect(renderWithConsent("@consent('necessary') yes @else no @endconsent", categories: null))->toBe('yes');
});

it('renders the unless consent directive', function (): void {
    expect(renderWithConsent("@unlessconsent('analytics') blocked @endconsent", categories: null))->toBe('blocked');
});
