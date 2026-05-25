<?php

declare(strict_types=1);

/**
 * TCA for incoming CMS-bridge webhook detections. Read-only — the
 * dedicated SimpleCMP backend module is the canonical UI for triage;
 * see `Classes/Controller/Backend/DetectionReviewController.php`. There
 * are no admin-editable fields on the row because the new model derives
 * resolution state from registry coverage rather than a flag.
 */

return [
    'ctrl' => [
        'title' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection',
        'label' => 'identifier',
        'label_alt' => 'kind',
        'label_alt_force' => true,
        'tstamp' => 'tstamp',
        'crdate' => 'crdate',
        'iconfile' => 'EXT:t3_simplecmp/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'identifier,origin,page_url,source',
        'rootLevel' => -1,
        'security' => [
            'ignorePageTypeRestriction' => true,
        ],
        'default_sortby' => 'received_at DESC',
        'hideTable' => false,
        'readOnly' => false,
    ],
    'columns' => [
        'source' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.source',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 30],
        ],
        'kind' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.kind',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 16],
        ],
        'identifier' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.identifier',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 60],
        ],
        'origin' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.origin',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 40],
        ],
        'page_url' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.page_url',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 60],
        ],
        'first_seen_on' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.first_seen_on',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 60],
        ],
        'sent_at' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.sent_at',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 30],
        ],
        'received_at' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.received_at',
            'config' => ['type' => 'datetime', 'readOnly' => true, 'format' => 'datetime'],
        ],
        'occurrences' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.occurrences',
            'config' => ['type' => 'number', 'readOnly' => true, 'size' => 8],
        ],
        'library_version' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.library_version',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 20],
        ],
        'user_agent' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.user_agent',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 60],
        ],
        'referrer' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.referrer',
            'config' => ['type' => 'input', 'readOnly' => true, 'size' => 60],
        ],
        'payload' => [
            'label' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_detection.payload',
            'config' => ['type' => 'text', 'readOnly' => true, 'rows' => 12, 'cols' => 80],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => '
                --div--;Identity,
                source, kind, identifier, origin,
                --div--;Page context,
                page_url, first_seen_on, referrer, user_agent, library_version,
                --div--;Timing,
                received_at, occurrences, sent_at,
                --div--;Raw payload,
                payload,
            ',
        ],
    ],
];
