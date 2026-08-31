<?php

/*
 * This file is part of the TYPO3 CMS extension "bw_outdated_pages".
 *
 * It is free software; you can redistribute it and/or modify it under
 * the terms of the GNU General Public License, either version 2
 * of the License, or any later version.
 *
 * For the full copyright and license information, please read the
 * LICENSE.txt file that was distributed with this source code.
 *
 * The TYPO3 project - inspiring people to share!
 */

declare(strict_types=1);

return [
    'backend' => [
        'blueways/bw-outdated-pages/module-page-access-fallback' => [
            'target' => \Blueways\BwOutdatedPages\Middleware\ModulePageAccessFallback::class,
            'after' => [
                'typo3/cms-backend/backend-module-validator',
            ],
        ],
    ],
];
