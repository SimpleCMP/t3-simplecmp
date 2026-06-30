<?php

declare(strict_types=1);

/**
 * Append-only visitor consent decisions (Phase 2 of the audit trail).
 *
 * Rows are inserted by the `POST /api/simplecmp/v1/consent-log`
 * endpoint when the visitor confirms (or revokes) consent. The
 * pseudonymized visitor id + snapshot `version_hash` + canonical
 * decision hash forms a UNIQUE 4-tuple that dedups no-op confirms;
 * genuinely-different decisions get fresh rows.
 *
 * Editor-level append-only enforced by `readOnly: true` + `hideTable: true`
 * plus {@see \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConsentLogAppendOnly}.
 * Direct SQL access can mutate the table; that is intentional —
 * production retention is a Phase-3 CLI workflow with its own audit
 * trail over the retention decision.
 */
return [
    'ctrl' => [
        'title' => 'LLL:EXT:simplecmp/Resources/Private/Language/locallang_db.xlf:tx_t3simplecmp_consent_log',
        'label' => 'visitor_id_sha256',
        'label_alt' => 'version_hash',
        'label_alt_force' => true,
        'crdate' => 'crdate',
        'iconfile' => 'EXT:simplecmp/Resources/Public/Icons/simplecmp.svg',
        'searchFields' => 'site,version_hash,visitor_id_sha256,decision_type',
        'rootLevel' => -1,
        'default_sortby' => 'crdate DESC',
        // Append-only — no BE edit surface.
        'readOnly' => true,
        // Out of the "List" module's table picker; the audit-tab is the
        // official viewing surface (Phase 2 extends the snapshot show
        // action with a "decisions for this version" section).
        'hideTable' => true,
    ],
    'columns' => [
        'site' => [
            'label' => 'Site',
            'config' => ['type' => 'input', 'size' => 30, 'readOnly' => true],
        ],
        'version_hash' => [
            'label' => 'Snapshot version (sha256)',
            'config' => ['type' => 'input', 'size' => 70, 'readOnly' => true],
        ],
        'visitor_id_sha256' => [
            'label' => 'Visitor id (sha256)',
            'config' => ['type' => 'input', 'size' => 70, 'readOnly' => true],
        ],
        'decision_hash' => [
            'label' => 'Decision hash (sha256)',
            'config' => ['type' => 'input', 'size' => 70, 'readOnly' => true],
        ],
        'decisions_json' => [
            'label' => 'Decisions (canonical JSON)',
            'config' => ['type' => 'text', 'rows' => 10, 'readOnly' => true],
        ],
        'decision_type' => [
            'label' => 'Decision type',
            'config' => ['type' => 'input', 'size' => 20, 'readOnly' => true],
        ],
        'ua_family' => [
            'label' => 'UA family (coarse)',
            'config' => ['type' => 'input', 'size' => 20, 'readOnly' => true],
        ],
        'page_url_host' => [
            'label' => 'Page host',
            'config' => ['type' => 'input', 'size' => 50, 'readOnly' => true],
        ],
    ],
    'types' => [
        '0' => [
            'showitem' => 'site, version_hash, visitor_id_sha256, decision_type, decision_hash, decisions_json, ua_family, page_url_host',
        ],
    ],
];
