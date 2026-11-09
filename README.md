# GuardLMS for Chamilo

Connect a self-hosted Chamilo site to [GuardLMS](https://guardlms.com) for software inventory, vulnerability monitoring and opt-in configuration checks and error monitoring.

**Development alpha 0.1.0.** Compatibility targets are Chamilo **3.0.2 / PHP 8.3** and **1.11.42 / PHP 7.4**. The [verification report](https://github.com/LdesignMedia/chamilo-guardlms/blob/79a15157e74d6231acea1c0fd30b452f159f8ed7/verification/VERIFICATION.md) records actual results; do not assume untested releases work.

## Features

- Complete native installed-plugin inventory, Chamilo/PHP/database/OS metadata and locally available Composer/npm dependencies.
- Connect, reconnect, disconnect, manual reporting, preview and daily CLI reporting.
- Site-bound credentials encrypted with AES-256-GCM, isolated by canonical access URL.
- Optional MFA/session/debug configuration observations and GuardLMS findings.
- Optional browser errors, page views and scroll analytics through the existing SDK.
- Optional PHP/SQL error observations with private bounded delivery queues and conservative redaction.

## Installation

Deploy the matching GuardLMS receiver support first. Build an installable ZIP with `scripts/package.sh` or download the CI artifact. Extract `guardlms/` into `public/plugin/` on Chamilo 3, or `plugin/` on Chamilo 1.11. Do not copy tests, Git metadata or development files into the LMS.

Create private storage as the PHP service account, outside the document root:

```sh
sudo install -d -m 0700 -o www-data -g www-data /var/lib/chamilo-guardlms
```

Set `CHAMILO_GUARDLMS_DATA` in the PHP/CLI environment if using another private directory. Install and enable GuardLMS with Chamilo's native plugin manager. Assign `pre_footer` on Chamilo 3; its native response subscriber supplies the document-head integration. On Chamilo 1.11 assign both `header_main` and `pre_footer`. On Chamilo 3 rebuild the Composer autoloader and clear the application cache after installing so its native plugin subscriber discovery sees the new class. These are normal application deployment commands; no core source changes are required.

Open `/plugin/guardlms/settings.php` as a platform administrator. Preview inventory, click **Connect to GuardLMS**, sign in and approve the site. The callback installs the keys and sends the initial inventory. Confirm **Last accepted inventory** before enabling optional features.

Use the canonical access URL including any subdirectory. Different access URLs have separate encrypted settings and credentials. Connect each URL independently. Changing the configured canonical access URL selects separate connection storage; update the native URL configuration when cloning a site.

## Daily reports

Run as the same service account that owns private storage:

```cron
17 * * * * www-data /usr/local/bin/php /path/to/chamilo/public/plugin/guardlms/cli/report.php --due
```

Use `/path/to/chamilo/plugin/guardlms/cli/report.php` on 1.11. The hourly invocation sends only when the previous accepted report is at least a day old. `--preview` prints inventory without sending; omitting `--due` sends immediately. For multiple access URLs, schedule one invocation per connected URL with `--site=https://registered-portal.example`. Only active native registry entries are accepted. Selected secondary URLs report their own plugin enabled state and encrypted credentials; native security settings whose portal context cannot be established in CLI are omitted. Send a manual web report to refresh those observations. Concurrent jobs cannot overlap. Failed requests and ignored stale snapshots preserve the last accepted reporting time. A stale snapshot displays a clock/retry error rather than claiming a new successful report.

## Optional monitoring

Each feature defaults to **off** and can be enabled independently in the administrator page. Monitoring also requires a connected site, active GuardLMS monitoring/subscription and an allowed domain. Page analytics requires an eligible plan. CLI reports omit web-runtime debug and session-cookie observations because their ini values can differ from the web service; use a manual web report for those observations. The SDK owns browser consent/privacy controls and sampling; this connector does not inject user or course identifiers.

For server errors, configure a trusted per-vhost canonical URL and PHP prepend file:

```ini
auto_prepend_file=/path/to/chamilo/public/plugin/guardlms/bootstrap.php
```

```text
CHAMILO_GUARDLMS_SITE=https://your-chamilo.example
CHAMILO_GUARDLMS_DATA=/var/lib/chamilo-guardlms
```

Use the equivalent non-public plugin path on 1.11. Configure environment variables in the web service and cron environment; never derive the canonical URL from a request header. On multi-URL deployments configure the matching URL per vhost; otherwise early capture is deliberately skipped for unmatched hosts. Restart PHP after configuration changes.

When server reporting is enabled, the prepend file routes native PHP/Chamilo diagnostics to a private per-site log. This allows importing handled Chamilo 1.11 SQL errors. Rotate these private logs through normal operations; they may contain sensitive unredacted application diagnostics locally. Only generic PHP/SQL messages, exception class, basename, line number and argument-free stack frames are delivered. SQL statements, bound parameters, raw request data and exception arguments are withheld.

Delivery is best effort and at least once: up to 20 events per batch, 100 queued records, two-second web transport timeout (15 seconds in CLI), and retry through cron. Duplicate events can occur after interrupted acknowledgements. Hard process termination, errors before PHP can execute the prepend file, custom handlers that suppress diagnostics, and disabled native logging can prevent capture. Monitoring never enables public debug output or changes error responses.

## Privacy and credential lifecycle

Inventory includes the canonical site URL, software and selected environment metadata. Learner/course records, plugin configuration dumps, account identifiers, database connection secrets and raw lockfiles are not shared. Security observations are an explicit allowlist and are omitted when unavailable.

Back up the installation key and encrypted per-site settings together, with private permissions. Losing the installation key requires reconnecting; do not replace it on a live installation. The inventory credential never appears in payload preview or browser HTML. The browser SDK credential is intentionally public and scoped through GuardLMS's SDK controls.

Disconnect attempts remote inventory/SDK credential revocation, then clears local credentials, pending callbacks, optional monitoring and queued events even if the network is unavailable. If remote revocation failed, revoke credentials in GuardLMS. Before deleting plugin files, disconnect and remove the `auto_prepend_file` configuration, then restart PHP. Leaving a prepend path pointing at a deleted file can prevent PHP requests from running. Removing files alone does not revoke remote credentials.

The password strength-meter setting is not evidence of server-side enforcement, so password-policy enforcement remains unknown. MFA availability and policy coverage for external identity providers also remain unknown when native evidence is absent.

Chamilo plugin update availability remains unknown without an authoritative version source. Chamilo plugins never use Moodle's catalog identities. Plugin vulnerability status also remains unknown until authoritative Chamilo mapping is available; supported core, runtime and dependency checks continue. An enabled MFA setting does not prove enforcement for every account or external identity provider.

## Development and verification

```sh
php tests/unit.php
php tests/monitor.php
php tests/observations.php
php tests/lifecycle.php
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
scripts/package.sh
```

The private GuardLMS repository owns the real-runtime Docker/browser harness under `integrations/chamilo/docker`. See its README and verification report for exact runtimes, commands, completed checks and known gaps.

Licensed under GNU GPL v3 or later.

Dependency names longer than the receiver database limit (120 characters) are omitted from optional dependency inventory. Distinct installed versions remain separate observations.
