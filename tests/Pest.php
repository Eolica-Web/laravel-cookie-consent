<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Tests\TestCase;

pest()->project()->github('Eolica-Web/laravel-cookie-consent');

pest()->extend(TestCase::class)->in(__DIR__);
