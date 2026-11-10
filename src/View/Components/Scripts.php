<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\View\Components;

use Eolica\LaravelCookieConsent\CookieConsentAssets;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use Illuminate\View\ComponentAttributeBag;

final class Scripts extends Component
{
    public function __construct(
        private readonly CookieConsentManager $manager,
        private readonly CookieConsentAssets $assets,
        private readonly Request $request,
        private readonly Factory $views,
        public readonly ?string $nonce = null,
    ) {}

    public function render(): View|string
    {
        $policy = $this->manager->resolvePolicy($this->request);

        if ($policy === null) {
            return '';
        }

        return $this->views->make('cookie-consent::scripts', [
            'config' => $this->assets->config($policy),
            'src' => $this->assets->scriptUrl(),
            'scripts' => array_map(
                static fn(array $script): array => [
                    'attributes' => new ComponentAttributeBag()->merge($script['attributes']),
                    'content' => $script['content'],
                ],
                $this->assets->scripts($policy, $this->request, $this->nonce),
            ),
        ]);
    }
}
