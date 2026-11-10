<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Carbon\CarbonImmutable;
use Closure;
use Illuminate\Cookie\Middleware\EncryptCookies;
use Illuminate\Http\Request;
use InvalidArgumentException;

final class CookieConsentManager
{
    /**
     * @var array<string, CookiePolicy>
     */
    private array $policies = [];

    /**
     * @param Closure(CookiePolicy): mixed $callback
     */
    public function policy(Closure $callback): void
    {
        $policy = new CookiePolicy();

        $callback($policy);

        $id = $policy->getId();

        if (array_key_exists($id, $this->policies)) {
            throw new InvalidArgumentException("The cookie policy [{$id}] is already registered.");
        }

        $default = $this->getDefaultPolicy();

        if ($default !== null && $policy->isDefault()) {
            throw new InvalidArgumentException(
                "The cookie policies [{$default->getId()}] and [{$id}] cannot both be the default one, give one of them a domain or a condition.",
            );
        }

        $this->policies[$id] = $policy;

        EncryptCookies::except($policy->getCookieName());
    }

    /**
     * @return array<string, CookiePolicy>
     */
    public function getPolicies(): array
    {
        return $this->policies;
    }

    public function getPolicy(string $id): CookiePolicy
    {
        return (
            $this->policies[$id] ?? throw new InvalidArgumentException("The cookie policy [{$id}] is not registered.")
        );
    }

    public function resolvePolicy(Request $request): ?CookiePolicy
    {
        foreach ($this->policies as $policy) {
            if ($policy->matches($request)) {
                return $policy;
            }
        }

        return $this->getDefaultPolicy();
    }

    public function getConsent(Request $request): ?Consent
    {
        $policy = $this->resolvePolicy($request);

        return $policy === null ? null : $this->readConsent($request, $policy);
    }

    public function allows(Request $request, string $category): bool
    {
        $policy = $this->resolvePolicy($request);

        if ($policy === null) {
            return $category === Category::NECESSARY;
        }

        if (!$policy->hasCategory($category)) {
            throw new InvalidArgumentException(
                "The category [{$category}] is not defined in the cookie policy [{$policy->getId()}].",
            );
        }

        if ($category === Category::NECESSARY) {
            return true;
        }

        return $this->readConsent($request, $policy)?->allows($category) ?? false;
    }

    private function readConsent(Request $request, CookiePolicy $policy): ?Consent
    {
        $value = $request->cookies->get($policy->getCookieName());

        if (!is_string($value)) {
            return null;
        }

        $consent = Consent::fromJson($value);

        return $consent?->isValidFor($policy, CarbonImmutable::now()) === true ? $consent : null;
    }

    private function getDefaultPolicy(): ?CookiePolicy
    {
        foreach ($this->policies as $policy) {
            if ($policy->isDefault()) {
                return $policy;
            }
        }

        return null;
    }
}
