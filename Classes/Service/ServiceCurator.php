<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Service;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use WapplerSystems\SimpleCmpTypo3\Domain\Repository\ServiceRepository;

/**
 * Bridges the gap between an unknown-tracker detection and a curated
 * service entry. Two pieces of logic, both pulled out of the BE
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
 * - {@see buildServiceDefaults()} — pure transformation from a
 *   detection row to the TCA `defVals` shape for a new-record form.
 */
final readonly class ServiceCurator
{
    private const string SERVICE_TABLE = 'tx_simplecmptypo3_service';

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
     * Pre-fill rules for a new-service TCA form, derived from the
     * detection:
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
