<?php

declare(strict_types=1);

namespace App\TypedEntity\Core\Document;

use App\TypedEntity\TypedEntityDeclaration;

/**
 * `core.document` — the host-native baseline typed entity that step-02
 * builds the vertical spine against (ADR-013 envelope, ADR-017 host
 * storage). Fields the document actually carries
 * (`title`/`body`/`tags`/`collection`/`created`/`modified`) live on the
 * `CoreDocument` Doctrine entity; this declaration is the metadata layer
 * that lets the resolver, schema-driven renderer, and (later) the operation
 * registry handle it without any document-specific code.
 *
 * Presentation hints are progressive: the renderer falls back to its
 * defaults when a slot is missing. `custom_renderer` is `null` — the
 * vertical-spine slice exercises only the schema-driven path (ADR-012 escape
 * hatch is deferred; the new ADR's evidence list records what we observed).
 */
final class CoreDocumentDeclaration implements TypedEntityDeclaration
{
    public const string PROVIDER = 'core';
    public const string TYPE = 'core.document';
    public const string TYPE_VERSION = '1.0.0';
    public const string ENVELOPE_VERSION = '1';

    public function provider(): string
    {
        return self::PROVIDER;
    }

    public function type(): string
    {
        return self::TYPE;
    }

    public function envelope(): array
    {
        return [
            'envelope_version' => self::ENVELOPE_VERSION,
            'provider' => self::PROVIDER,
            'type' => self::TYPE,
            'type_version' => self::TYPE_VERSION,
            'custom_renderer' => null,
            'schema' => self::schema(),
            'presentation' => self::presentation(),
            'capabilities' => self::capabilities(),
        ];
    }

    /**
     * JSON Schema for an instance's `data` payload (structure only — the
     * envelope's `provider`/`type`/`id` are routing fields, not part of
     * `data`). Required: title + body. Optional: tags, collection, created,
     * modified (the latter two are provider-derived timestamps).
     *
     * @return array<string, mixed>
     */
    private static function schema(): array
    {
        return [
            '$schema' => 'https://json-schema.org/draft/2020-12/schema',
            'type' => 'object',
            'required' => ['title', 'body'],
            'additionalProperties' => false,
            'properties' => [
                'title' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 200],
                'body' => ['type' => 'string'],
                'tags' => [
                    'type' => 'array',
                    'items' => ['type' => 'string', 'minLength' => 1, 'maxLength' => 64],
                    'uniqueItems' => true,
                ],
                'collection' => ['type' => ['string', 'null'], 'maxLength' => 200],
                'created' => ['type' => 'string', 'format' => 'date-time'],
                'modified' => ['type' => 'string', 'format' => 'date-time'],
            ],
        ];
    }

    /**
     * Presentation hints (ADR-012). All field references are JSON Pointers
     * (e.g. `/title`) into the instance `data` payload, NOT bare field
     * names — the renderer resolves them with a pointer walk.
     *
     * @return array<string, mixed>
     */
    private static function presentation(): array
    {
        return [
            'title' => '/title',
            'summary' => [
                'strategy' => 'excerpt',
                'source' => '/body',
                'max_chars' => 200,
            ],
            'icon' => 'file-text',
            'card_fields' => ['/tags', '/modified'],
            'detail_fields' => ['/collection', '/created', '/modified', '/tags'],
            'content_types' => [
                ['field' => '/body', 'type' => 'markdown'],
            ],
        ];
    }

    /**
     * Write contract (ADR-017). v0 surface: create + update only. Bulk,
     * search, and delete are out of scope for the vertical-spine slice
     * (decision 10 in the workplan).
     *
     * @return array<string, mixed>
     */
    private static function capabilities(): array
    {
        return [
            'create' => true,
            'update' => true,
            'delete' => false,
            'search' => false,
        ];
    }
}
