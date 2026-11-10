<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Tests\TestCase;
use Illuminate\Routing\Router;

pest()->project()->github('Eolica-Web/laravel-cookie-consent');

pest()->extend(TestCase::class)->in(__DIR__);

/**
 * @param Closure(CookiePolicy): CookiePolicy $callback
 */
function registerPolicy(Closure $callback): CookiePolicy
{
    $registered = null;

    app(CookieConsentManager::class)->policy(static function (CookiePolicy $policy) use (
        $callback,
        &$registered,
    ): CookiePolicy {
        return $registered = $callback($policy);
    });

    require __DIR__ . '/../routes/web.php';

    app(Router::class)->getRoutes()->refreshNameLookups();

    return $registered ?? throw new LogicException('The policy was not registered.');
}
