<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Console\MakeCookiePolicyCommand;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicyProvider;
use Illuminate\Contracts\Console\Kernel;
use Illuminate\Filesystem\Filesystem;

covers(MakeCookiePolicyCommand::class);

beforeEach(function (): void {
    app()->setBasePath(sys_get_temp_dir() . '/cookie-consent-' . bin2hex(random_bytes(8)));

    $files = new Filesystem();
    $files->ensureDirectoryExists(base_path('bootstrap'));
    $files->put(base_path('composer.json'), '{"autoload": {"psr-4": {"App\\\\": "app/"}}}');
    $files->put(
        base_path('bootstrap/providers.php'),
        "<?php\n\nreturn [\n    App\\Providers\\AppServiceProvider::class,\n];\n",
    );
});

afterEach(function (): void {
    new Filesystem()->deleteDirectory(base_path());
});

/**
 * @param array<string, mixed> $arguments
 */
function makeCookiePolicy(array $arguments): int
{
    return app(Kernel::class)->call('make:cookie-policy', $arguments);
}

function putFile(string $path, string $contents): void
{
    new Filesystem()->ensureDirectoryExists(dirname($path));

    file_put_contents($path, $contents);
}

it('creates the cookie policy provider and registers it', function (): void {
    expect(makeCookiePolicy(['name' => 'CookiePolicyProvider']))->toBe(0);

    expect(file_get_contents(base_path('app/Providers/CookiePolicyProvider.php')))
        ->toBe(<<<'PHP'
            <?php

            namespace App\Providers;

            use Eolica\LaravelCookieConsent\Category;
            use Eolica\LaravelCookieConsent\CookiePolicy;
            use Eolica\LaravelCookieConsent\CookiePolicyProvider as BaseCookiePolicyProvider;

            class CookiePolicyProvider extends BaseCookiePolicyProvider
            {
                public function policy(CookiePolicy $policy): CookiePolicy
                {
                    return $policy
                        ->necessary(fn (Category $category) => $category->session()->csrf());
                }
            }

            PHP)
        ->and(file_get_contents(base_path('bootstrap/providers.php')))
        ->toBe(<<<'PHP'
            <?php

            return [
                App\Providers\AppServiceProvider::class,
                App\Providers\CookiePolicyProvider::class,
            ];

            PHP);
});

it('creates the cookie policy provider in the given namespace', function (): void {
    makeCookiePolicy(['name' => 'App\\Shop\\Providers\\CookiePolicyProvider']);

    expect(file_get_contents(base_path('app/Shop/Providers/CookiePolicyProvider.php')))
        ->toContain("namespace App\\Shop\\Providers;\n")
        ->and(file_get_contents(base_path('bootstrap/providers.php')))
        ->toContain("    App\\Shop\\Providers\\CookiePolicyProvider::class,\n");
});

it('creates a cookie policy provider that registers its policy', function (): void {
    makeCookiePolicy(['name' => 'GeneratedCookiePolicyProvider']);

    require base_path('app/Providers/GeneratedCookiePolicyProvider.php');

    $provider = app()->register('App\\Providers\\GeneratedCookiePolicyProvider');
    $necessary = app(CookieConsentManager::class)->getPolicy('default')->getCategories()['necessary'];

    expect($provider)->toBeInstanceOf(CookiePolicyProvider::class)->and($necessary->getCookies())->toHaveCount(3);
});

it('does not overwrite an existing cookie policy provider', function (): void {
    putFile(base_path('app/Providers/CookiePolicyProvider.php'), contents: 'existing');

    expect(makeCookiePolicy(['name' => 'CookiePolicyProvider']))
        ->toBe(0)
        ->and(file_get_contents(base_path('app/Providers/CookiePolicyProvider.php')))
        ->toBe('existing')
        ->and(file_get_contents(base_path('bootstrap/providers.php')))
        ->not->toContain('CookiePolicyProvider');
});

it('overwrites an existing cookie policy provider when forced', function (): void {
    putFile(base_path('app/Providers/CookiePolicyProvider.php'), contents: 'existing');

    makeCookiePolicy(['name' => 'CookiePolicyProvider', '--force' => true]);

    expect(file_get_contents(base_path('app/Providers/CookiePolicyProvider.php')))
        ->toContain('class CookiePolicyProvider extends BaseCookiePolicyProvider');
});

it('uses the published stub', function (): void {
    putFile(base_path('stubs/cookie-policy-provider.stub'), contents: 'custom {{ class }}');

    makeCookiePolicy(['name' => 'CookiePolicyProvider']);

    expect(file_get_contents(base_path('app/Providers/CookiePolicyProvider.php')))->toBe('custom CookiePolicyProvider');
});
