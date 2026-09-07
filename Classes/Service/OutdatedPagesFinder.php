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

use Blueways\BwOutdatedPages\Domain\Repository\ReviewRepository;
use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Database\Query\Restriction\HiddenRestriction;
use TYPO3\CMS\Core\Site\SiteFinder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Finds outdated pages within a branch of the page tree. This is the
 * shared business logic behind both OutdatedPagesTask (the scheduler
 * task, which emails a summary) and the "Outdated pages" dashboard
 * widget (which shows a live summary in the backend) - kept in its own
 * class so the scheduler task itself stays a thin wrapper, per TYPO3's
 * own recommendation for scheduler task design.
 *
 * Every language configured for the site (not just a fixed pair) is
 * checked independently: each language version of a page is only
 * reported outdated if it - and its own content elements - haven't been
 * changed within the threshold. A manual "mark as reviewed" confirmation
 * (see ReviewRepository) counts as a change too, so a confirmed page
 * naturally re-surfaces again once the threshold has passed since the
 * review, rather than being silenced forever.
 */
class OutdatedPagesFinder
{
    /**
     * Doktypes that are excluded from the check because they don't carry
     * editorial content of their own:
     * - 3   External URL
     * - 4   Shortcut (points to another page, has no own content)
     * - 6   Backend user section
     * - 7   Mount point (symlink to another page tree branch)
     * - 199 Spacer / Divider
     * - 254 Folder (Sysfolder)
     * - 255 Recycler
     * Pages that show content from another page (content_from_pid != 0)
     * are already excluded separately in fetchDefaultLanguagePages().
     */
    protected const DEFAULT_EXCLUDED_DOKTYPES = [3, 4, 6, 7, 199, 254, 255];

    /**
     * TYPO3 always stores the source/default-language record of a page or
     * content element with sys_language_uid = 0, regardless of which
     * language that is configured as in the site. This is a core
     * convention, not a specific language - it's used as the anchor for
     * page tree traversal and for looking up translations.
     */
    protected const DEFAULT_LANGUAGE_ID = 0;

    public function __construct(
        private readonly ReviewRepository $reviewRepository,
    ) {}

    /**
     * @param array{
     *   excludedDoktypes?: int[],
     *   excludePagesUnderSysfolders?: bool,
     *   checkContentElements?: bool,
     *   maxDepth?: int,
     *   excludePageUids?: int[],
     *   checkHiddenPages?: bool
     * } $settings
     * @return array<int, array{uid: int, title: string, tstamp: int, languageTag: string}>
     */
    public function findOutdatedPages(int $entryPageUid, int $daysThreshold, array $settings = []): array
    {
        if ($entryPageUid <= 0 || $daysThreshold <= 0) {
            return [];
        }

        $languages = $this->resolveSiteLanguages($entryPageUid);
        $defaultLanguage = $this->findLanguage($languages, self::DEFAULT_LANGUAGE_ID)
            ?? ['id' => self::DEFAULT_LANGUAGE_ID, 'code' => '', 'label' => ''];
        $translationLanguages = array_values(array_filter(
            $languages,
            static fn(array $language): bool => $language['id'] !== self::DEFAULT_LANGUAGE_ID
        ));

        $excludedDoktypes = $settings['excludedDoktypes'] ?? self::DEFAULT_EXCLUDED_DOKTYPES;
        $excludePagesUnderSysfolders = $settings['excludePagesUnderSysfolders'] ?? true;
        $checkContentElements = $settings['checkContentElements'] ?? true;
        $maxDepth = max(0, (int)($settings['maxDepth'] ?? 0));
        $excludePageUids = $settings['excludePageUids'] ?? [];
        $checkHiddenPages = $settings['checkHiddenPages'] ?? false;

        $pageIds = array_merge([$entryPageUid], $this->collectSubpageIds($entryPageUid, $maxDepth));
        $pageIds = array_unique($pageIds);

        if ($excludePageUids !== []) {
            $pageIds = array_values(array_diff($pageIds, $excludePageUids));
        }

        if ($pageIds === []) {
            return [];
        }

        $thresholdTimestamp = $GLOBALS['EXEC_TIME'] ?? time();
        $thresholdTimestamp -= $daysThreshold * 86400;

        // The default-language page rows define the actual page tree
        // position and are used as the anchor for everything else
        // (content elements and translations are both looked up
        // relative to this uid).
        $defaultPages = $this->fetchDefaultLanguagePages(
            $pageIds,
            $excludedDoktypes,
            $excludePagesUnderSysfolders,
            $checkHiddenPages
        );
        if ($defaultPages === []) {
            return [];
        }

        $defaultUids = array_keys($defaultPages);

        $allLanguageIds = array_merge(
            [-1, self::DEFAULT_LANGUAGE_ID],
            array_column($translationLanguages, 'id')
        );
        $contentTstampsByPageAndLanguage = $checkContentElements
            ? $this->fetchContentTstamps($defaultUids, $allLanguageIds, $checkHiddenPages)
            : [];
        $reviewsByPageAndLanguage = $this->reviewRepository->findLatestReviews($defaultUids);

        $translatedPagesByLanguage = [];
        foreach ($translationLanguages as $language) {
            $translatedPagesByLanguage[$language['id']] = $this->fetchTranslatedPages(
                $defaultUids,
                $language['id'],
                $checkHiddenPages
            );
        }

        $outdatedPages = [];

        foreach ($defaultPages as $uid => $page) {
            $allLangContentTstamp = $contentTstampsByPageAndLanguage[$uid][-1] ?? 0;

            $defaultLanguageTag = $this->buildLanguageTag($defaultLanguage);
            $defaultContentTstamp = max($contentTstampsByPageAndLanguage[$uid][self::DEFAULT_LANGUAGE_ID] ?? 0, $allLangContentTstamp);
            $defaultReviewedAt = $reviewsByPageAndLanguage[$uid][$defaultLanguageTag] ?? 0;
            $defaultLastChanged = max($page['tstamp'], $defaultContentTstamp, $defaultReviewedAt);

            if ($defaultLastChanged < $thresholdTimestamp) {
                $outdatedPages[] = [
                    'uid' => $uid,
                    'title' => $page['title'],
                    'tstamp' => $defaultLastChanged,
                    'languageTag' => $defaultLanguageTag,
                ];
            }

            foreach ($translationLanguages as $language) {
                $translatedPage = $translatedPagesByLanguage[$language['id']][$uid] ?? null;
                if ($translatedPage === null) {
                    // No translation into this language exists for this
                    // page - nothing to check/report for it.
                    continue;
                }

                $languageTag = $this->buildLanguageTag($language);
                $contentTstamp = max($contentTstampsByPageAndLanguage[$uid][$language['id']] ?? 0, $allLangContentTstamp);
                $reviewedAt = $reviewsByPageAndLanguage[$uid][$languageTag] ?? 0;
                $lastChanged = max($translatedPage['tstamp'], $contentTstamp, $reviewedAt);

                if ($lastChanged < $thresholdTimestamp) {
                    $outdatedPages[] = [
                        'uid' => $uid,
                        'title' => $translatedPage['title'] !== '' ? $translatedPage['title'] : $page['title'],
                        'tstamp' => $lastChanged,
                        'languageTag' => $languageTag,
                    ];
                }
            }
        }

        usort($outdatedPages, static fn(array $a, array $b): int => $a['tstamp'] <=> $b['tstamp']);

        return $outdatedPages;
    }

    /**
     * Reads all languages configured for the site the given page belongs
     * to (config/sites/<site>/config.yaml) - any number of them, not a
     * fixed set. Falls back to a single "default only" pseudo-language if
     * no site configuration can be determined (e.g. the entry page isn't
     * part of a configured site yet), so the task still works, just
     * without translation checks.
     *
     * @return array<int, array{id: int, code: string, label: string}>
     */
    protected function resolveSiteLanguages(int $pageUid): array
    {
        try {
            $site = GeneralUtility::makeInstance(SiteFinder::class)->getSiteByPageId($pageUid);
        } catch (\Throwable $exception) {
            return [['id' => self::DEFAULT_LANGUAGE_ID, 'code' => '', 'label' => '']];
        }

        $languages = [];
        foreach ($site->getLanguages() as $siteLanguage) {
            $languages[] = [
                'id' => $siteLanguage->getLanguageId(),
                'code' => strtolower($siteLanguage->getLocale()->getLanguageCode()),
                'label' => $siteLanguage->getTitle(),
            ];
        }

        if ($languages === []) {
            return [['id' => self::DEFAULT_LANGUAGE_ID, 'code' => '', 'label' => '']];
        }

        return $languages;
    }

    /**
     * @param array<int, array{id: int, code: string, label: string}> $languages
     * @return array{id: int, code: string, label: string}|null
     */
    protected function findLanguage(array $languages, int $languageId): ?array
    {
        foreach ($languages as $language) {
            if ($language['id'] === $languageId) {
                return $language;
            }
        }

        return null;
    }

    /**
     * Short, readable tag used to mark which language an outdated entry
     * refers to, e.g. "DE", "EN", "FR". Falls back to "L<id>" if no ISO
     * code is available.
     *
     * @param array{id: int, code: string, label: string} $language
     */
    protected function buildLanguageTag(array $language): string
    {
        if ($language['code'] !== '') {
            return strtoupper($language['code']);
        }

        return 'L' . $language['id'];
    }

    /**
     * Fetches the default-language candidate pages, excluding non-content
     * doktypes, pages that pull their content from another page via
     * "Show content from page" (content_from_pid), and hidden pages.
     * Optionally also excludes pages whose direct parent is a Sysfolder.
     *
     * @param int[] $pageIds
     * @param int[] $excludedDoktypes
     * @return array<int, array{uid: int, title: string, tstamp: int}> keyed by uid
     */
    protected function fetchDefaultLanguagePages(
        array $pageIds,
        array $excludedDoktypes = self::DEFAULT_EXCLUDED_DOKTYPES,
        bool $excludePagesUnderSysfolders = true,
        bool $checkHiddenPages = false
    ): array {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');
        $restrictions = $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if (!$checkHiddenPages) {
            $restrictions->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $queryBuilder
            ->select('pages.uid', 'pages.title', 'pages.tstamp')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'pages.uid',
                    $queryBuilder->createNamedParameter($pageIds, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq(
                    'pages.sys_language_uid',
                    $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE_ID, Connection::PARAM_INT)
                ),
                $queryBuilder->expr()->notIn(
                    'pages.doktype',
                    $queryBuilder->createNamedParameter($excludedDoktypes, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq(
                    'pages.content_from_pid',
                    $queryBuilder->createNamedParameter(0, Connection::PARAM_INT)
                )
            );

        if ($excludePagesUnderSysfolders) {
            $queryBuilder
                ->leftJoin('pages', 'pages', 'parent', 'pages.pid = parent.uid')
                ->andWhere(
                    $queryBuilder->expr()->or(
                        $queryBuilder->expr()->isNull('parent.uid'),
                        $queryBuilder->expr()->neq(
                            'parent.doktype',
                            $queryBuilder->createNamedParameter(254, Connection::PARAM_INT)
                        )
                    )
                );
        }

        $rows = $queryBuilder->executeQuery()->fetchAllAssociative();

        $pages = [];
        foreach ($rows as $row) {
            $pages[(int)$row['uid']] = [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'tstamp' => (int)$row['tstamp'],
            ];
        }

        return $pages;
    }

    /**
     * Fetches the translation page row (for one specific language) for
     * each given default-language page UID (via l10n_parent), excluding
     * deleted and hidden translations. Pages without a translation into
     * that language simply don't appear in the result.
     *
     * @param int[] $defaultUids
     * @return array<int, array{uid: int, title: string, tstamp: int}> keyed by l10n_parent (= default-language uid)
     */
    protected function fetchTranslatedPages(array $defaultUids, int $languageId, bool $checkHiddenPages = false): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');
        $restrictions = $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if (!$checkHiddenPages) {
            $restrictions->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $rows = $queryBuilder
            ->select('uid', 'l10n_parent', 'title', 'tstamp')
            ->from('pages')
            ->where(
                $queryBuilder->expr()->in(
                    'l10n_parent',
                    $queryBuilder->createNamedParameter($defaultUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->eq(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageId, Connection::PARAM_INT)
                )
            )
            ->executeQuery()
            ->fetchAllAssociative();

        $translations = [];
        foreach ($rows as $row) {
            $translations[(int)$row['l10n_parent']] = [
                'uid' => (int)$row['uid'],
                'title' => (string)$row['title'],
                'tstamp' => (int)$row['tstamp'],
            ];
        }

        return $translations;
    }

    /**
     * Returns, per default-language page UID and language UID, the
     * tstamp of the most recently changed, non-hidden content element.
     * Content elements always carry the default-language page's uid as
     * their pid (regardless of which language they belong to), so this
     * is queried once for all relevant language UIDs together and
     * grouped accordingly.
     *
     * TYPO3 does not automatically bump pages.tstamp when only a content
     * element is changed, so this has to be checked separately from the
     * page's own tstamp.
     *
     * @param int[] $defaultUids
     * @param int[] $languageIds
     * @return array<int, array<int, int>> [pid][languageUid] => max tstamp
     */
    protected function fetchContentTstamps(array $defaultUids, array $languageIds, bool $checkHiddenPages = false): array
    {
        if ($languageIds === []) {
            return [];
        }

        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $queryBuilder = $connectionPool->getQueryBuilderForTable('tt_content');
        $restrictions = $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));
        if (!$checkHiddenPages) {
            $restrictions->add(GeneralUtility::makeInstance(HiddenRestriction::class));
        }

        $rows = $queryBuilder
            ->selectLiteral('pid', 'sys_language_uid', 'MAX(tstamp) AS maxtstamp')
            ->from('tt_content')
            ->where(
                $queryBuilder->expr()->in(
                    'pid',
                    $queryBuilder->createNamedParameter($defaultUids, Connection::PARAM_INT_ARRAY)
                ),
                $queryBuilder->expr()->in(
                    'sys_language_uid',
                    $queryBuilder->createNamedParameter($languageIds, Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy('pid', 'sys_language_uid')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $pid = (int)$row['pid'];
            $languageId = (int)$row['sys_language_uid'];
            $result[$pid][$languageId] = (int)$row['maxtstamp'];
        }

        return $result;
    }

    /**
     * Iteratively (breadth-first) collects all default-language page UIDs
     * below $startPid. Deliberately not using a recursive CTE to stay
     * compatible with all database platforms supported by TYPO3.
     * Restricted to the default language so the tree traversal follows
     * the actual page tree structure (translations share their pid with
     * the default-language sibling and would otherwise be visited twice
     * without adding any further children).
     *
     * @return int[]
     */
    protected function collectSubpageIds(int $startPid, int $maxDepth = 0): array
    {
        $connectionPool = GeneralUtility::makeInstance(ConnectionPool::class);
        $collected = [];
        $currentLevelPids = [$startPid];
        $currentDepth = 0;

        while ($currentLevelPids !== []) {
            if ($maxDepth > 0 && $currentDepth >= $maxDepth) {
                break;
            }
            $queryBuilder = $connectionPool->getQueryBuilderForTable('pages');
            $queryBuilder->getRestrictions()
                ->removeAll()
                ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

            $childUids = $queryBuilder
                ->select('uid')
                ->from('pages')
                ->where(
                    $queryBuilder->expr()->in(
                        'pid',
                        $queryBuilder->createNamedParameter($currentLevelPids, Connection::PARAM_INT_ARRAY)
                    ),
                    $queryBuilder->expr()->eq(
                        'sys_language_uid',
                        $queryBuilder->createNamedParameter(self::DEFAULT_LANGUAGE_ID, Connection::PARAM_INT)
                    )
                )
                ->executeQuery()
                ->fetchFirstColumn();

            $childUids = array_map('intval', $childUids);
            $newPids = array_diff($childUids, $collected);

            if ($newPids === []) {
                break;
            }

            $collected = array_merge($collected, $newPids);
            $currentLevelPids = $newPids;
            $currentDepth++;
        }

        return $collected;
    }
}
