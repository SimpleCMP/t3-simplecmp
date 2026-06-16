<?php

declare(strict_types=1);

/**
 * Append-only self-audit log of retention actions (Phase 3).
 *
 * Editor-level append-only enforced by `readOnly: true` + `hideTable: true`
 * plus {@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceAuditRetentionLogAppendOnly}.
 * Only `AuditRetentionService` writes here, via the
 * `simplecmp:audit-retention` CLI; no BE editor surface exists for
 * creating or modifying rows. Direct SQL access can still mutate the
 * table; that is intentional — same trade-off as Phase 1+2 — and is
 * surfaced via the Auskunfts-tab so a silent wipe stands out.
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_audit_retention_log',
        'label' => 'target_table',
        'label_alt' => 'crdate',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'iconfile' => 'EXT:t3_simplecmp/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'target_table,target_site,invoked_by,invocation_reason',
        'rootLevel' => -1,
        'default_sortby' => 'crdate DESC',
        'readOnly' => true,
        'hideTable' => true,
    ],
    'columns' => [
        'target_table' => [
            'label' => 'Target table',
            'config' => ['type' => 'input', 'size' => 50, 'readOnly' => true],
        ],
        'target_site' => [
            'label' => 'Target site',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'rows_deleted' => [
            'label' => 'Rows deleted',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'keep_days' => [
            'label' => 'Keep-days threshold',
            'config' => ['type' => 'number', 'readOnly' => true],
        ],
        'oldest_kept_crdate' => [
            'label' => 'Oldest kept crdate',
            'config' => ['type' => 'datetime', 'readOnly' => true],
        ],
        'invoked_by' => [
            'label' => 'Invoked by',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'invocation_reason' => [
            'label' => 'Operator reason',
            'config' => ['type' => 'text', 'rows' => 4, 'readOnly' => true],
        ],
        'dry_run' => [
            'label' => 'Dry run',
            'config' => ['type' => 'check', 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'target_table, target_site, rows_deleted, keep_days, oldest_kept_crdate, invoked_by, invocation_reason, dry_run',
        ],
    ],
];
