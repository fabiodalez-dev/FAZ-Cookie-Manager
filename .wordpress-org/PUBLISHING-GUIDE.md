# FAZ Cookie Manager — WordPress.org Publishing Guide

End-to-end guide for submitting, reviewing and releasing FAZ Cookie Manager on
the [WordPress.org plugin directory](https://wordpress.org/plugins/).

> This guide assumes you already have:
>
> - A wordpress.org account (the `fabiodalez` contributor slug).
> - `svn` installed locally (`brew install subversion`).
> - The plugin code in `${PROJECT_ROOT}/faz-cookie-manager/` clean on the `main` branch.

> **Path placeholders.** Every command below references two location
> placeholders so this guide is portable across machines:
>
> - `${PROJECT_ROOT}` — the directory that contains the `faz-cookie-manager/`
>   working tree (e.g. `~/Code/Cookie Crawler` on a contributor's laptop, or
>   `/srv/build/cookie-crawler` in CI).
> - `${WP_TEST_ROOT}` — the WordPress install used for the live smoke step
>   in §5 (the path WP-CLI talks to). On a typical local-by-flywheel /
>   Local-WP / Valet-Pro setup this is something like
>   `~/Sites/faz-test/`.
>
> Either export both values in your shell before copy-pasting (e.g.
> `export PROJECT_ROOT=~/Code/Cookie\ Crawler` /
> `export WP_TEST_ROOT=~/Sites/faz-test`) or hand-substitute the placeholders
> in your own paste — the rest of the guide assumes they resolve correctly.

---

## 1. Submission checklist (first upload only)

The initial upload to wp.org is a **manual review** that can take 1–14 days. The
reviewer downloads the ZIP you submit and looks for the issues listed in the
[Detailed Plugin Guidelines](https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/).
Use this checklist before uploading.

### 1.1 Headers and metadata

- [ ] `faz-cookie-manager.php` header has:
  - `Plugin Name: FAZ Cookie Manager`
  - `Plugin URI: https://github.com/fabiodalez-dev/FAZ-Cookie-Manager`
  - `Description:` single sentence, ≤ 150 chars
  - `Version:` matches `readme.txt` `Stable tag`
  - `Requires at least: 5.0`
  - `Requires PHP: 7.4`
  - `License: GPL-3.0-or-later`
  - `License URI: https://www.gnu.org/licenses/gpl-3.0.html`
  - `Text Domain: faz-cookie-manager`
  - `Domain Path: /languages`
- [ ] `readme.txt` passes https://wordpress.org/plugins/developers/readme-validator/
- [ ] Short description (line after the header block) is ≤ 150 chars
- [ ] `Tested up to:` matches a **released** WordPress version (currently `6.8`)
- [ ] `Contributors:` only lists real wordpress.org usernames
- [ ] No trademarked names in the slug or plugin name

### 1.2 Code sanity

- [ ] No external CDN loads for JS/CSS (WP.org guideline #1 — "ship what you use")
- [ ] All JS/CSS bundled under `admin/assets/` and `frontend/`
- [ ] `uninstall.php` respects the `remove_data_on_uninstall` setting and only
      drops tables / deletes options when explicitly allowed
- [ ] Every `echo` on user-controllable data goes through `esc_html` / `esc_attr`
      / `esc_url` / `wp_kses_post`
- [ ] Every DB query uses `$wpdb->prepare()` (or has a `phpcs:ignore` with a
      clear reason)
- [ ] Nonce verification on every admin-side POST
- [ ] `load_plugin_textdomain()` is called on `plugins_loaded` or in activation
- [ ] No `error_log()` / `var_dump()` left on the happy path

### 1.3 External services

The reviewer will ask for a written disclosure of every external service the
plugin can talk to, when it is called, and what data it sends. FAZ Cookie
Manager's disclosure is in `readme.txt` under `== External services ==`. Before
submission, confirm it lists:

- **GitHub** (Open Cookie Database download, **opt-in** from Settings → Cookie
  Database → "Update now"). URL, trigger, data sent, retention.
- **ip-api.com** (geolocation lookup, **opt-in** from Settings → Geolocation).
  URL, trigger, data sent, retention.
- **MaxMind GeoLite2** (license-key based, **opt-in**). URL, trigger, data sent,
  retention.

No external call must happen on activation or on a default install.

### 1.4 Build the submission ZIP

The plugin uses a `.distignore` file to exclude dev artefacts. To build a clean
ZIP locally (mirroring what the WP.org SVN deploy will ship):

```bash
cd "${PROJECT_ROOT}"
rsync -a --delete \
  --exclude-from='faz-cookie-manager/.distignore' \
  faz-cookie-manager/ \
  /tmp/faz-cookie-manager/
(cd /tmp && zip -r faz-cookie-manager.zip faz-cookie-manager -x '*.DS_Store')
```

Sanity checks on the ZIP:

```bash
# Must NOT contain: tests/, node_modules/, .git/, *.log, *.md (except readme),
# scripts/, .playwright-mcp/, .github/, phpstan.neon, package*.json, composer.*
unzip -l /tmp/faz-cookie-manager.zip | grep -E "(tests/|node_modules|\.git|\.log|package\.json|phpstan|scripts/)" && echo "DIRTY" || echo "CLEAN"
```

### 1.5 Upload

1. Sign in at https://wordpress.org/plugins/developers/add/
2. Upload `/tmp/faz-cookie-manager.zip`
3. Submit and wait for the reviewer email (comes from `plugins@wordpress.org`)
4. Address every comment in a reply to that same email; do **not** re-upload
   until the reviewer approves

---

## 2. SVN workflow (after approval) — STAGED, never publish straight to `trunk/`

Once the plugin is approved, you get an SVN URL
(`https://plugins.svn.wordpress.org/faz-cookie-manager/`) with three top-level
directories:

```
faz-cookie-manager/
├── trunk/       ← latest in-development code, used by the directory page
├── tags/
│   ├── 1.13.11/ ← frozen copy of each released version
│   └── …
└── assets/      ← banner / icon / screenshots (NOT shipped to users)
```

> **🛑 Hard rule: never run `rsync … trunk/` followed by `svn ci` in the same
> shot.** wp.org ships whatever is in `trunk/` (filtered by `Stable tag:` in
> readme.txt) to every active install. A typo in a path or a stale local file
> bleeds into production within the next 12-hour `wp_update_plugins` cron.
>
> Always go through a **local staging directory + diff review + atomic
> apply**. Concretely:
>
> 1. Build the wp.org-shape ZIP from your clean `main` (release.md §3) — the
>    ZIP is the canonical source of truth; the SVN trunk is just a copy of
>    its extracted contents.
> 2. Extract that ZIP into a **staging directory outside the SVN checkout**
>    (e.g. `~/Sites/faz-cookie-manager-svn-stage/`).
> 3. `diff -r staging/ trunk/` and inspect the change set: file count, size,
>    new/removed paths, suspicious deltas (anything outside the documented
>    release scope is a red flag).
> 4. Only after the diff is clean, `rsync` staging → SVN `trunk/`, `svn cp`
>    trunk → `tags/{version}`, then `svn ci` in a single atomic commit.
>
> The `scripts/svn-release.sh` helper in the repo automates exactly this
> flow — see §2.4 below.

### 2.1 One-time setup

```bash
# Install SVN (macOS).
brew install subversion

# Generate an SVN-specific password at:
#   https://wordpress.org/profile/security/
# (NOT the wordpress.org account password — a separate token.)

# Probe auth + cache the password in the macOS Keychain on first call.
svn ls https://plugins.svn.wordpress.org/faz-cookie-manager/ \
  --username fabiodalez \
  --password '<svn_password_token>' \
  --non-interactive

# Subsequent calls don't need --username/--password — the Keychain serves them.

# Checkout the SVN repo into ~/Sites/faz-cookie-manager-svn (separate from the
# git working tree at ${PROJECT_ROOT}/faz-cookie-manager/).
cd ~/Sites
svn co https://plugins.svn.wordpress.org/faz-cookie-manager/ faz-cookie-manager-svn
```

### 2.2 First release (1.13.11) — staged flow

```bash
VERSION=1.13.11
PROJECT_ROOT="/Users/fabio/Documents/GitHub/Cookie Crawler"
SVN_DIR="${HOME}/Sites/faz-cookie-manager-svn"
STAGE_DIR="${HOME}/Sites/faz-cookie-manager-svn-stage"

# ── Step 1. Build the wp.org-shape ZIP from a clean main checkout.
# (See release.md §3 — produces faz-cookie-manager-${VERSION}.zip in
# ${PROJECT_ROOT}/.)

# ── Step 2. Extract the ZIP into the staging dir (NOT directly into trunk).
rm -rf "${STAGE_DIR}"
mkdir -p "${STAGE_DIR}"
unzip -q "${PROJECT_ROOT}/faz-cookie-manager-${VERSION}.zip" -d "${STAGE_DIR}"
# Result: ${STAGE_DIR}/faz-cookie-manager/ (the ZIP's top-level folder).

# ── Step 3. Diff staging vs current trunk and review the delta.
cd "${SVN_DIR}"
svn up
diff -rq "${STAGE_DIR}/faz-cookie-manager/" trunk/ | head -50
echo
echo "Stats:"
echo "  Staging files:  $(find ${STAGE_DIR}/faz-cookie-manager -type f | wc -l)"
echo "  Staging size:   $(du -sh ${STAGE_DIR}/faz-cookie-manager | cut -f1)"
echo "  Trunk files:    $(find trunk -type f -not -path '*/.svn/*' | wc -l)"
# REVIEW the diff manually. Anything unexpected → stop and investigate.

# ── Step 4. Apply: sync staging → trunk, copy → tag, single commit.
rsync -a --delete \
  --exclude='.svn' \
  "${STAGE_DIR}/faz-cookie-manager/" \
  trunk/

# Copy screenshots / banner / icon into assets/.
mkdir -p assets
cp "${PROJECT_ROOT}/faz-cookie-manager/.wordpress-org/"screenshot-*.png assets/
cp "${PROJECT_ROOT}/faz-cookie-manager/.wordpress-org/"banner-*.png assets/ 2>/dev/null || true
cp "${PROJECT_ROOT}/faz-cookie-manager/.wordpress-org/"icon-*.png assets/ 2>/dev/null || true

# Create the tag (atomic copy of trunk's working state).
svn cp trunk "tags/${VERSION}"

# Stage all SVN changes (add new, remove deleted).
svn add --force trunk assets "tags/${VERSION}"
svn status | grep '^!' | awk '{print $2}' | xargs -I{} svn rm {}

# Sanity preview.
svn status | head -30
echo "Total SVN changes staged: $(svn status | wc -l)"

# Atomic commit (trunk + tag + assets in one go).
svn ci -m "Release ${VERSION}" --username fabiodalez
```

SVN credentials are cached in macOS Keychain after the §2.1 probe. The commit
may take 5–30 minutes to propagate to https://wordpress.org/plugins/faz-cookie-manager.

### 2.3 Subsequent releases

Identical to §2.2 with a new `VERSION`. The helper script in §2.4 automates
the whole flow including the diff/confirm gate.

### 2.4 Automated staged-release script

`scripts/svn-release.sh` (in the repo root) wraps §2.2 with:
- Mandatory `--version=X.Y.Z` flag.
- Pre-flight check that `faz-cookie-manager-X.Y.Z.zip` exists in
  `${PROJECT_ROOT}/`.
- Pre-flight check that the `Stable tag:` in `readme.txt` matches `X.Y.Z`.
- Fresh staging extraction in `~/Sites/faz-cookie-manager-svn-stage/`.
- `diff -rq` summary + interactive `[y/N]` confirmation gate before any
  rsync into `trunk/`.
- Automatic `svn add --force` of new files + `svn rm` of deleted files.
- `svn ci` only if the user explicitly confirms a second time at the commit
  prompt.

```bash
scripts/svn-release.sh --version=1.13.11
# → runs all of §2.2 with two confirmation gates (post-diff and pre-commit).
```

Use the manual flow (§2.2) only when the script flags something unusual or
when you need to deviate (e.g. assets-only update without a code release —
in that case touch only `assets/` and skip the trunk/tag steps).

Remember to bump:
- `Stable tag:` in `readme.txt`
- `Version:` in `faz-cookie-manager.php`
- `FAZ_VERSION` constant in `faz-cookie-manager.php`
- The `== Changelog ==` section of `readme.txt`

Follow `release.md` for the full, authoritative release flow.

---

## 3. Screenshots

Screenshots live in two places in this repo:

```
.wordpress-org/
├── screenshot-1.png   ← numbered file that WP.org expects (in /assets/ on SVN)
├── screenshot-2.png
├── …
├── screenshots-src/   ← raw timestamped originals from capture-wporg-screenshots.mjs
└── PUBLISHING-GUIDE.md (this file)
```

### Capturing / refreshing screenshots

From the repo root:

```bash
# Make sure the test site is running and the plugin is deployed.
rsync -av --delete \
  --exclude tests --exclude test-results --exclude node_modules \
  "${PROJECT_ROOT}/faz-cookie-manager/" \
  "${WP_TEST_ROOT}/wp-content/plugins/faz-cookie-manager/"

WP_BASE_URL=http://localhost:9998 \
WP_ADMIN_USER=admin WP_ADMIN_PASS=admin \
node scripts/capture-wporg-screenshots.mjs

# Copy the ordered files into the canonical wp.org names.
cd .wordpress-org/screenshots-src
for f in 01-*.png 02-*.png 03-*.png 04-*.png 05-*.png 06-*.png 07-*.png 08-*.png 09-*.png 10-*.png; do
  n=$(echo "$f" | sed -E 's/^0*([0-9]+).*/\1/')
  cp "$f" "../screenshot-${n}.png"
done
```

### Screenshot captions (mirrors `readme.txt` order)

| # | File | Caption |
|---|------|---------|
| 1 | `screenshot-1.png` | Cookie consent banner on the frontend — bottom-left box with equal-weight Customize / Reject All / Accept All buttons. |
| 2 | `screenshot-2.png` | Preference center — category-level opt-in modal with Necessary / Functional / Analytics / Uncategorized / Marketing. |
| 3 | `screenshot-3.png` | Admin dashboard — pageviews, banner impressions, accept/reject rate and 7-day trends. |
| 4 | `screenshot-4.png` | Banner editor — live iframe preview with GDPR Strict, High Contrast and Light Minimal presets. |
| 5 | `screenshot-5.png` | Cookies management — editable categories + bundled Open Cookie Database lookups. |
| 6 | `screenshot-6.png` | IAB TCF v2.3 Global Vendor List — bundled GVL, vendor selection and purpose filtering. |
| 7 | `screenshot-7.png` | Consent logs — local audit trail with CSV export, hashed IP, URL and timestamp. |
| 8 | `screenshot-8.png` | Google Consent Mode v2 — default vs. granted state for all 7 signal types. |
| 9 | `screenshot-9.png` | Languages — multi-language banner management, WPML / Polylang friendly. |
| 10 | `screenshot-10.png` | Settings — global controls, script blocking, consent log retention, scanner limits. |

### Technical requirements (WP.org)

- **Max size:** 10 MB per file (we are well under)
- **Formats:** PNG or JPG
- **Recommended ratio:** 4:3 — our captures are 2560×1920 @ 2x DPR (1280×960 logical)
- **Filename:** `screenshot-{n}.{png,jpg}`, 1-indexed, contiguous (no gaps)
- **Text:** must match order of the `== Screenshots ==` section of `readme.txt`

---

## 4. Plugin header assets (banner + icon)

These go under `assets/` on SVN but NOT inside `trunk/`. They are displayed on
the plugin page header and in the plugin directory listings.

### 4.1 Header banner

| File | Size | Where it appears |
|------|------|------------------|
| `banner-1544x500.png` | 1544×500 | Header on the plugin page (desktop) |
| `banner-772x250.png` | 772×250 | Header on the plugin page (mobile) |
| `banner-1544x500.jpg` / `banner-772x250.jpg` | same | JPG alternative, loaded in place of PNG when present |

### 4.2 Icon

| File | Size | Where it appears |
|------|------|------------------|
| `icon-128x128.png` | 128×128 | Plugin listing thumbnail |
| `icon-256x256.png` | 256×256 | Plugin page sidebar |

All header assets must use your own artwork — no WordPress logo, no trademarks
you don't own. Keep them simple: plugin name + tagline, high-contrast, no
screenshots.

### 4.3 Placeholder until the real ones are ready

The repo currently does not ship banner/icon art. For the very first SVN
commit, you can either:

- Leave them out (the directory page will show a default placeholder), or
- Use a simple wordmark you generate with any tool. Suggested copy: "FAZ Cookie
  Manager — GDPR-ready consent for WordPress".

---

## 5. Pre-submission validation checklist

Run these from the repo root right before every submission:

```bash
# 1. PHP syntax on every file that ships.
find . -name '*.php' \
  -not -path './vendor/*' \
  -not -path './node_modules/*' \
  -not -path './tests/*' \
  -exec php -l {} \; | grep -v "No syntax errors"

# 2. No stray debugger / log calls on the hot path.
grep -rn "var_dump\|print_r\|dd(" admin/ frontend/ includes/ --include='*.php' \
  | grep -v "// phpcs:ignore\|phpstan:ignore"

# 3. readme.txt fields (Stable tag, version, tested up to).
grep -E "^(Stable tag|Requires at least|Tested up to|Requires PHP):" readme.txt

# 4. FAZ_VERSION matches readme.txt Stable tag.
grep "FAZ_VERSION" faz-cookie-manager.php
grep "Stable tag" readme.txt

# 5. Bundled Open Cookie Database is recent (re-download if >6 months old).
stat -f "%Sm" includes/data/open-cookie-database.json
```

And the interactive checks:

- [ ] readme.txt validator: https://wordpress.org/plugins/developers/readme-validator/
- [ ] i18n round-trip: `wp i18n make-pot . languages/faz-cookie-manager.pot`
      then `msgfmt -c --statistics languages/faz-cookie-manager-it_IT.po`
- [ ] Activate + deactivate + reactivate on a clean WP install
- [ ] Uninstall with `FAZ_REMOVE_ALL_DATA` constant set → DB is clean
- [ ] E2E smoke test: `WP_BASE_URL=... npm run test:e2e`

---

## 6. FAQ the reviewer typically asks

> **Q: Does your plugin make external requests on activation or on a default install?**
> A: No. Every external request is gated behind an admin action or an opt-in
> setting. See `== External services ==` in `readme.txt` for the full list.

> **Q: Are you shipping minified JavaScript without the corresponding source?**
> A: We ship only two minified files (`frontend/js/gcm.min.js`,
> `frontend/js/tcf-cmp.min.js`). The unminified sources are next to them
> (`gcm.js`, `tcf-cmp.js`) and `npm run build:min` rebuilds them with `terser`.

> **Q: Where is the full source of the bundled Open Cookie Database?**
> A: `includes/data/open-cookie-database.json` is a JSON snapshot of
> https://github.com/fabiodalez-dev/Open-Cookie-Database (Apache-2.0). It is
> regenerated from upstream before every release.

> **Q: Do you collect any analytics about the plugin install base?**
> A: No. The plugin contains no telemetry, no "phone home", and no opt-out
> analytics. The dashboard stats are computed locally from the site's own
> `wp_faz_pageviews` and `wp_faz_consent_logs` tables.

> **Q: How do you handle GDPR yourself?**
> A: All visitor data (consent logs, pageviews) is stored locally in the
> site's own database. IPs are SHA-256 hashed with `wp_salt()` before storage.
> Nothing leaves the site unless the admin explicitly opts in to geolocation.

---

## 7. Useful links

- Plugin directory rules: https://developer.wordpress.org/plugins/wordpress-org/detailed-plugin-guidelines/
- Readme.txt validator: https://wordpress.org/plugins/developers/readme-validator/
- Header asset spec: https://developer.wordpress.org/plugins/wordpress-org/plugin-assets/
- SVN cheatsheet: https://developer.wordpress.org/plugins/wordpress-org/how-to-use-subversion/
- i18n for plugin authors: https://developer.wordpress.org/plugins/internationalization/
