<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use App\Ai\ModelProfile\ModelProfileResolver;
use App\Conversation\Event\EventEnvelope;
use App\Conversation\Event\Payload\AssistantContentDelta;
use App\Conversation\Event\Payload\AssistantTurnCompleted;
use App\Conversation\Event\Payload\AssistantTurnCreated;
use App\Conversation\Event\Payload\AssistantTurnFailed;
use App\Conversation\Event\Payload\UserMessageSubmitted;
use App\Conversation\EventAppender;
use App\Entity\Message;
use App\Enum\ActorType;
use App\Enum\MessageRole;
use App\Repository\MessagePartRepository;
use App\Repository\MessageRepository;
use Doctrine\ORM\EntityManagerInterface;
use Psr\Log\LoggerInterface;
use Psr\Log\NullLogger;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Message as PlatformMessage;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\Result\Stream\Delta\TextDelta;
use Symfony\AI\Platform\TokenUsage\TokenUsageInterface;
use Symfony\Component\Uid\Uuid;

/**
 * Phase 0.4 streaming turn loop. Shape-compatible with ADR-014's
 * `core.chat.respond` operation so adopting the registry later is additive,
 * not a refactor (handoff §"Decision 2 — Operation seam").
 *
 * Flow:
 *   1. append `user_message_submitted` — fold creates Thread + user Message
 *   2. read prior messages on the thread in position order (dumb prompt
 *      assembly per handoff §"Decision 4")
 *   3. resolve the model profile (`chat.frontier` -> Platform + model + opts)
 *   4. append `assistant_turn_created` — fold creates the Turn row
 *   5. invoke the Platform in STREAMING mode (`stream: true`) and walk the
 *      text-delta generator. Provider tokens are accumulated into a running
 *      cumulative buffer and emitted as coalesced `assistant_content_delta`
 *      events at UI cadence (see {@see self::DELTA_FLUSH_CHARS} /
 *      {@see self::DELTA_FLUSH_MS}) — never one event per provider token
 *      (`design-notes/streaming-runtime-notes.md` §5).
 *   6. emit a final flush so the last cumulative text is durable, then
 *      `assistant_turn_completed` — marks Turn + Message COMPLETE
 *
 * Delta semantics (ADR-024). Each `assistant_content_delta` carries the
 * **cumulative text-so-far** at the same `part_index` (0). `ProjectionFolder`
 * folds this as "replace the part content," which means progressively longer
 * text lands naturally and projection rebuild/replay stay idempotent — replaying
 * any prefix-then-suffix arrives at the same final state. Marginal/append
 * semantics were considered and ruled out for that reason.
 *
 * Token usage: the Platform's StreamListener promotes a `TokenUsage` delta into
 * the deferred result's metadata when the stream completes; we read it after
 * the generator is exhausted, same as the non-streaming path used to.
 *
 * Live HTTP streaming: callers (e.g. the SSE endpoint) can pass an `onDelta`
 * callback into {@see ChatRespondRequest}; it fires with the current cumulative
 * text on every coalesced flush. The callback is best-effort progress, not the
 * source of truth — the event log is.
 *
 * Errors (ADR-025): when the Platform throws, `user_message_submitted` (and
 * possibly `assistant_turn_created` + some `assistant_content_delta`s) have
 * already been appended. Before rethrowing, the loop appends
 * `assistant_turn_failed` so the projection moves the Turn (and any partial
 * assistant Message) to FAILED instead of staying stuck in STREAMING. The
 * `user_message_submitted` event is never rolled back — the user's input is
 * real and should survive, and an "explain what happened" reply or a
 * retry-from-here UI both need it. Failure-event `error_summary` is
 * sanitized by {@see self::summarizeError()} — never a raw stack trace.
 */
final class ChatRespondLoop
{
    /**
     * Flush a coalesced delta when the cumulative buffer has grown by this
     * many characters since the last flush. Tuned for typing-cadence UI updates
     * (~10–30 deltas/second on a fast provider).
     */
    private const int DELTA_FLUSH_CHARS = 32;

    /**
     * Wall-clock fallback: flush at least this often so a slow provider that
     * emits few-character tokens does not look frozen.
     */
    private const int DELTA_FLUSH_MS = 80;

    public function __construct(
        private readonly EventAppender $appender,
        private readonly ModelProfileResolver $resolver,
        private readonly MessageRepository $messages,
        private readonly MessagePartRepository $parts,
        private readonly EntityManagerInterface $em,
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

        // 5. invoke the model in streaming mode and emit coalesced deltas
        $assistantMessageId = Uuid::v7();
        $options = array_merge($resolved->options, ['stream' => true]);
        $cumulative = '';
        $lastFlushedLen = 0;
        $lastFlushAtMs = $this->nowMs();
        $deltaCount = 0;

        $emit = function (string $text) use ($request, $turnId, $assistantMessageId, &$deltaCount, &$lastFlushedLen, &$lastFlushAtMs): void {
            $this->appender->append(new EventEnvelope(
                tenantId: $request->tenantId,
                threadId: $request->threadId,
                turnId: $turnId,
                actorType: ActorType::ASSISTANT,
                actorId: null,
                payload: new AssistantContentDelta($assistantMessageId, 0, $text),
            ));
            ++$deltaCount;
            $lastFlushedLen = \strlen($text);
            $lastFlushAtMs = $this->nowMs();
        };

        try {
            $deferred = $resolved->platform->invoke($resolved->modelId, $messageBag, $options);
            foreach ($deferred->asTextStream() as $delta) {
                if (!$delta instanceof TextDelta) {
                    continue;
                }
                $cumulative .= $delta->getText();

                $grewBy = \strlen($cumulative) - $lastFlushedLen;
                $sinceMs = $this->nowMs() - $lastFlushAtMs;
                if ($grewBy >= self::DELTA_FLUSH_CHARS || ($grewBy > 0 && $sinceMs >= self::DELTA_FLUSH_MS)) {
                    $emit($cumulative);
                    if (null !== $request->onDelta) {
                        ($request->onDelta)($cumulative);
                    }
                }
            }
        } catch (\Throwable $e) {
            // ADR-025: record the failure on the event log BEFORE rethrowing so
            // the projection moves the Turn (and any partial assistant Message)
            // to FAILED instead of staying stuck in STREAMING. Widened from
            // PlatformExceptionInterface to \Throwable in response to PR #5
            // review: a bug in delta handling, a Doctrine error, an unexpected
            // SDK throwable — none of those are Platform-typed but all of
            // them leave the same stuck-turn projection unless we land the
            // failure event here.
            //
            // Two guards keep the failure-event append best-effort:
            //  1. If the EntityManager is already closed (e.g. an earlier
            //     append in this turn raised a Doctrine error and closed it),
            //     skip the append — calling appender would just throw again
            //     and shadow the original.
            //  2. If the append itself throws for any other reason, swallow
            //     the SECONDARY error and rethrow the ORIGINAL — masking the
            //     real cause is the worst failure mode here.
            if ($this->em->isOpen()) {
                try {
                    $this->appender->append(new EventEnvelope(
                        tenantId: $request->tenantId,
                        threadId: $request->threadId,
                        turnId: $turnId,
                        actorType: ActorType::ASSISTANT,
                        actorId: null,
                        payload: new AssistantTurnFailed(
                            messageId: $deltaCount > 0 ? $assistantMessageId : null,
                            finishReason: $e instanceof PlatformExceptionInterface ? 'platform_error' : 'internal_error',
                            errorSummary: self::summarizeError($e),
                        ),
                    ));
                } catch (\Throwable $secondary) {
                    $this->logger->error('chat.respond: failed to append assistant_turn_failed; original error will be rethrown', [
                        'profile' => $request->modelProfile,
                        'original' => self::summarizeError($e),
                        'secondary' => self::summarizeError($secondary),
                    ]);
                }
            } else {
                $this->logger->error('chat.respond: EntityManager closed before assistant_turn_failed could be appended', [
                    'profile' => $request->modelProfile,
                    'original' => self::summarizeError($e),
                ]);
            }

            if ($e instanceof PlatformExceptionInterface) {
                throw new \RuntimeException(\sprintf('Model invocation failed for profile "%s": %s', $request->modelProfile, $e->getMessage()), 0, $e);
            }
            throw $e;
        }

        // 6. flush the final cumulative text. Emit at least one delta even if
        // empty so the assistant message materializes and assistant_turn_completed
        // has something to mark COMPLETE.
        if (0 === $deltaCount || \strlen($cumulative) > $lastFlushedLen) {
            $emit($cumulative);
            if (null !== $request->onDelta) {
                ($request->onDelta)($cumulative);
            }
        }

        $usage = null;
        // Streamed token-usage path: `DeferredResult::getResult()` registers a
        // `TokenUsageStreamListener` against the underlying `StreamResult` (see
        // vendor/symfony/ai-platform/src/Result/DeferredResult.php:60). The
        // listener catches `TokenUsageInterface` deltas mid-stream and writes
        // them onto the result's metadata. `DeferredResult::asStream()`'s
        // `finally` block then copies the result metadata onto the deferred
        // itself once the generator is exhausted (DeferredResult.php:168) — so
        // by the time the `foreach` above has fully drained, the value lives
        // here, not on `getResult()->getMetadata()`.
        $tokenUsage = $deferred->getMetadata()->get('token_usage');
        if ($tokenUsage instanceof TokenUsageInterface) {
            $usage = $tokenUsage;
            $this->logger->info('chat.respond usage', [
                'profile' => $request->modelProfile,
                'model' => $resolved->modelId,
                'prompt_tokens' => $tokenUsage->getPromptTokens(),
                'completion_tokens' => $tokenUsage->getCompletionTokens(),
            ]);
        }

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
            assistantText: $cumulative,
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

    private function nowMs(): int
    {
        return (int) (microtime(true) * 1000);
    }

    /**
     * Reduce a Throwable to a short, non-sensitive summary safe to persist in
     * the event log forever. The payload ends up in projection rebuilds and
     * audit exports, so this method actively redacts — it does not just
     * truncate — before clamping length:
     *
     *   - drops stack traces (we never read `getTrace()`);
     *   - replaces URL query strings with `?[redacted]` (provider URLs often
     *     carry session tokens / api versions / signed params in the query);
     *   - masks `sk-…` / `pk-…` / `rk-…` style provider API keys
     *     (OpenAI/Anthropic/Replicate shapes);
     *   - masks `Bearer <token>` headers if echoed into a message;
     *   - masks long contiguous hex / base64 runs (≥24 chars), which is the
     *     shape of session tokens, signed URLs, and JWT segments.
     *
     * Order matters: redact first, then collapse whitespace, then clamp to
     * 140 chars — clamping first could leave a half-masked token in the
     * payload.
     */
    private static function summarizeError(\Throwable $e): string
    {
        $class = new \ReflectionClass($e)->getShortName();
        $raw = $e->getMessage();
        $redacted = self::redactSensitive($raw);
        $collapsed = trim((string) preg_replace('/\s+/', ' ', $redacted));
        if (\strlen($collapsed) > 140) {
            $collapsed = substr($collapsed, 0, 137).'...';
        }

        return '' === $collapsed ? $class : $class.': '.$collapsed;
    }

    /**
     * Apply the redaction rules documented on {@see self::summarizeError()}.
     * Public-by-test-visibility consideration: stays private; the unit test
     * exercises it through `summarizeError()`'s observable output.
     */
    private static function redactSensitive(string $msg): string
    {
        // Drop full URLs (host, path, AND query). Provider URLs leak api
        // version, deployment names, signed parameters, region, etc. — none
        // of that helps an operator debug from the event log and all of it
        // looks like noise in an audit export.
        $msg = (string) preg_replace('#https?://\S+#i', '[redacted-url]', $msg);

        // Provider API keys: sk-/pk-/rk- followed by a key-shaped run.
        // (OpenAI/Anthropic/Replicate publish these exact prefixes.)
        $msg = (string) preg_replace('/\b([sp]k|rk)-[A-Za-z0-9_\-]{6,}/i', '$1-[redacted]', $msg);

        // `Bearer <token>` / `Token <token>` auth headers if echoed.
        $msg = (string) preg_replace('/\b(Bearer|Token)\s+\S+/i', '$1 [redacted]', $msg);

        // Long contiguous hex / base64 / base64url runs: session tokens, JWT
        // segments, signed-URL signatures, hash digests. 32-char threshold
        // avoids clobbering long English/camelCase identifiers (typical
        // exception class names cap well below 32 chars and UUIDs are broken
        // up by their dashes).
        $msg = (string) preg_replace('/[A-Za-z0-9+\/_\-]{32,}={0,2}/', '[redacted]', $msg);

        return $msg;
    }
}
