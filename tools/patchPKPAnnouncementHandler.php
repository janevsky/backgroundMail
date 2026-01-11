<?php
// Patch PKPAnnouncementHandler.inc.php for BackgroundMail plugin

// Log start of patch script
error_log('BackgroundMail patchPKPAnnouncementHandler.php: Script started');
header('Content-Type: application/json');

// Find OJS root by traversing up from plugin directory

// Compute plugin and OJS root paths
$pluginDir = dirname(__DIR__, 2); // expected to be plugins/generic
$pluginFolder = dirname(__DIR__); // actual plugin folder: plugins/generic/backgroundMail
$ojsRoot = dirname($pluginDir, 2); // OJS root
error_log('BackgroundMail patchPKPAnnouncementHandler.php: pluginFolder=' . $pluginFolder);
error_log('BackgroundMail patchPKPAnnouncementHandler.php: pluginDir=' . $pluginDir);
error_log('BackgroundMail patchPKPAnnouncementHandler.php: ojsRoot=' . $ojsRoot);


$coreFile = $ojsRoot . '/lib/pkp/api/v1/announcements/PKPAnnouncementHandler.inc.php';
$backupFile = $coreFile . 'BACKUP';
error_log('BackgroundMail patchPKPAnnouncementHandler.php: coreFile=' . $coreFile);
error_log('BackgroundMail patchPKPAnnouncementHandler.php: backupFile=' . $backupFile);


if (!file_exists($coreFile)) {
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: Core file not found: ' . $coreFile);
    echo json_encode(['status' => 'error', 'message' => 'Core file not found: ' . $coreFile]);
    exit;
}

// Validate token parameter for security
$providedToken = isset($_REQUEST['token']) ? trim($_REQUEST['token']) : '';
// Token file lives in the plugin folder
$tokenFile = $pluginFolder . '/.patch_token';
if (!file_exists($tokenFile)) {
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: token file missing');
    echo json_encode(['status' => 'error', 'message' => 'Authorization token missing on server']);
    exit;
}
$expectedToken = trim(@file_get_contents($tokenFile));
if (empty($providedToken) || $providedToken !== $expectedToken) {
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: invalid or missing token provided');
    echo json_encode(['status' => 'error', 'message' => 'Invalid authorization token']);
    exit;
}


// Read file
$contents = file_get_contents($coreFile);
if ($contents === false) {
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: Failed to read core file.');
    echo json_encode(['status' => 'error', 'message' => 'Failed to read core file.']);
    exit;
}


// Backup
if (!file_exists($backupFile)) {
    $backupResult = file_put_contents($backupFile, $contents);
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: Backup result=' . var_export($backupResult, true));
}

// Patch logic: Replace add() function body
$pattern = '/public function add\s*\(\$slimRequest, \$response, \$args\)\s*{(.*?)return \$response->withJson\(\$announcementProps, 200\);\s*}/s';
// Log the pattern
error_log('BackgroundMail patchPKPAnnouncementHandler.php: pattern=' . $pattern);
// Log the first 500 chars of the add() function for debugging
$addStart = strpos($contents, 'public function add($slimRequest, $response, $args) {');
if ($addStart !== false) {
    $snippet = substr($contents, $addStart, 500);
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: add() snippet=' . $snippet);
} else {
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: add() function not found in file.');
}
$replacement = <<<PATCH
public function add(\$slimRequest, \$response, \$args) {
    // PATCHED BY BackgroundMail
    \$request = \$this->getRequest();
    if (!\$request->getContext()) {
        throw new Exception('You can not add an announcement without sending a request to the API endpoint of a particular context.');
    }
    \$params = \$this->convertStringsToSchema(SCHEMA_ANNOUNCEMENT, \$slimRequest->getParsedBody());
    \$params['assocType'] = Application::get()->getContextAssocType();
    \$params['assocId'] = \$request->getContext()->getId();
    \$primaryLocale = \$request->getContext()->getPrimaryLocale();
    \$allowedLocales = \$request->getContext()->getSupportedFormLocales();
    \$errors = Services::get('announcement')->validate(VALIDATE_ACTION_ADD, \$params, \$allowedLocales, \$primaryLocale);
    if (!empty(\$errors)) {
        return \$response->withStatus(400)->withJson(\$errors);
    }
    \$announcement = DAORegistry::getDao('AnnouncementDAO')->newDataObject();
    \$announcement->setAllData(\$params);
    \$announcement = Services::get('announcement')->add(\$announcement, \$request);
    // Call plugin hook after announcement is created
    \$hookHandled = \HookRegistry::call('Announcement::add', array(\$announcement, \$request->getContext()));
    \$announcementProps = Services::get('announcement')->getFullProperties(\$announcement, [
        'request' => \$request,
        'announcementContext' => \$request->getContext(),
    ]);
    // Only send emails if the hook was NOT handled (plugin did not block)
    if (!\$hookHandled && filter_var(\$params['sendEmail'], FILTER_VALIDATE_BOOLEAN)) {
        import('lib.pkp.classes.notification.managerDelegate.AnnouncementNotificationManager');
        \$announcementNotificationManager = new AnnouncementNotificationManager(NOTIFICATION_TYPE_NEW_ANNOUNCEMENT);
        \$announcementNotificationManager->initialize(\$announcement);
        \$notificationSubscriptionSettingsDao = DAORegistry::getDAO('NotificationSubscriptionSettingsDAO');
        \$userGroupDao = DAORegistry::getDAO('UserGroupDAO');
        \$allUsers = \$userGroupDao->getUsersByContextId(\$request->getContext()->getId());
        while (\$user = \$allUsers->next()) {
            if (\$user->getDisabled()) continue;
            \$blockedEmails = \$notificationSubscriptionSettingsDao->getNotificationSubscriptionSettings('blocked_emailed_notification', \$user->getId(), \$request->getContext()->getId());
            if (!in_array(NOTIFICATION_TYPE_NEW_ANNOUNCEMENT, \$blockedEmails)) {
                \$announcementNotificationManager->notify(\$user);
            }
        }
    }
    return \$response->withJson(\$announcementProps, 200);
}
PATCH;


$newContents = preg_replace($pattern, $replacement, $contents, 1);
if ($newContents === null) {
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: Failed to patch core file (preg_replace returned null).');
    echo json_encode(['status' => 'error', 'message' => 'Failed to patch core file.']);
    exit;
}


// Write patched file
$writeResult = file_put_contents($coreFile, $newContents);
error_log('BackgroundMail patchPKPAnnouncementHandler.php: Write result=' . var_export($writeResult, true));
if ($writeResult === false) {
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: Failed to write patched file.');
    echo json_encode(['status' => 'error', 'message' => 'Failed to write patched file.']);
    exit;
}
echo json_encode(['status' => 'success', 'message' => 'PKPAnnouncementHandler.inc.php patched successfully. Backup saved as PKPAnnouncementHandler.inc.phpBACKUP.']);
error_log('BackgroundMail patchPKPAnnouncementHandler.php: Patch completed successfully.');
// Revoke single-use token
if (file_exists($tokenFile)) {
    $unlinkResult = @unlink($tokenFile);
    error_log('BackgroundMail patchPKPAnnouncementHandler.php: token revoke result=' . var_export($unlinkResult, true));
}
