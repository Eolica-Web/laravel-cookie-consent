@if ($category->getProviders() !== [])
    <p class="{{ $class }}">
        {{ __('cookie-consent::declaration.providers') }}
        @foreach ($category->getProviders() as $name => $url)
            @if ($url !== null)
                <a class="cookie-consent__link" href="{{ $url }}">{{ $name }}</a>{{ $loop->last ? '' : ',' }}
            @else
                {{ $name }}{{ $loop->last ? '' : ',' }}
            @endif
        @endforeach
    </p>
@endif
