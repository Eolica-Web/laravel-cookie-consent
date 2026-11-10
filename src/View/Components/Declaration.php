<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\View\Components;

use Closure;
use Illuminate\Contracts\View\View;

final class Declaration extends CookiePolicyComponent
{
    /**
     * @return Closure(array<string, mixed>): (View|string)
     */
    public function render(): Closure
    {
        return $this->viewOrSlot('cookie-consent::declaration');
    }
}
