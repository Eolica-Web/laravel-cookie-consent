<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Eolica\LaravelCookieConsent\Console\MakeCookiePolicyCommand;
use Illuminate\Container\Container;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use Illuminate\Support\ServiceProvider;
use Illuminate\View\Compilers\BladeCompiler;

final class CookieConsentServiceProvider extends ServiceProvider
{
    private const string LANG_PATH = __DIR__ . '/../lang';

    private const string ROUTES_PATH = __DIR__ . '/../routes/web.php';

    private const string VIEWS_PATH = __DIR__ . '/../resources/views';

    private const string STUBS_PATH = __DIR__ . '/../stubs/cookie-policy-provider.stub';

    public function register(): void
    {
        $this->app->singleton(CookieConsentManager::class);
    }

    public function boot(): void
    {
        $this->publishes([
            self::LANG_PATH => $this->app->langPath('vendor/cookie-consent'),
        ], 'cookie-consent-translations');

        $this->loadTranslationsFrom(self::LANG_PATH, 'cookie-consent');

        $this->publishes([
            self::STUBS_PATH => $this->app->basePath('stubs/cookie-policy-provider.stub'),
        ], 'cookie-consent-stubs');

        $this->publishes([
            self::VIEWS_PATH => $this->app->resourcePath('views/vendor/cookie-consent'),
        ], 'cookie-consent-views');

        if ($this->app->runningInConsole()) {
            $this->commands([MakeCookiePolicyCommand::class]);
        }

        $this->loadRoutesFrom(self::ROUTES_PATH);
        $this->loadViewsFrom(self::VIEWS_PATH, 'cookie-consent');

        $this->callAfterResolving(EncryptCookies::class, static function (
            EncryptCookies $middleware,
            Container $container,
        ): void {
            foreach ($container->make(CookieConsentManager::class)->getPolicies() as $policy) {
                $middleware::except($policy->getCookieName());
            }
        });

        $this->callAfterResolving(BladeCompiler::class, static function (BladeCompiler $blade): void {
            $blade->componentNamespace('Eolica\\LaravelCookieConsent\\View\\Components', 'cookie-consent');

            $blade->if('consent', static function (string $category): bool {
                $container = Container::getInstance();

                return $container->make(CookieConsentManager::class)->allows(
                    $container->make(Request::class),
                    $category,
                );
            });
        });
    }
}
