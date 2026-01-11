# backgroundMail v1.0.0

Release: 1.0.0.0 (2026-01-08)

Initial release: Asynchronous announcement email delivery via scheduled task.

Highlights

- Adds a file-backed per-recipient queue and a scheduled task to deliver announcement emails in the background.
- Provides a settings modal with a one-click patch helper to modify the OJS core announcement handler (creates a backup).
- Single-use patch token and backup created by the helper for safety.

Important notes

- This plugin requires applying a mandatory core change to `lib/pkp/api/v1/announcements/PKPAnnouncementHandler.inc.php`. The plugin includes a standalone patch helper at `tools/patchPKPAnnouncementHandler.php` which creates a backup file when run.
- Ensure the webserver/PHP user has write permissions for the plugin folder and for creating the backup in the core `lib/pkp/...` path before running the patch.
- Test staged sends (5 → 100 → 500 → 2000) and verify SMTP provider sending limits before full production run.

Files added/changed (high level)

- `AnnouncementQueueDAO.inc.php` — file-backed queue storage
- `BackgroundMailScheduledTask.inc.php` + `scheduledTasks.xml` — scheduled task processing
- `BackgroundMailPlugin.inc.php` — main plugin class and token generation
- `templates/settingsForm.tpl` — settings modal + patch UI
- `tools/patchPKPAnnouncementHandler.php` — standalone patch helper (single-use token)

Rollout checklist

1. Backup OJS code and DB.
2. Ensure patch helper can write the `PKPAnnouncementHandler.inc.phpBACKUP` file.
3. Run small staged tests and monitor delivery and server load.
4. Monitor mailserver quotas and bounce/spam reports; enable SPF/DKIM/DMARC.

For full release entry on GitHub: the repo already has tag `v1.0.0`. If you want me to create the GitHub Release on your behalf I can do that using the GitHub API if you provide a Personal Access Token with `repo` scope, or you can publish the release in the GitHub web UI.

— backgroundMail bot
