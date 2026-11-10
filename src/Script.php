<?php

declare(strict_types=1);

namespace Eolica\LaravelCookieConsent;

use Closure;
use Illuminate\Http\Request;
use InvalidArgumentException;

final readonly class Script
{
    /**
     * @param (Closure(Request): string)|string|null $src
     * @param (Closure(Request): string)|string|null $content
     * @param array<string, bool|string|null> $attributes
     */
    public function __construct(
        private Closure|string|null $src = null,
        private Closure|string|null $content = null,
        public array $attributes = [],
    ) {
        if (($src === null) === ($content === null)) {
            throw new InvalidArgumentException('A script needs either a src or a content.');
        }
    }

    public function getSrc(Request $request): ?string
    {
        return $this->src instanceof Closure ? ($this->src)($request) : $this->src;
    }

    public function getContent(Request $request): ?string
    {
        return $this->content instanceof Closure ? ($this->content)($request) : $this->content;
    }
}
