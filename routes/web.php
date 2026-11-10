<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Http\Controllers\ScriptController;
use Eolica\LaravelCookieConsent\Http\Controllers\StoreConsentController;
use Illuminate\Contracts\Routing\Registrar;

$router = app(Registrar::class);

$router->post('cookie-consent', StoreConsentController::class)->name('cookie-consent.store');
$router->get('cookie-consent/cookie-consent.js', ScriptController::class)->name('cookie-consent.script');
