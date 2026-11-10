<?php

declare(strict_types=1);

use Eolica\LaravelCookieConsent\Script;
use Illuminate\Http\Request;

covers(Script::class);

it('needs either a src or a content', function (?string $src, ?string $content): void {
    expect(static fn() => new Script($src, $content))
        ->toThrow(InvalidArgumentException::class, 'A script needs either a src or a content.');
})->with([
    'neither' => [null, null],
    'both' => ['/a.js', 'a();'],
]);

it('returns the given src', function (): void {
    $script = new Script(src: '/a.js', attributes: ['async' => true]);
    $request = Request::create('https://tienda.test');

    expect($script->getSrc($request))
        ->toBe('/a.js')
        ->and($script->getContent($request))
        ->toBeNull()
        ->and($script->attributes)
        ->toBe(['async' => true]);
});

it('returns the given content', function (): void {
    $script = new Script(content: 'a();');
    $request = Request::create('https://tienda.test');

    expect($script->getContent($request))
        ->toBe('a();')
        ->and($script->getSrc($request))
        ->toBeNull()
        ->and($script->attributes)
        ->toBe([]);
});

it('resolves the src and the content with the request', function (): void {
    $request = Request::create('https://tienda.test');

    expect(new Script(src: static fn(Request $request): string => "/gtag.js?host={$request->getHost()}")->getSrc(
        $request,
    ))
        ->toBe('/gtag.js?host=tienda.test')
        ->and(new Script(content: static fn(Request $request): string => "gtag('{$request->getHost()}');")->getContent(
            $request,
        ))
        ->toBe("gtag('tienda.test');");
});
