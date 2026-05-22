<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\UniversalBlocking\Service;

use SimpleCMP\ServicesLibrary\ServicesLibrary;

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
     */
    public function __construct(
        array $allowlist = [],
        private readonly bool $blockAllThirdParty = true,
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
        foreach (ServicesLibrary::services() as $service) {
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
                if (str_starts_with($origin, '*.')) {
                    $apex = substr($origin, 2);
                    $this->wildcards[] = [
                        'suffix' => substr($origin, 1),  // ".youtube.com"
                        'apex'   => $apex,                // "youtube.com"
                        'service' => $id,
                    ];
                    continue;
                }
                $this->exact[$origin] = $this->exact[$origin] ?? $id;
            }
        }
    }

    /**
     * Returns the service id that owns this host, or null if the host
     * should pass through (allowlisted or empty).
     *
     * In universal-blocking mode (the default, see constructor), hosts
     * not in the library fall back to the host itself as the synthetic
     * service id — so the rewriter still rewrites them and the
     * admin can later Kuratieren the unknown service from the BE.
     */
    public function match(string $host): ?string
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
        if (isset($this->exact[$host])) {
            return $this->exact[$host];
        }
        foreach ($this->wildcards as $w) {
            // `*.youtube.com` matches both the apex and any subdomain.
            if ($host === $w['apex'] || str_ends_with($host, $w['suffix'])) {
                return $w['service'];
            }
        }
        return $this->blockAllThirdParty ? $host : null;
    }

    /**
     * Returns the size of the underlying indexes, useful for benchmark
     * reports and "is the library big enough yet" sanity checks.
     *
     * @return array{exact: int, wildcards: int}
     */
    public function size(): array
    {
        return [
            'exact' => count($this->exact),
            'wildcards' => count($this->wildcards),
        ];
    }
}
