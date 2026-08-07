<?php

namespace App\Services\Messaging;

/**
 * Result of a provider send operation.
 */
class ProviderResult
{
    public function __construct(
        public readonly bool $success,
        public readonly ?string $providerMessageId = null,
        public readonly ?string $errorCode = null,
        public readonly ?string $errorMessage = null,
        public readonly array $raw = [],
    ) {}

    public static function ok(string $providerMessageId, array $raw = []): self
    {
        return new self(true, $providerMessageId, null, null, $raw);
    }

    public static function failed(string $errorCode, string $errorMessage, array $raw = []): self
    {
        return new self(false, null, $errorCode, $errorMessage, $raw);
    }
}
