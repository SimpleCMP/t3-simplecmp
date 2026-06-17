<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Phase-5 repository for `tx_t3simplecmp_active_settings`. One row
 * per site holding a JSON blob of editor-confirmed banner-content
 * settings.
 *
 * Only writers touch this table — never editor-form-driven. Writes
 * are made by {@see \SimpleCMP\T3SimpleCmp\Service\EffectiveSettingsResolver}
 * via the BE Settings controller.
 */
final readonly class ActiveSettingsRepository
{
    public const string TABLE = 'tx_t3simplecmp_active_settings';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<string, mixed>|null  decoded active-settings map, or null if no row
     */
    public function findBySite(string $site): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('active_json')
            ->from(self::TABLE)
            ->where('site = :site')
            ->setParameter('site', $site)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false || !is_string($row['active_json']) || $row['active_json'] === '') {
            return null;
        }
        try {
            $decoded = json_decode($row['active_json'], true, 32, JSON_THROW_ON_ERROR);
            return is_array($decoded) ? $decoded : null;
        } catch (\JsonException) {
            return null;
        }
    }

    public function hasRow(string $site): bool
    {
        $count = (int) $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->count('*')
            ->from(self::TABLE)
            ->where('site = :site')
            ->setParameter('site', $site)
            ->executeQuery()
            ->fetchOne();
        return $count > 0;
    }

    /**
     * Replace the entire active map for a site. Use this for
     * bootstrap-import and for adoptAll/resetAll batch operations.
     * Pass an empty array to clear the editor-confirmed state.
     *
     * @param array<string, mixed> $activeMap
     */
    public function replaceAll(string $site, array $activeMap, int $beUserId): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);
        $now = time();
        $payload = [
            'tstamp' => $now,
            'active_json' => json_encode($activeMap, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
            'last_modified_be_user' => $beUserId,
        ];
        $existing = $conn->createQueryBuilder()
            ->select('uid')
            ->from(self::TABLE)
            ->where('site = :site')
            ->setParameter('site', $site)
            ->executeQuery()
            ->fetchOne();
        if ($existing === false) {
            $payload['crdate'] = $now;
            $payload['site'] = $site;
            $conn->insert(self::TABLE, $payload);
            return;
        }
        $conn->update(self::TABLE, $payload, ['uid' => (int) $existing]);
    }

    /**
     * Set a single key in the active map. Merges with the existing row
     * if present (so other keys aren't lost).
     */
    public function upsertKey(string $site, string $key, mixed $value, int $beUserId): void
    {
        $map = $this->findBySite($site) ?? [];
        $map[$key] = $value;
        $this->replaceAll($site, $map, $beUserId);
    }

    /**
     * Remove a single key from the active map. Resolver then falls
     * back to YAML for that key.
     */
    public function deleteKey(string $site, string $key, int $beUserId): void
    {
        $map = $this->findBySite($site);
        if ($map === null || !array_key_exists($key, $map)) {
            return;
        }
        unset($map[$key]);
        $this->replaceAll($site, $map, $beUserId);
    }

    public function deleteBySite(string $site): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)->delete(
            self::TABLE,
            ['site' => $site],
            ['site' => Connection::PARAM_STR],
        );
    }
}
