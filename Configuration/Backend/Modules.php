<?php

declare(strict_types=1);

use SimpleCMP\T3SimpleCmp\Controller\AuditAuskunftController;
use SimpleCMP\T3SimpleCmp\Controller\AuditSnapshotController;
use SimpleCMP\T3SimpleCmp\Controller\DetectionReviewController;
use SimpleCMP\T3SimpleCmp\Controller\PublishController;
use SimpleCMP\T3SimpleCmp\Controller\SettingsController;
use SimpleCMP\T3SimpleCmp\Controller\SetupWizardController;
use SimpleCMP\T3SimpleCmp\Controller\DiscoveryController;
use SimpleCMP\T3SimpleCmp\Controller\LibraryBrowserController;
use SimpleCMP\T3SimpleCmp\Controller\RegistryListController;
use SimpleCMP\T3SimpleCmp\Controller\ThemeDesignerController;
use SimpleCMP\T3SimpleCmp\Controller\TrackerSetupController;

/**
 * Backend module registration.
 *
 * Single module under the "Websites" group with tabs. The designer is
 * a tab inside the same module rather than a separate module entry:
 *
 *   Websites
 *     ├─ Einrichtung (core)
 *     └─ SimpleCMP  (tabs: Detections | Dienste | Bibliothek | … | Design)
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
                'init',
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
            SetupWizardController::class => [
                'welcome',
                'skip',
                'reopen',
                'tracker',
                'saveTracker',
                'design',
                'saveDesign',
                'publish',
                'finish',
            ],
            ThemeDesignerController::class => [
                'index',
                'save',
                'reset',
                'compliance',
            ],
        ],
    ],
];
