<?php

declare(strict_types=1);

namespace App\TypedEntity;

/**
 * Universal reference envelope (ADR-013a §8). One shape works for every
 * provider/type pair: routing fields (`provider`, `type`, `id`), the
 * universal `resolved` flag, and a small set of optional fields the
 * renderer/resolver consult when present.
 *
 * Two non-negotiables:
 *  - `id` is OPAQUE to the host. Adapters canonicalize on ingress; the
 *    host compares as byte-strings, never parses the value.
 *  - `expansion` is the reserved transclusion seam. v0 honors `pill` only;
 *    `summary` / `full` are silently downgraded by the assembler (with a
 *    debug log). DO NOT branch on `expansion` outside of the assembler —
 *    every other consumer treats it as opaque.
 *
 * `marker` is the discriminator that splits "structural reference"
 * (attached card; no marker) from "content-origin reference" (in-text
 * citation pill; marker like `[[…]]` or `@mention`). The vertical-spine
 * slice only mints structural references (attach), so `marker` is null
 * in practice; the field stays in the envelope so the renderer's contract
 * doesn't shift when in-text references arrive.
 */
final readonly class Reference
{
    public const string EXPANSION_PILL = 'pill';
    public const string EXPANSION_SUMMARY = 'summary';
    public const string EXPANSION_FULL = 'full';

    /**
     * @param 'pill'|'summary'|'full'             $expansion
     * @param array<string, mixed>|null           $snapshot  partial entity data; renderer may use, host may ignore
     * @param array<string, mixed>|null           $target    resolved-instance sidecar (rare outside Note body)
     */
    public function __construct(
        public string $provider,
        public string $type,
        public string $id,
        public bool $resolved = false,
        public ?string $marker = null,
        public ?string $label = null,
        public string $expansion = self::EXPANSION_PILL,
        public ?array $snapshot = null,
        public ?array $target = null,
    ) {
        if ('' === $provider) {
            throw new \InvalidArgumentException('Reference.provider must be non-empty.');
        }
        if ('' === $type) {
            throw new \InvalidArgumentException('Reference.type must be non-empty.');
        }
        if ('' === $id) {
            throw new \InvalidArgumentException('Reference.id must be non-empty.');
        }
        if (!\in_array($expansion, [self::EXPANSION_PILL, self::EXPANSION_SUMMARY, self::EXPANSION_FULL], true)) {
            throw new \InvalidArgumentException(\sprintf('Reference.expansion must be pill|summary|full, got %s.', $expansion));
        }
    }

    /**
     * Identity triple — byte-equality is the host's notion of "same
     * reference". Used as the dedup key for the thread attachments
     * projection (chunk 6) and for the detach event's payload.
     */
    public function identityKey(): string
    {
        return $this->provider.'|'.$this->type.'|'.$this->id;
    }

    /**
     * Wire shape — what the SPA receives and what the conversation event log
     * stores in the `thread_entity_attached` payload. Optional fields are
     * omitted when null to keep the on-the-wire shape clean.
     *
     * @return array<string, mixed>
     */
    public function toArray(): array
    {
        $out = [
            'provider' => $this->provider,
            'type' => $this->type,
            'id' => $this->id,
            'resolved' => $this->resolved,
            'expansion' => $this->expansion,
        ];
        if (null !== $this->marker) {
            $out['marker'] = $this->marker;
        }
        if (null !== $this->label) {
            $out['label'] = $this->label;
        }
        if (null !== $this->snapshot) {
            $out['snapshot'] = $this->snapshot;
        }
        if (null !== $this->target) {
            $out['target'] = $this->target;
        }

        return $out;
    }

    /**
     * @param array<string, mixed> $data
     */
    public static function fromArray(array $data): self
    {
        if (!\is_string($data['provider'] ?? null)) {
            throw new \InvalidArgumentException('Reference.provider must be a string.');
        }
        if (!\is_string($data['type'] ?? null)) {
            throw new \InvalidArgumentException('Reference.type must be a string.');
        }
        if (!\is_string($data['id'] ?? null)) {
            throw new \InvalidArgumentException('Reference.id must be a string.');
        }

        $expansion = $data['expansion'] ?? self::EXPANSION_PILL;
        if (!\is_string($expansion)) {
            throw new \InvalidArgumentException('Reference.expansion must be a string.');
        }

        return new self(
            provider: $data['provider'],
            type: $data['type'],
            id: $data['id'],
            resolved: (bool) ($data['resolved'] ?? false),
            marker: isset($data['marker']) && \is_string($data['marker']) ? $data['marker'] : null,
            label: isset($data['label']) && \is_string($data['label']) ? $data['label'] : null,
            expansion: $expansion,
            snapshot: isset($data['snapshot']) && \is_array($data['snapshot']) ? $data['snapshot'] : null,
            target: isset($data['target']) && \is_array($data['target']) ? $data['target'] : null,
        );
    }
}
