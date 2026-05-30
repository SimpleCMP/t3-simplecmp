<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Per-site bundle-translation overrides keyed by `(site, language,
 * dotted-key)`. Lets the BE designer override any string the bundle
 * exposes — particularly important for languages with a formal /
 * informal distinction (DE Sie vs. Du, FR vous vs. tu, …) where the
 * upstream defaults necessarily pick one tone and the editor often
 * wants the other.
 *
 * One row per site, JSON blob `{ <lang>: { <dotted.key>: <value>, … }, … }`.
 * Same one-row-per-site shape as ThemeRepository so we keep the
 * persistence story simple — both editor surfaces follow the same
 * upsert/delete semantics.
 */
final readonly class TranslationOverrideRepository
{
    private const string TABLE = 'tx_t3simplecmp_translation_override';

    public function __construct(
        private ConnectionPool $connectionPool,
    ) {
    }

    /**
     * @return array<string, array<string, string>>|null
     *   language code → (dotted-key → value), or null when no row exists
     */
    public function findBySite(string $site): ?array
    {
        $row = $this->connectionPool->getConnectionForTable(self::TABLE)
            ->createQueryBuilder()
            ->select('overrides')
            ->from(self::TABLE)
            ->where('site = :site')
            ->setParameter('site', $site)
            ->executeQuery()
            ->fetchAssociative();
        if ($row === false || !is_string($row['overrides']) || $row['overrides'] === '') {
            return null;
        }
        try {
            $decoded = json_decode($row['overrides'], true, 8, JSON_THROW_ON_ERROR);
        } catch (\JsonException) {
            return null;
        }
        if (!is_array($decoded)) {
            return null;
        }
        // Normalise: drop anything that isn't a `<lang> => map<string, string>` pair.
        $out = [];
        foreach ($decoded as $lang => $entries) {
            if (!is_string($lang) || $lang === '' || !is_array($entries)) {
                continue;
            }
            $clean = [];
            foreach ($entries as $key => $value) {
                if (is_string($key) && is_string($value) && $value !== '') {
                    $clean[$key] = $value;
                }
            }
            if ($clean !== []) {
                $out[$lang] = $clean;
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * @param array<string, array<string, string>> $overrides
     *   language → (dotted-key → value)
     */
    public function upsert(string $site, array $overrides): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        // Empty payload → drop the row outright so the FE layer falls
        // back cleanly to upstream defaults.
        if ($overrides === []) {
            $this->delete($site);
            return;
        }

        $now = time();
        $payload = [
            'tstamp' => $now,
            'overrides' => json_encode($overrides, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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

    public function delete(string $site): void
    {
        $this->connectionPool->getConnectionForTable(self::TABLE)
            ->delete(self::TABLE, ['site' => $site]);
    }
}
