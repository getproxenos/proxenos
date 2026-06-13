<?php

declare(strict_types=1);

namespace App\Client\Llm;

use App\Config\LlmConfig;
use Symfony\AI\Platform\Exception\ExceptionInterface as PlatformExceptionInterface;
use Symfony\AI\Platform\Message\Message;
use Symfony\AI\Platform\Message\MessageBag;
use Symfony\AI\Platform\PlatformInterface;

/**
 * Thin app-owned adapter over the Symfony AI Platform chat model (ADR-019).
 * Sends a system + user message pair to the configured chat model and returns
 * the assistant's reply text.
 *
 * The Platform (a generic, OpenAI-compatible bridge wired in config/packages/ai.yaml)
 * owns the connection — base URL, credential, HTTP transport. This adapter only
 * supplies the model id ({@see LlmConfig::$chatModel}) and isolates app code from
 * the pre-1.0 (`^0.8`) Symfony AI API so churn stays at this one seam.
 *
 * On any Platform failure/timeout — or when not configured — it throws a typed
 * {@see LlmException} so callers can degrade gracefully. The 0.3 turn loop wraps
 * this behind a host-owned model-profile resolver (ADR-014).
 *
 * Non-final so tests can subclass it with a recording double.
 */
class LlmClient
{
    public function __construct(
        private readonly PlatformInterface $platform,
        private readonly LlmConfig $config,
    ) {
    }

    /**
     * Sends a system + user message pair and returns the assistant's reply text.
     * `temperature` defaults to 0 for deterministic output.
     *
     * @throws LlmException when not configured, the request fails, or the
     *                      response is missing a completion
     */
    public function complete(string $system, string $user, float $temperature = 0.0): string
    {
        if (!$this->config->isConfigured()) {
            throw new LlmException('LLM client is not configured (LLM_BASE_URL missing).');
        }

        $messages = new MessageBag(
            Message::forSystem($system),
            Message::ofUser($user),
        );

        try {
            $result = $this->platform->invoke(
                (string) $this->config->chatModel,
                $messages,
                ['temperature' => $temperature],
            );

            return $result->asText();
        } catch (PlatformExceptionInterface $e) {
            throw new LlmException('LLM platform error: '.$e->getMessage(), null, $e);
        } catch (\Throwable $e) {
            throw new LlmException('LLM request failed: '.$e->getMessage(), null, $e);
        }
    }
}
