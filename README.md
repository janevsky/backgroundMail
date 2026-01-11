# BackgroundMail

BackgroundMail enables asynchronous delivery of OJS announcement emails by queuing announcement deliveries and sending them via a scheduled task. This avoids PHP timeout and memory issues when sending announcements to large recipient lists and allows plugins to intercept announcement creation.

## Purpose

- Prevent synchronous sending of announcement emails from the OJS core.
- Offload delivery to a scheduled task that processes recipients in batches.
- Provide a small, auditable core patch so plugins can intercept announcement creation and handle delivery themselves.

## Features

- Queue announcement emails on creation instead of sending synchronously.
- Process the queue in configurable batches via an OJS scheduled task.
- Includes a one-click patch helper (with single-use token) to apply the required small core change.

## Requirements

- OJS 3.3.x (tested) — confirm compatibility for other versions before use.
- Web server user must have write permissions to the OJS installation when using the automatic patch helper (so backup and patch can be written).

## Installation

There are two supported installation methods:

- Plugin gallery upload: create a release tar.gz from this repository and upload it in OJS plugin gallery UI.
- Manual install: copy the `backgroundMail` directory into `plugins/generic/` of your OJS installation.

After installing the plugin (either method):

1. Enable the plugin in OJS Admin → Plugins → Generic.
2. Run the core patch helper (mandatory) — see "Core patch" below.
3. Ensure scheduled tasks are enabled and running (see OJS scheduled tasks / Acron notes below).

IMPORTANT: The core patch is mandatory for BackgroundMail to intercept announcement delivery. Apply it immediately after enabling the plugin.

## Core patch (what the patch does and why)

BackgroundMail depends on a small, explicit change to the OJS announcement handler so plugins can prevent core synchronous emailing and handle delivery themselves. The patcher makes two changes:

1. After an announcement is created in `add()`, the core will call a hook: `Announcement::add`.
2. Core email-sending is made conditional on the hook result: if a plugin reports the hook handled the event, core will skip sending emails.

This allows BackgroundMail to queue the emails and the scheduled task to perform delivery later.

### Exact code change (summary)

In `lib/pkp/api/v1/announcements/PKPAnnouncementHandler.inc.php` the `add()` function is modified.

Original (simplified):

```php
public function add($slimRequest, $response, $args) {
	// ... validate and create the announcement object
	$announcement = // created announcement

	// core sends emails synchronously here
	if (filter_var($params['sendEmail'], FILTER_VALIDATE_BOOLEAN)) {
		// build recipients and send emails
	}

	return $response->withJson($announcementProps, 200);
}
```

Patched (applied by `tools/patchPKPAnnouncementHandler.php`):

```php
public function add($slimRequest, $response, $args) {
	// ... validate and create the announcement object
	$announcement = // created announcement

	// allow plugins to intercept announcement handling
	$hookHandled = \HookRegistry::call('Announcement::add', array($announcement, $request->getContext()));

	// send emails only if hook did not handle delivery and sendEmail flag is true
	if (!$hookHandled && filter_var($params['sendEmail'], FILTER_VALIDATE_BOOLEAN)) {
		// build recipients and send emails
	}

	return $response->withJson($announcementProps, 200);
}
```

Notes:

- The patcher replaces the `add()` function body so the hook call and conditional check are inserted in the correct place.
- The hook call signature is `HookRegistry::call('Announcement::add', array($announcement, $request->getContext()))` and returns a boolean indicating whether a plugin handled the event.

## Patcher helper (mandatory)

- Location: `plugins/generic/backgroundMail/tools/patchPKPAnnouncementHandler.php`
- Purpose: safely apply the core change described above.
- Behavior:
  - Generates and validates a single-use token for patch requests.
  - Creates a backup of the original core file: `PKPAnnouncementHandler.inc.phpBACKUP`.
  - Applies the patch (via a regex replace targeted at the `add()` function) and writes the patched file.
  - Revokes the single-use token on successful patch.

Usage: After enabling the plugin open the plugin settings modal and click the "Apply core compatibility patch" button (or open the script URL in a browser). The helper will return JSON and write debug entries to the webserver error log with prefix `BackgroundMail patchPKPAnnouncementHandler.php:`.

Security notes:

- The patch helper requires file write permissions and uses a single-use token stored in the plugin folder; the token is revoked after a successful run.
- Keep backups of your OJS installation and confirm the backup file `PKPAnnouncementHandler.inc.phpBACKUP` exists before proceeding to production.

## Scheduled tasks and Acron

- OJS uses Acron (or your platform's scheduler) to poll for scheduled tasks. The Acron plugin typically checks for scheduled tasks every 1 hour by default.
- When Acron picks up tasks, BackgroundMail's scheduled task will run immediately if pending.
- Ensure scheduled tasks are enabled and running; otherwise queued emails will not be delivered. See OJS docs for `tools/runScheduledTasks.php` for manual or cron-driven execution.

Example (system cron to run every 5 minutes, optional):

```bash
# Run scheduled tasks every 5 minutes (Linux cron example)
*/5 * * * * /usr/bin/php /path/to/ojs/tools/runScheduledTasks.php
```

Note: If you rely on the OJS Acron plugin (web-driven polling), it checks approximately every 1 hour and will execute BackgroundMail's task when it next polls.

## Troubleshooting

- If the patch helper fails, check webserver `error.log` entries prefixed with `BackgroundMail patchPKPAnnouncementHandler.php:` for diagnostics.
- If you see permission errors, ensure the web server user can write to `lib/pkp/api/v1/announcements/` and to `plugins/generic/backgroundMail/` for token handling.
- If the patch was applied but the plugin is not queuing emails, verify the `HookRegistry::call` line exists in `PKPAnnouncementHandler.inc.php` and that the token was used only once (the helper revokes the token on success).

## Files and where to look

- `plugins/generic/backgroundMail/` — the plugin root.
- `plugins/generic/backgroundMail/tools/patchPKPAnnouncementHandler.php` — core patch helper.
- `lib/pkp/api/v1/announcements/PKPAnnouncementHandler.inc.php` — core file patched by the helper (backup created at `...BACKUP`).
- `plugins/generic/backgroundMail/BackgroundMailScheduledTask.inc.php` — scheduled task worker.
- `plugins/generic/backgroundMail/AnnouncementQueueDAO.inc.php` — queue storage.

## License

GNU GPL v3

## Contributing

Create issues or PRs against the repository. Include OJS version, PHP version, and any `error.log` output when reporting issues.
