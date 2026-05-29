<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;

/**
 * Surfaces three signals about the bundled services-library snapshot
 * to the Bibliothek-tab freshness panel:
 *
 *   - version()  — pretty version label of the upstream snapshot
 *                  (`v0.3.3`, `dev-main`)
 *   - sha()      — full 40-char commit SHA the snapshot was built
 *                  from
 *   - dataHash() — sha256 over the bundled service JSON files
 *
 * `version()` + `sha()` read from `Resources/Private/ServicesLibrary/SOURCE.json`,
 * a sidecar file written alongside the vendored data by the
 * `sync-library` GitHub workflow on each push from the upstream
 * `simplecmp/services-library` repository. The file shape:
 *
 *   {
 *       "version": "v0.3.3",
 *       "sha": "90207c54d9cf9a5c24a95b2590c4aedb696c37f2",
 *       "syncedAt": "2026-05-29T12:00:00Z"
 *   }
 *
 * Drift detection uses `dataHash()` rather than `sha()` so README /
 * CI / docs commits on the upstream library repo don't show up as
 * "drift". The repo SHA stays exposed for debugging context but
 * doesn't drive the badge.
 *
 * Returns null for any field that can't be resolved (e.g. SOURCE.json
 * missing or malformed); templates degrade per-row.
 */
final class BundledLibraryInfo
{
    /**
     * Memoized result of `ServicesLibrary::dataHash()` — that call walks
     * every bundled JSON file (~368) and sha256s the content. The bundle
     * cannot change mid-request (vendored under Resources/Private/), so
     * caching for the lifetime of the singleton instance is safe and
     * avoids re-hashing on every visitor lookup that consults the sync
     * gate in `LibraryUpstreamClient`.
     */
    private ?string $cachedDataHash = null;

    /**
     * Memoized SOURCE.json decode. `false` sentinel = decode tried and
     * failed (missing file / unreadable / malformed JSON); arrays cache
     * the successful decode.
     *
     * @var array<string, mixed>|false|null
     */
    private array|false|null $cachedSource = null;

    /**
     * Version label such as `v0.3.3` or `dev-main`. Returns null when
     * SOURCE.json is missing or doesn't carry a usable version string.
     */
    public function version(): ?string
    {
        $source = $this->loadSource();
        if ($source === false) {
            return null;
        }
        $value = $source['version'] ?? null;
        return is_string($value) && $value !== '' ? $value : null;
    }

    /**
     * Full 40-character commit SHA the snapshot was built from. Returns
     * null when SOURCE.json is missing or doesn't carry a 40-char hex
     * SHA — e.g. a future dev-snapshot sync that records short SHAs.
     */
    public function sha(): ?string
    {
        $source = $this->loadSource();
        if ($source === false) {
            return null;
        }
        $value = $source['sha'] ?? null;
        return is_string($value) && strlen($value) === 40 && ctype_xdigit($value)
            ? $value
            : null;
    }

    /**
     * Content hash of the bundled service files. 64-char sha256 hex
     * string. The drift indicator compares this against the upstream
     * `/v1/health.dataHash` field — equal → in sync regardless of
     * how many README commits the upstream main has accumulated.
     */
    public function dataHash(): string
    {
        return $this->cachedDataHash ??= ServicesLibrary::dataHash();
    }

    /**
     * Lazy-load + memoize the SOURCE.json decode. Returns the decoded
     * array, or `false` when the file is missing / unreadable / not
     * valid JSON / not an object.
     *
     * @return array<string, mixed>|false
     */
    private function loadSource(): array|false
    {
        if ($this->cachedSource !== null) {
            return $this->cachedSource;
        }
        $path = dirname(__DIR__, 2) . '/Resources/Private/ServicesLibrary/SOURCE.json';
        if (!is_file($path) || !is_readable($path)) {
            return $this->cachedSource = false;
        }
        $raw = file_get_contents($path);
        if ($raw === false) {
            return $this->cachedSource = false;
        }
        try {
            $decoded = json_decode($raw, true, 32, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return $this->cachedSource = false;
        }
        if (!is_array($decoded)) {
            return $this->cachedSource = false;
        }
        return $this->cachedSource = $decoded;
    }
}
