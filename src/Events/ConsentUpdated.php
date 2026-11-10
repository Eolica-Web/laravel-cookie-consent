<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Events;

use Eolica\LaravelCookieConsent\Consent;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Illuminate\Http\Request;

final readonly class ConsentUpdated
{
    public function __construct(
        public Consent $consent,
        public CookiePolicy $policy,
        public Request $request,
    ) {}
}
