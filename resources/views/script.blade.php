<script {{ $attributes->merge(['type' => 'text/plain', 'data-cookie-consent' => $service, 'data-src' => $src, 'data-type' => $type]) }}>{{ $slot }}</script>
