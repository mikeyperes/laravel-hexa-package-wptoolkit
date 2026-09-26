# WP Toolkit Bug Log

Permanent record of critical and high-severity defects in the reusable WP
Toolkit package.

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
