<?php

declare(strict_types=1);

namespace App\TypedEntity\Renderer;

/**
 * Minimal RFC 6901 JSON Pointer evaluator — enough to walk presentation-hint
 * pointers like `/title`, `/body`, `/tags/0`. The host owns the renderer
 * dispatch, so we don't need the URI fragment form, registries, or `~0/~1`
 * escape support beyond what the hint-author will plausibly write
 * (`~1` = "/", `~0` = "~"; both implemented because it's two lines).
 *
 * Returns null when any segment is missing — the renderer treats "pointer
 * misses" the same as "presentation hint omitted" so authoring a hint that
 * targets an optional schema field stays cheap.
 */
final class JsonPointer
{
    /**
     * @param array<string, mixed>|array<int, mixed> $data
     */
    public static function get(array $data, string $pointer): mixed
    {
        if ('' === $pointer) {
            return $data;
        }
        if (!str_starts_with($pointer, '/')) {
            throw new \InvalidArgumentException(\sprintf('JSON Pointer must be empty or start with "/", got %s.', $pointer));
        }

        $segments = array_map(
            static fn (string $s): string => strtr($s, ['~1' => '/', '~0' => '~']),
            explode('/', substr($pointer, 1)),
        );

        $cursor = $data;
        foreach ($segments as $segment) {
            if (\is_array($cursor) && array_is_list($cursor)) {
                if (!ctype_digit($segment)) {
                    return null;
                }
                $idx = (int) $segment;
                if (!\array_key_exists($idx, $cursor)) {
                    return null;
                }
                $cursor = $cursor[$idx];
                continue;
            }
            if (\is_array($cursor) && \array_key_exists($segment, $cursor)) {
                $cursor = $cursor[$segment];
                continue;
            }

            return null;
        }

        return $cursor;
    }
}
