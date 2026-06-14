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
 */
final readonly class ChatRespondRequest
{
    public function __construct(
        public Uuid $tenantId,
        public Uuid $userId,
        public Uuid $threadId,
        public string $userMessageText,
        public string $modelProfile = 'chat.frontier',
    ) {
        if ('' === trim($userMessageText)) {
            throw new \InvalidArgumentException('userMessageText must be non-empty.');
        }
        if ('' === $modelProfile) {
            throw new \InvalidArgumentException('modelProfile must be non-empty.');
        }
    }
}
