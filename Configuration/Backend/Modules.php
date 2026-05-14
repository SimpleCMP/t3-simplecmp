<?php

declare(strict_types=1);

use WapplerSystems\SimpleCmpTypo3\Controller\Backend\DetectionReviewController;

/**
 * Backend module registration. Lives under the "Site Management" parent
 * (same group as site settings — admins managing trackers operate at the
 * site level, not the content level).
 *
 * Extbase-style `controllerActions` config: TYPO3 dispatches HTTP actions
 * to `*Action` methods on the controller. The first action listed is the
 * default landing.
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
                'markReviewed',
                'unmarkReviewed',
                'bulkDelete',
                'createService',
            ],
        ],
    ],
];
