<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\BundledLibraryInfo;

/**
 * Locks `BundledLibraryInfo` against the SOURCE.json sidecar that the
 * sync workflow writes alongside the vendored library data. Run as
 * part of `composer test:unit`, which executes from the ext's own
 * root — the SOURCE.json file lives at a deterministic relative path.
 *
 * No mocking: the file IO isn't worth a DI seam for these trivial
 * accessors. Real shipped state is what gets tested.
 */
final class BundledLibraryInfoTest extends TestCase
{
    #[Test]
    public function versionReturnsNonEmptyStringWhenSourceJsonExists(): void
    {
        $sourcePath = dirname(__DIR__, 3) . '/Resources/Private/ServicesLibrary/SOURCE.json';
        if (!is_file($sourcePath)) {
            self::markTestSkipped('SOURCE.json sidecar not present in this test run');
        }
        $info = new BundledLibraryInfo();
        $version = $info->version();
        self::assertIsString($version);
        self::assertNotSame('', $version);
    }

    #[Test]
    public function shaReturnsNullOrA40CharHexString(): void
    {
        $info = new BundledLibraryInfo();
        $sha = $info->sha();
        if ($sha === null) {
            self::assertTrue(true, 'SOURCE.json missing or sha field unrecognised');
            return;
        }
        self::assertSame(40, strlen($sha));
        self::assertMatchesRegularExpression('/^[0-9a-f]{40}$/', $sha);
    }

    #[Test]
    public function dataHashIsAStableSha256(): void
    {
        $info = new BundledLibraryInfo();
        $hash = $info->dataHash();
        self::assertSame(64, strlen($hash));
        self::assertMatchesRegularExpression('/^[0-9a-f]{64}$/', $hash);
        self::assertSame($hash, $info->dataHash(), 'two reads must agree');
    }
}
