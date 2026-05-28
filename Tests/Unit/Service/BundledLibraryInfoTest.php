<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Tests\Unit\Service;

use Composer\InstalledVersions;
use PHPUnit\Framework\Attributes\Test;
use PHPUnit\Framework\TestCase;
use SimpleCMP\T3SimpleCmp\Service\BundledLibraryInfo;

/**
 * Locks `BundledLibraryInfo` against the live Composer install of
 * `simplecmp/services-library`. Run as part of `composer test:unit`,
 * which executes from the ext's own root — Composer's autoloader is
 * already initialised, so `InstalledVersions` resolves naturally.
 *
 * No mocking: the static facade isn't worth a DI seam for these
 * trivial accessors. Real Composer state is what gets shipped.
 */
final class BundledLibraryInfoTest extends TestCase
{
    #[Test]
    public function versionReturnsNonEmptyStringWhenInstalled(): void
    {
        if (!InstalledVersions::isInstalled('simplecmp/services-library')) {
            self::markTestSkipped('services-library is not installed in this test run');
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
            self::assertTrue(true, 'path-repo or branch install — no SHA recorded');
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
