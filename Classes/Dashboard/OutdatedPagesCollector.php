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

namespace Blueways\BwOutdatedPages\Dashboard;

use Blueways\BwOutdatedPages\Service\OutdatedPagesFinder;
use TYPO3\CMS\Core\Site\SiteFinder;

/**
 * Aggregates outdated-pages results across the current backend user's
 * web mounts so the dashboard widget shows a combined overview of all
 * page trees the editor has access to.
 *
 * The threshold is intentionally fixed here (same default as the backend
 * module) so the widget works out of the box without any scheduler task
 * configuration. The PageAccessChecker filter in
 * OutdatedPagesListDataProvider removes individual pages within those
 * trees that the user cannot access.
 */
class OutdatedPagesCollector
{
    protected const DEFAULT_DAYS_THRESHOLD = 180;

    public function __construct(
        private readonly OutdatedPagesFinder $finder,
        private readonly SiteFinder $siteFinder,
    ) {}

    /**
     * @return array<int, array{uid: int, title: string, tstamp: int, languageTag: string}>
     */
    public function collect(): array
    {
        $webMounts = $this->getWebMounts();

        if ($webMounts === []) {
            return [];
        }

        $entries = [];

        foreach ($webMounts as $mountPageUid) {
            $pages = $this->finder->findOutdatedPages($mountPageUid, self::DEFAULT_DAYS_THRESHOLD);

            foreach ($pages as $page) {
                // The same page/language can appear in overlapping mounts —
                // keep the earliest (oldest) tstamp.
                $key = $page['uid'] . ':' . $page['languageTag'];

                if (!isset($entries[$key])) {
                    $entries[$key] = $page;
                } elseif ($page['tstamp'] < $entries[$key]['tstamp']) {
                    $entries[$key] = $page;
                }
            }
        }

        $entries = array_values($entries);
        usort($entries, static fn (array $a, array $b): int => $a['tstamp'] <=> $b['tstamp']);

        return $entries;
    }

    /**
     * @return int[]
     */
    protected function getWebMounts(): array
    {
        $backendUser = $GLOBALS['BE_USER'] ?? null;
        if ($backendUser === null) {
            return [];
        }

        $mounts = $backendUser->getWebmounts();
        $mounts = array_map('intval', $mounts);

        // Mount 0 means "all pages", and admin users often have no explicit
        // mounts configured at all — in both cases use all TYPO3 site roots.
        if ($mounts === [] || in_array(0, $mounts, true)) {
            return $this->getAllSiteRootPageUids();
        }

        return array_values(array_filter($mounts, static fn (int $uid): bool => $uid > 0));
    }

    /**
     * @return int[]
     */
    protected function getAllSiteRootPageUids(): array
    {
        $uids = [];
        foreach ($this->siteFinder->getAllSites() as $site) {
            $uids[] = $site->getRootPageId();
        }

        return array_values(array_unique($uids));
    }
}
