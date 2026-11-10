<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Tests\Fixtures;

use Illuminate\Cookie\Middleware\EncryptCookies;

final class OwnEncryptCookies extends EncryptCookies
{
    /**
     * @var list<string>
     */
    protected static $neverEncrypt = [];
}
