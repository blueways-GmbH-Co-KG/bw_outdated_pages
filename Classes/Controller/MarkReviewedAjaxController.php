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

use Blueways\BwOutdatedPages\Domain\Repository\ReviewRepository;
use Psr\Http\Message\ResponseInterface;
use Psr\Http\Message\ServerRequestInterface;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Http\JsonResponse;
use TYPO3\CMS\Core\Type\Bitmask\Permission;

/**
 * AJAX endpoint behind the "mark as reviewed" buttons in both the
 * dashboard widget and the backend module. Requires an authenticated
 * backend user (enforced by the ajax route registration) who also has
 * edit permission on the page in question.
 */
class MarkReviewedAjaxController
{
    public function __construct(
        private readonly ReviewRepository $reviewRepository,
    ) {}

    public function markReviewedAction(ServerRequestInterface $request): ResponseInterface
    {
        $queryParams = $request->getQueryParams();
        $pageUid = (int)($queryParams['pageUid'] ?? 0);
        $languageTag = trim((string)($queryParams['languageTag'] ?? ''));

        $backendUser = $GLOBALS['BE_USER'] ?? null;

        if ($pageUid <= 0 || $languageTag === '' || $backendUser === null) {
            return new JsonResponse(['success' => false], 400);
        }

        $pageRecord = BackendUtility::getRecord('pages', $pageUid);
        $hasAccess = $pageRecord !== null
            && ($backendUser->isAdmin() || $backendUser->isInWebMount($pageUid))
            && $backendUser->doesUserHaveAccess($pageRecord, Permission::PAGE_EDIT);

        if (!$hasAccess) {
            return new JsonResponse(['success' => false], 403);
        }

        $this->reviewRepository->markReviewed($pageUid, $languageTag, (int)$backendUser->getUserId());

        return new JsonResponse(['success' => true]);
    }
}
