<script type="application/json" data-cookie-consent-config>{!! $config !!}</script>
<script {{ $attributes->merge(['type' => 'module', 'src' => $src, 'nonce' => $nonce, 'data-navigate-once' => '']) }}></script>
@foreach ($scripts as $script)
<script {{ $script['attributes'] }}>{!! $script['content'] !!}</script>
@endforeach
