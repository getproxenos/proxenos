<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Event\Payload;

use App\Conversation\Event\Payload\AssistantContentDelta;
use App\Conversation\Event\Payload\AssistantTurnCompleted;
use App\Conversation\Event\Payload\AssistantTurnCreated;
use App\Conversation\Event\Payload\UserMessageSubmitted;
use App\Enum\ConversationEventType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Locks the per-type payload contracts that `ConversationEvent.payload` JSONB
 * stores and `ProjectionFolder` reads back. These are the four shapes ADR-022
 * pins for Phase 0.2.
 */
final class PayloadTest extends TestCase
{
    public function testUserMessageSubmittedSerializesToContract(): void
    {
        $id = Uuid::v7();
        $payload = new UserMessageSubmitted($id, 'hello');

        self::assertSame(ConversationEventType::USER_MESSAGE_SUBMITTED, $payload->type());
        self::assertSame(
            ['message_id' => $id->toRfc4122(), 'text' => 'hello'],
            $payload->toArray(),
        );
    }

    public function testUserMessageSubmittedRejectsEmptyText(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new UserMessageSubmitted(Uuid::v7(), '');
    }

    public function testAssistantTurnCreatedIsEmptyPayload(): void
    {
        $payload = new AssistantTurnCreated();

        self::assertSame(ConversationEventType::ASSISTANT_TURN_CREATED, $payload->type());
        self::assertSame([], $payload->toArray());
    }

    public function testAssistantContentDeltaSerializesToContract(): void
    {
        $id = Uuid::v7();
        $payload = new AssistantContentDelta($id, 0, 'hi back');

        self::assertSame(ConversationEventType::ASSISTANT_CONTENT_DELTA, $payload->type());
        self::assertSame(
            ['message_id' => $id->toRfc4122(), 'part_index' => 0, 'text' => 'hi back'],
            $payload->toArray(),
        );
    }

    public function testAssistantContentDeltaRejectsNegativePartIndex(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AssistantContentDelta(Uuid::v7(), -1, 'hi');
    }

    public function testAssistantTurnCompletedSerializesToContract(): void
    {
        $id = Uuid::v7();
        $payload = new AssistantTurnCompleted($id);

        self::assertSame(ConversationEventType::ASSISTANT_TURN_COMPLETED, $payload->type());
        self::assertSame(
            ['message_id' => $id->toRfc4122(), 'finish_reason' => 'stop'],
            $payload->toArray(),
        );
    }

    public function testAssistantTurnCompletedRejectsEmptyFinishReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AssistantTurnCompleted(Uuid::v7(), '');
    }
}
