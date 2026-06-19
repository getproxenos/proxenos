<?php

declare(strict_types=1);

namespace App\Tests\Unit\Conversation\Event\Payload;

use App\Conversation\Event\Payload\AssistantTurnCancelled;
use App\Enum\ConversationEventType;
use PHPUnit\Framework\TestCase;
use Symfony\Component\Uid\Uuid;

/**
 * Locks the `assistant_turn_cancelled` payload contract (step-03 chunk D7).
 * Shape mirrors `assistant_turn_failed`: nullable message_id + finish_reason +
 * error_summary, with cancellation-appropriate defaults.
 */
final class AssistantTurnCancelledPayloadTest extends TestCase
{
    public function testSerializesToContractWithMessageId(): void
    {
        $id = Uuid::v7();
        $payload = new AssistantTurnCancelled($id);

        self::assertSame(ConversationEventType::ASSISTANT_TURN_CANCELLED, $payload->type());
        self::assertSame(
            [
                'message_id' => $id->toRfc4122(),
                'finish_reason' => 'cancelled',
                'error_summary' => '',
            ],
            $payload->toArray(),
        );
    }

    public function testSerializesWithNullMessageIdBeforeAnyDelta(): void
    {
        $payload = new AssistantTurnCancelled(null);

        self::assertNull($payload->toArray()['message_id']);
        self::assertSame('cancelled', $payload->toArray()['finish_reason']);
    }

    public function testRejectsEmptyFinishReason(): void
    {
        $this->expectException(\InvalidArgumentException::class);

        new AssistantTurnCancelled(Uuid::v7(), '');
    }
}
