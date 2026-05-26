<?php

declare(strict_types=1);

use SimpleCMP\T3SimpleCmp\Controller\Backend\DetectionReviewController;
use SimpleCMP\T3SimpleCmp\Controller\Backend\DiscoveryController;
use SimpleCMP\T3SimpleCmp\Controller\Backend\LibraryBrowserController;
use SimpleCMP\T3SimpleCmp\Controller\Backend\RegistryListController;
use SimpleCMP\T3SimpleCmp\Controller\Backend\ThemeDesignerController;

/**
 * Backend module registration.
 *
 * Two flat sibling modules under the "Websites" group — TYPO3's BE module
 * menu is intentionally 2-level only, so the SimpleCMP feature area is
 * grouped by adjacency + the shared icon rather than a hierarchical
 * sub-menu. The detections module hosts three tabs reflecting the
 * 3-table architecture: Detections (observation log) | Dienste (registry
 * surface, source-tagged) | Bibliothek (bundled library browser):
 *
 *   Websites
 *     ├─ Einrichtung (core)
 *     ├─ SimpleCMP             (tabs: Detections | Dienste | Bibliothek)
 *     └─ SimpleCMP-Banner-Design (theme designer)
 */
return [
    'simplecmp_detections' => [
        'parent' => 'site',
        'position' => ['after' => 'site_configuration'],
        'access' => 'admin',
        'path' => '/module/simplecmp/detections',
        'iconIdentifier' => 'simplecmp-module',
        'labels' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_mod.xlf',
        'extensionName' => 'SimpleCmpTypo3',
        'controllerActions' => [
            DetectionReviewController::class => [
                'list',
                'show',
                'approve',
                'dismiss',
                'undismiss',
                'purge',
                'bulkDismissAll',
                'bulkDismissSelected',
                'bulkUndismissSelected',
                'bulkPurgeSelected',
                'createService',
                'generateBridgeSecret',
                'reclassifyUnknowns',
            ],
            LibraryBrowserController::class => [
                'list',
                'adopt',
                'unadopt',
            ],
            RegistryListController::class => [
                'list',
                'delete',
            ],
            DiscoveryController::class => [
                'index',
                'fetchSitemap',
            ],
        ],
    ],
    'simplecmp_design' => [
        'parent' => 'site',
        'position' => ['after' => 'simplecmp_detections'],
        'access' => 'admin',
        'path' => '/module/simplecmp/design',
        'iconIdentifier' => 'simplecmp-module',
        'labels' => 'LLL:EXT:t3_simplecmp/Resources/Private/Language/locallang_design.xlf',
        'extensionName' => 'SimpleCmpTypo3',
        'controllerActions' => [
            ThemeDesignerController::class => [
                'index',
                'save',
                'reset',
            ],
        ],
    ],
];
