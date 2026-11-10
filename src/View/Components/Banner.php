<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\View\Components;

use Closure;
use Eolica\LaravelCookieConsent\CookieConsentAssets;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;

final class Banner extends CookiePolicyComponent
{
    public function __construct(
        CookieConsentManager $manager,
        Request $request,
        Translator $translator,
        Factory $views,
        private readonly CookieConsentAssets $assets,
        public readonly bool $styles = true,
        public readonly ?string $nonce = null,
    ) {
        parent::__construct($manager, $request, $translator, $views);
    }

    /**
     * @return Closure(array<string, mixed>): (View|string)
     */
    public function render(): Closure
    {
        return $this->viewOrSlot('cookie-consent::banner', [
            'css' => $this->styles ? $this->assets->styles() : null,
        ]);
    }
}
