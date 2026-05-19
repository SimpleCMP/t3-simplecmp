<?php

declare(strict_types=1);

/**
 * TCA for the SimpleCMP service registry. Shows in the standard List
 * module so admins can curate the registry directly. The JSON columns
 * stay JSON in the form — TYPO3 doesn't have a generic JSON editor and
 * a value-object UI here would be overkill for a v0; a textarea with a
 * help hint is enough for now.
 */

return [
    'ctrl' => [
        'title' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service',
        'label' => 'name',
        'label_alt' => 'service_id',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'iconfile' => 'EXT:simplecmp_typo3/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'service_id,name,vendor',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        // No `sortby` — that requires an integer column (TYPO3 uses arithmetic
        // for drag-and-drop ordering). `service_id` is varchar, so we rely on
        // `default_sortby` instead for display order.
        'default_sortby' => 'service_id ASC',
    ],
    'columns' => [
        'service_id' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.service_id',
            'description' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.service_id.description',
            'config' => [
                // TCA `slug` (v14-native) — designed exactly for stable, unique
                // kebab-case identifiers. Two advantages over `input` +
                // `eval: 'trim,unique'`:
                //   1. Collision: slugs reject the save with a clear inline
                //      error ("This value is already used") instead of
                //      silently auto-renaming `foo` → `foo0` and emitting
                //      the misleading yellow "could not be completed" notice.
                //   2. Character set: the field normalizes spaces / non-ASCII
                //      to `-` automatically, so admins can type a display
                //      name and get a valid slug.
                'type' => 'slug',
                'size' => 30,
                'required' => true,
                // Auto-fill the slug from `name` on initial create when left
                // blank, so the "In Dienst umwandeln" pre-fill path still
                // works even if the controller didn't supply a service_id.
                'generatorOptions' => [
                    'fields' => ['name'],
                    'fieldSeparator' => '-',
                    'replacements' => [
                        '/' => '-',
                    ],
                ],
                'fallbackCharacter' => '-',
                // Globally unique across the whole installation — there is
                // exactly one service registry (pid=0), so `unique` is the
                // right scope (not `uniqueInPid` or `uniqueInSite`).
                'eval' => 'unique',
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.name',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'required' => true,
                'eval' => 'trim',
            ],
        ],
        'vendor' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.vendor',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim'],
        ],
        'vendor_country' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.vendor_country',
            'config' => ['type' => 'input', 'size' => 4, 'eval' => 'trim,upper', 'max' => 8],
        ],
        'purposes' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.purposes',
            'description' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.purposes.description',
            'config' => [
                // Multi-select checkbox group; items list is built at
                // render time from whatever purposes the bundled
                // services-library currently uses. See
                // `Classes/Backend/TcaItemsProc/PurposeItems.php`.
                //
                // The DB column stays TEXT/JSON. A FormDataProvider
                // decodes JSON → CSV on form load and a DataHandler
                // hook re-encodes CSV → JSON on save; both are
                // registered in `ext_localconf.php`.
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'items' => [],
                'itemsProcFunc' => \WapplerSystems\SimpleCmpTypo3\Backend\TcaItemsProc\PurposeItems::class . '->items',
                'size' => 6,
                'enableMultiSelectFilterTextfield' => true,
                'default' => '[]',
                // No `required` — an empty purposes list is a valid
                // "needs review" state that curation tooling can
                // surface; the constraint would only force admins to
                // tick something arbitrary to silence the error.
            ],
        ],
        'privacy_policy_url' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.privacy_policy_url',
            'config' => ['type' => 'link', 'allowedTypes' => ['url']],
        ],
        'description' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.description',
            'config' => ['type' => 'text', 'rows' => 4, 'cols' => 40],
        ],
        'retention' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.retention',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'i18n' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.i18n',
            'config' => ['type' => 'text', 'rows' => 5, 'cols' => 40],
        ],
        'cookies' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.cookies',
            'description' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.cookies.description',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'origins' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.origins',
            'description' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.origins.description',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'extensions' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.extensions',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'fe_visible' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.fe_visible',
            'description' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.fe_visible.description',
            'config' => [
                'type' => 'check',
                'renderType' => 'checkboxToggle',
                // New records via the TCA form (Anpassen / Kuratieren on a
                // detection row) default to visible — the admin reviewing the
                // pre-filled form and clicking Save is the per-entry approval.
                // Bulk paths (`import-known-trackers`) bypass the TCA default
                // and set fe_visible = 0 explicitly via the repository.
                'default' => 1,
                'items' => [
                    ['label' => ''],
                ],
            ],
        ],
    ],
    'palettes' => [
        'protocol' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.palette.protocol',
            'showitem' => 'service_id, name, vendor, vendor_country',
        ],
        'classification' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.palette.classification',
            'showitem' => 'cookies, --linebreak--, origins',
        ],
        'metadata' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.palette.metadata',
            // `purposes` on its own row so the side-by-side dual-listbox
            // gets the full palette width; the other fields share the
            // row below it.
            'showitem' => 'purposes, --linebreak--, privacy_policy_url, retention, i18n, extensions',
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --palette--;;protocol,
                fe_visible,
                description,
                --palette--;;classification,
                --palette--;;metadata,
            ',
        ],
    ],
];
