<?php

declare(strict_types=1);

namespace App\Tests\Functional\Chat;

use App\Ai\Chat\ChatRespondLoop;
use App\Ai\Chat\ChatRespondRequest;
use App\Entity\Membership;
use App\Entity\Tenant;
use App\Entity\User;
use App\Enum\ConversationEventType;
use App\Enum\MembershipRole;
use App\Enum\MessageRole;
use App\Enum\MessageStatus;
use App\Enum\TurnStatus;
use App\Repository\ConversationEventRepository;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use App\Repository\TurnRepository;
use App\Tests\Support\RecordingInMemoryPlatform;
use Doctrine\ORM\EntityManagerInterface;
use Symfony\AI\Platform\Message\AssistantMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Message\UserMessage;
use Symfony\Bundle\FrameworkBundle\Test\KernelTestCase;
use Symfony\Component\Clock\Clock;
use Symfony\Component\PasswordHasher\Hasher\UserPasswordHasherInterface;
use Symfony\Component\Security\Core\User\PasswordAuthenticatedUserInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 0.4 DoD: the turn loop drives the Platform in streaming mode and
 * emits cumulative `assistant_content_delta` events coalesced at UI cadence
 * (ADR-024).
 *
 * Covers:
 *  - multiple coalesced deltas, each cumulative, in order;
 *  - final cumulative text equals the full reply and the message folds COMPLETE;
 *  - the loop requests `stream: true`;
 *  - the `onDelta` callback fires with the same cumulative text the event log sees;
 *  - multi-turn assembly survives streaming (the projection reads aggregate the
 *    streamed parts via the same `replace-at-(message_id, part_index)` fold);
 *  - very short replies still produce at least one delta (the assistant message
 *    must materialize before `assistant_turn_completed` lands).
 */
final class ChatRespondLoopStreamingTest extends KernelTestCase
{
    private EntityManagerInterface $em;
    private ChatRespondLoop $loop;
    private RecordingInMemoryPlatform $platform;
    private ConversationEventRepository $events;
    private MessageRepository $messages;
    private MessagePartRepository $parts;
    private TurnRepository $turns;
    private Tenant $tenant;
    private User $user;

    protected function setUp(): void
    {
        self::bootKernel();
        $container = static::getContainer();

        $this->em = $container->get(EntityManagerInterface::class);
        $this->em->getConnection()->executeStatement(
            'TRUNCATE TABLE message_parts, messages, turns, threads, conversation_events, memberships, users, tenants RESTART IDENTITY CASCADE',
        );

        $hasher = $container->get(UserPasswordHasherInterface::class);
        $clock = Clock::get();

        $this->tenant = new Tenant('personal', 'Personal', $clock);
        $hash = $hasher->hashPassword(new TransientStreamUserHash(), 'hunter2hunter2');
        $this->user = new User('beau-stream@example.com', $hash, $clock);
        $membership = new Membership($this->user, $this->tenant, MembershipRole::OWNER, $clock);

        $this->em->persist($this->tenant);
        $this->em->persist($this->user);
        $this->em->persist($membership);
        $this->em->flush();

        $this->loop = $container->get(ChatRespondLoop::class);
        $this->platform = $container->get(RecordingInMemoryPlatform::class);
        $this->platform->reset();
        $this->events = $container->get(ConversationEventRepository::class);
        $this->messages = $container->get(MessageRepository::class);
        $this->parts = $container->get(MessagePartRepository::class);
        $this->turns = $container->get(TurnRepository::class);
    }

    public function testStreamsCumulativeCoalescedDeltasAndCompletes(): void
    {
        // Pin explicit chunks crossing the 32-char flush threshold deterministically.
        // Cumulative lengths after each chunk: 16, 32, 48, 64, 70.
        $this->platform->setNextReplyChunks([
            'aaaaaaaaaaaaaaaa',  // 16
            'bbbbbbbbbbbbbbbb',  // +16 = 32 — first flush
            'cccccccccccccccc',  // +16 = 48 — buffered
            'dddddddddddddddd',  // +16 = 64 — second flush (since first flush, +32)
            'eeeeee',            // +6  = 70 — buffered, then final flush
        ]);

        $captured = [];
        $threadId = Uuid::v7();

        $result = $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'hello',
            onDelta: function (string $cumulative) use (&$captured): void {
                $captured[] = $cumulative;
            },
        ));

        self::assertSame(str_repeat('a', 16).str_repeat('b', 16).str_repeat('c', 16).str_repeat('d', 16).str_repeat('e', 6), $result->assistantText);

        // The platform must have been invoked in streaming mode.
        $calls = $this->platform->calls();
        self::assertCount(1, $calls);
        self::assertTrue($calls[0]['options']['stream'] ?? false, 'loop must request stream: true');

        // Event ordering and types.
        $events = $this->events->findByThreadOrdered($threadId);
        $types = array_map(static fn ($e) => $e->getType(), $events);

        // 1 user_message_submitted + 1 assistant_turn_created + N deltas + 1 completed.
        self::assertSame(ConversationEventType::USER_MESSAGE_SUBMITTED, $types[0]);
        self::assertSame(ConversationEventType::ASSISTANT_TURN_CREATED, $types[1]);
        self::assertSame(ConversationEventType::ASSISTANT_TURN_COMPLETED, end($types));

        $deltaEvents = array_values(array_filter($events, static fn ($e) => ConversationEventType::ASSISTANT_CONTENT_DELTA === $e->getType()));
        self::assertGreaterThanOrEqual(2, count($deltaEvents), 'expected coalesced flushes mid-stream plus a final flush');

        // Each successive delta's text must be cumulative (strict prefix of the next).
        $prev = '';
        $messageIds = [];
        foreach ($deltaEvents as $idx => $e) {
            $text = (string) $e->getPayload()['text'];
            self::assertSame(0, (int) $e->getPayload()['part_index'], 'all deltas land on part_index 0');
            $messageIds[] = (string) $e->getPayload()['message_id'];
            self::assertGreaterThanOrEqual(\strlen($prev), \strlen($text), "delta {$idx} must be at least as long as prior");
            self::assertSame($prev, substr($text, 0, \strlen($prev)), "delta {$idx} must extend the prior cumulative text");
            $prev = $text;
        }
        self::assertSame($result->assistantText, $prev, 'last delta must equal final text');
        self::assertCount(1, array_unique($messageIds), 'all deltas must target the same assistant message');

        // Callback echoed each flush — same cumulative strings the event log saw.
        self::assertSame(array_map(static fn ($e) => (string) $e->getPayload()['text'], $deltaEvents), $captured);

        // Projection: assistant message COMPLETE with full text in part 0.
        $this->em->clear();
        $messages = $this->messages->findByThreadOrdered($threadId);
        self::assertCount(2, $messages);
        $assistantMsg = $messages[1];
        self::assertSame(MessageRole::ASSISTANT, $assistantMsg->getRole());
        self::assertSame(MessageStatus::COMPLETE, $assistantMsg->getStatus());

        $partsRows = $this->parts->findByMessageOrdered($assistantMsg->getId());
        self::assertCount(1, $partsRows);
        self::assertSame($result->assistantText, $partsRows[0]->getContent());

        $turn = $this->turns->find($result->turnId);
        self::assertNotNull($turn);
        self::assertSame(TurnStatus::COMPLETED, $turn->getStatus());
    }

    public function testEmptyReplyStillEmitsOneDeltaAndCompletes(): void
    {
        $this->platform->setNextReplyChunks([]);

        $threadId = Uuid::v7();
        $result = $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'hello',
        ));

        self::assertSame('', $result->assistantText);

        $events = $this->events->findByThreadOrdered($threadId);
        $deltaEvents = array_values(array_filter($events, static fn ($e) => ConversationEventType::ASSISTANT_CONTENT_DELTA === $e->getType()));
        self::assertCount(1, $deltaEvents, 'an empty reply still emits exactly one delta so the message materializes');
        self::assertSame('', (string) $deltaEvents[0]->getPayload()['text']);

        $turn = $this->turns->find($result->turnId);
        self::assertNotNull($turn);
        self::assertSame(TurnStatus::COMPLETED, $turn->getStatus());
    }

    public function testMultiTurnHistoryAssembledFromStreamedReplies(): void
    {
        $threadId = Uuid::v7();

        // First turn: small reply, single coalesced delta.
        $this->platform->setNextReply('reply-one');
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'question one',
        ));

        // Second turn: longer reply, multiple deltas.
        $this->platform->setNextReply(str_repeat('Z', 80));
        $this->loop->execute(new ChatRespondRequest(
            tenantId: $this->tenant->getId(),
            userId: $this->user->getId(),
            threadId: $threadId,
            userMessageText: 'question two',
        ));

        $calls = $this->platform->calls();
        self::assertCount(2, $calls);

        /** @var MessageBag $bag */
        $bag = $calls[1]['input'];
        self::assertInstanceOf(MessageBag::class, $bag);

        // The second call must see the full streamed history.
        $messages = array_values(iterator_to_array($bag));
        self::assertCount(3, $messages);

        self::assertInstanceOf(UserMessage::class, $messages[0]);
        self::assertSame('question one', $messages[0]->asText());

        self::assertInstanceOf(AssistantMessage::class, $messages[1]);
        self::assertSame('reply-one', $messages[1]->getContent());

        self::assertInstanceOf(UserMessage::class, $messages[2]);
        self::assertSame('question two', $messages[2]->asText());

        $this->em->clear();
        $persisted = $this->messages->findByThreadOrdered($threadId);
        self::assertCount(4, $persisted);
        self::assertSame(
            [MessageRole::USER, MessageRole::ASSISTANT, MessageRole::USER, MessageRole::ASSISTANT],
            array_map(static fn ($m) => $m->getRole(), $persisted),
        );

        // Both assistant messages COMPLETE, each carries the full streamed text.
        $assistantOne = $persisted[1];
        $assistantTwo = $persisted[3];
        self::assertSame(MessageStatus::COMPLETE, $assistantOne->getStatus());
        self::assertSame(MessageStatus::COMPLETE, $assistantTwo->getStatus());

        $partsOne = $this->parts->findByMessageOrdered($assistantOne->getId());
        $partsTwo = $this->parts->findByMessageOrdered($assistantTwo->getId());
        self::assertCount(1, $partsOne);
        self::assertCount(1, $partsTwo);
        self::assertSame('reply-one', $partsOne[0]->getContent());
        self::assertSame(str_repeat('Z', 80), $partsTwo[0]->getContent());
    }
}

final class TransientStreamUserHash implements PasswordAuthenticatedUserInterface
{
    public function getPassword(): ?string
    {
        return null;
    }
}
