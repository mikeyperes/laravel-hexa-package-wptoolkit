# WP Toolkit integration

## Features

- Reuses protected WHM connections to inventory WordPress installations and their owning accounts.
- Provides WP-CLI reads and writes, plugin/theme operations, media and taxonomy operations, backups and exact-site login generation.
- Resolves native plugin-loaded WP-CLI commands under the installation owner and preserves a CLI PHP interpreter.
- Caches installation and native command metadata through the existing persistent state owner.
- Returns execution status and output to the shared WordPress package; callers retain their own action authorization and mutation checks.

## WordPress database campaigns

Use `php artisan wordpress-database:campaign {inventory|inspect|rotate|status} --server=<id> [--campaign=<uuid>] [--group=<id>] [--security-evidence=<sha256>] [--authorized]`.

- `inventory` starts with WP Toolkit, reconciles filesystem-discovered installs, inventories every cPanel database/user/grant/job, runs target-server database and HTTP baselines, and reports every mapped or unmapped database.
- `inspect` refreshes a saved campaign and proves its non-secret identity has not drifted.
- `rotate` changes one complete shared-user group only after explicit authorization and server-security evidence, proves the old login returns MySQL 1045, proves the new login plus `SELECT 1` and WordPress options, atomically publishes all canonical configs, and restores the whole group on any difference.
- `status` reads the private campaign record without storing or reconstructing credentials.

Progress is JSON Lines with stable server/site/database/group identifiers and immediate full per-item terminal records. Reports are written as private JSON and spreadsheet-safe CSV under `storage/app/private/wordpress-database-campaigns`. Passwords remain only in process memory, cPanel's protected POST body, the authenticated SSH input channel, and canonical wp-config files.

## Local execution

On a same-host application, the existing noninteractive privilege bridge executes local Toolkit commands when available. Runtime probes use that same context, so root-only vendor launchers do not force an unnecessary SSH fallback. Native commands reuse the installation-owner and CLI-PHP resolver, verifying the local application PHP installation before shell PATH and caching only commands with an explicit CLI interpreter; failed author commands are never cached as empty success. No vendor permissions or stored SSH credentials are changed; an unavailable bridge or failed probe remains a failure.

## Native installation paths

Toolkit versions may return fullPath or a home-relative path from --info. Native evaluation uses fullPath when supplied; otherwise it resolves the exact installation ID through the existing full-inventory parser, which returns the absolute installation path. A relative /public_html value is never treated as a global filesystem root. Unknown IDs fail rather than borrowing another installation's path.

## Tests

The focused NativeInstallPathTest and NativeWpCliCacheTest run through the consuming Laravel app's PHPUnit runner. They use fixtures and issue no site writes or emails.

### Per-installation reporting guard

Every inspected installation immediately emits its complete safe report with cPanel account, site/configuration identity, database and HTTP checks, change state, old/new-login fields, recovery, and context. Missing account/identity/report sections stop the runner. Dependency reconciliation updates reports later. Grant inventory resolves each attached user/database pair through cPanel. Inventory does not change passwords.
