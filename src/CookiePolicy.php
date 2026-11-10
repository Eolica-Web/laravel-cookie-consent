<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Carbon\CarbonInterval;
use Closure;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use InvalidArgumentException;

final class CookiePolicy
{
    private string $id = 'default';

    /**
     * @var list<string>
     */
    private array $domains = [];

    /**
     * @var list<Closure(Request): bool>
     */
    private array $conditions = [];

    private bool $default = false;

    private ?string $version = null;

    private CarbonInterval $lifetime;

    private ?string $cookieName = null;

    private ?string $cookieDomain = null;

    private bool $googleConsentMode = false;

    /**
     * @var (Closure(): string)|string|null
     */
    private Closure|string|null $policyUrl = null;

    /**
     * @var array<string, Category>
     */
    private array $categories;

    public function __construct()
    {
        $this->lifetime = CarbonInterval::months(12);
        $this->categories = [Category::NECESSARY => new Category(Category::NECESSARY)];
    }

    public function id(string $id): static
    {
        if (preg_match('/^[a-z][a-z0-9_-]*$/D', $id) !== 1) {
            throw new InvalidArgumentException(
                "The policy id [{$id}] must start with a lowercase letter and only contain lowercase letters, numbers, dashes and underscores.",
            );
        }

        $this->id = $id;

        return $this;
    }

    public function domain(string ...$domains): static
    {
        foreach ($domains as $domain) {
            $this->domains[] = strtolower($domain);
        }

        return $this;
    }

    /**
     * @param Closure(Request): bool $condition
     */
    public function when(Closure $condition): static
    {
        $this->conditions[] = $condition;

        return $this;
    }

    public function default(bool $default = true): static
    {
        $this->default = $default;

        return $this;
    }

    public function version(string $version): static
    {
        $this->version = $version;

        return $this;
    }

    public function lifetime(CarbonInterval $lifetime): static
    {
        $this->lifetime = $lifetime;

        return $this;
    }

    /**
     * @param (Closure(): string)|string $url
     */
    public function policyUrl(Closure|string $url): static
    {
        $this->policyUrl = $url;

        return $this;
    }

    public function cookie(?string $name = null, ?string $domain = null): static
    {
        if ($name !== null && preg_match('/^[A-Za-z0-9_-]+$/D', $name) !== 1) {
            throw new InvalidArgumentException(
                "The consent cookie name [{$name}] must only contain letters, numbers, dashes and underscores.",
            );
        }

        $this->cookieName = $name ?? $this->cookieName;
        $this->cookieDomain = $domain ?? $this->cookieDomain;

        return $this;
    }

    public function googleConsentMode(bool $enabled = true): static
    {
        $this->googleConsentMode = $enabled;

        return $this;
    }

    /**
     * @param Closure(Category): mixed $callback
     */
    public function necessary(Closure $callback): static
    {
        return $this->category(Category::NECESSARY, $callback);
    }

    /**
     * @param Closure(Category): mixed $callback
     */
    public function preferences(Closure $callback): static
    {
        return $this->category(Category::PREFERENCES, $callback);
    }

    /**
     * @param Closure(Category): mixed $callback
     */
    public function analytics(Closure $callback): static
    {
        return $this->category(Category::ANALYTICS, $callback);
    }

    /**
     * @param Closure(Category): mixed $callback
     */
    public function marketing(Closure $callback): static
    {
        return $this->category(Category::MARKETING, $callback);
    }

    /**
     * @param Closure(Category): mixed $callback
     */
    public function category(string $key, Closure $callback): static
    {
        $this->categories[$key] ??= new Category($key);

        $category = $this->categories[$key];

        $callback($category);

        foreach ($this->categories as $other) {
            foreach (array_keys($category->getServices()) as $service) {
                if ($other !== $category && $other->hasService($service)) {
                    throw new InvalidArgumentException(
                        "The service [{$service}] is already defined in the category [{$other->getKey()}].",
                    );
                }
            }
        }

        return $this;
    }

    public function getId(): string
    {
        return $this->id;
    }

    public function isDefault(): bool
    {
        return $this->default || !$this->hasConditions();
    }

    public function matches(Request $request): bool
    {
        if (!$this->hasConditions()) {
            return false;
        }

        if ($this->domains !== [] && !Str::is($this->domains, $request->getHost())) {
            return false;
        }

        foreach ($this->conditions as $condition) {
            if (!$condition($request)) {
                return false;
            }
        }

        return true;
    }

    public function getVersion(): ?string
    {
        return $this->version;
    }

    public function getRevision(): string
    {
        $keys = [];

        foreach ($this->categories as $key => $category) {
            $services = array_keys($category->getServices());

            sort($services);

            $keys[] = $services === [] ? $key : $key . ':' . implode('+', $services);
        }

        sort($keys);

        $hash = substr(hash('xxh128', implode(',', $keys)), offset: 0, length: 8);

        return $this->version === null ? $hash : "{$this->version}.{$hash}";
    }

    public function getLifetime(): CarbonInterval
    {
        return $this->lifetime;
    }

    public function getCookieName(): string
    {
        return $this->cookieName ?? ($this->id === 'default' ? 'cookie_consent' : "cookie_consent_{$this->id}");
    }

    public function getCookieDomain(): ?string
    {
        return $this->cookieDomain;
    }

    public function getPolicyUrl(): ?string
    {
        return $this->policyUrl instanceof Closure ? ($this->policyUrl)() : $this->policyUrl;
    }

    /**
     * @return array<string, list<string>>|null
     */
    public function getGoogleConsent(): ?array
    {
        if (!$this->googleConsentMode) {
            return null;
        }

        $signals = [];

        foreach (GoogleConsent::cases() as $signal) {
            $signals[$signal->value] = array_keys(array_filter(
                $this->categories,
                static fn(Category $category): bool => in_array($signal, $category->getGoogleConsent(), strict: true),
            ));
        }

        return $signals;
    }

    public function hasCategory(string $key): bool
    {
        return array_key_exists($key, $this->categories);
    }

    public function hasService(string $key): bool
    {
        foreach ($this->categories as $category) {
            if ($category->hasService($key)) {
                return true;
            }
        }

        return false;
    }

    /**
     * @return array<string, Category>
     */
    public function getCategories(): array
    {
        $consent = new Cookie(
            name: $this->getCookieName(),
            purpose: 'cookie-consent::cookies.consent',
            duration: $this->lifetime,
        );

        $categories = $this->categories;
        $categories[Category::NECESSARY] = $categories[Category::NECESSARY]->withLeadingCookie($consent);

        return $categories;
    }

    private function hasConditions(): bool
    {
        return $this->domains !== [] || $this->conditions !== [];
    }
}
