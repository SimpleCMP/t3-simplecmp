<?php

declare(strict_types=1);

namespace WapplerSystems\SimpleCmpTypo3\Hooks\DataHandler;

use TYPO3\CMS\Core\DataHandling\DataHandler;

/**
 * Inverse of
 * {@see \WapplerSystems\SimpleCmpTypo3\Backend\FormDataProvider\DecodePurposesJson}:
 * intercepts the BE edit-form save for `tx_simplecmptypo3_service` and
 * converts the comma-separated `purposes` value (`"analytics,marketing"`)
 * back into the JSON shape (`["analytics","marketing"]`) that the rest
 * of the protocol expects.
 *
 * `processDatamap_postProcessFieldArray` fires for every record write
 * but for every table — this hook short-circuits on anything that
 * isn't the service table to avoid muddying unrelated writes.
 *
 * NEW records arrive without a `uid`; the hook still runs because
 * `$fieldArray` is the values about to be inserted. Both code paths
 * (insert and update) flow through here identically.
 */
final class EncodePurposesJson
{
    /**
     * @param array<string, mixed> $fieldArray
     */
    public function processDatamap_postProcessFieldArray(
        string $status,
        string $table,
        mixed $id,
        array &$fieldArray,
        DataHandler $dataHandler,
    ): void {
        unset($status, $id, $dataHandler);
        if ($table !== 'tx_simplecmptypo3_service') {
            return;
        }
        if (!array_key_exists('purposes', $fieldArray)) {
            return;
        }

        $value = $fieldArray['purposes'];

        // If somehow already JSON (e.g. partial datamap from the
        // importer command going through DataHandler), leave it alone.
        if (is_string($value) && str_starts_with(ltrim($value), '[')) {
            return;
        }

        $items = [];
        if (is_string($value) && $value !== '') {
            foreach (explode(',', $value) as $item) {
                $item = trim($item);
                if ($item !== '') {
                    $items[] = $item;
                }
            }
        } elseif (is_array($value)) {
            foreach ($value as $item) {
                if (is_string($item) && $item !== '') {
                    $items[] = $item;
                }
            }
        }

        $fieldArray['purposes'] = json_encode(
            array_values(array_unique($items)),
            JSON_UNESCAPED_SLASHES,
        );
    }
}
