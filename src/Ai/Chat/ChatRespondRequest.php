<?php

declare(strict_types=1);

namespace App\Ai\Chat;

use Symfony\Component\Uid\Uuid;

/**
 * Operation-compatible request envelope for {@see ChatRespondLoop}. The shape
 * mirrors ADR-014's `core.chat.respond` operation (request_context + the
 * caller-supplied input + a model-profile reference). The host does not yet
 * route through ADR-014's registry; adopting it later only requires wrapping
 * the call site, not changing this contract.
 *
 * `onDelta` is best-effort live progress for HTTP-side streaming (the SSE
 * endpoint passes one in). It fires on every coalesced cumulative flush — the
 * same string that goes into the durable `assistant_content_delta` event. The
 * event log remains the source of truth; the callback is fan-out only.
 */
final readonly class ChatRespondRequest
{
    /**
     * @param (\Closure(string): void)|null $onDelta fires with cumulative text on each flush
     */
    public function __construct(
        public Uuid $tenantId,
        public Uuid $userId,
        public Uuid $threadId,
        public string $userMessageText,
        public string $modelProfile = 'proxenos.task.chat',
        public ?\Closure $onDelta = null,
    ) {
        if ('' === trim($userMessageText)) {
            throw new \InvalidArgumentException('userMessageText must be non-empty.');
        }
        if ('' === $modelProfile) {
            throw new \InvalidArgumentException('modelProfile must be non-empty.');
        }
    }
}
