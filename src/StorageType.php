<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

enum StorageType: string
{
    case Cookie = 'cookie';
    case LocalStorage = 'local_storage';
    case SessionStorage = 'session_storage';
}
