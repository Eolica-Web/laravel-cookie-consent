<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Support\Facades\Config;
use InvalidArgumentException;

final class Category
{
    public const string NECESSARY = 'necessary';

    public const string PREFERENCES = 'preferences';

    public const string ANALYTICS = 'analytics';

    public const string MARKETING = 'marketing';

    private ?string $label = null;

    private ?string $description = null;

    /**
     * @var list<Cookie|Service>
     */
    private array $entries = [];

    /**
     * @var array<string, Service>
     */
    private array $services = [];

    /**
     * @var list<GoogleConsent>|null
     */
    private ?array $googleConsent = null;

    public function __construct(
        private readonly string $key,
    ) {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $key) !== 1) {
            throw new InvalidArgumentException(
                "The category key [{$key}] must start with a lowercase letter and only contain lowercase letters, numbers, dashes and underscores.",
            );
        }
    }

    public function label(string $label): static
    {
        $this->label = $label;

        return $this;
    }

    public function description(string $description): static
    {
        $this->description = $description;

        return $this;
    }

    public function cookie(string $name, string $purpose, ?CarbonInterval $duration = null): static
    {
        return $this->add(new Cookie($name, $purpose, StorageType::Cookie, $duration));
    }

    public function localStorage(string $name, string $purpose): static
    {
        return $this->add(new Cookie($name, $purpose, StorageType::LocalStorage));
    }

    public function sessionStorage(string $name, string $purpose): static
    {
        return $this->add(new Cookie($name, $purpose, StorageType::SessionStorage));
    }

    /**
     * @param Closure(Service): mixed $callback
     */
    public function service(string $key, Closure $callback): static
    {
        if (!array_key_exists($key, $this->services)) {
            $this->services[$key] = new Service($key);
            $this->entries[] = $this->services[$key];
        }

        $callback($this->services[$key]);

        return $this;
    }

    public function session(): static
    {
        return $this->cookie(
            name: Config::string('session.cookie'),
            purpose: 'cookie-consent::cookies.session',
            duration: Config::boolean('session.expire_on_close') ? null : self::sessionLifetime(),
        );
    }

    public function csrf(): static
    {
        return $this->cookie(
            name: 'XSRF-TOKEN',
            purpose: 'cookie-consent::cookies.csrf',
            duration: self::sessionLifetime(),
        );
    }

    public function googleConsent(GoogleConsent ...$signals): static
    {
        $this->googleConsent = array_values($signals);

        return $this;
    }

    public function getKey(): string
    {
        return $this->key;
    }

    public function getLabel(): string
    {
        return $this->label ?? "cookie-consent::categories.{$this->key}.label";
    }

    public function getDescription(): string
    {
        return $this->description ?? "cookie-consent::categories.{$this->key}.description";
    }

    public function isNecessary(): bool
    {
        return $this->key === self::NECESSARY;
    }

    /**
     * @return list<Cookie>
     */
    public function getCookies(): array
    {
        $cookies = [];

        foreach ($this->entries as $entry) {
            $cookies = [...$cookies, ...($entry instanceof Service ? $entry->getCookies() : [$entry])];
        }

        return $cookies;
    }

    /**
     * @return array<string, Service>
     */
    public function getServices(): array
    {
        return $this->services;
    }

    public function hasService(string $key): bool
    {
        return array_key_exists($key, $this->services);
    }

    /**
     * @return list<GoogleConsent>
     */
    public function getGoogleConsent(): array
    {
        return (
            $this->googleConsent ?? match ($this->key) {
                self::NECESSARY => [GoogleConsent::SecurityStorage],
                self::PREFERENCES => [GoogleConsent::FunctionalityStorage, GoogleConsent::PersonalizationStorage],
                self::ANALYTICS => [GoogleConsent::AnalyticsStorage],
                self::MARKETING => [
                    GoogleConsent::AdStorage,
                    GoogleConsent::AdUserData,
                    GoogleConsent::AdPersonalization,
                ],
                default => [],
            }
        );
    }

    public function withLeadingCookie(Cookie $cookie): self
    {
        $category = clone $this;
        $category->entries = [$cookie, ...$this->entries];

        return $category;
    }

    private function add(Cookie $cookie): static
    {
        $this->entries[] = $cookie;

        return $this;
    }

    private static function sessionLifetime(): CarbonInterval
    {
        return CarbonInterval::minutes(Config::integer('session.lifetime'));
    }
}
