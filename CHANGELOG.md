# Changelog

## 3.3.15 - 2026-11-03

- WPTOOLKIT-LOCAL-006: verify and explicitly bind CLI PHP for every native command, including uncached sites and non-root execution; invalidate older command cache entries.

## 3.3.14 - 2026-11-03

- WPTOOLKIT-LOCAL-005: probe and execute same-host Toolkit commands through the existing privilege bridge; preserve root-only vendor launchers, reuse the native CLI-PHP resolver, reject failed author-directory reads and avoid duplicate bridge wrapping.

## 3.3.13 - 2026-11-01

- WPTOOLKIT-NATIVE-004: resolve home-relative --info path metadata through the existing full inventory for the exact installation ID; preserve absolute-path caching and unknown-ID failure.
