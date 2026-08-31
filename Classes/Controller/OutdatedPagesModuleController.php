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

namespace Blueways\BwOutdatedPages\Controller;

use Blueways\BwOutdatedPages\Service\OutdatedPagesFinder;
use Blueways\BwOutdatedPages\Service\PageAccessChecker;
use Psr\Http\Message\ResponseInterface;
use TYPO3\CMS\Backend\Template\ModuleTemplateFactory;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Backend\Attribute\AsController;
use TYPO3\CMS\Extbase\Mvc\Controller\ActionController;
use TYPO3\CMS\Core\Page\PageRenderer;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Core\Utility\GeneralUtility;

#[AsController]

/**
 * Backend module showing a filterable list of outdated pages for the
 * branch of the page tree currently selected (the standard "id" GET
 * parameter TYPO3 passes whenever an editor clicks a page in the tree,
 * same as e.g. the Page module) - not tied to any pre-configured
 * scheduler task's entry point. The threshold (days) is adjustable
 * directly in the module, so it doubles as an ad-hoc "check this
 * branch" tool independent of what's scheduled.
 *
 * Results are filtered through PageAccessChecker so an editor only ever
 * sees pages they could also reach via their own page tree/web mounts.
 */
class OutdatedPagesModuleController extends ActionController
{
    protected const DEFAULT_DAYS_THRESHOLD = 180;

    public function __construct(
        private readonly ModuleTemplateFactory $moduleTemplateFactory,
        private readonly OutdatedPagesFinder $finder,
        private readonly PageAccessChecker $pageAccessChecker,
        private readonly PageRenderer $pageRenderer,
        private readonly UriBuilder $backendUriBuilder
    ) {}

    protected function initializeView(): void
    {
        $queryParams = $this->request->getAttribute('extbase')->getArguments();
        $pageUid = (int)($queryParams['id'] ?? 0);
        if ($pageUid <= 0) {
            return;
        }

        $tsconfig = BackendUtility::getPagesTSconfig($pageUid);
        $viewSettings = $tsconfig['module.']['tx_bw_outdated_pages.']['view.'] ?? [];
        if ($viewSettings === []) {
            return;
        }

        // @phpstan-ignore-next-line
        $templatePaths = $this->view->getRenderingContext()->getTemplatePaths();

        foreach ([
            'templateRootPaths.' => 'setTemplateRootPaths',
            'partialRootPaths.'  => 'setPartialRootPaths',
            'layoutRootPaths.'   => 'setLayoutRootPaths',
        ] as $tsconfigKey => $setter) {
            if (empty($viewSettings[$tsconfigKey])) {
                continue;
            }
            $configured = $viewSettings[$tsconfigKey];
            ksort($configured);
            $resolved = array_map(
                static fn(string $p): string => GeneralUtility::getFileAbsFileName($p),
                $configured
            );
            $getter = str_replace('set', 'get', $setter);
            $merged = array_merge($templatePaths->$getter(), array_values($resolved));
            $templatePaths->$setter($merged);
        }
    }

    public function mainAction(): ResponseInterface
    {
        $moduleTemplate = $this->moduleTemplateFactory->create($this->request);
        $moduleTemplate->setTitle(
            'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:mlang_tabs_tab'
        );
        $this->pageRenderer->addJsFooterFile(
            'EXT:bw_outdated_pages/Resources/Public/JavaScript/mark-reviewed.js'
        );

        $queryParams = $this->request->getAttribute('extbase')->getArguments();
        $pageUid = (int)($queryParams['id'] ?? 0);

        $finderSettings = $this->resolveFinderSettings($pageUid);
        $defaultDays = $finderSettings['defaultDaysThreshold'];

        $daysThreshold = (int)($queryParams['days'] ?? $defaultDays);
        if ($daysThreshold <= 0) {
            $daysThreshold = $defaultDays;
        }
        $languageFilter = trim((string)($queryParams['language'] ?? ''));
        $searchFilter = trim((string)($queryParams['search'] ?? ''));

        $entries = $pageUid > 0 ? $this->finder->findOutdatedPages($pageUid, $daysThreshold, $finderSettings) : [];
        $entries = $this->pageAccessChecker->filterAccessible($entries);

        $availableLanguages = [];
        foreach ($entries as $entry) {
            $availableLanguages[$entry['languageTag']] = true;
        }
        $availableLanguages = array_keys($availableLanguages);
        sort($availableLanguages);

        // Built as [value, selected] pairs rather than comparing
        // {lang} == {currentLanguage} directly in the Fluid template -
        // Fluid's inline string-based boolean expressions are unreliable
        // for this and tend to mark the wrong (often last) option as
        // selected.
        $languageOptions = [];
        foreach ($availableLanguages as $lang) {
            $languageOptions[] = [
                'value' => $lang,
                'selected' => $lang === $languageFilter,
            ];
        }

        $filteredEntries = array_filter(
            $entries,
            static function (array $entry) use ($languageFilter, $searchFilter): bool {
                if ($languageFilter !== '' && $entry['languageTag'] !== $languageFilter) {
                    return false;
                }
                if ($searchFilter !== '' && stripos($entry['title'], $searchFilter) === false) {
                    return false;
                }
                return true;
            }
        );

        $rows = [];
        foreach ($filteredEntries as $entry) {
            $rows[] = [
                'uid' => $entry['uid'],
                'title' => $entry['title'],
                'languageTag' => $entry['languageTag'],
                'date' => date('d.m.Y', $entry['tstamp']),
                'editUrl' => $this->buildPageModuleUrl($entry['uid']),
                'reviewUrl' => $this->buildReviewUrl($entry['uid'], $entry['languageTag']),
            ];
        }
        $currentPageRecord = $pageUid > 0 ? BackendUtility::getRecord('pages', $pageUid) : null;

        $moduleTemplate->assignMultiple([
            'rows' => $rows,
            'totalCount' => count($entries),
            'filteredCount' => count($rows),
            'languageOptions' => $languageOptions,
            'currentSearch' => $searchFilter,
            'currentDays' => $daysThreshold,
            'currentPageUid' => $pageUid,
            'currentPageTitle' => $currentPageRecord['title'] ?? null,
            'noPageSelected' => $pageUid <= 0,
        ]);

        return $moduleTemplate->renderResponse('Backend/Index');
    }

    /**
     * Reads module settings from Page TSconfig for the given page.
     * Falls back to hardcoded defaults when no page is selected or no
     * TSconfig is present for the setting.
     *
     * @return array{excludedDoktypes: int[], excludePagesUnderSysfolders: bool, defaultDaysThreshold: int, checkContentElements: bool, maxDepth: int, excludePageUids: int[], checkHiddenPages: bool}
     */
    protected function resolveFinderSettings(int $pageUid): array
    {
        $tsconfig = $pageUid > 0 ? BackendUtility::getPagesTSconfig($pageUid) : [];
        $s = $tsconfig['module.']['tx_bw_outdated_pages.']['settings.'] ?? [];

        return [
            'excludedDoktypes' => GeneralUtility::intExplode(
                ',',
                (string)($s['excludedDoktypes'] ?? '3,4,6,7,199,254,255'),
                true
            ),
            'excludePagesUnderSysfolders' => (bool)(int)($s['excludePagesUnderSysfolders'] ?? 1),
            'defaultDaysThreshold' => max(1, (int)($s['defaultDaysThreshold'] ?? self::DEFAULT_DAYS_THRESHOLD)),
            'checkContentElements' => (bool)(int)($s['checkContentElements'] ?? 1),
            'maxDepth' => max(0, (int)($s['maxDepth'] ?? 0)),
            'excludePageUids' => GeneralUtility::intExplode(',', (string)($s['excludePageUids'] ?? ''), true),
            'checkHiddenPages' => (bool)(int)($s['checkHiddenPages'] ?? 0),
        ];
    }

    protected function buildPageModuleUrl(int $pageUid): ?string
    {
        try {
            return (string)$this->backendUriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function buildReviewUrl(int $pageUid, string $languageTag): ?string
    {
        try {
            return (string)$this->backendUriBuilder->buildUriFromRoute(
                'ajax_bw_outdated_pages_mark_reviewed',
                ['pageUid' => $pageUid, 'languageTag' => $languageTag]
            );
        } catch (\Throwable $exception) {
            return null;
        }
    }
}
