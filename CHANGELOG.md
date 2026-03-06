# Changelog

All notable changes to FAZ Cookie Manager are documented in this file.

## [1.3.0] — 2026-03-06

### Added
- **Incremental cookie scans** — only re-scans pages modified since the last run, using a content fingerprint (post count + latest modified date + taxonomy term slugs)
- **Page discovery from DB** — discovers scannable URLs from `wp_posts` and public taxonomy archives instead of sitemap-only
- **Settle watchdog** — preserves last valid iframe read on timeout instead of discarding
- **Scan metrics** — tracks pages scanned, cookies found, timing, and early-stop reasons
- **Scan progress UI** — real-time progress bar with page count, cookie count, and ETA

### Changed
- `advertisement` category renamed to `marketing` across all JSON files, DB slugs, display names, and GCM region settings
- Idempotent migration with completion flag — safe for repeated activations
- Handles edge case where both slugs exist (merges cookies, deletes old category)

### Fixed
- Boundary-aware provider hostname matching in script blocking
- CSS transient cache key includes `FAZ_VERSION` to prevent stale styles after upgrades
- TCF Special Features always return `false` per IAB spec (removed category-based derivation)
- Scanner iframe cookies use scanned page hostname instead of admin hostname
- Scanner fingerprint persisted only after successful import
- German category translation typo ("Werbekampagne nzu" → "Werbekampagnen zu")
- E2E TCF test updated to match v2.3 apiVersion

### Security
- Inline-safe URL handling for banner preview sinks
- Hardened admin URL handling and stale bar actions
- Avoid HTML style injection in banner helpers

## [1.2.1] — 2026-02-28

### Fixed
- CSV export no longer wraps data in JSON encoding — produces valid CSV files
- Consent log now correctly records "rejected" status when visitors click Reject All
- Consent logger skips page-load init events to prevent false "partial" entries for returning visitors

### Security
- Prototype pollution guard in deepSet utility function (CodeQL)
- DOM XSS prevention — logo URL validated to https only, privacy link href sanitized (CodeQL)
- CSV export type guard and anti-cache headers for privacy

### Added
- Composer/Packagist support — install via `composer require fabiodalez/faz-cookie-manager`

## [1.2.0] — 2026-02-24

### Security
- Proxy header trust filter (`faz_trust_proxy_headers`) — proxy headers only parsed when explicitly enabled
- Dual-guardrail consent throttle (per-IP + per-consent_id) to prevent flooding
- TTL normalization in rate limiter — prevents zero/negative TTL bypass

### Changed
- Necessary category toggle uses active blue color instead of gray
- "Always active" label positioned right-aligned next to toggle
- Removed orphan methods from deprecated languages API

### Added
- Playwright E2E test suite: 11 tests with proper fixtures and global setup

## [1.1.0] — 2026-02-15

### Added
- Google Consent Mode v2 integration
- IAB TCF v2.3 CMP stub
- Microsoft UET/Clarity consent API
- Local consent logging with CSV export
- Cookie scanner with Open Cookie Database integration
- GeoLite2 geolocation support
- 180+ language translations

## [1.0.0] — 2026-01-20

- Initial release — fork of CookieYes v3.4.0, fully de-branded, cloud-free, all premium features unlocked
