<?php

declare(strict_types=1);

/**
 * Append-only audit snapshots of the resolved banner configuration
 * (Phase 1 of the audit / consent-log roadmap).
 *
 * Rows are inserted by {@see \SimpleCMP\T3SimpleCmp\Service\ConfigSnapshotListener}
 * whenever the editor saves a change to the service registry, theme
 * tokens, or translation overrides — and on-demand via the
 * `simplecmp:snapshot-config` CLI command for YAML-only edits.
 *
 * Editor-level append-only is enforced by:
 *   - `readOnly: true` here (no BE form, no edit links)
 *   - `hideTable: true` (no entry in the "List" module)
 *   - {@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConfigSnapshotAppendOnly}
 *     DataHandler hook that refuses update/delete commands
 *
 * Direct SQL access can still mutate the table; that is intentional —
 * production retention is a Phase-3 CLI workflow, not a hidden trigger.
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_config_snapshot',
        'label' => 'version_hash',
        'label_alt' => 'site',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'iconfile' => 'EXT:simplecmp/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'site,version_hash,trigger_event',
        'rootLevel' => -1,
        'default_sortby' => 'crdate DESC',
        // Append-only — no BE edit surface. Snapshots are written by
        // listeners + the CLI command exclusively.
        'readOnly' => true,
        // Keep the table out of the "List" module's table picker so
        // editors don't stumble onto raw rows; the audit-tab in the
        // BE module is the official surface.
        'hideTable' => true,
    ],
    'columns' => [
        'site' => [
            'label' => 'Site',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'version_hash' => [
            'label' => 'Version hash (sha256)',
            'config' => ['type' => 'input', 'size' => 70, 'readOnly' => true],
        ],
        'canonical_json' => [
            'label' => 'Canonical snapshot',
            'config' => ['type' => 'text', 'rows' => 30, 'readOnly' => true],
        ],
        'trigger_event' => [
            'label' => 'Trigger event',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'creator_be_user' => [
            'label' => 'BE user',
            'config' => ['type' => 'group', 'allowed' => 'be_users', 'size' => 1, 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'site, version_hash, trigger_event, creator_be_user, canonical_json',
        ],
    ],
];
