@if ($css !== null)
    @include('cookie-consent::styles')
@endif

<div {{ $attributes->merge(['class' => 'cookie-consent']) }} data-cookie-consent-banner role="region" aria-labelledby="cookie-consent-title" hidden>
    <p class="cookie-consent__title" id="cookie-consent-title">{{ __('cookie-consent::banner.title') }}</p>
    <p class="cookie-consent__text">
        {{ __('cookie-consent::banner.description') }}
        @if ($policyUrl() !== null)
            <a class="cookie-consent__link" href="{{ $policyUrl() }}">{{ __('cookie-consent::banner.policy') }}</a>
        @endif
    </p>
    <div class="cookie-consent__actions">
        <button class="cookie-consent__button" type="button" data-cookie-consent-action="reject-all">{{ __('cookie-consent::banner.reject_all') }}</button>
        <button class="cookie-consent__button" type="button" data-cookie-consent-action="show-preferences">{{ __('cookie-consent::banner.customize') }}</button>
        <button class="cookie-consent__button" type="button" data-cookie-consent-action="accept-all">{{ __('cookie-consent::banner.accept_all') }}</button>
    </div>
</div>
<dialog class="cookie-consent-preferences" data-cookie-consent-preferences aria-labelledby="cookie-consent-preferences-title">
    <div class="cookie-consent-preferences__header">
        <h2 class="cookie-consent__title" id="cookie-consent-preferences-title">{{ __('cookie-consent::banner.preferences.title') }}</h2>
        <button class="cookie-consent-preferences__close" type="button" data-cookie-consent-action="close-preferences" aria-label="{{ __('cookie-consent::banner.close') }}">&times;</button>
    </div>
    <p class="cookie-consent__text">
        {{ __('cookie-consent::banner.preferences.description') }}
        @if ($policyUrl() !== null)
            <a class="cookie-consent__link" href="{{ $policyUrl() }}">{{ __('cookie-consent::banner.policy') }}</a>
        @endif
    </p>
    @foreach ($categories() as $category)
        <section class="cookie-consent-category">
            <div class="cookie-consent-category__header">
                <input class="cookie-consent-category__input" type="checkbox" id="cookie-consent-category-{{ $category->getKey() }}" data-cookie-consent-category="{{ $category->getKey() }}" @checked($category->isNecessary()) @disabled($category->isNecessary())>
                <label class="cookie-consent-category__label" for="cookie-consent-category-{{ $category->getKey() }}">{{ $label($category) }}</label>
                @if ($category->isNecessary())
                    <span class="cookie-consent-category__badge">{{ __('cookie-consent::declaration.always_active') }}</span>
                @endif
            </div>
            <p class="cookie-consent__text">{{ $description($category) }}</p>
            @if ($category->getCookies() !== [])
                <details class="cookie-consent-category__details">
                    <summary>{{ trans_choice('cookie-consent::declaration.cookies', count($category->getCookies())) }}</summary>
                    <div class="cookie-consent-category__table">
                        @include('cookie-consent::partials.cookies')
                    </div>
                </details>
            @endif
        </section>
    @endforeach
    <div class="cookie-consent__actions">
        <button class="cookie-consent__button" type="button" data-cookie-consent-action="reject-all">{{ __('cookie-consent::banner.reject_all') }}</button>
        <button class="cookie-consent__button" type="button" data-cookie-consent-action="save">{{ __('cookie-consent::banner.save') }}</button>
        <button class="cookie-consent__button" type="button" data-cookie-consent-action="accept-all">{{ __('cookie-consent::banner.accept_all') }}</button>
    </div>
</dialog>
