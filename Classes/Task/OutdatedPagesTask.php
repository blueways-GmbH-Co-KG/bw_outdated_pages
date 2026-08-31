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

namespace Blueways\BwOutdatedPages\Task;

use Blueways\BwOutdatedPages\Service\OutdatedPagesFinder;
use Psr\Container\ContainerInterface;
use Symfony\Component\Mime\Address;
use TYPO3\CMS\Backend\Utility\BackendUtility;
use TYPO3\CMS\Core\Mail\MailMessage;
use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Fluid\View\StandaloneView;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Scheduler task that checks a configurable branch of the page tree for
 * pages that have not been edited for a configurable number of days and
 * sends a summary email to configurable recipients.
 *
 * Multiple instances of this task can be scheduled in parallel, each with
 * its own entry page, threshold and recipient(s) - e.g. one instance per
 * department / editor team responsible for a certain part of the site.
 *
 * The actual "find outdated pages" logic lives in OutdatedPagesFinder,
 * which is also used by the "Outdated pages" dashboard widget - keeping
 * this task class itself as small as possible, per TYPO3's own
 * recommendation for scheduler task design (task objects are serialized
 * into the database, so the class should change as little as possible).
 */
class OutdatedPagesTask extends AbstractTask
{
    protected int $entryPageUid = 0;

    protected int $daysThreshold = 180;

    protected string $recipientEmails = '';

    protected string $senderEmail = '';

    protected string $senderName = '';

    protected string $emailSubject = '';

    public function getEntryPageUid(): int
    {
        return $this->entryPageUid;
    }

    public function setEntryPageUid(int $entryPageUid): void
    {
        $this->entryPageUid = $entryPageUid;
    }

    public function getDaysThreshold(): int
    {
        return $this->daysThreshold;
    }

    public function setDaysThreshold(int $daysThreshold): void
    {
        $this->daysThreshold = $daysThreshold;
    }

    public function getRecipientEmails(): string
    {
        return $this->recipientEmails;
    }

    public function setRecipientEmails(string $recipientEmails): void
    {
        $this->recipientEmails = $recipientEmails;
    }

    public function getSenderEmail(): string
    {
        return $this->senderEmail;
    }

    public function setSenderEmail(string $senderEmail): void
    {
        $this->senderEmail = $senderEmail;
    }

    public function getSenderName(): string
    {
        return $this->senderName;
    }

    public function setSenderName(string $senderName): void
    {
        $this->senderName = $senderName;
    }

    public function getEmailSubject(): string
    {
        return $this->emailSubject;
    }

    public function setEmailSubject(string $emailSubject): void
    {
        $this->emailSubject = $emailSubject;
    }

    public function execute(): bool
    {
        if ($this->entryPageUid <= 0 || trim($this->recipientEmails) === '') {
            return false;
        }

        // Scheduler tasks are instantiated via unserialize, not the DI container,
        // so constructor injection is unavailable. The container is fetched
        // explicitly to resolve OutdatedPagesFinder's own dependencies correctly.
        $finder = GeneralUtility::makeInstance(ContainerInterface::class)->get(OutdatedPagesFinder::class);
        $finderSettings = $this->resolveFinderSettings();
        $outdatedPages = $finder->findOutdatedPages($this->entryPageUid, $this->daysThreshold, $finderSettings);

        // Nothing to report is a valid, successful outcome - the task
        // shouldn't be marked as failed just because everything is up to date.
        if ($outdatedPages === []) {
            return true;
        }

        return $this->sendNotification($outdatedPages);
    }

    /**
     * @return array{excludedDoktypes: int[], excludePagesUnderSysfolders: bool, checkContentElements: bool, maxDepth: int, excludePageUids: int[], checkHiddenPages: bool}
     */
    protected function resolveFinderSettings(): array
    {
        $tsconfig = $this->entryPageUid > 0 ? BackendUtility::getPagesTSconfig($this->entryPageUid) : [];
        $s = $tsconfig['module.']['tx_bw_outdated_pages.']['settings.'] ?? [];

        return [
            'excludedDoktypes' => GeneralUtility::intExplode(
                ',',
                (string)($s['excludedDoktypes'] ?? '3,4,6,7,199,254,255'),
                true
            ),
            'excludePagesUnderSysfolders' => (bool)(int)($s['excludePagesUnderSysfolders'] ?? 1),
            'checkContentElements' => (bool)(int)($s['checkContentElements'] ?? 1),
            'maxDepth' => max(0, (int)($s['maxDepth'] ?? 0)),
            'excludePageUids' => GeneralUtility::intExplode(',', (string)($s['excludePageUids'] ?? ''), true),
            'checkHiddenPages' => (bool)(int)($s['checkHiddenPages'] ?? 0),
        ];
    }

    /**
     * Shown in the scheduler task list to distinguish multiple instances
     * of this task from each other at a glance.
     */
    public function getAdditionalInformation(): string
    {
        return sprintf(
            'Page %d, after %d days, to: %s',
            $this->entryPageUid,
            $this->daysThreshold,
            $this->recipientEmails
        );
    }

    /**
     * @param array<int, array{uid: int, title: string, tstamp: int, languageTag: string}> $pages
     */
    protected function sendNotification(array $pages): bool
    {
        $recipients = GeneralUtility::trimExplode(',', $this->recipientEmails, true);

        if ($recipients === []) {
            return false;
        }

        $tsconfig = $this->entryPageUid > 0 ? BackendUtility::getPagesTSconfig($this->entryPageUid) : [];
        $emailSettings = $tsconfig['module.']['tx_bw_outdated_pages.']['email.'] ?? [];

        $templatePath = GeneralUtility::getFileAbsFileName(
            (string)($emailSettings['templatePath']
                ?? 'EXT:bw_outdated_pages/Resources/Private/Templates/Email/OutdatedPages.txt')
        );

        $templatePages = array_map(static function (array $page): array {
            return [
                'uid' => $page['uid'],
                'title' => $page['title'] !== '' ? $page['title'] : '(ohne Titel)',
                'languageTag' => $page['languageTag'],
                'date' => date('d.m.Y', $page['tstamp']),
            ];
        }, $pages);

        $view = GeneralUtility::makeInstance(StandaloneView::class);
        $view->setTemplatePathAndFilename($templatePath);
        $view->assignMultiple([
            'pages' => $templatePages,
            'daysThreshold' => $this->daysThreshold,
            'count' => count($pages),
        ]);
        $body = $view->render();

        $subjectTemplate = trim($this->emailSubject) !== ''
            ? $this->emailSubject
            : (string)($emailSettings['defaultSubject'] ?? '[TYPO3] {count} veraltete Seitenversion(en) gefunden');
        $subject = str_replace('{count}', (string)count($pages), $subjectTemplate);

        $mail = GeneralUtility::makeInstance(MailMessage::class);
        $mail->subject($subject)->text($body);

        if (trim($this->senderEmail) !== '') {
            $mail->from(new Address($this->senderEmail, $this->senderName));
        }

        $toAddresses = array_map(
            static fn (string $email): Address => new Address($email),
            $recipients
        );
        $mail->to(...$toAddresses);

        try {
            $mail->send();
        } catch (\Throwable $exception) {
            return false;
        }

        return true;
    }
}
