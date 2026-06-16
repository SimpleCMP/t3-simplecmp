<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\BridgeSecretProvider;
use SimpleCMP\T3SimpleCmp\Service\VisitorUuidHasher;

/**
 * Lock the HMAC recipe — changing it silently would break dedupe
 * (same visitor would suddenly produce a different sha256, so all
 * historical rows would appear to be different visitors).
 *
 * Recipe (frozen): `hash_hmac('sha256', $uuid, $secret . ':' . $source)`
 */
final class VisitorUuidHasherTest extends TestCase
{
    private const string UUID = 'e8400000-1234-4abc-9def-1234567890ab';
    private const string SOURCE = 'simplecmp-default';
    private const string SECRET = 'unit-test-secret-do-not-rotate-this-string-please';

    #[Test]
    public function deterministicForSameInputs(): void
    {
        $hasher = $this->hasher(self::SECRET);
        self::assertSame(
            $hasher->hash(self::UUID, self::SOURCE),
            $hasher->hash(self::UUID, self::SOURCE),
        );
    }

    #[Test]
    public function differentSourceYieldsDifferentHash(): void
    {
        $hasher = $this->hasher(self::SECRET);
        self::assertNotSame(
            $hasher->hash(self::UUID, 'simplecmp-default'),
            $hasher->hash(self::UUID, 'simplecmp-other'),
        );
    }

    #[Test]
    public function differentSecretYieldsDifferentHash(): void
    {
        self::assertNotSame(
            $this->hasher('secret-a')->hash(self::UUID, self::SOURCE),
            $this->hasher('secret-b')->hash(self::UUID, self::SOURCE),
        );
    }

    #[Test]
    public function emitsLowercase64CharHexString(): void
    {
        $hasher = $this->hasher(self::SECRET);
        $hash = $hasher->hash(self::UUID, self::SOURCE);
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
    }

    #[Test]
    public function recipeIsFrozen(): void
    {
        // Computed once with the recipe above; locks the exact bytes
        // a future refactor must reproduce or it breaks the dedup
        // contract.
        $expected = hash_hmac('sha256', self::UUID, self::SECRET . ':' . self::SOURCE);
        $hasher = $this->hasher(self::SECRET);
        self::assertSame($expected, $hasher->hash(self::UUID, self::SOURCE));
    }

    private function hasher(?string $secret): VisitorUuidHasher
    {
        $provider = $this->createMock(BridgeSecretProvider::class);
        $provider->method('get')->willReturn($secret);
        return new VisitorUuidHasher($provider);
    }
}
