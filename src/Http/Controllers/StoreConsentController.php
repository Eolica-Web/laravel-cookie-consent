<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Http\Controllers;

use Carbon\CarbonImmutable;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\Consent;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\Events\ConsentUpdated;
use Illuminate\Contracts\Events\Dispatcher;
use Illuminate\Http\JsonResponse;
use Illuminate\Http\Request;
use Illuminate\Support\Str;
use Symfony\Component\HttpFoundation\Cookie;

final readonly class StoreConsentController
{
    public const string HEADER = 'X-Cookie-Consent';

    public function __construct(
        private CookieConsentManager $manager,
        private Dispatcher $events,
    ) {}

    public function __invoke(Request $request): JsonResponse
    {
        if (!$request->hasHeader(self::HEADER)) {
            return new JsonResponse(['message' => 'The ' . self::HEADER . ' header is required.'], status: 403);
        }

        $policy = $this->findPolicy($request->input('policy'));

        if ($policy === null) {
            return new JsonResponse(['message' => 'The policy is not registered.'], status: 422);
        }

        if ($request->input('revision') !== $policy->getRevision()) {
            return new JsonResponse([
                'message' => 'The policy has changed.',
                'revision' => $policy->getRevision(),
            ], status: 409);
        }

        $categories = $this->filterCategories($policy, $request->input('categories'));

        if ($categories === null) {
            return new JsonResponse([
                'message' => 'The categories must be a list of the policy categories.',
            ], status: 422);
        }

        $consent = new Consent(
            id: Str::ulid()->toBase32(),
            policy: $policy->getId(),
            revision: $policy->getRevision(),
            categories: $categories,
            givenAt: CarbonImmutable::now(),
        );

        $this->events->dispatch(new ConsentUpdated($consent, $policy, $request));

        return new JsonResponse($consent->toJson(), json: true)->withCookie(Cookie::create(
            name: $policy->getCookieName(),
            value: $consent->toJson(),
            expire: $consent->givenAt->add($policy->getLifetime()),
            domain: $policy->getCookieDomain(),
            secure: $request->isSecure(),
            httpOnly: false,
            sameSite: Cookie::SAMESITE_LAX,
        ));
    }

    private function findPolicy(mixed $id): ?CookiePolicy
    {
        return is_string($id) ? $this->manager->getPolicies()[$id] ?? null : null;
    }

    /**
     * @return list<string>|null
     */
    private function filterCategories(CookiePolicy $policy, mixed $categories): ?array
    {
        if (!is_array($categories) || !array_is_list($categories)) {
            return null;
        }

        foreach ($categories as $category) {
            if (!is_string($category) || !$policy->hasCategory($category)) {
                return null;
            }
        }

        return array_values(array_filter(
            array_keys($policy->getCategories()),
            static fn(string $key): bool => $key === Category::NECESSARY || in_array($key, $categories, strict: true),
        ));
    }
}
