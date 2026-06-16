<?php

declare(strict_types=1);

namespace App\Tests\Unit\TypedEntity\Renderer;

use App\TypedEntity\Core\Document\CoreDocumentDeclaration;
use App\TypedEntity\Renderer\EntityRenderer;
use App\TypedEntity\Renderer\RenderedEntity;
use PHPUnit\Framework\TestCase;

final class EntityRendererTest extends TestCase
{
    private EntityRenderer $renderer;
    /** @var array<string, mixed> */
    private array $envelope;

    protected function setUp(): void
    {
        $this->renderer = new EntityRenderer();
        $this->envelope = new CoreDocumentDeclaration()->envelope();
    }

    public function testPillModeReturnsTitleAndIconOnly(): void
    {
        $rendered = $this->renderer->render(
            $this->envelope,
            ['title' => 'Hello', 'body' => 'world'],
            'pill',
        );

        self::assertSame(RenderedEntity::KIND_PILL, $rendered->kind);
        self::assertSame('Hello', $rendered->title);
        self::assertSame('file-text', $rendered->icon);
        self::assertNull($rendered->summary);
        self::assertSame([], $rendered->fields);
    }

    public function testCardModeBuildsSummaryAndFields(): void
    {
        $rendered = $this->renderer->render(
            $this->envelope,
            [
                'title' => 'Spec',
                'body' => 'This is the document body.',
                'tags' => ['adr', 'draft'],
                'collection' => 'inbox',
                'created' => '2026-06-16T10:00:00+00:00',
                'modified' => '2026-06-16T11:00:00+00:00',
            ],
            'card',
        );

        self::assertSame(RenderedEntity::KIND_CARD, $rendered->kind);
        self::assertSame('Spec', $rendered->title);
        self::assertSame('This is the document body.', $rendered->summary);
        self::assertNotEmpty($rendered->fields);

        $pointers = array_map(static fn (array $f): string => $f['pointer'], $rendered->fields);
        self::assertSame(['/tags', '/modified'], $pointers);
    }

    public function testSummaryStrategyExcerptClampsAndAppendsEllipsis(): void
    {
        $body = str_repeat('lorem ipsum dolor sit amet ', 50);
        $rendered = $this->renderer->render(
            $this->envelope,
            ['title' => 'T', 'body' => $body],
            'card',
        );

        self::assertNotNull($rendered->summary);
        self::assertSame(200, mb_strlen($rendered->summary));
        self::assertStringEndsWith('…', $rendered->summary);
    }

    public function testMissingTitleHintFallsBackToTypeId(): void
    {
        $envelope = $this->envelope;
        unset($envelope['presentation']['title']);

        $rendered = $this->renderer->render($envelope, ['body' => 'x'], 'pill');

        self::assertSame('core.document', $rendered->title);
    }

    public function testMissingPresentationHintsDontExplode(): void
    {
        $envelope = ['type' => 'core.document', 'presentation' => []];

        $rendered = $this->renderer->render($envelope, ['title' => 'X', 'body' => 'b'], 'card');

        self::assertSame('core.document', $rendered->title); // no /title hint → fallback
        self::assertNull($rendered->summary);
        self::assertSame([], $rendered->fields);
        self::assertNull($rendered->icon);
    }

    public function testFieldsCarryContentTypeWhenDeclared(): void
    {
        $rendered = $this->renderer->render(
            $this->envelope,
            ['title' => 'T', 'body' => 'b', 'tags' => ['x'], 'modified' => '2026-06-16T11:00:00+00:00'],
            'card',
        );

        // /body is not in card_fields, but /tags is — and content_types is
        // declared for /body. Make sure we don't accidentally tag /tags as
        // markdown.
        foreach ($rendered->fields as $field) {
            self::assertArrayNotHasKey('contentType', $field, '/tags is plain — must not pick up markdown');
        }
    }

    public function testUnknownModeIsRejectedThroughDto(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        // Renderer accepts the string verbatim; the DTO is what enforces the enum.
        new RenderedEntity('detail', 'T', null, [], null, []);
    }
}
