<?php

declare(strict_types=1);

namespace App\Config;

/**
 * Connection + model settings for the OpenAI-compatible Platform that backs both
 * chat completions ({@see \App\Client\Llm\LlmClient}) and, later, embeddings.
 * One endpoint, one credential, model ids passed per call (ADR-019).
 *
 * DI-safe: the constructor never throws, and consumers guard with
 * {@see isConfigured()} before making a call. When the endpoint is not
 * configured, dependent features degrade rather than break.
 */
final readonly class LlmConfig
{
    public function __construct(
        public ?string $baseUrl = null,
        public ?string $apiKey = null,
        public ?string $chatModel = null,
        public ?string $embeddingModel = null,
        public int $embeddingDim = 1024,
    ) {
    }

    public function isConfigured(): bool
    {
        return null !== $this->baseUrl && '' !== $this->baseUrl;
    }
}
