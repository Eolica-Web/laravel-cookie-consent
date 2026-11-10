<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Carbon\CarbonImmutable;
use Eolica\LaravelCookieConsent\Http\Controllers\ScriptController;
use Illuminate\Contracts\Routing\UrlGenerator;
use Illuminate\Http\Request;
use RuntimeException;

final readonly class CookieConsentAssets
{
    private const string STYLES_PATH = __DIR__ . '/../resources/css/cookie-consent.css';

    public function __construct(
        private UrlGenerator $url,
    ) {}

    public function config(CookiePolicy $policy): string
    {
        $now = CarbonImmutable::now();

        return json_encode(
            [
                'policy' => $policy->getId(),
                'revision' => $policy->getRevision(),
                'lifetime' => $now->add($policy->getLifetime())->getTimestamp() - $now->getTimestamp(),
                'cookie' => [
                    'name' => $policy->getCookieName(),
                    'domain' => $policy->getCookieDomain(),
                ],
                'endpoint' => $this->url->route('cookie-consent.store'),
                'googleConsent' => $policy->getGoogleConsent(),
                'services' => (object) $this->services($policy),
                'categories' => array_values(array_map(static fn(Category $category): array => [
                    'key' => $category->getKey(),
                    'necessary' => $category->isNecessary(),
                    'cookies' => array_map(static fn(Cookie $cookie): array => [
                        'name' => $cookie->name,
                        'type' => $cookie->type->value,
                    ], $category->getCookies()),
                ], $policy->getCategories())),
            ],
            JSON_THROW_ON_ERROR | JSON_HEX_TAG | JSON_HEX_AMP | JSON_HEX_APOS | JSON_HEX_QUOT | JSON_UNESCAPED_SLASHES,
        );
    }

    /**
     * @return list<array{attributes: array<string, bool|string|null>, content: string|null}>
     */
    public function scripts(CookiePolicy $policy, Request $request, ?string $nonce = null): array
    {
        $scripts = [];

        foreach ($policy->getCategories() as $category) {
            foreach ($category->getServices() as $service) {
                foreach ($service->getScripts() as $script) {
                    $scripts[] = [
                        'attributes' => [
                            ...$script->attributes,
                            'type' => 'text/plain',
                            'src' => null,
                            'nonce' => $nonce,
                            'data-cookie-consent' => $service->getKey(),
                            'data-src' => $script->getSrc($request),
                            'data-type' => $script->attributes['type'] ?? null,
                        ],
                        'content' => $script->getContent($request),
                    ];
                }
            }
        }

        return $scripts;
    }

    public function scriptUrl(): string
    {
        return $this->url->route('cookie-consent.script', ['id' => ScriptController::version()]);
    }

    public function styles(): string
    {
        $styles = file_get_contents(self::STYLES_PATH);

        return is_string($styles) ? $styles : throw new RuntimeException('Unreadable styles.');
    }

    /**
     * @return array<string, string>
     */
    private function services(CookiePolicy $policy): array
    {
        $services = [];

        foreach ($policy->getCategories() as $category) {
            foreach (array_keys($category->getServices()) as $service) {
                $services[$service] = $category->getKey();
            }
        }

        return $services;
    }
}
