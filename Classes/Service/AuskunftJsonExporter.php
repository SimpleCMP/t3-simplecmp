<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Phase-3 JSON-formatter for {@see AuskunftBundle}.
 *
 * Output shape (forward-compatible — additive fields allowed in
 * future schema-versions; the `schemaVersion` field guards consumers):
 *
 *     {
 *       "schemaVersion": 1,
 *       "exportedAt": "2026-06-16T20:00:00+00:00",
 *       "exportedBy": "cli" | "be:<userId>",
 *       "filter": { … bundle's filter verbatim … },
 *       "snapshots": [
 *         {
 *           "uid": 17,
 *           "crdate": 1718000000,
 *           "site": "default",
 *           "versionHash": "abc…",
 *           "triggerEvent": "service.save",
 *           "creatorBeUser": 1,
 *           "canonical": { … parsed canonical_json … }
 *         },
 *         …
 *       ],
 *       "decisions": [
 *         {
 *           "uid": 42,
 *           "crdate": 1718000123,
 *           "site": "default",
 *           "versionHash": "abc…",
 *           "visitorIdSha256": "def…",
 *           "decisionHash": "ghi…",
 *           "decisionType": "accept",
 *           "decisions": { "matomo": true, … },
 *           "uaFamily": "chrome",
 *           "pageHost": "example.com"
 *         },
 *         …
 *       ]
 *     }
 *
 * `crdate` stays an epoch integer (machine consumable); the human ISO
 * timestamp is on `exportedAt` only. JSON is `JSON_PRETTY_PRINT` for
 * legal-review friendliness, `JSON_UNESCAPED_SLASHES` + `JSON_UNESCAPED_UNICODE`
 * so paths and German umlauts render literally.
 */
final readonly class AuskunftJsonExporter
{
    public const int SCHEMA_VERSION = 1;

    public function encode(AuskunftBundle $bundle, string $exportedBy, ?int $exportedAt = null): string
    {
        $ts = $exportedAt ?? time();
        $payload = [
            'schemaVersion' => self::SCHEMA_VERSION,
            'exportedAt' => $this->isoUtc($ts),
            'exportedBy' => $exportedBy,
            'filter' => $bundle->filter,
            'snapshots' => array_map($this->mapSnapshot(...), $bundle->snapshots),
            'decisions' => array_map($this->mapDecision(...), $bundle->decisions),
        ];
        return json_encode(
            $payload,
            JSON_PRETTY_PRINT | JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE | JSON_THROW_ON_ERROR,
        );
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapSnapshot(array $row): array
    {
        $canonical = null;
        if (isset($row['canonical_json']) && is_string($row['canonical_json']) && $row['canonical_json'] !== '') {
            // The canonical column is already JSON — decode so the export
            // is a single coherent document instead of a stringified inner
            // payload. On parse failure leave it as a raw string field so
            // forensic value isn't lost.
            try {
                $canonical = json_decode($row['canonical_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $canonical = $row['canonical_json'];
            }
        }
        return [
            'uid' => isset($row['uid']) ? (int) $row['uid'] : null,
            'crdate' => isset($row['crdate']) ? (int) $row['crdate'] : null,
            'site' => isset($row['site']) ? (string) $row['site'] : null,
            'versionHash' => isset($row['version_hash']) ? (string) $row['version_hash'] : null,
            'triggerEvent' => isset($row['trigger_event']) ? (string) $row['trigger_event'] : null,
            'creatorBeUser' => isset($row['creator_be_user']) ? (int) $row['creator_be_user'] : null,
            'canonical' => $canonical,
        ];
    }

    /**
     * @param array<string, mixed> $row
     * @return array<string, mixed>
     */
    private function mapDecision(array $row): array
    {
        $decisions = null;
        if (isset($row['decisions_json']) && is_string($row['decisions_json']) && $row['decisions_json'] !== '') {
            try {
                $decisions = json_decode($row['decisions_json'], true, 512, JSON_THROW_ON_ERROR);
            } catch (\JsonException) {
                $decisions = $row['decisions_json'];
            }
        }
        return [
            'uid' => isset($row['uid']) ? (int) $row['uid'] : null,
            'crdate' => isset($row['crdate']) ? (int) $row['crdate'] : null,
            'site' => isset($row['site']) ? (string) $row['site'] : null,
            'versionHash' => isset($row['version_hash']) ? (string) $row['version_hash'] : null,
            'visitorIdSha256' => isset($row['visitor_id_sha256']) ? (string) $row['visitor_id_sha256'] : null,
            'decisionHash' => isset($row['decision_hash']) ? (string) $row['decision_hash'] : null,
            'decisionType' => isset($row['decision_type']) ? (string) $row['decision_type'] : null,
            'decisions' => $decisions,
            'uaFamily' => isset($row['ua_family']) ? (string) $row['ua_family'] : null,
            'pageHost' => isset($row['page_url_host']) ? (string) $row['page_url_host'] : null,
        ];
    }

    private function isoUtc(int $epoch): string
    {
        return (new \DateTimeImmutable('@' . $epoch))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
