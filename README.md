# WP Toolkit integration

## Features

- Reuses protected WHM connections to inventory WordPress installations and their owning accounts.
- Provides WP-CLI reads and writes, plugin/theme operations, media and taxonomy operations, backups and exact-site login generation.
- Resolves native plugin-loaded WP-CLI commands under the installation owner and preserves a CLI PHP interpreter.
- Caches installation and native command metadata through the existing persistent state owner.
- Returns execution status and output to the shared WordPress package; callers retain their own action authorization and mutation checks.

## Native installation paths

Toolkit versions may return fullPath or a home-relative path from --info. Native evaluation uses fullPath when supplied; otherwise it resolves the exact installation ID through the existing full-inventory parser, which returns the absolute installation path. A relative /public_html value is never treated as a global filesystem root. Unknown IDs fail rather than borrowing another installation's path.

## Tests

The focused NativeInstallPathTest and NativeWpCliCacheTest run through the consuming Laravel app's PHPUnit runner. They use fixtures and issue no site writes or emails.
