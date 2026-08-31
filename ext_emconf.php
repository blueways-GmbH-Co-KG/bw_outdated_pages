<?php

$EM_CONF[$_EXTKEY] = [
    'title' => 'BW Outdated Pages',
    'description' => 'Scheduler task, backend module and dashboard widget notifying about outdated pages in a configurable branch of the page tree.',
    'category' => 'misc',
    'author' => 'Blueways GmbH & Co. KG',
    'author_email' => 'info@blueways.de',
    'author_company' => 'Blueways GmbH & Co. KG',
    'state' => 'stable',
    'version' => '1.0.0',
    'constraints' => [
        'depends' => [
            'typo3' => '13.4.0-13.4.99',
            'scheduler' => '13.4.0-13.4.99',
            'dashboard' => '13.4.0-13.4.99',
        ],
        'conflicts' => [],
        'suggests' => [],
    ],
];
