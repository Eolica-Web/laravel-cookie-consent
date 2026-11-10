<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\Http\Controllers\ScriptController;
use Eolica\LaravelCookieConsent\Http\Controllers\StoreConsentController;
use Illuminate\Contracts\Routing\Registrar;

$router = app(Registrar::class);

foreach (app(CookieConsentManager::class)->getPolicies() as $id => $policy) {
    $router->post("cookie-consent/{$id}", [
        'uses' => StoreConsentController::class,
        StoreConsentController::POLICY => $id,
    ])->middleware($policy->getMiddleware())->name("cookie-consent.store.{$id}");
}

$router->get('cookie-consent/cookie-consent.js', ScriptController::class)->name('cookie-consent.script');
