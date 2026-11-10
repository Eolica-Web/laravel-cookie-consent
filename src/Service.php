<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class Service
{
    private ?string $provider = null;

    private ?string $providerUrl = null;

    /**
     * @var list<Cookie>
     */
    private array $cookies = [];

    /**
     * @var list<Script>
     */
    private array $scripts = [];

    public function __construct(
        private readonly string $key,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $key) !== 1) {
            throw new InvalidArgumentException(
                "The service key [{$key}] must start with a lowercase letter and only contain lowercase letters, numbers, dashes and underscores.",
            );
        }
    }

    public function provider(string $name, ?string $url = null): static
    {
        $this->provider = $name;
        $this->providerUrl = $url;

        return $this;
    }

    public function cookie(string $name, string $purpose, ?CarbonInterval $duration = null): static
    {
        $this->cookies[] = new Cookie($name, $purpose, StorageType::Cookie, $duration);

        return $this;
    }

    public function localStorage(string $name, string $purpose): static
    {
        $this->cookies[] = new Cookie($name, $purpose, StorageType::LocalStorage);

        return $this;
    }

    public function sessionStorage(string $name, string $purpose): static
    {
        $this->cookies[] = new Cookie($name, $purpose, StorageType::SessionStorage);

        return $this;
    }

    /**
     * @param (Closure(Request): string)|string|null $src
     * @param (Closure(Request): string)|string|null $content
     * @param array<string, bool|string|null> $attributes
     */
    public function script(
        Closure|string|null $src = null,
        Closure|string|null $content = null,
        array $attributes = [],
    ): static {
        $this->scripts[] = new Script($src, $content, $attributes);

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getProvider(): ?string
    {
        return $this->provider;
    }

    public function getProviderUrl(): ?string
    {
        return $this->providerUrl;
    }

    /**
     * @return list<Cookie>
     */
    public function getCookies(): array
    {
        return array_map(
            fn(Cookie $cookie): Cookie => new Cookie(
                $cookie->name,
                $cookie->purpose,
                $cookie->type,
                $cookie->duration,
                $this->provider,
                $this->providerUrl,
            ),
            $this->cookies,
        );
    }

    /**
     * @return list<Script>
     */
    public function getScripts(): array
    {
        return $this->scripts;
    }
}
