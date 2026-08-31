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

use Blueways\BwOutdatedPages\Service\PageAccessChecker;
use TYPO3\CMS\Backend\Routing\UriBuilder;
use TYPO3\CMS\Dashboard\Widgets\ListDataProviderInterface;

/**
 * Provides the items shown in the "Outdated pages" dashboard widget:
 * a live, clickable summary across every configured OutdatedPagesTask
 * instance, so editors can see (and jump straight to) outdated pages
 * without waiting for the next scheduled email. Each entry also has a
 * "mark as reviewed" button (handled by Resources/Public/JavaScript/
 * mark-reviewed.js via the ajax route registered in
 * Configuration/Backend/AjaxRoutes.php).
 *
 * Results are filtered through PageAccessChecker so an editor only ever
 * sees pages they could also reach via their own page tree/web mounts -
 * a scheduler task's entry point is independent of any single user's
 * permissions, so this filtering happens here rather than in the task
 * or the collector.
 *
 * Unlike the notification email (which runs in a CLI/cron context),
 * this runs inside a full backend request, so building proper links via
 * UriBuilder is safe here.
 */
class OutdatedPagesListDataProvider implements ListDataProviderInterface
{
    /**
     * Cap on how many entries are shown in the widget, so a large site
     * with many outdated pages doesn't turn the dashboard into an
     * unreadable wall of text - the notification emails remain the
     * complete, authoritative source.
     */
    protected const MAX_ITEMS = 20;

    public function __construct(
        private readonly OutdatedPagesCollector $collector,
        private readonly PageAccessChecker $pageAccessChecker,
        private readonly UriBuilder $uriBuilder,
    ) {}

    public function getItems(): array
    {
        $entries = $this->pageAccessChecker->filterAccessible($this->collector->collect());

        if ($entries === []) {
            return [$this->translate('widget.noResults')];
        }

        $items = [];
        foreach (array_slice($entries, 0, self::MAX_ITEMS) as $entry) {
            $items[] = $this->renderItem($entry);
        }

        if (count($entries) > self::MAX_ITEMS) {
            $items[] = sprintf(
                '<em>%s</em>',
                htmlspecialchars(sprintf(
                    $this->translate('widget.moreResults'),
                    count($entries) - self::MAX_ITEMS
                ))
            );
        }

        return $items;
    }

    /**
     * @param array{uid: int, title: string, tstamp: int, languageTag: string} $entry
     */
    protected function renderItem(array $entry): string
    {
        $title = $entry['title'] !== '' ? $entry['title'] : $this->translate('widget.untitled');
        $meta = sprintf(
            $this->translate('widget.itemMeta'),
            $entry['uid'],
            date('d.m.Y', $entry['tstamp'])
        );

        $inner = sprintf(
            '<span class="badge badge-secondary">%s</span> %s <span class="text-body-secondary">%s</span>',
            htmlspecialchars($entry['languageTag']),
            htmlspecialchars($title),
            htmlspecialchars($meta)
        );

        $editUrl = $this->buildPageModuleUrl($entry['uid']);
        $label = $editUrl !== null
            ? sprintf('<a href="%s" target="_top">%s</a>', htmlspecialchars($editUrl), $inner)
            : $inner;

        $reviewButton = '';
        $reviewUrl = $this->buildReviewUrl($entry['uid'], $entry['languageTag']);
        if ($reviewUrl !== null) {
            $reviewButton = sprintf(
                '<button type="button" class="btn btn-default btn-sm bw-mark-reviewed" data-url="%s">%s</button>',
                htmlspecialchars($reviewUrl),
                htmlspecialchars($this->translate('widget.markReviewed'))
            );
        }

        return sprintf(
            '<div class="bw-outdated-item d-flex justify-content-between align-items-center">'
            . '<div>%s</div>%s'
            . '</div>',
            $label,
            $reviewButton
        );
    }

    /**
     * Builds a link to open the page in the Page module (backend layout
     * view). Returns null if the URL cannot be built, so the widget still
     * renders (just without a link) instead of failing entirely.
     */
    protected function buildPageModuleUrl(int $pageUid): ?string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute('web_layout', ['id' => $pageUid]);
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function buildReviewUrl(int $pageUid, string $languageTag): ?string
    {
        try {
            return (string)$this->uriBuilder->buildUriFromRoute(
                'ajax_bw_outdated_pages_mark_reviewed',
                ['pageUid' => $pageUid, 'languageTag' => $languageTag]
            );
        } catch (\Throwable $exception) {
            return null;
        }
    }

    protected function translate(string $key): string
    {
        $languageService = $GLOBALS['LANG'] ?? null;
        if ($languageService === null) {
            return $key;
        }

        return $languageService->sL(
            'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:' . $key
        );
    }
}
