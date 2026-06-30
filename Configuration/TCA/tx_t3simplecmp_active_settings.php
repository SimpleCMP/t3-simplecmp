<?php

declare(strict_types=1);

/**
 * Phase-5 editor-confirmed active settings — one row per site,
 * holding a JSON blob of the banner-content keys the editor has
 * adopted from YAML or overridden with a custom value.
 *
 * Editor surface lives in `SettingsController` + Templates/Settings/Index.html.
 * Direct edits via the BE record editor are blocked via `readOnly` +
 * `hideTable` + the `EnforceLiveBannerConfigReadOnly` hook (the hook's
 * TABLES list is extended in Phase 5 to include this table).
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_active_settings',
        'label' => 'site',
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'iconfile' => 'EXT:simplecmp/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'site',
        'rootLevel' => -1,
        'default_sortby' => 'site ASC',
        'readOnly' => true,
        'hideTable' => true,
    ],
    'columns' => [
        'site' => [
            'label' => 'Site identifier',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'active_json' => [
            'label' => 'Active settings (JSON)',
            'config' => ['type' => 'text', 'rows' => 10, 'readOnly' => true],
        ],
        'last_modified_be_user' => [
            'label' => 'Last modified by',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'site, active_json, last_modified_be_user',
        ],
    ],
];
