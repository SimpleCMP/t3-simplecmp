<?php

declare(strict_types=1);

use WapplerSystems\SimpleCmpTypo3\Controller\Backend\DetectionReviewController;
use WapplerSystems\SimpleCmpTypo3\Controller\Backend\LibraryBrowserController;
use WapplerSystems\SimpleCmpTypo3\Controller\Backend\ThemeDesignerController;

/**
 * Backend module registration.
 *
 * Two flat sibling modules under the "Websites" group — TYPO3's BE module
 * menu is intentionally 2-level only, so the SimpleCMP feature area is
 * grouped by adjacency + the shared icon rather than a hierarchical
 * sub-menu. The detections module hosts two tabs (Detections + Services
 * catalog) so admins land in one entry-point regardless of whether they
 * want to triage incoming detections or promote registered services to
 * the banner:
 *
 *   Websites
 *     ├─ Einrichtung (core)
 *     ├─ SimpleCMP             (tabs: Detections | Services)
 *     └─ SimpleCMP-Banner-Design (theme designer)
 */
return [
    'simplecmp_detections' => [
        'parent' => 'site',
        'position' => ['after' => 'site_configuration'],
        'access' => 'admin',
        'path' => '/module/simplecmp/detections',
        'iconIdentifier' => 'simplecmp-module',
        'labels' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_mod.xlf',
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
            ],
            LibraryBrowserController::class => [
                'list',
                'adopt',
                'unadopt',
            ],
        ],
    ],
    'simplecmp_design' => [
        'parent' => 'site',
        'position' => ['after' => 'simplecmp_detections'],
        'access' => 'admin',
        'path' => '/module/simplecmp/design',
        'iconIdentifier' => 'simplecmp-module',
        'labels' => 'LLL:EXT:simplecmp_typo3/Resources/Private/Language/locallang_design.xlf',
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
