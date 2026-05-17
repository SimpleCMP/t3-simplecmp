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
