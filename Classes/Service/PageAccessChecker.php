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

namespace Blueways\BwOutdatedPages\Service;

use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * Checks whether the currently logged-in backend user is allowed to see
 * a given page - both in terms of being within one of their configured
 * web mounts and having the "show" permission bit on the page record
 * itself. Used to filter the dashboard widget and the backend module so
 * editors only ever see pages they could also reach via the page tree
 * themselves, regardless of which pages a scheduler task's entry point
 * happens to cover.
 */
class PageAccessChecker
{
    public function isAccessible(int $pageUid): bool
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            return false;
        }

        if ($backendUser->isAdmin()) {
            return true;
        }

        if (!$backendUser->isInWebMount($pageUid)) {
            return false;
        }

        $pageRecord = BackendUtility::getRecord('pages', $pageUid);
        if ($pageRecord === null) {
            return false;
        }

        return (bool)$backendUser->doesUserHaveAccess($pageRecord, Permission::PAGE_SHOW);
    }

    /**
     * @template T of array{uid: int}
     * @param array<int, T> $entries
     * @return array<int, T>
     */
    public function filterAccessible(array $entries): array
    {
        return array_values(array_filter(
            $entries,
            fn (array $entry): bool => $this->isAccessible($entry['uid'])
        ));
    }
}
