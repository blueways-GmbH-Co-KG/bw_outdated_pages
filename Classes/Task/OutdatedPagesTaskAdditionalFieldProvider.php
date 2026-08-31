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

use TYPO3\CMS\Core\Utility\GeneralUtility;
use TYPO3\CMS\Scheduler\AdditionalFieldProviderInterface;
use TYPO3\CMS\Scheduler\Controller\SchedulerModuleController;
use TYPO3\CMS\Scheduler\Task\AbstractTask;

/**
 * Renders and validates the additional configuration fields for
 * OutdatedPagesTask in the scheduler backend module:
 * - entry page (branch of the page tree to check)
 * - threshold in days
 * - recipient email address(es)
 * - optional sender email/name
 */
class OutdatedPagesTaskAdditionalFieldProvider implements AdditionalFieldProviderInterface
{
    public function getAdditionalFields(array &$taskInfo, $task, SchedulerModuleController $schedulerModule): array
    {
        $isOutdatedPagesTask = $task instanceof OutdatedPagesTask;

        if (!isset($taskInfo['entryPageUid'])) {
            $taskInfo['entryPageUid'] = $isOutdatedPagesTask ? $task->getEntryPageUid() : 0;
        }
        if (!isset($taskInfo['daysThreshold'])) {
            $taskInfo['daysThreshold'] = $isOutdatedPagesTask ? $task->getDaysThreshold() : 180;
        }
        if (!isset($taskInfo['recipientEmails'])) {
            $taskInfo['recipientEmails'] = $isOutdatedPagesTask ? $task->getRecipientEmails() : '';
        }
        if (!isset($taskInfo['senderEmail'])) {
            $taskInfo['senderEmail'] = $isOutdatedPagesTask ? $task->getSenderEmail() : '';
        }
        if (!isset($taskInfo['senderName'])) {
            $taskInfo['senderName'] = $isOutdatedPagesTask ? $task->getSenderName() : '';
        }
        if (!isset($taskInfo['emailSubject'])) {
            $taskInfo['emailSubject'] = $isOutdatedPagesTask ? $task->getEmailSubject() : '';
        }

        return [
            'entryPageUid' => [
                'code' => '<input type="number" min="0" class="form-control" '
                    . 'name="tx_scheduler[entryPageUid]" id="entryPageUid" value="'
                    . (int)$taskInfo['entryPageUid'] . '" />',
                'label' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:field.entryPageUid',
            ],
            'daysThreshold' => [
                'code' => '<input type="number" min="1" class="form-control" '
                    . 'name="tx_scheduler[daysThreshold]" id="daysThreshold" value="'
                    . (int)$taskInfo['daysThreshold'] . '" />',
                'label' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:field.daysThreshold',
            ],
            'recipientEmails' => [
                'code' => '<input type="text" class="form-control" '
                    . 'name="tx_scheduler[recipientEmails]" id="recipientEmails" value="'
                    . htmlspecialchars((string)$taskInfo['recipientEmails'])
                    . '" placeholder="editor1@example.com, editor2@example.com" />',
                'label' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:field.recipientEmails',
            ],
            'senderEmail' => [
                'code' => '<input type="text" class="form-control" '
                    . 'name="tx_scheduler[senderEmail]" id="senderEmail" value="'
                    . htmlspecialchars((string)$taskInfo['senderEmail'])
                    . '" placeholder="optional, falls back to system default" />',
                'label' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:field.senderEmail',
            ],
            'senderName' => [
                'code' => '<input type="text" class="form-control" '
                    . 'name="tx_scheduler[senderName]" id="senderName" value="'
                    . htmlspecialchars((string)$taskInfo['senderName'])
                    . '" placeholder="optional" />',
                'label' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:field.senderName',
            ],
            'emailSubject' => [
                'code' => '<input type="text" class="form-control" '
                    . 'name="tx_scheduler[emailSubject]" id="emailSubject" value="'
                    . htmlspecialchars((string)$taskInfo['emailSubject'])
                    . '" placeholder="optional, {count} wird durch die Anzahl ersetzt" />',
                'label' => 'LLL:EXT:bw_outdated_pages/Resources/Private/Language/locallang.xlf:field.emailSubject',
            ],
        ];
    }

    public function validateAdditionalFields(array &$submittedData, SchedulerModuleController $schedulerModule): bool
    {
        $valid = true;

        $submittedData['entryPageUid'] = (int)($submittedData['entryPageUid'] ?? 0);
        if ($submittedData['entryPageUid'] <= 0) {
            $this->addFlashMessage('Please provide a valid entry page (page UID > 0).');
            $valid = false;
        }

        $submittedData['daysThreshold'] = (int)($submittedData['daysThreshold'] ?? 0);
        if ($submittedData['daysThreshold'] <= 0) {
            $this->addFlashMessage('The threshold in days must be greater than 0.');
            $valid = false;
        }

        $emails = GeneralUtility::trimExplode(',', (string)($submittedData['recipientEmails'] ?? ''), true);
        if ($emails === []) {
            $this->addFlashMessage('Please provide at least one recipient email address.');
            $valid = false;
        } else {
            foreach ($emails as $email) {
                if (!GeneralUtility::validEmail($email)) {
                    $this->addFlashMessage('Invalid recipient email address: ' . $email);
                    $valid = false;
                }
            }
        }

        $senderEmail = trim((string)($submittedData['senderEmail'] ?? ''));
        if ($senderEmail !== '' && !GeneralUtility::validEmail($senderEmail)) {
            $this->addFlashMessage('Invalid sender email address: ' . $senderEmail);
            $valid = false;
        }

        return $valid;
    }

    public function saveAdditionalFields(array $submittedData, AbstractTask $task): void
    {
        /** @var OutdatedPagesTask $task */
        $task->setEntryPageUid((int)$submittedData['entryPageUid']);
        $task->setDaysThreshold((int)$submittedData['daysThreshold']);
        $task->setRecipientEmails((string)$submittedData['recipientEmails']);
        $task->setSenderEmail((string)($submittedData['senderEmail'] ?? ''));
        $task->setSenderName((string)($submittedData['senderName'] ?? ''));
        $task->setEmailSubject((string)($submittedData['emailSubject'] ?? ''));
    }

    protected function addFlashMessage(string $message): void
    {
        $flashMessage = GeneralUtility::makeInstance(
            \TYPO3\CMS\Core\Messaging\FlashMessage::class,
            $message,
            '',
            \TYPO3\CMS\Core\Type\ContextualFeedbackSeverity::ERROR
        );
        $flashMessageService = GeneralUtility::makeInstance(\TYPO3\CMS\Core\Messaging\FlashMessageService::class);
        $flashMessageService->getMessageQueueByIdentifier()->addMessage($flashMessage);
    }
}
