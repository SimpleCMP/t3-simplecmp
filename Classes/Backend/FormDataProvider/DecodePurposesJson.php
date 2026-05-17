<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Backend\FormDataProvider;

use TYPO3\CMS\Backend\Form\FormDataProviderInterface;

/**
 * Translates the JSON-encoded `purposes` column of
 * `tx_simplecmptypo3_service` into the comma-separated string that
 * TYPO3's `type: select` / `renderType: selectCheckBox` expects.
 *
 * The DB column is intentionally a TEXT column holding JSON
 * (`["analytics","marketing"]`) because:
 *   - the Service-DB protocol JSON-encodes the value at the wire layer
 *     (importer + bridge already produce JSON);
 *   - keeping the storage shape lets us round-trip records through the
 *     `simplecmp:import-known-trackers` command without a translation
 *     layer in the importer.
 *
 * The CSV pivot lives entirely in the BE form pipeline — DB and JS
 * consumers see JSON throughout. The companion writer is
 * {@see \WapplerSystems\SimpleCmpTypo3\Hooks\DataHandler\EncodePurposesJson}.
 *
 * Registered in `ext_localconf.php` with `depends` on
 * `DatabaseRowInitializeNew` (so the row exists) and `before` on
 * `TcaSelectItems` (which is the consumer that reads the CSV string).
 */
final class DecodePurposesJson implements FormDataProviderInterface
{
    /**
     * @param array<string, mixed> $result
     * @return array<string, mixed>
     */
    public function addData(array $result): array
    {
        if (($result['tableName'] ?? '') !== 'tx_simplecmptypo3_service') {
            return $result;
        }
        if (!array_key_exists('purposes', $result['databaseRow'] ?? [])) {
            return $result;
        }
        $result['databaseRow']['purposes'] = self::jsonToCsv($result['databaseRow']['purposes']);
        return $result;
    }

    /**
     * `defVals` pre-fill (e.g. from `ServiceCurator::buildDefaults()`)
     * also lands in `databaseRow['purposes']` as a JSON string, so the
     * conversion is symmetric for both fresh and edited rows.
     *
     * Defensive against historical data: empty string, malformed JSON,
     * or a non-array decode all map to an empty CSV. Non-string list
     * entries are silently dropped — the items list is purpose strings
     * by contract.
     */
    private static function jsonToCsv(mixed $value): string
    {
        if (is_string($value) && $value !== '') {
            $decoded = json_decode($value, true);
            if (is_array($decoded)) {
                $value = $decoded;
            }
        }
        if (!is_array($value)) {
            return '';
        }
        $clean = [];
        foreach ($value as $item) {
            if (is_string($item) && $item !== '') {
                $clean[] = $item;
            }
        }
        return implode(',', $clean);
    }
}
