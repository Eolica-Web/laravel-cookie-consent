<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\View\Components;

use Eolica\LaravelCookieConsent\CookieConsentManager;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use InvalidArgumentException;

final class Script extends Component
{
    public function __construct(
        private readonly CookieConsentManager $manager,
        private readonly Request $request,
        private readonly Factory $views,
        public readonly string $service,
        public readonly ?string $src = null,
        public readonly ?string $type = null,
    ) {}

    public function render(): View
    {
        $policy = $this->manager->resolvePolicy($this->request);

        if ($policy !== null && !$policy->hasService($this->service)) {
            throw new InvalidArgumentException(
                "The service [{$this->service}] is not defined in the cookie policy [{$policy->getId()}].",
            );
        }

        return $this->views->make('cookie-consent::script');
    }
}
