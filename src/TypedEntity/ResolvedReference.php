<?php

declare(strict_types=1);

namespace App\TypedEntity;

/**
 * What the {@see EntityResolver} returns. Pairs the original reference
 * (so callers don't lose the routing fields / marker / expansion) with
 * either the resolved instance payload (`data` per ADR-013) or null
 * (dangling).
 *
 * Sidecar is reserved for the rare case where a provider returns
 * additional resolved-context data (ADR-013a `target`). v0 only populates
 * it for downstream layers that ask; the assembler does not require it.
 */
final readonly class ResolvedReference
{
    /**
     * @param array<string, mixed>|null $instance the ADR-013 `data` payload, or null when dangling
     * @param array<string, mixed>      $sidecar  resolver-supplied extras (renderer hints, etc.)
     */
    public function __construct(
        public Reference $reference,
        public ?array $instance,
        public array $sidecar = [],
    ) {
    }

    public function isResolved(): bool
    {
        return null !== $this->instance;
    }
}
