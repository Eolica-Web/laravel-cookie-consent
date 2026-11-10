<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Tests;

use Eolica\LaravelCookieConsent\CookieConsentServiceProvider;
use Orchestra\Testbench\TestCase as Orchestra;

abstract class TestCase extends Orchestra
{
    /**
     * @return list<class-string>
     */
    protected function getPackageProviders($app): array
    {
        return [CookieConsentServiceProvider::class];
    }
}
