<?php
require_once(__DIR__ . '/AnnouncementQueueDAO.inc.php');
/**
 * BackgroundMailPlugin.inc.php
 *
 * Main plugin class for BackgroundMail.
 *
 * Copyright (c) 2024 Your Name
 * Distributed under the GNU GPL v3. For full terms see the file docs/COPYING.
 */

import('lib.pkp.classes.plugins.GenericPlugin');

class BackgroundMailPlugin extends GenericPlugin {
            // gateway handler is not required; patch helper is invoked via the plugin settings modal
        /**
         * Add management actions for plugin settings UI
         */
        function getActions($request, $verb) {
            import('lib.pkp.classes.linkAction.LinkAction');
            import('lib.pkp.classes.linkAction.request.AjaxModal');
            $router = $request->getRouter();
            $actions = array_merge(
                $this->getEnabled() ? array(
                    new LinkAction(
                        'settings',
                        new AjaxModal(
                            $router->url($request, null, null, 'manage', null, array('verb' => 'settings', 'plugin' => $this->getName(), 'category' => 'generic')),
                            $this->getDisplayName()
                        ),
                        __('manager.plugins.settings'),
                        null
                    ),
                ) : array(),
                parent::getActions($request, $verb)
            );
            return $actions;
        }
    /**
     * @copydoc Plugin::register()
     */
    function register($category, $path, $mainContextId = null) {
        $success = parent::register($category, $path, $mainContextId);
        if ($success && $this->getEnabled()) {
            // Register Acron scheduled task hook
            HookRegistry::register('AcronPlugin::parseCronTab', array($this, 'callbackParseCronTab'));
            // Try both after and main add hooks for API announcement creation
            HookRegistry::register('API::announcement::add::after', array($this, 'queueAnnouncementEmailsApi'));
            HookRegistry::register('API::announcement::add', array($this, 'queueAnnouncementEmailsApi'));
            // Register hook for announcement creation
            HookRegistry::register('Announcement::add', array($this, 'handleAnnouncementAdd'));
        }
        return $success;
    }
    /**
     * @copydoc Plugin::manage()
     */
    function manage($args, $request) {
        switch ($request->getUserVar('verb')) {
            case 'settings':
                $context = $request->getContext();
                AppLocale::requireComponents(LOCALE_COMPONENT_APP_COMMON, LOCALE_COMPONENT_PKP_MANAGER);
                $templateMgr = TemplateManager::getManager($request);
                $templateMgr->register_function('plugin_url', array($this, 'smartyPluginUrl'));
                // Add patch button to modal
                $patchResult = null;
                // Ensure a token exists for secure AJAX calls to the tool script
                $tokenFile = $this->getPluginPath() . '/.patch_token';
                if (!file_exists($tokenFile)) {
                    $token = bin2hex(random_bytes(16));
                    @file_put_contents($tokenFile, $token);
                } else {
                    $token = @file_get_contents($tokenFile);
                }
                $templateMgr->assign('patchResult', $patchResult);
                $templateMgr->assign('patchButtonLabel', __('plugins.generic.backgroundMail.patchPKPAnnouncementHandler'));
                // Provide an absolute URL for the patch script so environments
                // with different base paths (e.g. not '/ojs') work correctly.
                $patchScriptUrl = $request->getBaseUrl() . '/plugins/generic/backgroundMail/tools/patchPKPAnnouncementHandler.php';
                $templateMgr->assign('patchButtonLabel', __('plugins.generic.backgroundMail.patchPKPAnnouncementHandler'));
                $templateMgr->assign('patchToken', $token);
                $templateMgr->assign('patchScriptUrl', $patchScriptUrl);
                // Also provide an index.php routed fallback URL for environments
                // where direct file access is rewritten.
                $patchScriptIndexUrl = $request->getBaseUrl() . '/index.php/plugins/generic/backgroundMail/tools/patchPKPAnnouncementHandler.php';
                $templateMgr->assign('patchScriptIndexUrl', $patchScriptIndexUrl);
                return new JSONMessage(true, $templateMgr->fetch($this->getTemplateResource('settingsForm.tpl')));
        }
        return parent::manage($args, $request);
    }

    /**
     * Register scheduledTasks.xml for Acron
     */
    function callbackParseCronTab($hookName, $args) {
        $taskFilesPath =& $args[0];
        $taskFilesPath[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }
    /**
     * Handle Announcement::add hook
     * @param $hookName string
     * @param $args array
     */
    function handleAnnouncementAdd($hookName, $args) {
        $announcement = $args[0];
        $context = $args[1];
        // TODO: Add logic to queue background email delivery here
        // Example logging
        if ($context && method_exists($context, 'getId')) {
        } else {
            $contextType = is_object($context) ? get_class($context) : gettype($context);
        }
        // Only queue emails, do not send immediately
        // Block core email sending by returning true
        // Use UserGroupDAO to fetch all users in context, matching OJS core logic
        if ($announcement && $context && method_exists($context, 'getId')) {
            $userGroupDao = DAORegistry::getDAO('UserGroupDAO');
            $queueDao = new AnnouncementQueueDAO($this->getPluginPath() . '/queue');
            $notificationSubscriptionSettingsDao = DAORegistry::getDAO('NotificationSubscriptionSettingsDAO');
            $usersResult = $userGroupDao->getUsersByContextId($context->getId());
            $queuedCount = 0;
            $userCount = 0;
            while ($user = $usersResult->next()) {
                $userCount++;
                $userId = $user->getId();
                $userEmail = $user->getEmail();
                $isDisabled = $user->getDisabled();
                $blockedEmails = $notificationSubscriptionSettingsDao->getNotificationSubscriptionSettings('blocked_emailed_notification', $userId, $context->getId());
                $isBlocked = in_array(NOTIFICATION_TYPE_NEW_ANNOUNCEMENT, $blockedEmails);
                if ($isDisabled) {
                    continue;
                }
                if (!$isBlocked) {
                    $emailData = [
                        'announcementId' => $announcement->getId(),
                        'userId' => $userId,
                        'contextId' => $context->getId()
                    ];
                    $result = $queueDao->addToQueue($announcement->getId(), $userId, $emailData);
                    if ($result === false) {
                    } else {
                        $queuedCount++;
                    }
                } else {
                }
            }
        } else {
        }
        return true; // Block core email sending
    }

    /**
     * Queue emails for announcement via API add (after creation)
     */
    function queueAnnouncementEmailsApi($hookName, $args) {
        $announcement = $args[0];
        $request = Application::get()->getRequest();
        $context = $request->getContext();
        if (!$announcement || !$context) {
            return false;
        }
        $userDao = DAORegistry::getDAO('UserDAO');
        $queueDao = new AnnouncementQueueDAO($this->getPluginPath() . '/queue');
        // Get all users in context
        $usersResult = $userDao->getUsersByContextId($context->getId());
        foreach ($usersResult as $user) {
            // Check user settings for announcement email opt-out
            $receiveAnnouncements = $user->getSetting('notificationEmailAnnouncements', $context->getId());
            if ($receiveAnnouncements) {
                $emailData = [
                    'announcementId' => $announcement->getId(),
                    'userId' => $user->getId(),
                    'contextId' => $context->getId()
                ];
                $queueDao->addToQueue($announcement->getId(), $user->getId(), $emailData);
            }
        }
        // Returning false allows the core to continue, but core email sending should be disabled in config or overridden
        return false;
    }
    /**
     * Provide the path to the scheduled task XML for this plugin.
     */
    function getTaskPath($hookName, $args) {
        $taskPaths =& $args[0];
        $taskPaths[] = $this->getPluginPath() . '/scheduledTasks.xml';
        return false;
    }

    /**
     * @copydoc Plugin::getDisplayName()
     */
    function getDisplayName() {
        return __('plugins.generic.backgroundMail.displayName');
    }

    /**
     * @copydoc Plugin::getDescription()
     */
    function getDescription() {
        return __('plugins.generic.backgroundMail.description');
    }
}
