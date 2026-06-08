<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'SimpleCMP for TYPO3',
    'description' => 'TYPO3 integration for SimpleCMP — consent manager with banner UI, '
        . 'tracker recorder, optional service-DB endpoint, and optional CMS-bridge '
        . 'webhook receiver for unknown-tracker alerts.',
    'category' => 'plugin',
    'author' => 'Sven Wappler, Ilja Melnicenko',
    'author_email' => 'wappler@wappler.systems',
    'author_company' => 'SimpleCMP',
    'state' => 'alpha',
    'version' => '0.4.2',
    'constraints' => [
        'depends' => [
            'typo3' => '14.0.0-14.99.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
