<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use DateInterval;
use InvalidArgumentException;

final readonly class Cookie
{
    public function __construct(
        public string $name,
        public string $purpose,
        public StorageType $type = StorageType::Cookie,
        public ?DateInterval $duration = null,
        public ?string $provider = null,
        public ?string $providerUrl = null,
    ) {
        if ($providerUrl !== null && $provider === null) {
            throw new InvalidArgumentException("The cookie [{$name}] needs a provider to have a provider url.");
        }
    }

    public function isThirdParty(): bool
    {
        return $this->provider !== null;
    }
}
