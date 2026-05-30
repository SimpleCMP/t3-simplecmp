<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Domain\Repository;

use TYPO3\CMS\Core\Database\ConnectionPool;

/**
 * Per-site bundle-translation overrides + per-language tone preset
 * selection, keyed by `(site, language)`.
 *
 * Storage shape per row (JSON blob):
 *   {
 *     "<lang>": {
 *       "tone": "formal" | "informal" | null,
 *       "overrides": { "<dotted.key>": "<value>", … }
 *     },
 *     …
 *   }
 *
 * Tone is a soft preset (a one-click "use the informal variant for
 * this language"); manual overrides win when both are set. See
 * `ThemeDesignerController::TONE_PRESETS` for the curated mapping.
 *
 * Backward-compat: the v1 shape stored `{<lang>: {<key>: <val>}}`
 * directly. Rows in that shape are migrated to the new shape on
 * read; the next save persists the new shape.
 *
 * One row per site, same one-row-per-site model as
 * `ThemeRepository` so both editor surfaces follow the same
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
     * @return array<string, array{tone: ?string, overrides: array<string, string>}>|null
     *   language code → {tone, overrides-map}, or null when no row
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
        $out = [];
        foreach ($decoded as $lang => $entries) {
            if (!is_string($lang) || $lang === '' || !is_array($entries)) {
                continue;
            }
            // Detect new shape: `entries` is `{tone, overrides}`. Else
            // treat the entries dict as the v1 flat-key shape.
            $hasNewShape = array_key_exists('overrides', $entries) || array_key_exists('tone', $entries);
            $tone = null;
            $rawOverrides = $entries;
            if ($hasNewShape) {
                $maybeTone = $entries['tone'] ?? null;
                if (is_string($maybeTone) && $maybeTone !== '') {
                    $tone = $maybeTone;
                }
                $rawOverrides = is_array($entries['overrides'] ?? null) ? $entries['overrides'] : [];
            }
            $clean = [];
            foreach ($rawOverrides as $key => $value) {
                if (is_string($key) && is_string($value) && $value !== '') {
                    $clean[$key] = $value;
                }
            }
            if ($tone !== null || $clean !== []) {
                $out[$lang] = ['tone' => $tone, 'overrides' => $clean];
            }
        }
        return $out === [] ? null : $out;
    }

    /**
     * @param array<string, array{tone?: ?string, overrides?: array<string, string>}> $data
     */
    public function upsert(string $site, array $data): void
    {
        $conn = $this->connectionPool->getConnectionForTable(self::TABLE);

        // Empty payload → drop the row outright so the FE layer falls
        // back cleanly to upstream defaults.
        if ($data === []) {
            $this->delete($site);
            return;
        }

        // Persist the new shape only — backward-compat is read-side.
        $jsonPayload = [];
        foreach ($data as $lang => $entry) {
            $tone = $entry['tone'] ?? null;
            $overrides = $entry['overrides'] ?? [];
            if (($tone === null || $tone === '') && $overrides === []) {
                continue;
            }
            $jsonPayload[$lang] = [
                'tone' => is_string($tone) && $tone !== '' ? $tone : null,
                'overrides' => is_array($overrides) ? $overrides : [],
            ];
        }
        if ($jsonPayload === []) {
            $this->delete($site);
            return;
        }

        $now = time();
        $payload = [
            'tstamp' => $now,
            'overrides' => json_encode($jsonPayload, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES),
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
