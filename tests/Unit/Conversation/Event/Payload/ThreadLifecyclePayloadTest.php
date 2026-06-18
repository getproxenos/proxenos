<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Event\Payload;

use App\Conversation\Event\Payload\ThreadArchived;
use App\Conversation\Event\Payload\ThreadRenamed;
use App\Enum\ConversationEventType;
use PHPUnit\Framework\TestCase;

/**
 * Locks the thread-lifecycle payload contracts (step-03 chunk D2). Rename
 * carries the new title; archive is an empty payload (the thread id lives on
 * the envelope) and is a soft hide, not a delete.
 */
final class ThreadLifecyclePayloadTest extends TestCase
{
    public function testRenamedSerializesTitle(): void
    {
        $payload = new ThreadRenamed('Quarterly planning');

        self::assertSame(ConversationEventType::THREAD_RENAMED, $payload->type());
        self::assertSame(['title' => 'Quarterly planning'], $payload->toArray());
    }

    public function testRenamedRejectsBlankTitle(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new ThreadRenamed('   ');
    }

    public function testArchivedIsEmptyPayload(): void
    {
        $payload = new ThreadArchived();

        self::assertSame(ConversationEventType::THREAD_ARCHIVED, $payload->type());
        self::assertSame([], $payload->toArray());
    }
}
