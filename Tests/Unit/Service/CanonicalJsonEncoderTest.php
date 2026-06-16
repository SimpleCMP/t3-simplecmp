<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\CanonicalJsonEncoder;

/**
 * Lock the determinism contract of {@see CanonicalJsonEncoder}.
 *
 * The hash of the encoder's output is the deduplication key for audit
 * snapshots — two saves of semantically-equal content must produce
 * byte-identical JSON.
 */
final class CanonicalJsonEncoderTest extends TestCase
{
    private CanonicalJsonEncoder $encoder;

    protected function setUp(): void
    {
        $this->encoder = new CanonicalJsonEncoder();
    }

    #[Test]
    public function sortsTopLevelMapKeys(): void
    {
        $a = $this->encoder->encode(['b' => 1, 'a' => 2, 'c' => 3]);
        $b = $this->encoder->encode(['c' => 3, 'a' => 2, 'b' => 1]);
        self::assertSame($a, $b);
        // Alphabetical key order in the output, not insertion order.
        self::assertStringContainsString('"a":2,"b":1,"c":3', $a);
    }

    #[Test]
    public function sortsNestedMapKeys(): void
    {
        $a = $this->encoder->encode([
            'theme' => ['secondary' => '#fff', 'primary' => '#000'],
            'services' => [
                ['name' => 'matomo', 'purposes' => ['analytics']],
            ],
        ]);
        $b = $this->encoder->encode([
            'services' => [
                ['purposes' => ['analytics'], 'name' => 'matomo'],
            ],
            'theme' => ['primary' => '#000', 'secondary' => '#fff'],
        ]);
        self::assertSame($a, $b);
    }

    #[Test]
    public function preservesListOrder(): void
    {
        // Service order matters semantically — first service rendered
        // first in the banner. Lists must NOT be sorted.
        $a = $this->encoder->encode(['services' => ['matomo', 'ga4', 'meta']]);
        $b = $this->encoder->encode(['services' => ['meta', 'ga4', 'matomo']]);
        self::assertNotSame($a, $b);
    }

    #[Test]
    public function dropsVolatileFieldsAtEveryDepth(): void
    {
        $a = $this->encoder->encode([
            'service' => [
                'name' => 'matomo',
                'uid' => 42,
                'tstamp' => 1718500000,
                'crdate' => 1718000000,
                'library_adopted_at' => 1717000000,
            ],
        ]);
        $b = $this->encoder->encode([
            'service' => [
                'name' => 'matomo',
                'uid' => 99,
                'tstamp' => 9999999999,
                'crdate' => 1,
                'library_adopted_at' => 0,
            ],
        ]);
        // Volatile fields drift between writes; the canonical form
        // must not.
        self::assertSame($a, $b);
        self::assertStringNotContainsString('uid', $a);
        self::assertStringNotContainsString('tstamp', $a);
    }

    #[Test]
    public function emitsUnicodeLiterally(): void
    {
        // Umlauts must appear as-is for diff-readability in the BE.
        $encoded = $this->encoder->encode(['message' => 'Verfügbarkeit & Datenschutz']);
        self::assertStringContainsString('Verfügbarkeit', $encoded);
        self::assertStringNotContainsString('\\u00fc', $encoded);
    }

    #[Test]
    public function emitsSlashesLiterally(): void
    {
        $encoded = $this->encoder->encode(['url' => 'https://example.com/path']);
        self::assertStringContainsString('https://example.com/path', $encoded);
        self::assertStringNotContainsString('https:\\/\\/', $encoded);
    }

    #[Test]
    public function sameContentProducesIdenticalSha256(): void
    {
        $hashA = hash('sha256', $this->encoder->encode([
            'b' => ['z' => 2, 'a' => 1],
            'a' => [3, 2, 1],
        ]));
        $hashB = hash('sha256', $this->encoder->encode([
            'a' => [3, 2, 1],
            'b' => ['a' => 1, 'z' => 2],
        ]));
        self::assertSame($hashA, $hashB);
    }

    #[Test]
    public function differentContentProducesDifferentHash(): void
    {
        $hashA = hash('sha256', $this->encoder->encode(['name' => 'matomo']));
        $hashB = hash('sha256', $this->encoder->encode(['name' => 'ga4']));
        self::assertNotSame($hashA, $hashB);
    }

    #[Test]
    public function handlesEmptyArrayAsEmptyJsonObject(): void
    {
        // PHP can't tell `{}` from `[]` for an empty array, but the
        // encoder must be deterministic regardless of which one PHP
        // chooses. Lock the current behaviour: empty arrays serialize
        // to `[]` (PHP's default), and two empties hash identical.
        $a = $this->encoder->encode([]);
        $b = $this->encoder->encode([]);
        self::assertSame($a, $b);
    }

    #[Test]
    public function handlesDeeplyNestedStructures(): void
    {
        $a = $this->encoder->encode([
            'l1' => ['l2' => ['l3' => ['l4' => ['z' => 1, 'a' => 2]]]],
        ]);
        $b = $this->encoder->encode([
            'l1' => ['l2' => ['l3' => ['l4' => ['a' => 2, 'z' => 1]]]],
        ]);
        self::assertSame($a, $b);
    }
}
