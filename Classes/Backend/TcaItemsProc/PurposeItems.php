<?php

declare(strict_types=1);

namespace SimpleCMP\T3SimpleCmp\Backend\TcaItemsProc;

use SimpleCMP\ServicesLibrary\ServicesLibrary;

/**
 * Populates the `purposes` selectCheckBox items by introspecting every
 * record in the bundled `simplecmp/services-library`. The set of valid
 * purposes is whatever the library currently uses — there is no
 * hardcoded enum here.
 *
 * Why library-driven instead of a static list? The services library
 * is the source of truth for the protocol shape (see
 * `Classes/Service/ServiceCurator.php` and `bin/simplecmp:import-known-trackers`).
 * If a future library entry introduces a new purpose (e.g. an embed
 * service tagged `"embeds"`), the BE form must accept it without a
 * coordinated TCA edit, otherwise the import command would write
 * unstorable values and admins couldn't curate them.
 *
 * The list is sorted alphabetically and labelled via locallang_db
 * (`purposes.<key>`); unlabelled keys (e.g. a brand-new purpose
 * shipped by the library before this extension catches up) fall back
 * to a title-cased rendering of the key itself.
 */
final class PurposeItems
{
    /**
     * @param array{items: list<array{label: string, value: string}>} $config
     */
    public function items(array &$config): void
    {
        $found = [];
        foreach (ServicesLibrary::services() as $service) {
            $purposes = $service['purposes'] ?? [];
            if (!is_array($purposes)) {
                continue;
            }
            foreach ($purposes as $purpose) {
                if (is_string($purpose) && $purpose !== '') {
                    $found[$purpose] = true;
                }
            }
        }

        $keys = array_keys($found);
        sort($keys);

        foreach ($keys as $key) {
            $config['items'][] = [
                'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.purposes.item.'
                    . $key,
                'value' => $key,
            ];
        }
    }
}
