<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use Composer\InstalledVersions;
use SimpleCMP\ServicesLibrary\ServicesLibrary;

/**
 * Thin DI-friendly wrapper over Composer's InstalledVersions API +
 * the services-library content-hash for the bundled
 * `simplecmp/services-library` package. Surfaces three signals to
 * the Bibliothek-tab freshness panel:
 *
 *   - version()  — pretty version label (`v0.3.0`, `dev-main`)
 *   - sha()      — commit SHA the package was installed from
 *   - dataHash() — sha256 over the bundled service JSON files
 *
 * Drift detection uses `dataHash()` rather than `sha()` so README /
 * CI / docs commits on the upstream library repo don't show up as
 * "drift". The repo SHA stays exposed for debugging context but
 * doesn't drive the badge.
 *
 * Wrapped (rather than calling Composer / ServicesLibrary statically
 * inline) so the controller stays testable — the static facades
 * can't be mocked without a DI seam.
 *
 * Returns null for any field that can't be resolved (e.g. path-repo
 * dev installs without a stable commit reference); templates degrade
 * per-row.
 */
final class BundledLibraryInfo
{
    private const string PACKAGE_NAME = 'simplecmp/services-library';

    /**
     * Version label such as `v0.3.0` or `dev-main`. Returns null when
     * the package isn't installed at all (defensive — the composer
     * deps require it).
     */
    public function version(): ?string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return null;
        }
        $value = InstalledVersions::getPrettyVersion(self::PACKAGE_NAME);
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Full 40-character commit SHA the package was installed from.
     * Returns null for path-repo / dev-branch installs where Composer
     * has no stable commit reference to record.
     */
    public function sha(): ?string
    {
        if (!InstalledVersions::isInstalled(self::PACKAGE_NAME)) {
            return null;
        }
        $value = InstalledVersions::getReference(self::PACKAGE_NAME);
        return is_string($value) && strlen($value) === 40 ? $value : null;
    }

    /**
     * Content hash of the bundled service files. 64-char sha256 hex
     * string. The drift indicator compares this against the upstream
     * `/v1/health.dataHash` field — equal → in sync regardless of
     * how many README commits the upstream main has accumulated.
     */
    public function dataHash(): string
    {
        return ServicesLibrary::dataHash();
    }
}
