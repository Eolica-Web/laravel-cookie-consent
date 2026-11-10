<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Http\Controllers;

use RuntimeException;
use Symfony\Component\HttpFoundation\BinaryFileResponse;

final readonly class ScriptController
{
    public const string PATH = __DIR__ . '/../../../resources/js/cookie-consent.js';

    public function __invoke(): BinaryFileResponse
    {
        $response = new BinaryFileResponse(self::PATH, headers: ['Content-Type' => 'text/javascript; charset=utf-8']);

        return $response->setPublic()->setMaxAge(31_536_000)->setImmutable();
    }

    public static function version(): string
    {
        $hash = hash_file('xxh128', self::PATH);

        return is_string($hash)
            ? substr($hash, offset: 0, length: 8)
            : throw new RuntimeException('Unreadable script.');
    }
}
