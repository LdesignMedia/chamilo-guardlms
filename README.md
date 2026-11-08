# GuardLMS for Chamilo

Connect a self-hosted Chamilo site to [GuardLMS](https://guardlms.com) for software inventory, vulnerability monitoring and opt-in configuration checks and error monitoring.

**Development alpha 0.1.0.** Compatibility targets are Chamilo **3.0.2 / PHP 8.3** and **1.11.42 / PHP 7.4**. The verification report records actual results; do not assume untested releases work.

## Features

- Complete native installed-plugin inventory, Chamilo/PHP/database/OS metadata and locally available Composer/npm dependencies.
- Connect, reconnect, disconnect, manual reporting, preview and daily CLI reporting.
- Site-bound credentials encrypted with AES-256-GCM, isolated by canonical access URL.
- Optional password/MFA/session/debug configuration observations and GuardLMS findings.
- Optional browser errors, page views and scroll analytics through the existing SDK.
- Optional PHP/SQL error observations with private bounded delivery queues and conservative redaction.

## Installation

Deploy the matching GuardLMS receiver support first. Build an installable ZIP with `scripts/package.sh` or download the CI artifact. Extract `guardlms/` into `public/plugin/` on Chamilo 3, or `plugin/` on Chamilo 1.11. Do not copy tests, Git metadata or development files into the LMS.

Create private storage as the PHP service account, outside the document root:

```sh
sudo install -d -m 0700 -o www-data -g www-data /var/lib/chamilo-guardlms
```

Set `CHAMILO_GUARDLMS_DATA` in the PHP/CLI environment if using another private directory. Install and enable GuardLMS with Chamilo's native plugin manager. Assign the `header_main` and `pre_footer` regions. On Chamilo 3 rebuild the Composer autoloader and clear the application cache after installing so its native plugin subscriber discovery sees the new class. These are normal application deployment commands; no core source changes are required.

Open `/plugin/guardlms/settings.php` as a platform administrator. Preview inventory, click **Connect to GuardLMS**, sign in and approve the site. The callback installs the keys and sends the initial inventory. Confirm **Last accepted inventory** before enabling optional features.

Use the canonical access URL including any subdirectory. Different access URLs have separate encrypted settings and credentials. Connect each URL independently. Copying the site to another URL does not reuse its stored connection.

## Daily reports

Run as the same service account that owns private storage:

```cron
17 * * * * www-data /usr/local/bin/php /path/to/chamilo/public/plugin/guardlms/cli/report.php --due
```

Use `/path/to/chamilo/plugin/guardlms/cli/report.php` on 1.11. The hourly invocation sends only when the previous accepted report is at least a day old. `--preview` prints inventory without sending; omitting `--due` sends immediately. Concurrent jobs cannot overlap. Failed requests preserve the last accepted reporting time.

## Optional monitoring

Each feature defaults to **off** and can be enabled independently in the administrator page. Monitoring also requires a connected site, active GuardLMS monitoring/subscription and an allowed domain. Page analytics requires an eligible plan. The SDK owns browser consent/privacy controls and sampling; this connector does not inject user or course identifiers.

For server errors, configure a trusted per-vhost canonical URL and PHP prepend file:

```ini
auto_prepend_file=/path/to/chamilo/public/plugin/guardlms/bootstrap.php
```

```text
CHAMILO_GUARDLMS_SITE=https://your-chamilo.example
CHAMILO_GUARDLMS_DATA=/var/lib/chamilo-guardlms
```

Use the equivalent non-public plugin path on 1.11. Configure environment variables in the web service and cron environment; never derive the canonical URL from a request header. On multi-URL deployments configure the matching URL per vhost; otherwise early capture is deliberately skipped for unmatched hosts. Restart PHP after configuration changes.

When server reporting is enabled, the prepend file routes native PHP/Chamilo diagnostics to a private per-site log. This allows importing handled Chamilo 1.11 SQL errors. Rotate these private logs through normal operations; they may contain sensitive unredacted application diagnostics locally. Only generic PHP/SQL messages, exception class, basename and line number are delivered. SQL statements, bound parameters, raw request data and exception arguments are withheld.

Delivery is best effort and at least once: up to 20 events per batch, 100 queued records, two-second transport timeout, and retry through cron. Duplicate events can occur after interrupted acknowledgements. Hard process termination, errors before PHP can execute the prepend file, custom handlers that suppress diagnostics, and disabled native logging can prevent capture. Monitoring never enables public debug output or changes error responses.

## Privacy and credential lifecycle

Inventory includes the canonical site URL, software and selected environment metadata. Learner/course records, plugin configuration dumps, account identifiers, database connection secrets and raw lockfiles are not shared. Security observations are an explicit allowlist and are omitted when unavailable.

Back up the installation key and encrypted per-site settings together, with private permissions. Losing the installation key requires reconnecting; do not replace it on a live installation. The inventory credential never appears in payload preview or browser HTML. The browser SDK credential is intentionally public and scoped through GuardLMS's SDK controls.

Disconnect attempts remote inventory/SDK credential revocation, then clears local credentials, pending callbacks, optional monitoring and queued events even if the network is unavailable. If remote revocation failed, revoke credentials in GuardLMS. Removing files alone does not revoke remote credentials.

Chamilo plugin update availability remains unknown without an authoritative version source. Chamilo plugins never use Moodle's catalog identities. An enabled MFA setting does not prove enforcement for every account or external identity provider.

## Development and verification

```sh
php tests/unit.php
find . -name '*.php' -not -path './vendor/*' -exec php -l {} \;
scripts/package.sh
```

The GuardLMS repository owns the real-runtime Docker/browser harness under `integrations/chamilo/docker`. See its README and verification report for exact runtimes, commands, completed checks and known gaps.

Licensed under GNU GPL v3 or later.
