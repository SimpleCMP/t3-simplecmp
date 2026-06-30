<?php

declare(strict_types=1);

/**
 * Draft/Publish workspace lock (Phase 4).
 *
 * Only ever mutated by
 * {@see \SimpleCMP\T3SimpleCmp\Service\DraftWorkspaceService} (lock
 * acquire/release/takeover) and
 * {@see \SimpleCMP\T3SimpleCmp\Service\DraftPublishService} (lock
 * release on publish). Editor-level mutations are blocked via
 * `readOnly: true` + `hideTable: true` + the
 * {@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceLiveBannerConfigReadOnly}
 * DataHandler hook. Direct SQL access can still mutate this table —
 * the BE Auskunfts-tab surfaces the active lock so silent tampering
 * is conspicuous.
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_publish_lock',
        'label' => 'scope',
        'label_alt' => 'owner_be_user',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'iconfile' => 'EXT:simplecmp/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'scope,owner_be_user',
        'rootLevel' => -1,
        'default_sortby' => 'last_activity_at DESC',
        'readOnly' => true,
        'hideTable' => true,
    ],
    'columns' => [
        'scope' => [
            'label' => 'Scope (site identifier or __global__)',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'owner_be_user' => [
            'label' => 'Owner BE-User uid',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'acquired_at' => [
            'label' => 'Acquired at',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'last_activity_at' => [
            'label' => 'Last activity at',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'scope, owner_be_user, acquired_at, last_activity_at',
        ],
    ],
];