<?php
// Ensure role constants are defined
if (!defined('ROLE_ID_MANAGER')) define('ROLE_ID_MANAGER', 16);
if (!defined('ROLE_ID_EDITOR')) define('ROLE_ID_EDITOR', 256);
/**
 * BackgroundMailScheduledTask.inc.php
 *
 * Scheduled task for processing queued announcement emails asynchronously.
 *
 * Copyright (c) 2026 Janevsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 */

require_once(__DIR__ . '/AnnouncementQueueDAO.inc.php');
import('lib.pkp.classes.scheduledTask.ScheduledTask');
            // ...existing code...

class BackgroundMailScheduledTask extends ScheduledTask {
    function __construct($args) {
        parent::__construct($args);
    }

    /**
     * @copydoc ScheduledTask::getName()
     */
    function getName() {
        return __('plugins.generic.backgroundMail.scheduledTask.name');
    }

    /**
     * @copydoc ScheduledTask::execute()
     */
    function executeActions() {
        $pluginPath = dirname(__FILE__);
        $queueDir = $pluginPath . '/queue';
        if (!is_dir($queueDir)) {
            return false;
        }
        if (!is_readable($queueDir)) {
            return false;
        }
        $scandirFiles = scandir($queueDir);
        $queueDao = new AnnouncementQueueDAO($queueDir);
        $userDao = DAORegistry::getDAO('UserDAO');
        $contextDao = Application::getContextDAO(); // returns JournalDAO in OJS
        $announcementDao = DAORegistry::getDAO('AnnouncementDAO');

        $queueFiles = $queueDao->getQueueItems(100); // batch size
        if (empty($queueFiles)) {
            return true;
        }
        $sentCount = 0;
        $skippedCount = 0;
        $announcementId = null;
        $contextId = null;
        $notified = false;
        $journalManagers = [];
        $journalEditors = [];

        $firstFile = $queueFiles[0];
        $firstEmailDataRaw = @file_get_contents($firstFile);
        if ($firstEmailDataRaw === false) {
            return false;
        }
        $firstEmailData = json_decode($firstEmailDataRaw, true);
        if (!is_array($firstEmailData)) {
            return false;
        }
        if (!isset($firstEmailData['announcementId']) || !isset($firstEmailData['contextId'])) {
            return false;
        }
        $announcementId = $firstEmailData['announcementId'];
        $contextId = $firstEmailData['contextId'];
        $context = $contextDao->getById($contextId);
        $announcement = $announcementDao->getById($announcementId);
        if (!$context) {
            return false;
        }
        if (!$announcement) {
            return false;
        }
        // Notify all users in context directly using MailTemplate (no AnnouncementNotificationManager)
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
        $notificationSubscriptionSettingsDao = DAORegistry::getDAO('NotificationSubscriptionSettingsDAO');
        $allUsers = $userGroupDao->getUsersByContextId($contextId);
        $notified = false;
        $notifiedCount = 0;
        import('lib.pkp.classes.mail.MailTemplate');
        while ($user = $allUsers->next()) {
            if ($user->getDisabled()) continue;
            $blockedEmails = $notificationSubscriptionSettingsDao->getNotificationSubscriptionSettings('blocked_emailed_notification', $user->getId(), $contextId);
            if (!in_array(NOTIFICATION_TYPE_NEW_ANNOUNCEMENT, $blockedEmails)) {
                $mail = new MailTemplate('ANNOUNCEMENT', null, $context, false);
                $mail->addRecipient($user->getEmail(), $user->getFullName());
                // Manually construct the announcement URL
                $baseUrl = $context->getData('url');
                $contextPath = $context->getData('path');
                $url = rtrim($baseUrl, '/') . '/' . $contextPath . '/announcement/view/' . $announcement->getId();
                $mail->assignParams([
                    'title' => htmlspecialchars($announcement->getLocalizedTitle()),
                    'summary' => PKPString::stripUnsafeHtml($announcement->getLocalizedDescriptionShort()),
                    'announcement' => PKPString::stripUnsafeHtml($announcement->getLocalizedDescription()),
                    'url' => $url,
                ]);
                $mailResult = $mail->send();
                $notified = true;
                $notifiedCount++;
            }
        }
                
                foreach ($queueFiles as $queueFile) {
                    try {
                        $raw = @file_get_contents($queueFile);
                        if ($raw === false) {
                            $queueDao->removeFromQueue($queueFile);
                            $skippedCount++;
                            continue;
                        }
                        $emailData = json_decode($raw, true);
                        if (!is_array($emailData)) {
                            $queueDao->removeFromQueue($queueFile);
                            $skippedCount++;
                            continue;
                        }
                        if (!isset($emailData['userId']) || !isset($emailData['contextId']) || !isset($emailData['announcementId'])) {
                            $queueDao->removeFromQueue($queueFile);
                            $skippedCount++;
                            continue;
                        }
                        $user = $userDao->getById($emailData['userId']);
                        if (!$user) {
                            $queueDao->removeFromQueue($queueFile);
                            $skippedCount++;
                            continue;
                        }
                        // Prepare and send email (no notificationEmailAnnouncements check)
                        import('lib.pkp.classes.mail.MailTemplate');
                        $context = $contextDao->getById($emailData['contextId']);
                        $announcement = $announcementDao->getById($emailData['announcementId']);
                        if (!$context || !$announcement) {
                            $queueDao->removeFromQueue($queueFile);
                            $skippedCount++;
                            continue;
                        }
                        $mail = new MailTemplate('ANNOUNCEMENT', null, $context, false);
                        $mail->addRecipient($user->getEmail(), $user->getFullName());
                        // Manually construct the announcement URL
                        $baseUrl = $context->getData('url');
                        $contextPath = $context->getData('path');
                        $url = rtrim($baseUrl, '/') . '/' . $contextPath . '/announcement/view/' . $announcement->getId();
                        $mail->assignParams([
                            'title' => htmlspecialchars($announcement->getLocalizedTitle()),
                            'summary' => PKPString::stripUnsafeHtml($announcement->getLocalizedDescriptionShort()),
                            'announcement' => PKPString::stripUnsafeHtml($announcement->getLocalizedDescription()),
                            'url' => $url,
                        ]);
                        $mailResult = $mail->send();
                        if ($mailResult) {
                            $queueDao->removeFromQueue($queueFile);
                            $sentCount++;
                        } else {
                        }
                    } catch (Exception $e) {
                    }
                }

        // Send end notification
        if ($notified && $context && $announcement) {
            $this->notifyManagersEditors($context, $announcement, $journalManagers, $journalEditors, 'end', $sentCount, $skippedCount);
        } else {
        }
        return true;
    }

    /**
     * Notify journal managers and editors when sending starts/ends
     */
    function notifyManagersEditors($context, $announcement, $managers, $editors, $phase, $sentCount, $skippedCount) {
        // Send summary email to all managers and editors
        $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
        $userDao = DAORegistry::getDAO('UserDAO');
        $managerGroups = $userGroupDao->getByRoleId($context->getId(), ROLE_ID_MANAGER)->toArray();
        $editorGroups = $userGroupDao->getByRoleId($context->getId(), ROLE_ID_EDITOR)->toArray();
        $allGroups = array_merge($managerGroups, $editorGroups);
        $recipients = array();
        foreach ($allGroups as $group) {
            $userGroupAssignments = $userDao->retrieve(
                'SELECT u.user_id, u.email, u.username FROM users u JOIN user_user_groups uug ON u.user_id = uug.user_id WHERE uug.user_group_id = ?',
                array((int)$group->getId())
            );
            while ($row = $userGroupAssignments->current()) {
                $email = $row->email;
                $name = $row->username;
                if ($email && !isset($recipients[$email])) {
                    $recipients[$email] = $name;
                }
                $userGroupAssignments->next();
            }
        }
        if (!empty($recipients)) {
            import('lib.pkp.classes.mail.MailTemplate');
            $mail = new MailTemplate(null, null, $context, false);
            $mail->setSubject('Announcement Email Summary');
            $mail->setBody(
                "Announcement email batch complete.\n\n" .
                "Announcement: " . $announcement->getLocalizedTitle() . "\n" .
                "Phase: " . $phase . "\n" .
                "Sent: " . $sentCount . "\n" .
                "Skipped: " . $skippedCount . "\n"
            );
            foreach ($recipients as $email => $name) {
                $mail->addRecipient($email, $name);
            }
            $mail->send();
        }
    }
}
