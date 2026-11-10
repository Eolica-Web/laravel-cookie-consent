<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Http\Controllers\ScriptController;
use Illuminate\Contracts\Http\Kernel;
use Illuminate\Http\Request;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

covers(ScriptController::class);

it('serves the script', function (): void {
    $response = app(Kernel::class)->handle(Request::create(
        'https://tienda.test/cookie-consent/cookie-consent.js?id=abc',
    ));

    expect($response)
        ->toBeInstanceOf(BinaryFileResponse::class)
        ->and($response->getStatusCode())
        ->toBe(200)
        ->and($response instanceof BinaryFileResponse ? $response->getFile()->getRealPath() : null)
        ->toBe(realpath(__DIR__ . '/../../../resources/js/cookie-consent.js'))
        ->and($response->headers->get('Content-Type'))
        ->toBe('text/javascript; charset=utf-8')
        ->and($response->headers->get('Cache-Control'))
        ->toBe('immutable, max-age=31536000, public');
});

it('versions the script by its contents', function (): void {
    expect(ScriptController::version())
        ->toBe(substr(
            (string) hash_file('xxh128', __DIR__ . '/../../../resources/js/cookie-consent.js'),
            offset: 0,
            length: 8,
        ));
});
