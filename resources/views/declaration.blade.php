<div {{ $attributes->merge(['class' => 'cookie-consent-declaration']) }}>
    @foreach ($categories() as $category)
        <section class="cookie-consent-declaration__category">
            <h3 class="cookie-consent-declaration__title">
                {{ $label($category) }}
                @if ($category->isNecessary())
                    <span class="cookie-consent-declaration__badge">{{ __('cookie-consent::declaration.always_active') }}</span>
                @endif
            </h3>
            <p class="cookie-consent-declaration__description">{{ $description($category) }}</p>
            @if ($category->getCookies() === [])
                <p class="cookie-consent-declaration__empty">{{ __('cookie-consent::declaration.empty') }}</p>
            @else
                <div class="cookie-consent-declaration__table">
                    @include('cookie-consent::partials.cookies')
                </div>
            @endif
        </section>
    @endforeach
    <button class="cookie-consent-declaration__button" type="button" data-cookie-consent-action="show-preferences">{{ __('cookie-consent::declaration.change_preferences') }}</button>
</div>
