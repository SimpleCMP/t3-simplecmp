<?php

declare(strict_types=1);

/**
 * Phase-4 draft mirror of {@see tx_t3simplecmp_service}.
 *
 * Same form as the live table (mirrored field-by-field, palette layout
 * unchanged) so editors get the identical editing experience. Three
 * extras:
 *
 *   - `draft_site` / `draft_owner_be_user` / `draft_modified_at` are
 *     hidden-from-UI workspace bookkeeping columns set by
 *     {@see \SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService}.
 *   - The `hideTable` flag is FALSE here (so the table is visible to
 *     Extbase + DataHandler save), but the table is hidden from the
 *     standard List module via not registering it there — Phase-4
 *     editors land on draft records via the SimpleCMP module's "edit"
 *     links, never via the generic record picker.
 *   - The `service_id` slug uses `uniqueInPid` scope rather than
 *     `unique` — the global UNIQUE constraint on the table is
 *     `(draft_site, service_id)`, so two drafts on different scopes
 *     could legally share the same `service_id`. In practice services
 *     only have draft_site='' so the effect matches live, but the
 *     looser TCA eval avoids false-positive UI rejections.
 */

return [
    'ctrl' => [
        'title' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service_draft',
        'label' => 'name',
        'label_alt' => 'service_id',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'iconfile' => 'EXT:simplecmp/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'service_id,name,vendor',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'default_sortby' => 'service_id ASC',
        // Not in List-module table picker; access only via SimpleCMP
        // module's edit links.
        'hideTable' => true,
    ],
    'columns' => [
        'orphan_callout' => [
            'config' => [
                'type' => 'user',
                'renderType' => 'simplecmpOrphanCallout',
            ],
        ],
        'service_id' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.service_id',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.service_id.description',
            'config' => [
                'type' => 'slug',
                'size' => 30,
                'required' => true,
                'generatorOptions' => [
                    'fields' => ['name'],
                    'fieldSeparator' => '-',
                    'replacements' => [
                        '/' => '-',
                    ],
                ],
                'fallbackCharacter' => '-',
                // No `eval: unique` here — DB UNIQUE on
                // (draft_site, service_id) is the authoritative check.
                // The TCA `unique` would falsely reject when a draft
                // mirrors a live row with the same service_id (which
                // is the normal state during copy-on-write).
                'eval' => 'trim',
            ],
        ],
        'name' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.name',
            'config' => ['type' => 'input', 'size' => 30, 'required' => true, 'eval' => 'trim'],
        ],
        'vendor' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor',
            'config' => ['type' => 'input', 'size' => 30, 'eval' => 'trim'],
        ],
        'vendor_country' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_country',
            'config' => ['type' => 'input', 'size' => 4, 'eval' => 'trim,upper', 'max' => 8],
        ],
        'vendor_address' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_address',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_address.description',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'vendor_opt_out_url' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_opt_out_url',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_opt_out_url.description',
            'config' => ['type' => 'link', 'allowedTypes' => ['url']],
        ],
        'vendor_partner' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_partner',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_partner.description',
            'config' => ['type' => 'text', 'rows' => 4, 'cols' => 40],
        ],
        'vendor_description' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_description',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.vendor_description.description',
            'config' => ['type' => 'text', 'rows' => 4, 'cols' => 40],
        ],
        'purposes' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.purposes',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.purposes.description',
            'config' => [
                'type' => 'select',
                'renderType' => 'selectMultipleSideBySide',
                'items' => [],
                'itemsProcFunc' => \SimpleCMP\T3SimpleCmp\Backend\TcaItemsProc\PurposeItems::class . '->items',
                'size' => 6,
                'enableMultiSelectFilterTextfield' => true,
                'default' => '[]',
                'minitems' => 1,
            ],
        ],
        'privacy_policy_url' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.privacy_policy_url',
            'config' => ['type' => 'link', 'allowedTypes' => ['url']],
        ],
        'description' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.description',
            'config' => ['type' => 'text', 'rows' => 4, 'cols' => 40, 'required' => true],
        ],
        'retention' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.retention',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'i18n' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.i18n',
            'config' => ['type' => 'text', 'rows' => 5, 'cols' => 40],
        ],
        'cookies' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.cookies',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.cookies.description',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'origins' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.origins',
            'description' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.origins.description',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        'extensions' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.extensions',
            'config' => ['type' => 'text', 'rows' => 3, 'cols' => 40],
        ],
        // Draft-workspace bookkeeping — hidden from the form.
        'draft_site' => [
            'label' => 'Draft scope',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'draft_owner_be_user' => [
            'label' => 'Draft owner (BE user uid)',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'draft_modified_at' => [
            'label' => 'Draft last modified',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
    ],
    'palettes' => [
        'protocol' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.palette.protocol',
            'showitem' => 'service_id, name, vendor, vendor_country, --linebreak--, vendor_address, --linebreak--, vendor_description, --linebreak--, vendor_partner, --linebreak--, vendor_opt_out_url',
        ],
        'classification' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.palette.classification',
            'showitem' => 'cookies, --linebreak--, origins',
        ],
        'metadata' => [
            'label' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_service.palette.metadata',
            'showitem' => 'purposes, --linebreak--, privacy_policy_url, retention, i18n, extensions',
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                orphan_callout,
                --palette--;;protocol,
                description,
                --palette--;;classification,
                --palette--;;metadata,
            ',
        ],
    ],
];