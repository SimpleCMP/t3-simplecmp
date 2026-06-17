<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\SingletonInterface;

/**
 * BE-managed tracker rows — the database-backed source for the
 * Tracker-Setup wizard.
 *
 * Storage shape (one row per tracker per site):
 *   - `site`           the site identifier this tracker is bound to
 *   - `tracker_type`   one of the registered provider types
 *                      (`matomo`, `ga4`, `gtm`, …)
 *   - `service_id`     either the provider's default or an explicit
 *                      override; the `data-name` the FE bundle gates
 *                      on
 *   - `config`         JSON-encoded provider config (Matomo url +
 *                      siteId, GA4 measurementId, …)
 *
 * Sister-table to `simplecmp.trackers` YAML — same materialization
 * pipeline (TrackerMaterializer), different source. Both coexist by
 * design:
 *   - YAML is git-friendly, integrator-focused
 *   - DB is BE-editable, editor-friendly
 *
 * Collision policy: if the same `service_id` exists in both sources,
 * the YAML entry wins. This keeps the file-based source authoritative
 * — admins can always override a BE-edited tracker via deploy.
 */
final class ManagedTrackerRepository implements SingletonInterface
{
    private const TABLE = 'tx_t3simplecmp_managed_tracker';
    protected const string LIVE_TABLE = 'tx_t3simplecmp_managed_tracker';
    protected const string DRAFT_TABLE = 'tx_t3simplecmp_managed_tracker_draft';

    use DraftRepositoryTrait;

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return list<array{
     *     uid: int,
     *     site: string,
     *     tracker_type: string,
     *     service_id: string,
     *     config: array<string, mixed>,
     *     tstamp: int,
     *     crdate: int,
     * }>
     */
    public function findBySite(string $siteIdentifier): array
    {
        $rows = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('site = :site AND deleted = 0')
            ->setParameter('site', $siteIdentifier)
            ->orderBy('tracker_type', 'ASC')
            ->addOrderBy('uid', 'ASC')
            ->executeQuery()
            ->fetchAllAssociative();

        return array_map($this->hydrate(...), $rows);
    }

    /**
     * @return array{
     *     uid: int, site: string, tracker_type: string, service_id: string,
     *     config: array<string, mixed>, tstamp: int, crdate: int
     * }|null
     */
    public function findOne(int $uid): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('*')
            ->from(self::TABLE)
            ->where('uid = :uid AND deleted = 0')
            ->setParameter('uid', $uid)
            ->executeQuery()
            ->fetchAssociative();

        return $row === false ? null : $this->hydrate($row);
    }

    /**
     * @param array<string, mixed> $config
     */
    public function save(
        ?int $uid,
        string $siteIdentifier,
        string $trackerType,
        string $serviceId,
        array $config,
    ): int {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $payload = [
            'site' => $siteIdentifier,
            'tracker_type' => $trackerType,
            'service_id' => $serviceId,
            'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
            'tstamp' => time(),
        ];

        if ($uid === null || $uid === 0) {
            $payload['crdate'] = time();
            $conn->insert(self::TABLE, $payload);
            return (int) $conn->lastInsertId();
        }

        $conn->update(self::TABLE, $payload, ['uid' => $uid]);
        return $uid;
    }

    /**
     * Soft-delete — keeps the row for audit. Hard-purge via
     * `purgeDeleted()` if needed.
     */
    public function delete(int $uid): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->update(self::TABLE, ['deleted' => 1, 'tstamp' => time()], ['uid' => $uid]);
    }

    /**
     * @param array<string, mixed> $row
     * @return array{uid: int, site: string, tracker_type: string, service_id: string, config: array<string, mixed>, tstamp: int, crdate: int}
     */
    // --- Phase 4 draft surface ---------------------------------------------

    /**
     * Mirror of {@see findBySite()} reading from the draft table.
     *
     * @return list<array{uid: int, site: string, tracker_type: string, service_id: string, config: array<string, mixed>, tstamp: int, crdate: int}>
     */
    public function findBySiteDraft(string $scope): array
    {
        $rows = $this->selectDraftRows($scope);
        $filtered = array_values(array_filter(
            $rows,
            static fn (array $r) => ($r['site'] ?? null) === $scope && (int) ($r['deleted'] ?? 0) === 0,
        ));
        return array_map($this->hydrate(...), $filtered);
    }

    public function findOneDraft(string $scope, int $uid): ?array
    {
        foreach ($this->selectDraftRows($scope) as $row) {
            if ((int) $row['uid'] === $uid && (int) ($row['deleted'] ?? 0) === 0) {
                return $this->hydrate($row);
            }
        }
        return null;
    }

    /**
     * @param array<string, mixed> $config
     */
    public function saveDraft(
        string $scope,
        ?int $uid,
        string $siteIdentifier,
        string $trackerType,
        string $serviceId,
        array $config,
        int $beUserId,
    ): int {
        $payload = [
            'site' => $siteIdentifier,
            'tracker_type' => $trackerType,
            'service_id' => $serviceId,
            'config' => json_encode($config, JSON_THROW_ON_ERROR | JSON_UNESCAPED_SLASHES),
        ];
        if ($uid === null || $uid === 0) {
            return $this->insertDraftRow($scope, $payload, $beUserId);
        }
        $this->updateDraftRow($scope, ['uid' => $uid], $payload, $beUserId);
        return $uid;
    }

    public function deleteDraft(string $scope, int $uid): void
    {
        $this->updateDraftRow($scope, ['uid' => $uid], ['deleted' => 1], 0);
    }

    private function hydrate(array $row): array
    {
        $config = [];
        if (is_string($row['config']) && $row['config'] !== '') {
            try {
                $decoded = json_decode($row['config'], true, 16, JSON_THROW_ON_ERROR);
                if (is_array($decoded)) {
                    $config = $decoded;
                }
            } catch (\JsonException) {
                // Drop silently — corrupt JSON yields an empty config,
                // which the provider's required-field guard catches at
                // materialization time and logs a clear warning.
            }
        }
        return [
            'uid' => (int) $row['uid'],
            'site' => (string) $row['site'],
            'tracker_type' => (string) $row['tracker_type'],
            'service_id' => (string) $row['service_id'],
            'config' => $config,
            'tstamp' => (int) $row['tstamp'],
            'crdate' => (int) $row['crdate'],
        ];
    }
}
