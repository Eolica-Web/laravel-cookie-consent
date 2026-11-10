<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\Head;

use Eolica\LaravelCookieConsent\CookieConsentAssets;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Illuminate\Container\Container;
use Illuminate\Http\Request;
use Seo\Head\Context\TagsResolvingContext;
use Seo\Head\Head;
use Seo\Head\HeadPlugin;
use Seo\Head\HeadTag;
use Seo\Head\HeadTagPosition;

final readonly class CookieConsentPlugin implements HeadPlugin
{
    private function __construct(
        private bool $styles,
        private ?string $nonce,
    ) {}

    public static function make(bool $styles = false, ?string $nonce = null): self
    {
        return new self($styles, $nonce);
    }

    public function initialize(Head $head): void
    {
        $head->onTagsResolving(function (TagsResolvingContext $context): void {
            $this->resolve($context);
        });
    }

    private function resolve(TagsResolvingContext $context): void
    {
        foreach ($context->tags as $tag) {
            if ($tag->attribute('id') === 'cookie-consent-config') {
                return;
            }
        }

        $container = Container::getInstance();
        $request = $container->make(Request::class);
        $policy = $container->make(CookieConsentManager::class)->resolvePolicy($request);

        if ($policy === null) {
            return;
        }

        $assets = $container->make(CookieConsentAssets::class);

        $context->tags->add(new HeadTag(
            type: 'script',
            attributes: [
                'id' => 'cookie-consent-config',
                'type' => 'application/json',
                'data-cookie-consent-config' => true,
            ],
            textContent: $assets->config($policy),
            position: HeadTagPosition::Head,
        ));

        $context->tags->add(new HeadTag(
            type: 'script',
            attributes: [
                'id' => 'cookie-consent-script',
                'type' => 'module',
                'src' => $assets->scriptUrl(),
                'nonce' => $this->nonce,
                'data-navigate-once' => true,
            ],
            textContent: null,
            position: HeadTagPosition::Head,
        ));

        if ($this->styles) {
            $context->tags->add(new HeadTag(
                type: 'style',
                attributes: [
                    'id' => 'cookie-consent-styles',
                    'nonce' => $this->nonce,
                ],
                textContent: $assets->styles(),
                position: HeadTagPosition::Head,
            ));
        }

        foreach ($assets->scripts($policy, $request, $this->nonce) as $script) {
            $context->tags->add(new HeadTag(
                type: 'script',
                attributes: $script['attributes'],
                textContent: $script['content'],
                position: HeadTagPosition::Head,
            ));
        }
    }
}
