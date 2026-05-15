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
