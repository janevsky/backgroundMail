<?php
/**
 * AnnouncementQueueDAO.inc.php
 *
 * Data access object for announcement email queue.
 *
 * Copyright (c) 2026 janevsky https://github.com/janevsky
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 */

class AnnouncementQueueDAO {
    private $queueDir;

    function __construct($queueDir) {
        $this->queueDir = $queueDir;
        if (!file_exists($queueDir)) {
            mkdir($queueDir, 0777, true);
        }
    }

    function addToQueue($announcementId, $userId, $emailData) {
        $queueFile = $this->queueDir . "/queue_{$announcementId}_{$userId}.json";
        $result = @file_put_contents($queueFile, json_encode($emailData));
        if ($result === false) {
            error_log('[AnnouncementQueueDAO] ERROR: Failed to write queue file: ' . $queueFile);
            return false;
        }
        return true;
    }

    function getQueueItems($limit = 50) {
        $files = glob($this->queueDir . '/queue_*.json');
        return array_slice($files, 0, $limit);
    }

    function removeFromQueue($queueFile) {
        if (file_exists($queueFile)) {
            unlink($queueFile);
        }
    }
}
