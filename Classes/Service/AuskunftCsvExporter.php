<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Service;

/**
 * Phase-3 CSV-formatter for {@see AuskunftBundle}.
 *
 * Two-section layout — easiest format that a) survives Excel import,
 * b) preserves the snapshot ↔ decision relationship via `versionHash`:
 *
 *     # SimpleCMP Auskunft export
 *     # exportedAt: 2026-06-16T20:00:00+00:00
 *     # exportedBy: cli
 *     # filter: {"kind":"visitor","site":"default","visitorHash":"…"}
 *     #
 *     # SECTION: snapshots
 *     uid,crdate,crdate_iso,site,version_hash,trigger_event,creator_be_user
 *     17,1718000000,2026-06-10T08:00:00+00:00,default,abc…,service.save,1
 *     …
 *     #
 *     # SECTION: decisions
 *     uid,crdate,crdate_iso,site,version_hash,visitor_id_sha256,decision_hash,decision_type,decisions_json,ua_family,page_url_host
 *     42,1718000123,2026-06-10T08:02:03+00:00,default,abc…,def…,ghi…,accept,"{""matomo"":true,""youtube"":false}",chrome,example.com
 *     …
 *
 * Excel "Get External Data → From Text" with `,` delimiter and the
 * UTF-8 BOM gives correct umlauts. The canonical snapshot JSON is
 * intentionally NOT inlined here — it can be huge — refer the reader
 * to the JSON export for the full canonical content.
 */
final readonly class AuskunftCsvExporter
{
    private const string UTF8_BOM = "\xEF\xBB\xBF";

    public function encode(AuskunftBundle $bundle, string $exportedBy, ?int $exportedAt = null): string
    {
        $ts = $exportedAt ?? time();
        $lines = [];
        $lines[] = '# SimpleCMP Auskunft export';
        $lines[] = '# exportedAt: ' . $this->isoUtc($ts);
        $lines[] = '# exportedBy: ' . $exportedBy;
        $lines[] = '# filter: ' . json_encode($bundle->filter, JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        $lines[] = '#';

        $lines[] = '# SECTION: snapshots';
        $lines[] = $this->csvRow(['uid', 'crdate', 'crdate_iso', 'site', 'version_hash', 'trigger_event', 'creator_be_user']);
        foreach ($bundle->snapshots as $row) {
            $crdate = isset($row['crdate']) ? (int) $row['crdate'] : 0;
            $lines[] = $this->csvRow([
                isset($row['uid']) ? (string) $row['uid'] : '',
                (string) $crdate,
                $crdate > 0 ? $this->isoUtc($crdate) : '',
                isset($row['site']) ? (string) $row['site'] : '',
                isset($row['version_hash']) ? (string) $row['version_hash'] : '',
                isset($row['trigger_event']) ? (string) $row['trigger_event'] : '',
                isset($row['creator_be_user']) ? (string) $row['creator_be_user'] : '',
            ]);
        }

        $lines[] = '#';
        $lines[] = '# SECTION: decisions';
        $lines[] = $this->csvRow([
            'uid', 'crdate', 'crdate_iso', 'site', 'version_hash',
            'visitor_id_sha256', 'decision_hash', 'decision_type',
            'decisions_json', 'ua_family', 'page_url_host',
        ]);
        foreach ($bundle->decisions as $row) {
            $crdate = isset($row['crdate']) ? (int) $row['crdate'] : 0;
            $lines[] = $this->csvRow([
                isset($row['uid']) ? (string) $row['uid'] : '',
                (string) $crdate,
                $crdate > 0 ? $this->isoUtc($crdate) : '',
                isset($row['site']) ? (string) $row['site'] : '',
                isset($row['version_hash']) ? (string) $row['version_hash'] : '',
                isset($row['visitor_id_sha256']) ? (string) $row['visitor_id_sha256'] : '',
                isset($row['decision_hash']) ? (string) $row['decision_hash'] : '',
                isset($row['decision_type']) ? (string) $row['decision_type'] : '',
                isset($row['decisions_json']) ? (string) $row['decisions_json'] : '',
                isset($row['ua_family']) ? (string) $row['ua_family'] : '',
                isset($row['page_url_host']) ? (string) $row['page_url_host'] : '',
            ]);
        }

        return self::UTF8_BOM . implode("\n", $lines) . "\n";
    }

    /**
     * RFC-4180 single-row encode. Fields are double-quoted when they
     * contain a comma, newline, or embedded quote; embedded quotes
     * double-up. Always quoting unconditionally avoids edge-cases
     * and Excel still parses it fine — but unquoted is more compact
     * for the typical hash-and-int values, so we quote only when
     * needed.
     *
     * @param list<string> $fields
     */
    private function csvRow(array $fields): string
    {
        $encoded = array_map(static function (string $field): string {
            if (preg_match('/[",\r\n]/', $field) === 1) {
                return '"' . str_replace('"', '""', $field) . '"';
            }
            return $field;
        }, $fields);
        return implode(',', $encoded);
    }

    private function isoUtc(int $epoch): string
    {
        return (new \DateTimeImmutable('@' . $epoch))->setTimezone(new \DateTimeZone('UTC'))->format(\DateTimeInterface::ATOM);
    }
}
