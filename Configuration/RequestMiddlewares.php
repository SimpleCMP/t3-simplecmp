<?php

declare(strict_types=1);

use WapplerSystems\SimpleCmpTypo3\Middleware\ServiceDbApi;
use WapplerSystems\SimpleCmpTypo3\UniversalBlocking\Middleware\HtmlRewriter;

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
        // ADR-0013 — universal-blocking HTML rewriter. Activated per
        // site via the Site Set setting `simplecmp.universalBlocking.
        // enabled` (off by default). Runs after every other frontend
        // middleware so the response body is fully rendered HTML by
        // the time we see it.
        'wapplersystems/simplecmp/universal-blocking-rewriter' => [
            'target' => HtmlRewriter::class,
            'after' => [
                'typo3/cms-frontend/content-length-headers',
            ],
        ],
    ],
];
