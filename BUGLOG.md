# WP Toolkit Bug Log

Permanent record of critical and high-severity defects in the reusable WP
Toolkit package.

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
