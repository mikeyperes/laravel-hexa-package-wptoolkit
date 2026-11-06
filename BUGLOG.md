# WP Toolkit Bug Log

## WPDB-CAMPAIGN-003 — Scheduled-job inventory used an unavailable UAPI module
- Severity: High. Fixed in 3.3.18.
- Symptom/impact: Both-server inventory could not finish, blocking database mapping and every safe password swap.
- Cause: Cron::list_cron_jobs does not exist in cPanel UAPI; the supported listing remains API 2 Cron::listcron.
- Patch: Use the existing WHM proxy for the account-scoped API 2 call and require cpanelresult.event.result=1 before reading command rows.
- Guard: Provider, database and job inventory errors still block coverage; no empty-list fallback or orphan assumption. No source tests requested.


## WPDB-CAMPAIGN-001 — Grant inventory omitted its required user
- Severity: High. Fixed in 3.3.17.
- Symptom/impact: cPanel returned Provide the user argument, blocking inventory and safe rotation.
- Cause: The privilege call omitted user and interpreted its flat privilege-string response as user rows.
- Patch: Resolve attached users from list_databases; query each exact database/user pair.
- Guard: Require named attachments and successful pair-specific responses. Live read-only reproduction failed without user and returned ALL PRIVILEGES with it. No source tests requested.

## WPDB-CAMPAIGN-002 — Full individual reports were delayed until the whole scan
- Severity: High. Fixed in 3.3.17.
- Symptom/impact: Live inspection events exposed only counters, so database/check details were unavailable inline.
- Cause: Full reports were assembled only after all installation and account work.
- Patch: Emit a complete safe inspection report before the next installation and retain later dependency reconciliation.
- Guard: Command refuses report events without cPanel account, configuration identity, before/change/old/new/after/recovery/context fields. Every event carries the exact server. Inventory never implies a password change.


Permanent record of critical and high-severity defects in the reusable WP
Toolkit package.

## WPTOOLKIT-BUG-003 — Words in site content turned successful commands into failures

- **Severity:** High
- **Status:** Fixed in 3.3.11.
- **Symptom:** Reading every page of herforward.com failed with "Direct wp-cli
  eval failed" although WP-CLI exited 0 and printed the whole payload. Batches
  2 and 5 of 7 failed; the others passed.
- **Impact:** Any command whose returned data mentioned "permission denied",
  "fatal error", "parse error" or "not authorized" (for example a news article
  about a cyberattack) was reported as failed, so whole-site SEO reads stopped.
- **Root cause:** `isCommandRefusalOutput()` searched the entire output,
  including returned site content, for refusal phrases.
- **Patch:** Only message lines are checked; a data line (JSON, optionally
  after a `MARKER:` prefix) is skipped. Shell, WP-CLI and PHP fatal messages
  are still caught on their own lines.
- **Guard:** `tests/Unit/CommandRefusalOutputTest.php` and the
  `WPTOOLKIT-BUG-003` docblock on `isCommandRefusalOutput()`.

## LOGIN-BUG-001 — Login-only generation inventoried unrelated credentials

- **Severity:** High
- **Status:** Fixed in the next package release.
- **Symptom:** A link request ran a full administrator/credential inventory,
  WP Toolkit info and database-credential reads before writing the bootstrap.
- **Root cause:** The login method reused the dashboard's `getCredentials()`;
  its default-user fallback could silently select a different administrator.
- **Patch:** Read only the preferred username and perform one bounded WordPress
  administrator lookup, preserving the connection through bootstrap creation.
  An invalid preferred user fails instead of selecting another person. The thin
  `wptoolkit:login` command reuses exact bindings and issues requested links in
  one application process through the existing services.
- **Guard:** The login-only method does not call `getCredentials()`; existing
  runtime/SSH incident guards remain intact. Tests were not requested.

## LOGIN-BUG-002 — WordPress bootstrap authenticated before consuming the link

- **Severity:** High
- **Status:** Fixed in the next package release.
- **Root cause:** Authentication preceded best-effort deletion, allowing
  concurrent clicks or deletion failure to bypass single-use consumption.
- **Patch:** Require successful atomic unlink before issuing authentication
  cookies, own the bootstrap directory as the cPanel account so it can consume
  the file, and require the selected user to remain an administrator.
- **Guard:** Consumption failure stops authentication. Tests were not requested.

## JOURNALIST-BUG-001 — WP Toolkit setup repeated on every request (about 15 seconds)

- **Severity:** High
- **Status:** Fixed in 3.3.8, 2026-09-27 18:04:31 EST (with laravel-hexa-package-wordpress 2.0.84).
- **Symptom:** Every Publish request that touched a WP Toolkit site spent about
  15 seconds before its first WordPress command; a journalist profile read took
  21.7 seconds on a new request.
- **Root cause:** Nothing expensive survived between requests. Each request
  unlocked the passphrase-protected SSH key with bcrypt-pbkdf in pure PHP
  (6.4 s), started WP Toolkit once per binary candidate to probe the runtime
  (7.6 s), ran `wp-toolkit --info` for the install path (2.5 s) and repeated up
  to eight shell commands to resolve the native wp-cli command on every
  evaluation.
- **Patch:** The unlocked key is cached only as a Crypt-encrypted PKCS8 string
  keyed by a hash of the stored key and passphrase (`loadServerPrivateKey()`).
  Successful runtime probes, install paths and resolved native wp-cli commands
  are kept between requests through `Support\PersistentState`. A cached
  command is dropped when it cannot start WordPress
  (`nativeWpCliTargetMissing()`); a failed WordPress operation never
  invalidates it. Commands themselves are unchanged. A profile read on a new
  request dropped from 21.7 s to 2.7-3.3 s.
- **Guard:** `NativeWpCliCacheTest`.

## CAMPAIGN-BUG-114 — Timed-out liveness probe returned a poisoned SSH connection

- **Severity:** High
- **Status:** Patched 2026-09-26 16:25:33 EST; released in
  `laravel-hexa-package-wptoolkit` 3.3.7.
- **Impact:** Publish media preparation intermittently stopped before applying
  an article correction or creating a WordPress post. Articles 7914 and 7916
  surfaced `Please close the channel (1) before trying to open it again`; the
  two attempts for article 7916 remained unapplied and undelivered.
- **Root cause:** Cached WP Toolkit SSH connections were probed with a
  three-second `exec('true')`. A phpseclib timeout can return `false` while the
  transport still reports connected and its exec channel remains open. The
  cache ignored both the false result and timeout flag, returned the poisoned
  object, and the next command tried to reopen channel 1.
- **Patch:** Reuse a cached SSH connection only when the liveness command
  returns successfully and does not time out. Any false or timed-out probe now
  falls through to the existing disconnect-and-reconnect path.
- **Guard:** `CRITICAL — see BUGLOG.md CAMPAIGN-BUG-114` in
  `ManagesWpToolkitConnections::getConnection()` must continue to reject a
  cached connection after any unsuccessful liveness probe.

## WPT-PLUGIN-VERSION (high) - 2026-10-15

- Symptom: `ensurePluginInstalledAndActive($server, $id, 'all-in-one-wp-migration', '7.110')` left 7.112 in place on a site that already had the plugin.
- Impact: the newer migration plugin refused the stored Unlimited extension ("out of date"), so the eagleswill.com restore failed until the version was forced by hand.
- Root cause: the requested version was used only when the plugin was missing.
- Patch: when a version is given and the installed version differs, reinstall that exact version with `--force`.
- Guard: `ManagesWpToolkitOperations::ensurePluginInstalledAndActive`, marked `CRITICAL — see BUGLOG.md WPT-PLUGIN-VERSION`.


## WPT-TIMEOUT-RESET - 2026-10-26

- Symptom: `wpCliEvalWithPlugins(..., 600)` failed with "WP Toolkit SSH command timed out after 120 seconds" while the remote command kept running and finished.
- Impact: long WordPress clean-ups (deleting thousands of events) reported failure although they succeeded; a caller could repeat a destructive step.
- Root cause: `execWithConnection()` reset the connection timeout to `commandTimeoutSeconds()` (the 120 s default) before every command, overriding the per-call limit.
- Fix: a per-call `commandTimeoutOverride` that `commandTimeoutSeconds()` returns while the operation runs, restored in `finally`.
- Guard: `CRITICAL - see BUGLOG.md WPT-TIMEOUT-RESET` in `ManagesWpToolkitConnections::commandTimeoutSeconds()`.

## WPTOOLKIT-NATIVE-004 (high): native evaluation assumed --info always returned fullPath
- Symptom: existing WordPress installations failed native path resolution while Toolkit CLI worked.
- Root cause: --info can return path=/public_html rather than fullPath; treating that as an absolute host path would also be incorrect.
- Patch: use fullPath when present; otherwise reuse the existing full-inventory parser and match the exact installation ID to obtain its absolute path. Cache the normalized result.
- Guard: NativeInstallPathTest covers relative metadata, both installation IDs, old absolute metadata, caching and unknown-ID refusal.


## WPTOOLKIT-LOCAL-005 - Root-only Toolkit launcher forced failing SSH fallback

- Severity: High.
- Status: Fixed in 3.3.14.
- Symptom: Daily campaign preparation stopped with WordPress author lookup failed before generation.
- Impact: Publish could not verify authors for same-host WordPress installations.
- Root cause: Local binary checks ran as the application user against a root-only vendor launcher, even though the established privileged local bridge was available. Auto mode consequently selected SSH without usable stored authentication.
- Patch: Local connections explicitly use the existing bridge when available; binary probes use that same execution context. Native WP-CLI reuses the existing installation-owner and CLI-PHP resolver instead of the CGI PHP from PATH, and existing filesystem operations avoid double wrapping. Author listing checks command exit and JSON parsing so a failed command cannot become a successful empty cached directory. Vendor permissions, SSH credentials and campaign settings remain unchanged.
- Guard: The explicit LocalShellConnection privileged flag binds probe and execution context. Operational recovery reads the previously failing author directory through the normal campaign service.


## WPTOOLKIT-LOCAL-006 - Privileged shell PATH selected CGI PHP for uncached sites

- Severity: High.
- Status: Fixed in 3.3.15.
- Symptom: Grit Daily and Block Telegraph failed before sourcing with campaign_smp_manifest_unavailable after local transport was restored.
- Impact: A live publication manifest could not be evaluated; the generic manifest error hid an interpreter-resolution failure.
- Root cause: Native command resolution probed unqualified php inside the privileged login shell, which resolved to php-cgi. A previously cached native command let Breaking9to5 pass while uncached sites failed.
- Patch: For same-host execution, probe the application's CLI binary and its PHP installation's CLI sibling before PATH. Require an actual cli SAPI and bind its absolute path for every user context. Remote resolution continues to use the remote interpreter. Version the native command cache key so old commands cannot retain ambiguous PHP resolution.
- Guard: directWpCliCommand verifies CLI SAPI before constructing commands and fails closed if no supported candidate works. Read the formerly failing manifest through the normal WordPress manager after release.
