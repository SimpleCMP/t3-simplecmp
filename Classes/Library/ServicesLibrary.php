<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Library;

/**
 * Helper for consumers of the SimpleCMP services library — exposes the
 * data directory path and an iterator over loaded service records.
 *
 * The data files at `Resources/Private/ServicesLibrary/data/services/*.json`
 * follow the upstream SimpleCMP Service-DB protocol shape. See
 * https://github.com/SimpleCMP/simplecmp/blob/main/docs/service-db-protocol.md
 * for the field reference.
 *
 * This file is vendored from `SimpleCMP/services-library` by the
 * `sync-library` GitHub workflow on every push to that repository's
 * main branch. Manual edits to this file or the data directory will
 * be overwritten on the next sync — make changes upstream.
 *
 * ## Multi-TLD vendor coverage
 *
 * Real-world vendors frequently run their services across several
 * apex domains — Meta on `facebook.com` AND `facebook.net` AND
 * `fbcdn.net`, Google on `googletagmanager.com` AND `doubleclick.net`,
 * etc. The library models this by allowing each service to declare
 * an optional `matches.aliasOrigins` list alongside the canonical
 * `matches.origins`. Both contain origin-matcher entries (exact
 * hosts or `*.suffix` wildcards), same semantics.
 *
 * `services()` **flattens** the two lists at load time — consumers
 * see one combined `matches.origins` array and don't need to know
 * about the split. The on-disk separation exists only for curation
 * + audit purposes: `origins` is the canonical (typically
 * OCD-derived or hand-curated headline domains), `aliasOrigins` is
 * the hand-curated extension capturing the vendor's other TLDs.
 * Audit tooling reads the raw files to flag coverage gaps without
 * the flattening interfering.
 */
final class ServicesLibrary
{
    /**
     * Absolute filesystem path to the directory holding the JSON
     * service definitions, without trailing slash.
     */
    public static function dataPath(): string
    {
        return dirname(__DIR__, 2) . '/Resources/Private/ServicesLibrary/data/services';
    }

    /**
     * Content hash of the bundled service data — a sha256 over every
     * `data/services/*.json` file, concatenated in filename order
     * with `\0` separators. Stable across reads, identical bundles
     * produce identical hashes regardless of filesystem timestamps.
     *
     * Designed as the canonical "data version" signal for consumers
     * that want to detect drift between a bundled snapshot and a
     * live upstream serving the same library. README edits, CI
     * config changes, importer scripts, audit fixtures — none of
     * them touch this hash. Only the service files themselves do.
     *
     * Reference-server implementations should expose this on their
     * `/v1/health` endpoint (as `dataHash`) so consumers can do a
     * single-string equality check.
     *
     * `$customDataDir` is provided for build tools (the canonical
     * reference-server's `rebuild-from-library.php` script) that
     * compute the hash against a fresh repo clone, not the
     * composer-installed bundle. Pass `null` to hash the bundled
     * data — the common case.
     */
    public static function dataHash(?string $customDataDir = null): string
    {
        $dir = $customDataDir ?? self::dataPath();
        $files = glob(rtrim($dir, '/') . '/*.json') ?: [];
        sort($files);
        $hash = hash_init('sha256');
        foreach ($files as $file) {
            // Include the basename so a rename — even with identical
            // content — produces a different hash. Otherwise renaming
            // `foo.json` to `bar.json` would be invisible to drift
            // detection.
            hash_update($hash, basename($file));
            hash_update($hash, "\0");
            hash_update($hash, (string) file_get_contents($file));
            hash_update($hash, "\0");
        }
        return hash_final($hash);
    }

    /**
     * Iterate every bundled service as a decoded array. Files are
     * yielded in filename order for deterministic test output.
     *
     * `matches.aliasOrigins` (when present) is merged into
     * `matches.origins` before yielding. Duplicates are removed
     * preserving first-seen order. Consumers see one flat list.
     *
     * @return iterable<int, array<string, mixed>>
     */
    public static function services(): iterable
    {
        $files = glob(self::dataPath() . '/*.json') ?: [];
        sort($files);
        foreach ($files as $file) {
            $decoded = json_decode((string) file_get_contents($file), true, 32, JSON_THROW_ON_ERROR);
            if (is_array($decoded)) {
                yield self::flattenAliasOrigins($decoded);
            }
        }
    }

    /**
     * Merge `matches.aliasOrigins` into `matches.origins` and drop
     * the `aliasOrigins` key from the yielded record. Idempotent on
     * services without `aliasOrigins`.
     *
     * @param array<string, mixed> $service
     * @return array<string, mixed>
     */
    private static function flattenAliasOrigins(array $service): array
    {
        $matches = $service['matches'] ?? null;
        if (!is_array($matches)) {
            return $service;
        }
        $aliases = $matches['aliasOrigins'] ?? null;
        if (!is_array($aliases) || $aliases === []) {
            // Still strip the empty alias array if present, so
            // consumers never see it.
            if (array_key_exists('aliasOrigins', $matches)) {
                unset($matches['aliasOrigins']);
                $service['matches'] = $matches;
            }
            return $service;
        }
        $origins = (array) ($matches['origins'] ?? []);
        // First-seen-wins dedup. Origins come first so the canonical
        // entries keep their position. Per the Service-DB protocol,
        // `origins` and `aliasOrigins` are arrays of strings — the
        // `aliasOriginsFieldShapeIsValid` schema test enforces that
        // upstream — so non-string entries are skipped defensively
        // rather than coerced via `serialize()`.
        $seen = [];
        $merged = [];
        foreach ([...$origins, ...$aliases] as $entry) {
            if (!is_string($entry)) {
                continue;
            }
            if (isset($seen[$entry])) {
                continue;
            }
            $seen[$entry] = true;
            $merged[] = $entry;
        }
        $matches['origins'] = $merged;
        unset($matches['aliasOrigins']);
        $service['matches'] = $matches;
        return $service;
    }
}
