<?php

declare(strict_types=1);

use WapplerSystems\SimpleCmpTypo3\Middleware\ServiceDbApi;

return [
    'frontend' => [
        'wapplersystems/simplecmp/service-db-api' => [
            'target' => ServiceDbApi::class,
            // Run before TYPO3's site resolver — we don't need a resolved
            // page for an API endpoint, and skipping it shaves ~80 ms off
            // every API hit.
            'before' => [
                'typo3/cms-frontend/site',
            ],
            'after' => [
                'typo3/cms-frontend/normalized-params-attribute',
            ],
        ],
    ],
];
