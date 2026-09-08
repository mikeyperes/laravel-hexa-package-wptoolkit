# WP Toolkit diagnostic

`smoke-settings.mjs` preserves the former Core `scripts/smoke-wptoolkit-settings.mjs`
diagnostic in its owning package. It is not part of the automated test suite.

This script saves settings and performs a real write against a selected WordPress
installation. Run it only with authorization for those exact changes. It requires:

- `WPT_SMOKE_BASE_URL`, `WPT_SMOKE_SERVER_ID`, and `WPT_SMOKE_SITE_ID`: explicit targets.
- `WPT_SMOKE_EMAIL` and `WPT_SMOKE_PASSWORD`: injected through a protected environment.
- `WPT_SMOKE_SETTINGS_JSON`: the exact approved settings object; no installation defaults are supplied.
- Both `--allow-settings-write` and `--allow-site-write` on the command line.
- Optional `WPT_SMOKE_EXPECT_TRANSPORT` (`ssh` by default).

Use the consuming application's supported Node/Playwright installation. Do not use
this business-action diagnostic as a substitute for isolated PHPUnit coverage.
