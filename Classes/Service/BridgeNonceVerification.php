<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Result of {@see BridgeNonceService::verify()}.
 *
 * The status code distinguishes legitimate-but-old nonces (`expired`)
 * from forged or tampered ones (`invalid`, `sourceMismatch`,
 * `malformed`). Logs / metrics can use that to tell "page open too
 * long" apart from "active attack."
 */
final readonly class BridgeNonceVerification
{
    public const string OK = 'ok';
    public const string MALFORMED = 'malformed';
    public const string INVALID = 'invalid';
    public const string SOURCE_MISMATCH = 'source_mismatch';
    public const string EXPIRED = 'expired';

    private function __construct(public string $status) {}

    public static function ok(): self { return new self(self::OK); }
    public static function malformed(): self { return new self(self::MALFORMED); }
    public static function invalid(): self { return new self(self::INVALID); }
    public static function sourceMismatch(): self { return new self(self::SOURCE_MISMATCH); }
    public static function expired(): self { return new self(self::EXPIRED); }

    public function isValid(): bool
    {
        return $this->status === self::OK;
    }
}
