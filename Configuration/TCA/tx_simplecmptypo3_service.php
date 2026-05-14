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
        'sortby' => 'service_id',
        'default_sortby' => 'service_id ASC',
    ],
    'columns' => [
        'service_id' => [
            'label' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.service_id',
            'description' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_db.xlf:tx_simplecmptypo3_service.service_id.description',
            'config' => [
                'type' => 'input',
                'size' => 30,
                'required' => true,
                'eval' => 'trim,unique',
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
                'type' => 'text',
                'rows' => 2,
                'cols' => 30,
                'default' => '[]',
                'required' => true,
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
            'showitem' => 'purposes, privacy_policy_url, retention, i18n, extensions',
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --palette--;;protocol,
                description,
                --palette--;;classification,
                --palette--;;metadata,
            ',
        ],
    ],
];
