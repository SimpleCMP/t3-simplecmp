<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Hooks\DataHandler;

use SimpleCMP\T3SimpleCmp\Domain\Repository\ConfigSnapshotRepository;
use SimpleCMP\T3SimpleCmp\Service\ConfigSnapshotListener;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\DataHandling\DataHandler;
use TYPO3\CMS\Core\SingletonInterface;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * DataHandler hook that snapshots the resolved banner config whenever
 * the editor saves a change to one of the three source tables.
 *
 * Strategy:
 *   - `tx_t3simplecmp_service` is a global registry — any change can
 *     affect every site, so we snapshot **all sites** from SiteFinder.
 *     The hash-dedup keeps the cost bounded: sites whose resolved
 *     content didn't change skip the INSERT.
 *   - `tx_t3simplecmp_theme` and `tx_t3simplecmp_translation_override`
 *     are per-site — we look up the affected site identifier from the
 *     row (in the datamap directly or via a DB read for UPDATEs that
 *     didn't carry the `site` field) and snapshot only that site.
 *
 * Hook fires on `processDatamap_afterAllOperations` so we run **once
 * per DataHandler invocation** rather than per-row. That's important
 * for multi-row saves (e.g. bulk-adopt from the library) where we
 * want one snapshot at the end, not N intermediate ones.
 *
 * Hook is a Singleton — TYPO3 instantiates DataHandler hooks via
 * `GeneralUtility::makeInstance()` once per request, but downstream
 * services come from the DI container so we resolve them lazily.
 */
final class SnapshotConfigOnSave implements SingletonInterface
{
    private const string TABLE_SERVICE = 'tx_t3simplecmp_service';
    private const string TABLE_THEME = 'tx_t3simplecmp_theme';
    private const string TABLE_OVERRIDE = 'tx_t3simplecmp_translation_override';

    /**
     * Sites that already got a snapshot during the current request,
     * keyed by `<site>:<table>`. Prevents duplicate work if a single
     * DataHandler save touches multiple rows of the same table.
     *
     * @var array<string, true>
     */
    private array $snapshotted = [];

    public function processDatamap_afterAllOperations(DataHandler $dataHandler): void
    {
        $affected = $this->collectAffectedSitesPerTable($dataHandler);
        if ($affected === []) {
            return;
        }
        $listener = $this->resolveListener();
        $beUserId = (int) ($dataHandler->BE_USER->user['uid'] ?? 0);
        foreach ($affected as $entry) {
            $key = $entry['site'] . ':' . $entry['table'];
            if (isset($this->snapshotted[$key])) {
                continue;
            }
            $this->snapshotted[$key] = true;
            $listener->snapshotIfChanged(
                $entry['site'],
                $this->triggerEventForTable($entry['table']),
                $beUserId,
            );
        }
    }

    /**
     * Inspect the DataHandler's datamap, return the list of sites
     * that need a fresh snapshot.
     *
     * @return list<array{site: string, table: string}>
     */
    private function collectAffectedSitesPerTable(DataHandler $dataHandler): array
    {
        $out = [];
        foreach ($dataHandler->datamap as $table => $rows) {
            if (!is_array($rows) || $rows === []) {
                continue;
            }
            if ($table === self::TABLE_SERVICE) {
                // Global registry — snapshot every site.
                foreach ($this->allSiteIdentifiers() as $siteIdentifier) {
                    $out[] = ['site' => $siteIdentifier, 'table' => $table];
                }
                continue;
            }
            if ($table !== self::TABLE_THEME && $table !== self::TABLE_OVERRIDE) {
                continue;
            }
            foreach ($rows as $rawUid => $fields) {
                $siteIdentifier = $this->resolveSiteForRow($table, (string) $rawUid, $fields, $dataHandler);
                if ($siteIdentifier === null || $siteIdentifier === '') {
                    continue;
                }
                $out[] = ['site' => $siteIdentifier, 'table' => $table];
            }
        }
        return $out;
    }

    /**
     * Pull the `site` value either from the datamap (the editor just
     * set it, or it's an INSERT carrying the full payload) or from
     * the persisted row (an UPDATE that didn't include the site
     * field). For NEW-prefixed uids we consult `substNEWwithIDs` so
     * we hit the post-INSERT real uid.
     *
     * @param array<string, mixed> $fields
     */
    private function resolveSiteForRow(
        string $table,
        string $rawUid,
        array $fields,
        DataHandler $dataHandler,
    ): ?string {
        if (isset($fields['site']) && is_string($fields['site']) && $fields['site'] !== '') {
            return $fields['site'];
        }
        // UPDATE that didn't pass the site column — read it back.
        $realUid = $rawUid;
        if (str_starts_with($rawUid, 'NEW') && isset($dataHandler->substNEWwithIDs[$rawUid])) {
            $realUid = (string) $dataHandler->substNEWwithIDs[$rawUid];
        }
        if (!ctype_digit($realUid) || (int) $realUid <= 0) {
            return null;
        }
        $row = GeneralUtility::makeInstance(ConnectionPool::class)
            ->getConnectionForTable($table)
            ->createQueryBuilder()
            ->select('site')
            ->from($table)
            ->where('uid = :uid')
            ->setParameter('uid', (int) $realUid)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false) {
            return null;
        }
        return is_string($row['site']) ? $row['site'] : null;
    }

    /**
     * @return list<string>
     */
    private function allSiteIdentifiers(): array
    {
        $finder = GeneralUtility::makeInstance(SiteFinder::class);
        $out = [];
        foreach ($finder->getAllSites() as $site) {
            $out[] = $site->getIdentifier();
        }
        return $out;
    }

    private function triggerEventForTable(string $table): string
    {
        return match ($table) {
            self::TABLE_SERVICE => 'service-save',
            self::TABLE_THEME => 'theme-save',
            self::TABLE_OVERRIDE => 'translation-override-save',
            default => 'unknown-save',
        };
    }

    /**
     * Resolve the listener lazily — DataHandler hooks live for the
     * lifetime of a request, but we want a fresh listener (and its
     * fresh repo connections) for each call.
     */
    private function resolveListener(): ConfigSnapshotListener
    {
        return GeneralUtility::makeInstance(ConfigSnapshotListener::class);
    }
}
