<?php

declare(strict_types=1);

use WapplerSystems\SimpleCmpTypo3\Controller\Backend\DetectionReviewController;
use WapplerSystems\SimpleCmpTypo3\Controller\Backend\ThemeDesignerController;

/**
 * Backend module registration.
 *
 * Two flat sibling modules under the "Websites" group — TYPO3's BE module
 * menu is intentionally 2-level only, so the SimpleCMP feature area is
 * grouped by adjacency + the shared icon rather than a hierarchical
 * sub-menu. Both modules sort next to `site_configuration`:
 *
 *   Websites
 *     ├─ Einrichtung (core)
 *     ├─ SimpleCMP-Detektionen   (detection triage)
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
                'delete',
                'bulkDeleteAll',
                'bulkDeleteSelected',
                'createService',
                'generateBridgeSecret',
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
