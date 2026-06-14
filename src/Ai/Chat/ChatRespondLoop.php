<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Ai\ModelProfile\ModelProfileResolver;
use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\AssistantContentDelta;
use App\Conversation\Event\Payload\AssistantTurnCompleted;
use App\Conversation\Event\Payload\AssistantTurnCreated;
use App\Conversation\Event\Payload\UserMessageSubmitted;
use App\Conversation\EventAppender;
use App\Entity\Message;
use App\Enum\ActorType;
use App\Enum\MessageRole;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Message as PlatformMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 0.3 minimal turn loop. Shape-compatible with ADR-014's
 * `core.chat.respond` operation so adopting the registry later is additive,
 * not a refactor (handoff §"Decision 2 — Operation seam").
 *
 * Flow (non-streaming v0):
 *   1. append `user_message_submitted` — fold creates Thread + user Message
 *   2. read prior messages on the thread in position order (the dumb prompt
 *      assembly per handoff §"Decision 4")
 *   3. resolve the model profile (`chat.frontier` -> Platform + model + opts)
 *   4. append `assistant_turn_created` — fold creates the Turn row
 *   5. invoke the Platform; capture full text + token usage if surfaced
 *   6. append `assistant_content_delta` — payload carries the FULL response
 *      text in v0; once 0.3-streaming lands, deltas split per chunk
 *   7. append `assistant_turn_completed` — marks Turn + Message COMPLETE
 *
 * Why a single delta carries the whole text in v0: ADR-022 v1 payload contract
 * already specifies `assistant_content_delta` as the surface for assistant
 * text; folding it once as the cumulative-text-replace form (which the
 * existing `ProjectionFolder` already implements) lets the streaming layer
 * append more deltas later without changing the projection contract.
 *
 * Errors: when the Platform throws, the user_message_submitted event has
 * already been appended (and the projection updated). We do NOT roll it back;
 * the user's message is real and should survive — even if the assistant
 * generation failed. The exception bubbles to the caller; ADR-022's
 * `assistant_turn_failed` event is left as Phase 0.4+ work.
 */
final class ChatRespondLoop
{
    public function __construct(
        private readonly EventAppender $appender,
        private readonly ModelProfileResolver $resolver,
        private readonly MessageRepository $messages,
        private readonly MessagePartRepository $parts,
        private readonly LoggerInterface $logger = new NullLogger(),
    ) {
    }

    public function execute(ChatRespondRequest $request): ChatRespondResult
    {
        // 1. user_message_submitted -> creates Thread (lazy) + user Message
        $userMessageId = Uuid::v7();
        $this->appender->append(new EventEnvelope(
            tenantId: $request->tenantId,
            threadId: $request->threadId,
            turnId: null,
            actorType: ActorType::USER,
            actorId: $request->userId->toRfc4122(),
            payload: new UserMessageSubmitted($userMessageId, $request->userMessageText),
        ));

        // 2. dumb prompt assembly: prior messages on this thread, in order
        $messageBag = $this->assemblePromptFromProjection($request->threadId);

        // 3. resolve profile -> Platform + model id + options
        $resolved = $this->resolver->resolve($request->modelProfile);

        // 4. assistant_turn_created -> Turn row
        $turnId = Uuid::v7();
        $this->appender->append(new EventEnvelope(
            tenantId: $request->tenantId,
            threadId: $request->threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantTurnCreated(),
        ));

        // 5. invoke the model
        try {
            $platformResult = $resolved->platform->invoke($resolved->modelId, $messageBag, $resolved->options);
            $assistantText = $platformResult->asText();
        } catch (PlatformExceptionInterface $e) {
            throw new \RuntimeException(\sprintf('Model invocation failed for profile "%s": %s', $request->modelProfile, $e->getMessage()), 0, $e);
        }

        $usage = null;
        // asText() triggered DeferredResult::getResult(), which populates metadata
        $tokenUsage = $platformResult->getResult()->getMetadata()->get('token_usage');
        if ($tokenUsage instanceof TokenUsageInterface) {
            $usage = $tokenUsage;
            $this->logger->info('chat.respond usage', [
                'profile' => $request->modelProfile,
                'model' => $resolved->modelId,
                'prompt_tokens' => $tokenUsage->getPromptTokens(),
                'completion_tokens' => $tokenUsage->getCompletionTokens(),
            ]);
        }

        // 6. assistant_content_delta -> creates assistant Message + part, full text
        $assistantMessageId = Uuid::v7();
        $this->appender->append(new EventEnvelope(
            tenantId: $request->tenantId,
            threadId: $request->threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantContentDelta($assistantMessageId, 0, $assistantText),
        ));

        // 7. assistant_turn_completed -> marks Turn + Message COMPLETE
        $this->appender->append(new EventEnvelope(
            tenantId: $request->tenantId,
            threadId: $request->threadId,
            turnId: $turnId,
            actorType: ActorType::ASSISTANT,
            actorId: null,
            payload: new AssistantTurnCompleted($assistantMessageId, 'stop'),
        ));

        return new ChatRespondResult(
            threadId: $request->threadId,
            turnId: $turnId,
            assistantMessageId: $assistantMessageId,
            assistantText: $assistantText,
            usage: $usage,
        );
    }

    private function assemblePromptFromProjection(Uuid $threadId): MessageBag
    {
        $messages = $this->messages->findByThreadOrdered($threadId);

        $platformMessages = [];
        foreach ($messages as $message) {
            $text = $this->collectMessageText($message);
            if ('' === $text) {
                continue;
            }

            $platformMessages[] = match ($message->getRole()) {
                MessageRole::USER => PlatformMessage::ofUser($text),
                MessageRole::ASSISTANT => PlatformMessage::ofAssistant($text),
            };
        }

        return new MessageBag(...$platformMessages);
    }

    private function collectMessageText(Message $message): string
    {
        $parts = $this->parts->findByMessageOrdered($message->getId());
        $buffer = '';
        foreach ($parts as $part) {
            if ('text' === $part->getKind()) {
                $buffer .= $part->getContent();
            }
        }

        return $buffer;
    }
}
