<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Carbon\CarbonImmutable;
use JsonException;

final readonly class Consent
{
    /**
     * @param list<string> $categories
     */
    public function __construct(
        public string $id,
        public string $policy,
        public string $revision,
        public array $categories,
        public CarbonImmutable $givenAt,
    ) {}

    public static function fromJson(string $json): ?self
    {
        try {
            $data = json_decode($json, associative: true, flags: JSON_THROW_ON_ERROR);
        } catch (JsonException) {
            return null;
        }

        $data = is_array($data) ? $data : [];

        $id = $data['id'] ?? null;
        $policy = $data['policy'] ?? null;
        $revision = $data['revision'] ?? null;
        $categories = $data['categories'] ?? null;
        $at = $data['at'] ?? null;

        if (
            !is_string($id)
            || !is_string($policy)
            || !is_string($revision)
            || !is_array($categories)
            || !array_is_list($categories)
            || !is_int($at)
        ) {
            return null;
        }

        $keys = [];

        foreach ($categories as $category) {
            if (!is_string($category)) {
                return null;
            }

            $keys[] = $category;
        }

        return new self($id, $policy, $revision, $keys, CarbonImmutable::createFromTimestamp($at));
    }

    public function toJson(): string
    {
        return json_encode([
            'id' => $this->id,
            'policy' => $this->policy,
            'revision' => $this->revision,
            'categories' => $this->categories,
            'at' => $this->givenAt->getTimestamp(),
        ], JSON_THROW_ON_ERROR);
    }

    public function allows(string $category): bool
    {
        return in_array($category, $this->categories, strict: true);
    }

    public function isValidFor(CookiePolicy $policy, CarbonImmutable $now): bool
    {
        return (
            $this->policy === $policy->getId()
            && $this->revision === $policy->getRevision()
            && $this->givenAt->add($policy->getLifetime())->isAfter($now)
        );
    }
}
