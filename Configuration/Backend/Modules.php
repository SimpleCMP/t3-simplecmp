<?php

declare(strict_types=1);

use SimpleCMP\T3SimpleCmp\Controller\AuditAuskunftController;
use SimpleCMP\T3SimpleCmp\Controller\AuditSnapshotController;
use SimpleCMP\T3SimpleCmp\Controller\DetectionReviewController;
use SimpleCMP\T3SimpleCmp\Controller\PublishController;
use SimpleCMP\T3SimpleCmp\Controller\SettingsController;
use SimpleCMP\T3SimpleCmp\Controller\DiscoveryController;
use SimpleCMP\T3SimpleCmp\Controller\LibraryBrowserController;
use SimpleCMP\T3SimpleCmp\Controller\RegistryListController;
use SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController;
use SimpleCMP\T3SimpleCmp\Controller\TrackerSetupController;

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
                'unadoptMatchedService',
                'allowStylesheetHost',
            ],
            LibraryBrowserController::class => [
                'list',
                'adopt',
                'bulkAdopt',
                'unadopt',
                'bulkUnadopt',
                'refreshUpstreamHealth',
            ],
            RegistryListController::class => [
                'list',
                'delete',
            ],
            DiscoveryController::class => [
                'index',
                'fetchSitemap',
            ],
            TrackerSetupController::class => [
                'list',
                'new',
                'edit',
                'save',
                'delete',
            ],
            AuditSnapshotController::class => [
                'list',
                'show',
            ],
            AuditAuskunftController::class => [
                'index',
                'lookup',
                'download',
            ],
            PublishController::class => [
                'publish',
                'discard',
                'takeover',
            ],
            SettingsController::class => [
                'index',
                'bootstrap',
                'adoptKey',
                'adoptAll',
                'setCustom',
                'resetKey',
                'adoptTracker',
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
                'compliance',
            ],
        ],
    ],
];
