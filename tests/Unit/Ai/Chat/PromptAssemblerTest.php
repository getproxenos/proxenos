<?php

declare(strict_types=1);

namespace App\Tests\Unit\Ai\Chat;

use App\Ai\Chat\ContextBudgetPlanner;
use App\Ai\Chat\PromptAssembler;
use App\Ai\Chat\PromptContribution;
use App\Conversation\ThreadAttachmentService;
use App\TypedEntity\Core\Document\CoreDocumentDeclaration;
use App\TypedEntity\EntityResolver;
use App\TypedEntity\Reference;
use App\TypedEntity\Renderer\EntityRenderer;
use App\TypedEntity\ResolvedReference;
use PHPUnit\Framework\TestCase;
use Psr\Log\AbstractLogger;
use Psr\Log\LoggerInterface;
use Psr\Log\LogLevel;

/**
 * Entity-aware prompt assembly (step-02 chunk 7). The infra entrypoint
 * `assemble(threadId, tenantId)` is exercised end-to-end by the functional
 * chat tests; here we drive the pure core `assembleResolved()` directly with
 * hand-built {@see ResolvedReference}s — no projection, no DB.
 */
final class PromptAssemblerTest extends TestCase
{
    private const string DOC_ID_A = '019ec729-48f8-7a28-88c3-000000000001';
    private const string DOC_ID_B = '019ec729-48f8-7a28-88c3-000000000002';

    public function testReturnsOneOrderedSystemContributionForAttachedDocuments(): void
    {
        $assembler = $this->makeAssembler($this->recordingLogger());

        $contributions = $assembler->assembleResolved([
            $this->resolvedDocument(self::DOC_ID_A, 'Doc A', 'first body'),
            $this->resolvedDocument(self::DOC_ID_B, 'Doc B', 'second body'),
        ]);

        self::assertCount(1, $contributions);
        $contribution = $contributions[0];
        self::assertInstanceOf(PromptContribution::class, $contribution);
        self::assertSame(PromptContribution::ROLE_SYSTEM, $contribution->role);
        self::assertSame(PromptAssembler::ENTITY_CONTEXT_WEIGHT, $contribution->weight);

        // Both pills present, in attach order.
        self::assertStringContainsString('Doc A', $contribution->text);
        self::assertStringContainsString('Doc B', $contribution->text);
        self::assertLessThan(
            strpos($contribution->text, 'Doc B'),
            strpos($contribution->text, 'Doc A'),
            'attach order preserved',
        );
    }

    public function testPillExpansionIsHonoredWithoutDowngradeLog(): void
    {
        $logger = $this->recordingLogger();
        $assembler = $this->makeAssembler($logger);

        $contributions = $assembler->assembleResolved([
            $this->resolvedDocument(self::DOC_ID_A, 'Doc A', 'body', Reference::EXPANSION_PILL),
        ]);

        self::assertCount(1, $contributions);
        self::assertSame([], $logger->records, 'pill must not log a downgrade');
    }

    /**
     * @return list<array{0: string}>
     */
    public static function nonPillExpansions(): array
    {
        return [
            'summary downgrades' => [Reference::EXPANSION_SUMMARY],
            'full downgrades' => [Reference::EXPANSION_FULL],
        ];
    }

    #[\PHPUnit\Framework\Attributes\DataProvider('nonPillExpansions')]
    public function testSummaryAndFullExpansionAreDowngradedToPillWithDebugLog(string $expansion): void
    {
        $logger = $this->recordingLogger();
        $assembler = $this->makeAssembler($logger);

        $contributions = $assembler->assembleResolved([
            $this->resolvedDocument(self::DOC_ID_A, 'Doc A', 'body', $expansion),
        ]);

        // Still serialized (downgraded to pill), not dropped.
        self::assertCount(1, $contributions);
        self::assertStringContainsString('Doc A', $contributions[0]->text);

        // Exactly one debug record naming the requested expansion.
        $debug = array_values(array_filter(
            $logger->records,
            static fn (array $r): bool => LogLevel::DEBUG === $r['level'],
        ));
        self::assertCount(1, $debug);
        self::assertSame($expansion, $debug[0]['context']['requested_expansion'] ?? null);
        self::assertSame('core.document', $debug[0]['context']['type'] ?? null);
    }

    public function testDanglingReferenceIsSkipped(): void
    {
        $assembler = $this->makeAssembler($this->recordingLogger());

        $dangling = new ResolvedReference(
            new Reference('core', 'core.document', self::DOC_ID_A, resolved: false),
            instance: null,
        );

        $contributions = $assembler->assembleResolved([
            $dangling,
            $this->resolvedDocument(self::DOC_ID_B, 'Doc B', 'body'),
        ]);

        self::assertCount(1, $contributions);
        self::assertStringContainsString('Doc B', $contributions[0]->text);
        self::assertStringNotContainsString(self::DOC_ID_A, $contributions[0]->text);
    }

    public function testZeroAttachmentsYieldNoContributions(): void
    {
        $assembler = $this->makeAssembler($this->recordingLogger());

        self::assertSame([], $assembler->assembleResolved([]));
    }

    private function resolvedDocument(string $id, string $title, string $body, string $expansion = Reference::EXPANSION_PILL): ResolvedReference
    {
        return new ResolvedReference(
            new Reference('core', 'core.document', $id, resolved: true, expansion: $expansion),
            instance: ['title' => $title, 'body' => $body],
        );
    }

    /**
     * Build the real assembler. `assembleResolved()` touches only the renderer,
     * budget planner, declaration, and logger — so the two infra collaborators
     * are constructed without their (Doctrine-bound, final) dependencies; the
     * pure core never calls them.
     */
    private function makeAssembler(LoggerInterface $logger): PromptAssembler
    {
        /** @var ThreadAttachmentService $attachments */
        $attachments = new \ReflectionClass(ThreadAttachmentService::class)->newInstanceWithoutConstructor();
        /** @var EntityResolver $resolver */
        $resolver = new \ReflectionClass(EntityResolver::class)->newInstanceWithoutConstructor();

        return new PromptAssembler(
            $attachments,
            $resolver,
            new EntityRenderer(),
            new ContextBudgetPlanner(),
            new CoreDocumentDeclaration(),
            $logger,
        );
    }

    /**
     * @return AbstractLogger&object{records: list<array{level: string, message: string, context: array<string, mixed>}>}
     */
    private function recordingLogger(): AbstractLogger
    {
        return new class extends AbstractLogger {
            /** @var list<array{level: string, message: string, context: array<string, mixed>}> */
            public array $records = [];

            public function log($level, string|\Stringable $message, array $context = []): void
            {
                $this->records[] = ['level' => (string) $level, 'message' => (string) $message, 'context' => $context];
            }
        };
    }
}
