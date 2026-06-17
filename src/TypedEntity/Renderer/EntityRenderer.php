<?php

declare(strict_types=1);

namespace App\TypedEntity\Renderer;

/**
 * Schema-driven renderer (ADR-012). Pure function over
 * (envelope, instance, mode) → {@see RenderedEntity}.
 *
 * Presentation hints are PROGRESSIVE: a missing slot falls back to a
 * sensible renderer default (no title hint → use the type id as a stand-in,
 * etc.). The renderer never inspects `provider`/`type` to special-case
 * shape — that would defeat the schema-driven contract. The escape hatch
 * (`envelope.custom_renderer`) is deferred per the step-02 decisions; if
 * an envelope carries one, we still render the schema-driven fallback in
 * v0 and the new ADR's evidence list records the case.
 *
 * Mode contract:
 *  - `pill` carries title + icon. No summary, no fields. Used in-text and
 *    inside the prompt (`expansion: pill`).
 *  - `card` carries title + summary (from presentation.summary strategy) +
 *    fields (from presentation.card_fields). Used in the SPA attach list
 *    above the composer.
 *
 * Detail view (`detail_fields`) is deferred to a follow-up — the
 * vertical-spine slice does not need it (the attach card + the in-prompt
 * pill cover the end-to-end seams).
 */
final class EntityRenderer
{
    /**
     * @param array<string, mixed> $envelope ADR-013 envelope (the type declaration)
     * @param array<string, mixed> $instance the `data` payload (the type's row)
     * @param 'card'|'pill'        $mode
     */
    public function render(array $envelope, array $instance, string $mode): RenderedEntity
    {
        $presentation = \is_array($envelope['presentation'] ?? null) ? $envelope['presentation'] : [];

        $title = $this->resolveTitle($presentation, $instance, $envelope['type'] ?? 'entity');
        $icon = \is_string($presentation['icon'] ?? null) ? $presentation['icon'] : null;
        $contentTypes = $this->normalizeContentTypes($presentation['content_types'] ?? null);

        if (RenderedEntity::KIND_PILL === $mode) {
            return new RenderedEntity(
                kind: RenderedEntity::KIND_PILL,
                title: $title,
                summary: null,
                fields: [],
                icon: $icon,
                contentTypes: $contentTypes,
            );
        }

        return new RenderedEntity(
            kind: RenderedEntity::KIND_CARD,
            title: $title,
            summary: $this->resolveSummary($presentation, $instance),
            fields: $this->resolveFields($presentation['card_fields'] ?? null, $instance, $contentTypes),
            icon: $icon,
            contentTypes: $contentTypes,
        );
    }

    /**
     * @param array<string, mixed> $presentation
     * @param array<string, mixed> $instance
     */
    private function resolveTitle(array $presentation, array $instance, string $fallback): string
    {
        $hint = $presentation['title'] ?? null;
        if (\is_string($hint) && str_starts_with($hint, '/')) {
            $value = JsonPointer::get($instance, $hint);
            if (\is_string($value) && '' !== $value) {
                return $value;
            }
        }

        return $fallback;
    }

    /**
     * @param array<string, mixed> $presentation
     * @param array<string, mixed> $instance
     */
    private function resolveSummary(array $presentation, array $instance): ?string
    {
        $hint = $presentation['summary'] ?? null;
        if (\is_string($hint)) {
            $value = JsonPointer::get($instance, $hint);

            return \is_string($value) ? $value : null;
        }
        if (!\is_array($hint)) {
            return null;
        }

        $strategy = $hint['strategy'] ?? null;
        $source = $hint['source'] ?? null;
        if (!\is_string($strategy) || !\is_string($source)) {
            return null;
        }

        $value = JsonPointer::get($instance, $source);
        if (!\is_string($value)) {
            return null;
        }

        return match ($strategy) {
            'excerpt' => $this->excerpt($value, (int) ($hint['max_chars'] ?? 200)),
            default => null, // unknown strategy: skip until ADR-012 extends it
        };
    }

    private function excerpt(string $text, int $maxChars): string
    {
        $normalized = trim((string) preg_replace('/\s+/', ' ', $text));
        if (mb_strlen($normalized) <= $maxChars) {
            return $normalized;
        }

        return rtrim(mb_substr($normalized, 0, max(0, $maxChars - 1))).'…';
    }

    /**
     * @param array<string, mixed>                    $instance
     * @param list<array{field: string, type: string}> $contentTypes
     *
     * @return list<array{pointer: string, value: mixed, contentType?: string}>
     */
    private function resolveFields(mixed $pointers, array $instance, array $contentTypes): array
    {
        if (!\is_array($pointers)) {
            return [];
        }
        $byField = [];
        foreach ($contentTypes as $entry) {
            $byField[$entry['field']] = $entry['type'];
        }
        $out = [];
        foreach ($pointers as $pointer) {
            if (!\is_string($pointer)) {
                continue;
            }
            $value = JsonPointer::get($instance, $pointer);
            if (null === $value) {
                continue;
            }
            $entry = ['pointer' => $pointer, 'value' => $value];
            if (isset($byField[$pointer])) {
                $entry['contentType'] = $byField[$pointer];
            }
            $out[] = $entry;
        }

        return $out;
    }

    /**
     * @return list<array{field: string, type: string}>
     */
    private function normalizeContentTypes(mixed $raw): array
    {
        if (!\is_array($raw)) {
            return [];
        }
        $out = [];
        foreach ($raw as $entry) {
            if (\is_array($entry) && \is_string($entry['field'] ?? null) && \is_string($entry['type'] ?? null)) {
                $out[] = ['field' => $entry['field'], 'type' => $entry['type']];
            }
        }

        return $out;
    }
}
