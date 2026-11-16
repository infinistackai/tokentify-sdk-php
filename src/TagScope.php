<?php

declare(strict_types=1);

namespace Tokentify;

/**
 * Returned by {@see Tokentify::tag()}. The tags are already applied; call restore() to put the previous
 * values back (PHP's stand-in for Python's `with tokentify.tag(...)`). Nesting works: restore inner scopes first.
 *
 *     $scope = Tokentify::tag(user_id: 'u_123');
 *     try { $client->chat()->create([...]); } finally { $scope->restore(); }
 *
 * Or let {@see Tokentify::withTags()} do the try/finally for you.
 */
final class TagScope
{
    private bool $restored = false;

    /**
     * @param array<string, mixed> $previous Key => previous value; keys absent before map to TagScope::MISSING
     * @param \Closure(array<string, mixed>): void $restoreFn
     */
    public function __construct(
        private readonly array $previous,
        private readonly \Closure $restoreFn,
    ) {
    }

    /** Marker for "the key was not set before". */
    public const MISSING = "\0tokentify.missing\0";

    /** Put back the values the tags had before this scope. Safe to call more than once. */
    public function restore(): void
    {
        if ($this->restored) {
            return;
        }
        $this->restored = true;
        ($this->restoreFn)($this->previous);
    }
}
