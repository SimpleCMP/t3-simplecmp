<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use Doctrine\DBAL\Exception\UniqueConstraintViolationException;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * Admin-allowed third-party stylesheet hosts (REQ-N8 Phase C2).
 *
 * When `universalBlocking.blockStylesheets` is on, a host listed here has its
 * `<link rel="stylesheet">` passed through by the rewriter — but ONLY its
 * stylesheets. Scripts / iframes from the same host are still gated by
 * universal blocking. This is the deliberate difference from the host-wide
 * `universalBlocking.allowlist` setting (which passes every resource type):
 * the admin reviewing blocked CSS allows the *stylesheet*, not the host.
 *
 * Keyed by `source` (= `DiscoverSource::forSite()` = the site's storageName),
 * the same value stored on discover-recorded detections — so the BE "allow"
 * action (from a detection row) and `HtmlRewriter::process()` (which derives
 * the source from the request's Site) agree on the key. Hosts are stored
 * lowercased (DNS is case-insensitive; the rewriter compares lowercased).
 */
final class AllowedStylesheetHostRepository implements SingletonInterface
{
    private const TABLE = 'tx_t3simplecmp_allowed_stylesheet_host';
    protected const string LIVE_TABLE = 'tx_t3simplecmp_allowed_stylesheet_host';
    protected const string DRAFT_TABLE = 'tx_t3simplecmp_allowed_stylesheet_host_draft';

    use DraftRepositoryTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * Lowercased stylesheet-allowed hosts for a detection source.
     *
     * @return list<string>
     */
    public function hostsForSource(string $source): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('host')
            ->from(self::TABLE)
            ->where('source = :source')
            ->setParameter('source', $source)
            ->executeQuery()
            ->fetchFirstColumn();

        $hosts = [];
        foreach ($rows as $host) {
            $host = strtolower((string) $host);
            if ($host !== '') {
                $hosts[] = $host;
            }
        }

        return $hosts;
    }

    /**
     * Allow a host's stylesheets for a source. Idempotent: a repeat allow (or
     * a concurrent one) is a no-op via the UNIQUE (source, host) key.
     */
    public function allow(string $source, string $host): void
    {
        $host = strtolower(trim($host));
        if ($source === '' || $host === '') {
            return;
        }
        $now = time();
        try {
            $this->connectionPool->getConnectionForTable(self::TABLE)->insert(self::TABLE, [
                'pid' => 0,
                'crdate' => $now,
                'tstamp' => $now,
                'source' => $source,
                'host' => $host,
            ]);
        } catch (UniqueConstraintViolationException) {
            // Already allowed — nothing to do.
        }
    }

    // --- Phase 4 draft surface ---------------------------------------------

    /**
     * @return list<string>
     */
    public function hostsForSourceDraft(string $scope): array
    {
        $source = 'simplecmp-' . $scope;
        $rows = $this->selectDraftRows($scope);
        $hosts = [];
        foreach ($rows as $row) {
            if (($row['source'] ?? null) !== $source) {
                continue;
            }
            $host = strtolower((string) ($row['host'] ?? ''));
            if ($host !== '') {
                $hosts[] = $host;
            }
        }
        return $hosts;
    }

    /**
     * Allow a host in the draft set. Idempotent — duplicates are
     * silently swallowed via the UNIQUE (draft_site, source, host).
     */
    public function allowDraft(string $scope, string $host, int $beUserId): void
    {
        $host = strtolower(trim($host));
        if ($host === '') {
            return;
        }
        try {
            $this->insertDraftRow($scope, [
                'pid' => 0,
                'source' => 'simplecmp-' . $scope,
                'host' => $host,
            ], $beUserId);
        } catch (UniqueConstraintViolationException) {
            // Already in the draft set.
        }
    }

    public function revokeDraft(string $scope, string $host): int
    {
        return $this->deleteDraftRows($scope, [
            'source' => 'simplecmp-' . $scope,
            'host' => strtolower(trim($host)),
        ]);
    }
}
