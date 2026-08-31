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

defined('TYPO3') or die();

use Blueways\BwOutdatedPages\Task\OutdatedPagesTask;
use Blueways\BwOutdatedPages\Task\OutdatedPagesTaskAdditionalFieldProvider;
use TYPO3\CMS\Core\Utility\ExtensionManagementUtility;

ExtensionManagementUtility::addPageTSConfig(
    "@import 'EXT:bw_outdated_pages/Configuration/page.tsconfig'"
);

$GLOBALS['TYPO3_CONF_VARS']['SC_OPTIONS']['scheduler']['tasks'][OutdatedPagesTask::class] = [
    'extension' => 'bw_outdated_pages',
    'title' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:task.title',
    'description' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:task.description',
    'additionalFields' => OutdatedPagesTaskAdditionalFieldProvider::class,
];
