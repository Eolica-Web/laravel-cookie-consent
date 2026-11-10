<?php

declare(strict_types=1);

use Carbon\CarbonImmutable;
use Eolica\LaravelCookieConsent\Consent;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Events\ConsentUpdated;
use Illuminate\Http\Request;

covers(ConsentUpdated::class);

it('holds the consent, its policy and the request', function (): void {
    $consent = new Consent('id', 'default', 'revision', ['necessary'], CarbonImmutable::now());
    $policy = new CookiePolicy();
    $request = Request::create('https://tienda.test');

    $event = new ConsentUpdated($consent, $policy, $request);

    expect($event->consent)->toBe($consent)->and($event->policy)->toBe($policy)->and($event->request)->toBe($request);
});
