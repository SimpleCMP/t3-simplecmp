<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Rate-limit bucket cache for the bridge webhook receiver. One entry
// per (IP, hour); entries expire naturally on the next-hour rollover.
// Default backend is fine — a few hundred small entries per hour.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']
    [\SimpleCMP\T3SimpleCmp\Service\BridgeRateLimiter::CACHE_IDENTIFIER] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups' => ['system'],
    ];

// Upstream library /v1/health snapshot cache for the Bibliothek tab's
// freshness panel. One entry per configured upstream URL; 30-minute
// TTL so the panel stays cheap to open repeatedly. The "Jetzt prüfen"
// button on the panel flushes the cache for on-demand refresh.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']
    [\SimpleCMP\T3SimpleCmp\Service\LibraryUpstreamHealth::CACHE_IDENTIFIER] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups' => ['system'],
    ];

// Pivot `tx_t3simplecmp_service.purposes` between JSON storage and
// CSV form value. See the two classes' docblocks for why.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']
    ['tcaDatabaseRecord'][\SimpleCMP\T3SimpleCmp\Backend\FormDataProvider\DecodePurposesJson::class] = [
        'depends' => [\TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowInitializeNew::class],
        'before' => [\TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectItems::class],
    ];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']
    [\SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EncodePurposesJson::class]
    = \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EncodePurposesJson::class;

// Append-only audit snapshots of the banner config (Phase 1). Fires
// once per DataHandler invocation, after all individual rows have
// been processed — collects affected sites and stores a snapshot per
// site. Identical-content saves dedupe via a sha256 hash, so editor
// re-saves without changes don't create churn.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']
    [\SimpleCMP\T3SimpleCmp\Hooks\DataHandler\SnapshotConfigOnSave::class]
    = \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\SnapshotConfigOnSave::class;

// Editor-level append-only enforcement for the snapshot table. TCA
// `readOnly`/`hideTable` is the first defence; this hook refuses any
// `update` datamap call or `delete`/`move`/`copy`/`undelete` cmdmap
// entries that somehow target the table. Direct SQL is still
// possible — production retention is a Phase-3 CLI workflow.
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']
    [\SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConfigSnapshotAppendOnly::class]
    = \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConfigSnapshotAppendOnly::class;
$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processCmdmapClass']
    [\SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConfigSnapshotAppendOnly::class]
    = \SimpleCMP\T3SimpleCmp\Hooks\DataHandler\EnforceConfigSnapshotAppendOnly::class;

// Inline "this service is no longer in the bundled library" callout
// at the top of the SimpleCMP-Dienst edit form. The custom TCA
// `type: user` field renders nothing for Eigene and Aus-Bibliothek
// rows, and a yellow alert with the adoption date for Verwaist rows
// — same warning the Dienste BE tab carries at list level, surfaced
// where the admin is actually editing the row so the orphan state
// can't be missed.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry']
    [\SimpleCMP\T3SimpleCmp\Backend\Form\Element\OrphanCalloutFieldElement::class] = [
        'nodeName' => 'simplecmpOrphanCallout',
        'priority' => 40,
        'class' => \SimpleCMP\T3SimpleCmp\Backend\Form\Element\OrphanCalloutFieldElement::class,
    ];
