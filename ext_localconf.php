<?php

declare(strict_types=1);

defined('TYPO3') or die();

// Rate-limit bucket cache for the bridge webhook receiver. One entry
// per (IP, hour); entries expire naturally on the next-hour rollover.
// Default backend is fine — a few hundred small entries per hour.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['caching']['cacheConfigurations']
    [\WapplerSystems\SimpleCmpTypo3\Service\BridgeRateLimiter::CACHE_IDENTIFIER] ??= [
        'frontend' => \TYPO3\CMS\Core\Cache\Frontend\VariableFrontend::class,
        'backend' => \TYPO3\CMS\Core\Cache\Backend\Typo3DatabaseBackend::class,
        'groups' => ['system'],
    ];

// Pivot `tx_simplecmptypo3_service.purposes` between JSON storage and
// CSV form value. See the two classes' docblocks for why.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['formDataGroup']
    ['tcaDatabaseRecord'][\WapplerSystems\SimpleCmpTypo3\Backend\FormDataProvider\DecodePurposesJson::class] = [
        'depends' => [\TYPO3\CMS\Backend\Form\FormDataProvider\DatabaseRowInitializeNew::class],
        'before' => [\TYPO3\CMS\Backend\Form\FormDataProvider\TcaSelectItems::class],
    ];

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['t3lib/class.t3lib_tcemain.php']['processDatamapClass']
    [\WapplerSystems\SimpleCmpTypo3\Hooks\DataHandler\EncodePurposesJson::class]
    = \WapplerSystems\SimpleCmpTypo3\Hooks\DataHandler\EncodePurposesJson::class;

// Inline "this service is no longer in the bundled library" callout
// at the top of the SimpleCMP-Dienst edit form. The custom TCA
// `type: user` field renders nothing for Eigene and Aus-Bibliothek
// rows, and a yellow alert with the adoption date for Verwaist rows
// — same warning the Dienste BE tab carries at list level, surfaced
// where the admin is actually editing the row so the orphan state
// can't be missed.
$GLOBALS['TYPO3_CONF_VARS']['SYS']['formEngine']['nodeRegistry']
    [\WapplerSystems\SimpleCmpTypo3\Backend\Form\Element\OrphanCalloutFieldElement::class] = [
        'nodeName' => 'simplecmpOrphanCallout',
        'priority' => 40,
        'class' => \WapplerSystems\SimpleCmpTypo3\Backend\Form\Element\OrphanCalloutFieldElement::class,
    ];
