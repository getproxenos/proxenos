<?php

declare(strict_types=1);

namespace App\Tests\Unit\TypedEntity\Core\Document;

use App\TypedEntity\Core\Document\CoreDocumentDeclaration;
use PHPUnit\Framework\TestCase;

/**
 * Locks the ADR-013 envelope shape for `core.document`. The renderer, the
 * resolver, and the SPA all consume this verbatim — a silent change here
 * is a wire-format change.
 */
final class CoreDocumentDeclarationTest extends TestCase
{
    public function testRoutingFields(): void
    {
        $decl = new CoreDocumentDeclaration();

        self::assertSame('core', $decl->provider());
        self::assertSame('core.document', $decl->type());
    }

    public function testEnvelopeShape(): void
    {
        $envelope = new CoreDocumentDeclaration()->envelope();

        self::assertSame('1', $envelope['envelope_version']);
        self::assertSame('core', $envelope['provider']);
        self::assertSame('core.document', $envelope['type']);
        self::assertSame('1.0.0', $envelope['type_version']);
        self::assertNull($envelope['custom_renderer']);
        self::assertIsArray($envelope['schema']);
        self::assertIsArray($envelope['presentation']);
        self::assertIsArray($envelope['capabilities']);
    }

    public function testSchemaRequiresTitleAndBody(): void
    {
        $schema = new CoreDocumentDeclaration()->envelope()['schema'];

        self::assertSame('object', $schema['type']);
        self::assertSame(['title', 'body'], $schema['required']);
        self::assertFalse($schema['additionalProperties']);
        self::assertArrayHasKey('title', $schema['properties']);
        self::assertArrayHasKey('body', $schema['properties']);
        self::assertArrayHasKey('tags', $schema['properties']);
        self::assertArrayHasKey('collection', $schema['properties']);
    }

    public function testPresentationHintsAreJsonPointers(): void
    {
        $presentation = new CoreDocumentDeclaration()->envelope()['presentation'];

        self::assertSame('/title', $presentation['title']);
        self::assertSame('/body', $presentation['summary']['source']);
        self::assertSame('excerpt', $presentation['summary']['strategy']);
        self::assertSame(200, $presentation['summary']['max_chars']);

        foreach ($presentation['card_fields'] as $pointer) {
            self::assertStringStartsWith('/', $pointer, 'card_fields entries must be JSON Pointers');
        }
        foreach ($presentation['detail_fields'] as $pointer) {
            self::assertStringStartsWith('/', $pointer, 'detail_fields entries must be JSON Pointers');
        }
    }

    public function testCapabilitiesAreV0Minimal(): void
    {
        $caps = new CoreDocumentDeclaration()->envelope()['capabilities'];

        self::assertTrue($caps['create']);
        self::assertTrue($caps['update']);
        self::assertFalse($caps['delete']);
        self::assertFalse($caps['search']);
    }
}
