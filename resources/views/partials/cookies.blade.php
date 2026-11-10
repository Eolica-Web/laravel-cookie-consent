<table>
    <thead>
        <tr>
            <th scope="col">{{ __('cookie-consent::declaration.table.name') }}</th>
            <th scope="col">{{ __('cookie-consent::declaration.table.provider') }}</th>
            <th scope="col">{{ __('cookie-consent::declaration.table.purpose') }}</th>
            <th scope="col">{{ __('cookie-consent::declaration.table.duration') }}</th>
        </tr>
    </thead>
    <tbody>
        @foreach ($category->getCookies() as $cookie)
            <tr>
                <td><code>{{ $cookie->name }}</code></td>
                <td>
                    @if ($cookie->providerUrl !== null)
                        <a class="cookie-consent__link" href="{{ $cookie->providerUrl }}">{{ $provider($cookie) }}</a>
                    @else
                        {{ $provider($cookie) }}
                    @endif
                </td>
                <td>{{ $purpose($cookie) }}</td>
                <td>{{ $duration($cookie) }}</td>
            </tr>
        @endforeach
    </tbody>
</table>
