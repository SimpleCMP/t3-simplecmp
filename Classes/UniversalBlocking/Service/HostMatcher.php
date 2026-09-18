<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\UniversalBlocking\Service;

use SimpleCMP\T3SimpleCmp\Library\ServicesLibrary;
use SimpleCMP\T3SimpleCmp\Service\OriginMatcher;

/**
 * Fast host → service-id lookup for the universal-blocking rewriter
 * (ADR-0013).
 *
 * Builds an index from `ServicesLibrary::services()` once at construction
 * and answers host lookups in O(1) for exact matches + O(W) for wildcard
 * suffix walks, where W is the small number of wildcard patterns the
 * library carries (~few hundred in total across 369 services).
 *
 * Three layers of resolution, returned as the first hit:
 *
 * 1. **Admin allowlist** — passed in via constructor. Highest
 *    priority. Returns null for matched hosts so the rewriter passes
 *    them through. Supports exact hosts and `*.suffix` wildcards.
 * 2. **Exact library host** — `youtube.com` → `youtube`. Hash table.
 * 3. **Wildcard library suffix** — `*.youtube.com` matches
 *    `youtube.com` (apex) AND every subdomain (`www.youtube.com`,
 *    `m.youtube.com`, …). Walk until first match. Semantics
 *    deliberately mirror the recorder's JS classifier and
 *    `ClassifierLookup::originMatches` so the rewriter and the
 *    recorder agree on what's third-party.
 *
 * Same-origin handling lives at the caller — the rewriter feeds in the
 * site's own host as a separate input and HostMatcher::match returns
 * null for it.
 */
final class HostMatcher
{
    /** @var array<string, string> exact host → service id */
    private array $exact = [];

    /** @var list<array{suffix: string, apex: string, service: string}> */
    private array $wildcards = [];

    /**
     * Path-scoped claims (`www.google.com/maps/`). Checked BEFORE the
     * exact and wildcard host indexes because a matcher that names a
     * path is strictly more specific than one that names only a host.
     *
     * @var list<array{host: string, path: string, service: string}>
     */
    private array $pathScoped = [];

    /** @var array<string, true> exact hosts the admin marked as allowed */
    private array $allowExact = [];

    /** @var list<array{suffix: string, apex: string}> allow-wildcards */
    private array $allowWildcards = [];

    /**
     * @param list<string> $allowlist admin-curated hosts that should
     *                                pass through (per `simplecmp.
     *                                universalBlocking.allowlist`).
     *                                Each entry is either an exact host
     *                                (`cdn.example.com`) or a wildcard
     *                                (`*.example.com`).
     * @param bool $blockAllThirdParty when true (default), hosts not
     *                                found in the library and not in
     *                                the allowlist return the host
     *                                itself as a synthetic service id
     *                                so the rewriter still rewrites
     *                                them. Pass `false` for the legacy
     *                                library-only narrow behaviour
     *                                (used by tests that pre-date the
     *                                strict universal-blocking posture).
     * @param iterable<array<string, mixed>>|null $services service
     *                                records to index instead of the
     *                                bundled library. Production passes
     *                                null. Tests use it to pin a small
     *                                fixture, so a resolution assertion
     *                                does not silently change meaning
     *                                when the next services-library
     *                                sync reshuffles the real data.
     */
    public function __construct(
        array $allowlist = [],
        private readonly bool $blockAllThirdParty = true,
        ?iterable $services = null,
    ) {
        foreach ($allowlist as $entry) {
            if (!is_string($entry) || $entry === '') {
                continue;
            }
            if (str_starts_with($entry, '*.')) {
                $apex = substr($entry, 2);
                $this->allowWildcards[] = [
                    'suffix' => substr($entry, 1),
                    'apex'   => $apex,
                ];
                continue;
            }
            $this->allowExact[$entry] = true;
        }
        foreach ($services ?? ServicesLibrary::services() as $service) {
            $id = (string) ($service['id'] ?? '');
            if ($id === '') {
                continue;
            }
            $origins = $service['matches']['origins'] ?? [];
            if (!is_array($origins)) {
                continue;
            }
            foreach ($origins as $origin) {
                if (!is_string($origin) || $origin === '') {
                    continue;
                }
                [$hostPattern, $pathPrefix] = OriginMatcher::split($origin);
                if ($pathPrefix !== null) {
                    $this->pathScoped[] = [
                        'host'    => $hostPattern,   // exact or "*.apex"
                        'path'    => $pathPrefix,    // "/maps/"
                        'service' => $id,
                    ];
                    continue;
                }
                if (str_starts_with($hostPattern, '*.')) {
                    $apex = substr($hostPattern, 2);
                    $this->wildcards[] = [
                        'suffix' => substr($hostPattern, 1),  // ".youtube.com"
                        'apex'   => $apex,                     // "youtube.com"
                        'service' => $id,
                    ];
                    continue;
                }
                $this->exact[$hostPattern] = $this->exact[$hostPattern] ?? $id;
            }
        }
    }

    /**
     * Returns the service id that owns this host, or null if the host
     * should pass through (allowlisted or empty).
     *
     * Thin wrapper over `resolve()` — kept for the existing test
     * surface that asserts on a plain string return. New callers
     * (HtmlRewriter, audit tooling) should prefer `resolve()` so they
     * also get the `source` field needed to drive the FE notice's
     * library-known-vs-host-derived rendering.
     */
    public function match(string $host, ?string $path = null): ?string
    {
        return $this->resolve($host, $path)['service'] ?? null;
    }

    /**
     * Resolve a host to both its service id AND the source of the
     * resolution, so callers can distinguish:
     *
     * - `source: 'library'` — the host matched a curated origin in
     *   the `simplecmp/services-library` bundle. The service id is
     *   the library entry's id (e.g. `youtube`, `facebook`). FE
     *   contextual-notice can offer "Ja" (one-time accept) safely
     *   because the visitor recognises the brand.
     * - `source: 'host'` — universal-blocking mode caught an
     *   otherwise-unknown third-party host. The service id IS the
     *   host (e.g. `random-tracker.example`). FE contextual-notice
     *   should render an informational-only state (no consent
     *   button) because the visitor has no basis to make an informed
     *   decision and the admin hasn't reviewed it.
     *
     * Returns null when the host should pass through (allowlisted,
     * empty, or `blockAllThirdParty: false` + no library match).
     *
     * @return array{service: string, source: 'library'|'host'}|null
     */
    public function resolve(string $host, ?string $path = null): ?array
    {
        if ($host === '') {
            return null;
        }
        if (isset($this->allowExact[$host])) {
            return null;
        }
        foreach ($this->allowWildcards as $w) {
            if ($host === $w['apex'] || str_ends_with($host, $w['suffix'])) {
                return null;
            }
        }
        // Most specific first: a claim that names a path beats one that
        // names only a host. Skipped entirely when the caller has no
        // path to check against — an unverifiable claim must not win
        // over a host match that IS verifiable.
        if ($path !== null) {
            foreach ($this->pathScoped as $p) {
                if (OriginMatcher::hostMatches($host, $p['host'])
                    && OriginMatcher::pathMatches($path, $p['path'])
                ) {
                    return ['service' => $p['service'], 'source' => 'library'];
                }
            }
        }
        if (isset($this->exact[$host])) {
            return ['service' => $this->exact[$host], 'source' => 'library'];
        }
        foreach ($this->wildcards as $w) {
            // `*.youtube.com` matches both the apex and any subdomain.
            if ($host === $w['apex'] || str_ends_with($host, $w['suffix'])) {
                return ['service' => $w['service'], 'source' => 'library'];
            }
        }
        return $this->blockAllThirdParty
            ? ['service' => $host, 'source' => 'host']
            : null;
    }

    /**
     * Returns the size of the underlying indexes, useful for benchmark
     * reports and "is the library big enough yet" sanity checks.
     *
     * @return array{exact: int, wildcards: int, pathScoped: int}
     */
    public function size(): array
    {
        return [
            'exact' => count($this->exact),
            'wildcards' => count($this->wildcards),
            'pathScoped' => count($this->pathScoped),
        ];
    }
}
