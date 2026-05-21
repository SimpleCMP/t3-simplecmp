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
 * Two layers of resolution, returned as the first hit:
 *
 * 1. **Exact host** — `youtube.com` → `youtube`. Hash table.
 * 2. **Wildcard suffix** — `*.youtube.com` matches `youtube.com` (apex)
 *    AND every subdomain (`www.youtube.com`, `m.youtube.com`, …). Walk
 *    until first match. Semantics deliberately mirror the recorder's
 *    JS classifier and `ClassifierLookup::originMatches` so the rewriter
 *    and the recorder agree on what's third-party.
 *
 * Same-origin handling lives at the caller — the rewriter feeds in the
 * site's own host(s) as an allowlist and HostMatcher returns null for
 * those so they aren't rewritten.
 *
 * Sandbox-only; not yet wired into anything that ships.
 */
final class HostMatcher
{
    /** @var array<string, string> exact host → service id */
    private array $exact = [];

    /** @var list<array{suffix: string, apex: string, service: string}> */
    private array $wildcards = [];

    public function __construct()
    {
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
     * Returns the service id that owns this host, or null if no library
     * service matches.
     */
    public function match(string $host): ?string
    {
        if ($host === '') {
            return null;
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
        return null;
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
