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

namespace Blueways\BwOutdatedPages\Domain\Repository;

use TYPO3\CMS\Core\Database\Connection;
use TYPO3\CMS\Core\Database\ConnectionPool;
use TYPO3\CMS\Core\Database\Query\Restriction\DeletedRestriction;
use TYPO3\CMS\Core\Utility\GeneralUtility;

/**
 * Stores and reads "reviewed" confirmations: an editor confirming that a
 * given page/language, although outdated by tstamp, has been checked and
 * is fine as-is. A confirmation is treated exactly like a fresh change
 * (see OutdatedPagesFinder) and therefore naturally "expires" again once
 * the configured threshold has passed - no separate expiry field needed,
 * and no page is silenced forever by accident.
 */
class ReviewRepository
{
    protected const TABLE_NAME = 'tx_bwoutdatedpages_review';

    public function __construct(
        private readonly ConnectionPool $connectionPool,
    ) {}

    public function markReviewed(int $pageUid, string $languageTag, int $backendUserUid): void
    {
        $now = time();

        $this->connectionPool->getConnectionForTable(self::TABLE_NAME)->insert(
            self::TABLE_NAME,
            [
                'pid' => 0,
                'tstamp' => $now,
                'crdate' => $now,
                'page_uid' => $pageUid,
                'language_tag' => $languageTag,
                'reviewed_at' => $now,
                'reviewed_by' => $backendUserUid,
            ]
        );
    }

    /**
     * @param int[] $pageUids
     * @return array<int, array<string, int>> [pageUid][languageTag] => most recent reviewed_at
     */
    public function findLatestReviews(array $pageUids): array
    {
        if ($pageUids === []) {
            return [];
        }

        $queryBuilder = $this->connectionPool->getQueryBuilderForTable(self::TABLE_NAME);
        $queryBuilder->getRestrictions()
            ->removeAll()
            ->add(GeneralUtility::makeInstance(DeletedRestriction::class));

        $rows = $queryBuilder
            ->selectLiteral('page_uid', 'language_tag', 'MAX(reviewed_at) AS latest')
            ->from(self::TABLE_NAME)
            ->where(
                $queryBuilder->expr()->in(
                    'page_uid',
                    $queryBuilder->createNamedParameter($pageUids, Connection::PARAM_INT_ARRAY)
                )
            )
            ->groupBy('page_uid', 'language_tag')
            ->executeQuery()
            ->fetchAllAssociative();

        $result = [];
        foreach ($rows as $row) {
            $result[(int)$row['page_uid']][(string)$row['language_tag']] = (int)$row['latest'];
        }

        return $result;
    }
}
