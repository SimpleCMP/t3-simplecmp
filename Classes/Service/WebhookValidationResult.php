<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Result of {@see WebhookPayloadValidator::validate()}.
 *
 * Either a validated payload array (success) or an HTTP status + safe
 * error message (failure). The message is intentionally terse — it
 * names the failing field, not the expected value, to avoid handing
 * a probing attacker an oracle.
 */
final readonly class WebhookValidationResult
{
    private function __construct(
        public bool $valid,
        public ?array $payload,
        public int $status,
        public string $message,
    ) {
    }

    public static function success(array $payload): self
    {
        return new self(true, $payload, 200, '');
    }

    public static function failure(int $status, string $message): self
    {
        return new self(false, null, $status, $message);
    }
}
