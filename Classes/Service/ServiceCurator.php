<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

use SimpleCMP\ServicesLibrary\ServicesLibrary;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use SimpleCMP\T3SimpleCmp\Domain\Repository\ServiceRepository;

/**
 * Bridges the gap between an unknown-tracker detection and a curated
 * service entry. Three pieces of logic, all pulled out of the BE
 * controller for isolated testability:
 *
 * - {@see findExistingServiceUid()} — if any service already covers
 *   this detection's cookie or origin, return its `uid` so the admin
 *   opens the existing record for editing instead of seeing a fresh
 *   pre-filled form. When several services overlap on the same
 *   matcher, the **most recently created** (`crdate DESC`) wins —
 *   that's almost always the admin's freshly-curated one. Using
 *   `tstamp` would also bump from merely opening the edit form, so
 *   `crdate` is the stable signal.
 *
 * - {@see buildDefaults()} — produces TCA `defVals` for a new-service
 *   form. If the detection matches a bundled `simplecmp/services-library`
 *   entry, the full entry (vendor, vendorCountry, purposes,
 *   privacyPolicyUrl, description, matchers…) is used as the pre-fill.
 *   Otherwise falls through to {@see buildServiceDefaults()}.
 *
 *   The library path matters most when the admin hasn't yet run
 *   `simplecmp:import-known-trackers`, or when legacy detection rows
 *   pre-date the import — in both cases unknown-but-textbook trackers
 *   reach this code path with nothing curated to match against in the
 *   registry.
 *
 * - {@see buildServiceDefaults()} — pure transformation from a
 *   detection row to the TCA `defVals` shape; the bare fallback when
 *   no library entry covers the detection.
 */
final readonly class ServiceCurator
{
    private const string SERVICE_TABLE = 'tx_t3simplecmp_service';

    public function __construct(
        private ServiceRepository $serviceRepository,
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @param array<string, mixed> $detection
     */
    public function findExistingServiceUid(array $detection): ?int
    {
        $kind = (string) ($detection['kind'] ?? '');
        $identifier = (string) ($detection['identifier'] ?? '');
        $origin = isset($detection['origin']) ? (string) $detection['origin'] : '';
        $cookie = $kind === 'cookie' && $identifier !== '' ? $identifier : null;
        $originVal = $kind !== 'cookie' && $origin !== '' ? $origin : null;
        if ($cookie === null && $originVal === null) {
            return null;
        }
        $matches = $this->serviceRepository->lookup($cookie, $originVal);
        if ($matches === []) {
            return null;
        }
        $serviceIds = array_map(static fn (array $m) => (string) $m['id'], $matches);
        $qb = $this->connectionPool->getQueryBuilderForTable(self::SERVICE_TABLE);
        $qb->getRestrictions()->removeAll();
        $uid = $qb->select('uid')
            ->from(self::SERVICE_TABLE)
            ->where($qb->expr()->in(
                'service_id',
                $qb->createNamedParameter($serviceIds, Connection::PARAM_STR_ARRAY)
            ))
            ->orderBy('crdate', 'DESC')
            ->setMaxResults(1)
            ->executeQuery()
            ->fetchOne();
        return $uid === false ? null : (int) $uid;
    }

    /**
     * Library-aware pre-fill: if the bundled services library covers
     * this detection, use that entry's full data (vendor, purposes,
     * privacy URL, matcher arrays, …) — otherwise fall back to the
     * bare detection-derived defaults from {@see buildServiceDefaults()}.
     *
     * @param array<string, mixed> $detection
     * @return array<string, mixed>
     */
    public function buildDefaults(array $detection): array
    {
        $match = $this->findLibraryMatch($detection);
        if ($match !== null) {
            return self::libraryEntryToDefaults($match);
        }
        return self::buildServiceDefaults($detection);
    }

    /**
     * Iterate the bundled services library, return the first entry
     * whose matchers cover this detection. Services are yielded in
     * filename order (see {@see ServicesLibrary::services()}); the
     * library is curated to avoid overlapping patterns so the first
     * hit is the only hit in practice.
     *
     * @param array<string, mixed> $detection
     * @return array<string, mixed>|null
     */
    public function findLibraryMatch(array $detection): ?array
    {
        $kind = (string) ($detection['kind'] ?? '');
        $identifier = (string) ($detection['identifier'] ?? '');
        $origin = isset($detection['origin']) ? (string) $detection['origin'] : '';
        $cookie = $kind === 'cookie' && $identifier !== '' ? $identifier : null;
        $host = $kind !== 'cookie' && $origin !== '' ? $origin : null;
        if ($cookie === null && $host === null) {
            return null;
        }
        foreach (ServicesLibrary::services() as $service) {
            $cookies = $service['matches']['cookies'] ?? [];
            $origins = $service['matches']['origins'] ?? [];
            if ($cookie !== null && is_array($cookies) && self::cookieMatches($cookie, $cookies)) {
                return $service;
            }
            if ($host !== null && is_array($origins) && self::originMatches($host, $origins)) {
                return $service;
            }
        }
        return null;
    }

    /**
     * Map a services-library entry (protocol-shape JSON) to the TCA
     * `defVals` shape used by the record-edit form. Mirrors
     * `ServiceRepository::upsert()`'s field naming so the resulting
     * row looks identical to a library-imported one.
     *
     * @param array<string, mixed> $service
     * @return array<string, string>
     */
    private static function libraryEntryToDefaults(array $service): array
    {
        $defaults = [
            'service_id' => (string) ($service['id'] ?? ''),
            'name' => (string) ($service['name'] ?? ''),
            'purposes' => json_encode($service['purposes'] ?? [], JSON_UNESCAPED_SLASHES),
        ];
        if (isset($service['vendor']) && $service['vendor'] !== '') {
            $defaults['vendor'] = (string) $service['vendor'];
        }
        if (isset($service['vendorCountry']) && $service['vendorCountry'] !== '') {
            $defaults['vendor_country'] = (string) $service['vendorCountry'];
        }
        if (isset($service['privacyPolicyUrl']) && $service['privacyPolicyUrl'] !== '') {
            $defaults['privacy_policy_url'] = (string) $service['privacyPolicyUrl'];
        }
        if (isset($service['description']) && $service['description'] !== '') {
            $defaults['description'] = (string) $service['description'];
        }
        if (isset($service['retention'])) {
            $defaults['retention'] = json_encode($service['retention'], JSON_UNESCAPED_SLASHES);
        }
        if (isset($service['i18n'])) {
            $defaults['i18n'] = json_encode($service['i18n'], JSON_UNESCAPED_SLASHES);
        }
        if (isset($service['matches']['cookies'])) {
            $defaults['cookies'] = json_encode($service['matches']['cookies'], JSON_UNESCAPED_SLASHES);
        }
        if (isset($service['matches']['origins'])) {
            $defaults['origins'] = json_encode($service['matches']['origins'], JSON_UNESCAPED_SLASHES);
        }
        if (isset($service['extensions'])) {
            $defaults['extensions'] = json_encode($service['extensions'], JSON_UNESCAPED_SLASHES);
        }
        return $defaults;
    }

    /**
     * @param list<mixed> $matchers
     */
    private static function cookieMatches(string $cookieName, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (!is_string($matcher)) {
                continue;
            }
            if (strlen($matcher) >= 2 && $matcher[0] === '/' && $matcher[-1] === '/') {
                $pattern = '/' . substr($matcher, 1, -1) . '/';
                if (@preg_match($pattern, $cookieName) === 1) {
                    return true;
                }
                continue;
            }
            if ($matcher === $cookieName) {
                return true;
            }
        }
        return false;
    }

    /**
     * @param list<mixed> $matchers
     */
    private static function originMatches(string $host, array $matchers): bool
    {
        foreach ($matchers as $matcher) {
            if (!is_string($matcher)) {
                continue;
            }
            if (str_starts_with($matcher, '*.')) {
                $suffix = substr($matcher, 1);
                if (str_ends_with($host, $suffix) || $host === substr($suffix, 1)) {
                    return true;
                }
                continue;
            }
            if ($matcher === $host) {
                return true;
            }
        }
        return false;
    }

    /**
     * Bare pre-fill rules for a new-service TCA form when nothing in
     * the bundled library matches the detection:
     *
     * - `service_id` ← lowercased, kebab-ified identifier
     *   (`_GA-prop_1` → `ga-prop-1`)
     * - `name` ← raw identifier, admin will edit
     * - `purposes` ← `'[]'` (admin fills in)
     * - `cookies` ← `["<identifier>"]` for kind=cookie
     * - `origins` ← `["<origin>"]` for non-cookie kinds with origin set
     *
     * @param array<string, mixed> $detection
     * @return array<string, mixed>
     */
    public static function buildServiceDefaults(array $detection): array
    {
        $kind = (string) ($detection['kind'] ?? '');
        $identifier = (string) ($detection['identifier'] ?? '');
        $origin = isset($detection['origin']) ? (string) $detection['origin'] : '';

        $slug = strtolower(preg_replace('/[^a-z0-9]+/i', '-', $identifier) ?? '');
        $slug = trim($slug, '-') ?: 'unknown';

        $defaults = [
            'service_id' => $slug,
            'name' => $identifier,
            'purposes' => '[]',
        ];

        if ($kind === 'cookie') {
            $defaults['cookies'] = json_encode([$identifier], JSON_UNESCAPED_SLASHES);
        } elseif ($origin !== '') {
            $defaults['origins'] = json_encode([$origin], JSON_UNESCAPED_SLASHES);
        }

        return $defaults;
    }
}
