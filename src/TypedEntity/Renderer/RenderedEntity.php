<?php

declare(strict_types=1);

namespace App\TypedEntity\Renderer;

/**
 * The schema-driven renderer's output (ADR-012). Two display modes:
 *  - `pill` — a tight in-line/citation chip; title + icon only.
 *  - `card` — an attached-entity surface; title + summary + fields[].
 *
 * Intentionally a PHP DTO, not HTML — the SPA (Track D) consumes this via
 * JSON and renders. The same DTO also gets serialized into the prompt by
 * the entity-aware assembler (chunk 7) when `expansion: pill` is honored.
 *
 * `fields[]` is an ordered list of `{ pointer, value, contentType? }` —
 * pointer kept so the SPA can label or sort if it wants; value is whatever
 * the JSON Pointer walked to (string/number/array). `null` values are
 * omitted by the renderer.
 */
final readonly class RenderedEntity
{
    public const string KIND_CARD = 'card';
    public const string KIND_PILL = 'pill';

    /**
     * @param 'card'|'pill'                                                                       $kind
     * @param list<array{pointer: string, value: mixed, contentType?: string}>                    $fields
     * @param list<array{field: string, type: string}>                                            $contentTypes
     */
    public function __construct(
        public string $kind,
        public string $title,
        public ?string $summary,
        public array $fields,
        public ?string $icon,
        public array $contentTypes,
    ) {
        if (!\in_array($kind, [self::KIND_CARD, self::KIND_PILL], true)) {
            throw new \InvalidArgumentException(\sprintf('RenderedEntity.kind must be card|pill, got %s.', $kind));
        }
    }

    /** @return array<string, mixed> */
    public function toArray(): array
    {
        return [
            'kind' => $this->kind,
            'title' => $this->title,
            'summary' => $this->summary,
            'fields' => $this->fields,
            'icon' => $this->icon,
            'content_types' => $this->contentTypes,
        ];
    }
}
