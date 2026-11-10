<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent\View\Components;

use Closure;
use Eolica\LaravelCookieConsent\Category;
use Eolica\LaravelCookieConsent\Cookie;
use Eolica\LaravelCookieConsent\CookieConsentManager;
use Eolica\LaravelCookieConsent\CookiePolicy;
use Eolica\LaravelCookieConsent\StorageType;
use Illuminate\Contracts\Translation\Translator;
use Illuminate\Contracts\View\Factory;
use Illuminate\Contracts\View\View;
use Illuminate\Http\Request;
use Illuminate\View\Component;
use Illuminate\View\ComponentSlot;

abstract class CookiePolicyComponent extends Component
{
    public function __construct(
        protected readonly CookieConsentManager $manager,
        protected readonly Request $request,
        protected readonly Translator $translator,
        protected readonly Factory $views,
    ) {}

    public function shouldRender(): bool
    {
        return $this->policy() !== null;
    }

    /**
     * @return list<Category>
     */
    public function categories(): array
    {
        return array_values($this->policy()?->getCategories() ?? []);
    }

    public function policyUrl(): ?string
    {
        return $this->policy()?->getPolicyUrl();
    }

    public function label(Category $category): string
    {
        return $this->translate($category->getLabel());
    }

    public function description(Category $category): string
    {
        return $this->translate($category->getDescription());
    }

    public function purpose(Cookie $cookie): string
    {
        return $this->translate($cookie->purpose);
    }

    public function provider(Cookie $cookie): string
    {
        return $cookie->provider ?? $this->translate('cookie-consent::declaration.first_party');
    }

    public function duration(Cookie $cookie): string
    {
        if ($cookie->duration !== null) {
            return $cookie->duration->copy()->locale($this->translator->getLocale())->cascade()->forHumans();
        }

        return $this->translate(
            $cookie->type === StorageType::LocalStorage
                ? 'cookie-consent::declaration.persistent'
                : 'cookie-consent::declaration.session',
        );
    }

    /**
     * @param array<string, mixed> $data
     *
     * @return Closure(array<string, mixed>): (View|string)
     */
    protected function viewOrSlot(string $view, array $data = []): Closure
    {
        return function (array $componentData) use ($view, $data): View|string {
            $slot = $componentData['slot'] ?? null;

            return $slot instanceof ComponentSlot && $slot->isNotEmpty()
                ? '{{ $slot }}'
                : $this->views->make($view, $data);
        };
    }

    private function policy(): ?CookiePolicy
    {
        return $this->manager->resolvePolicy($this->request);
    }

    private function translate(string $key): string
    {
        $translation = $this->translator->get($key);

        return is_string($translation) ? $translation : $key;
    }
}
