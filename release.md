# Release Process — FAZ Cookie Manager

## 0. wp.org Marketing Assets (banner e icone)

Questi file vanno in `.wordpress-org/` e vengono copiati in SVN `assets/`
automaticamente da `scripts/svn-release.sh`. **Non vanno nel ZIP del plugin.**

| File | Dimensioni | Formato |
|------|-----------|---------|
| `icon-128x128.png` o `.jpg` | 128 × 128 px | PNG o JPG |
| `icon-256x256.png` o `.jpg` | 256 × 256 px | PNG o JPG (retina) |
| `banner-772x250.png` o `.jpg` | 772 × 250 px | PNG o JPG |
| `banner-1544x500.png` o `.jpg` | 1544 × 500 px | PNG o JPG (retina, opzionale) |

Gli screenshot (`screenshot-1.png` … `screenshot-10.png`) sono già presenti.
`svn-release.sh` copia tutti i file da `.wordpress-org/` in SVN `assets/`
(sia `.png` che `.jpg`). Se aggiorni solo gli asset senza rilasciare una nuova
versione, usa `--no-tag`:

```bash
scripts/svn-release.sh --version=${VERSION} --no-tag
```

## 1. Version Bump

Update version in **four places**:

- `faz-cookie-manager.php` — lines `Version:`, `Stable tag:`, `Tested up to:`, and `define( 'FAZ_VERSION', '...' )`
- `readme.txt` — lines `Stable tag:` **and `Tested up to:`** (must match the value in `faz-cookie-manager.php` — Plugin Check fails if they differ or if value < current WP release)
- `README.md` — **MANDATORY**: add new version entry to the Changelog section (this is NOT optional — every release MUST have a corresponding entry in README.md)
- `CHANGELOG.md` — add new version section with full details

## 2. Build Minified JS

```bash
cd faz-cookie-manager
npm run build:min
```

Regenerates `frontend/js/gcm.min.js` and `frontend/js/tcf-cmp.min.js`.

## 3. Create Release ZIPs (THREE variants — wp.org + GitHub + ClassicPress)

Build all release archives with the scripted flow:

```bash
cd faz-cookie-manager
scripts/build-release.sh --version=${VERSION}
```

The script validates `readme.txt` stable tag, the plugin header version, and
`FAZ_VERSION`, then writes the ZIPs to the parent project directory by default.

| Variant | Filename | `run-scan.php` | `cp-api-fetch-polyfill.js` | `Requires CP` | Audience |
|---------|----------|---------------:|---------------------------:|--------------:|----------|
| **wp.org** | `faz-cookie-manager-{version}.zip` | excluded | excluded | no | wp.org submission + SVN |
| **GitHub (full)** | `faz-cookie-manager-{version}-full.zip` | included | included | no | developers who clone/download the GH release ZIP |
| **ClassicPress** | `faz-cookie-manager-v{version}.zip` | included | included | yes, injected in staging | ClassicPress Directory |

**Why separate variants?**
1. **`run-scan.php`** — WordPress Plugin Check cannot parse the `ABSPATH` guard
   pattern used by `admin/modules/scanner/run-scan.php` (a CLI bootstrap script —
   its guard isn't the literal `if ( ! defined( 'ABSPATH' ) ) { exit; }` that
   Plugin Check's parser expects, because the file is invoked outside WordPress on
   purpose). A wp.org review would flag it as `missing_direct_file_access_protection`.
   End-users running the scanner go through the Admin UI (Cookies → Scan) or
   WP-CLI (`wp faz scan`); they never need that file. Developers who download the
   GitHub release ZIP and want to scan their site without WP-CLI installed do.
2. **`cp-api-fetch-polyfill.js`** — Plugin Check fingerprints the file as
   `library_core_files` because it is a structural re-implementation of
   `wp-includes/js/dist/api-fetch.js`. The polyfill is needed only on
   ClassicPress 1.x (forked from WP 4.9 — its `wp-api-fetch` lacks
   `createRootURLMiddleware` introduced in WP 5.x). On WordPress.org-distributed
   WordPress the native `wp-api-fetch` is loaded and the polyfill is never
   enqueued. `class-admin.php::deregister_api_fetch()` carries a `file_exists()`
   guard so the wp.org build is a graceful no-op when the polyfill is absent.
3. **ClassicPress Directory asset** — the CP Directory requires a GitHub release
   asset URL in the form `faz-cookie-manager-v{version}.zip`, expanding to a
   `faz-cookie-manager/` folder. The CP ZIP is full-featured like the GitHub
   archive, includes `README.md`, and injects `Requires CP` only in the staged
   copy so the source tree and wp.org ZIP stay unchanged. Override the minimum
   CP version with `CP_REQUIRES=1.5 scripts/build-release.sh --version=${VERSION}`
   if the support baseline changes.

> All variants must be uploaded as assets on the GitHub release. wp.org
> submission/SVN uses ONLY `faz-cookie-manager-{version}.zip` (no suffix).
> ClassicPress Directory uses ONLY `faz-cookie-manager-v{version}.zip`.

### Expected size: ~1.4 MB

If the ZIP is significantly larger, check for:
- `vendor/` (phpstan.phar alone is 26 MB)
- `test-results/` or `.playwright-mcp/`
- `node_modules/`

### Verify contents

```bash
# Check largest files (no file should be > 500 KB except template.json and screenshots)
unzip -l "faz-cookie-manager-${VERSION}.zip" | awk '{print $1, $4}' | sort -rn | head -10

# Ensure no dev artifacts or temp files
unzip -l "faz-cookie-manager-${VERSION}.zip" | grep -cE "vendor/|test-results|\.playwright|phpstan|node_modules|\.po~|messages\.mo|\.githooks|\.github|plan\.md"
# Should output: 0
```

## 4. Commit, Tag, and Release

```bash
cd faz-cookie-manager
git add -A
git commit -m "chore: bump version to ${VERSION}"
git push origin main

gh release create "v${VERSION}" \
  --title "v${VERSION} — <brief description>" \
  --notes-file CHANGELOG.md \
  --target main

gh release upload "v${VERSION}" \
  "../faz-cookie-manager-${VERSION}.zip" \
  "../faz-cookie-manager-${VERSION}-full.zip" \
  "../faz-cookie-manager-v${VERSION}.zip"
```

## 5. Deploy to Test Site

```bash
rsync -av --delete \
  "/Users/fabio/Documents/GitHub/Cookie Crawler/faz-cookie-manager/" \
  "/Users/fabio/Sites/faz-test/wp-content/plugins/faz-cookie-manager/"
```

## 5b. Test su WordPress Playground — **OBBLIGATORIO prima dell'SVN**

> **Non saltare questo step.** Il crash di 1.13.13/1.13.14 è passato in produzione
> proprio perché il test su Playground non era stato fatto. Playground usa PHP WASM
> con un ordine di bootstrap diverso da WordPress standard: errori che non emergono
> in locale (es. `wp_salt()` non ancora disponibile al caricamento del plugin)
> si manifestano solo qui.

### Come testare con Playwright MCP

1. **Fai il GitHub release** (step 4) prima di questo test — Playground scarica il
   plugin dall'API di wp.org, che si aggiorna entro pochi minuti dal commit SVN.
   Se vuoi testare prima del SVN commit, puoi saltare al post-SVN e ritornare qui.

2. **Apri Playground con Playwright MCP** — incolla questo URL nel tool
   `browser_navigate`:

   ```
   https://playground.wordpress.net/?plugin=faz-cookie-manager#ewogICJwbHVnaW5zIjogWwogICAgImZhei1jb29raWUtbWFuYWdlciIKICBdLAogICJzdGVwcyI6IFtdLAogICJwcmVmZXJyZWRWZXJzaW9ucyI6IHsKICAgICJwaHAiOiAiOC4zIiwKICAgICJ3cCI6ICJsYXRlc3QiCiAgfSwKICAiZmVhdHVyZXMiOiB7fSwKICAibG9naW4iOiB0cnVlCn0=
   ```

   Blueprint (decoded): installa `faz-cookie-manager` da wp.org, PHP 8.3, WP latest,
   login automatico come admin.

3. **Aspetta 30 secondi** (`browser_wait_for time=30`) che il WASM PHP si avvii,
   WordPress si installi e il plugin venga attivato.

4. **Naviga alla dashboard del plugin** — usa la barra URL di Playground:

   ```
   browser_type target=<textbox URL> text="/wp-admin/admin.php?page=faz-cookie-manager" submit=true
   ```

5. **Aspetta 8 secondi** e poi fai uno screenshot (`browser_take_screenshot`).

### Cosa verificare

| Check | Atteso |
|-------|--------|
| Pagina carica senza "There has been a critical error" | ✅ |
| Dashboard FAZ visibile con menu (Cookie Banner, Cookies, Consent Logs…) | ✅ |
| Nessun PHP Fatal Error nel titolo o nel body della pagina | ✅ |

### Se Playground carica ancora la versione vecchia

wp.org impiega 5–15 minuti a propagare una nuova release. Se vedi ancora la versione
precedente, aspetta e ricarica (pulsante Refresh nella toolbar di Playground).
Puoi verificare la versione attiva in `wp-admin/plugins.php`.

---

## 6. Publish to wordpress.org SVN (STAGED — never `rsync … trunk/ && svn ci`)

> **Hard rule:** wp.org ships whatever is in `trunk/` to every install via the
> next `wp_update_plugins` cron. A typo or stale local file bleeds straight to
> production. Always go through a local staging dir + diff review + atomic
> apply. Use the helper script — it enforces two confirmation gates.

```bash
# One-time setup (già fatto — SVN checkout in ~/Sites/faz-cookie-manager-svn).
brew install subversion
svn co https://plugins.svn.wordpress.org/faz-cookie-manager/ ~/Sites/faz-cookie-manager-svn

# Each release: run the staged helper. It validates ZIP filename, readme.txt
# Stable tag, and FAZ_VERSION constant all match --version, then asks for
# confirmation twice (post-diff and pre-commit).
scripts/svn-release.sh --version=${VERSION}

# Optional flags:
#   --dry-run    runs everything up to (but not including) svn ci
#   --no-tag     update trunk + assets only, skip the tag (e.g. assets refresh)
```

The script:
1. Validates `--version` matches the wp.org-shape ZIP filename
   (`faz-cookie-manager-X.Y.Z.zip`), the `Stable tag:` in `readme.txt`, and
   the `FAZ_VERSION` constant in `faz-cookie-manager.php`.
2. Extracts the wp.org-shape ZIP into `~/Sites/faz-cookie-manager-svn-stage/`
   (outside the SVN checkout).
3. Computes `diff -rq` between staging and the current SVN `trunk/` and prints
   a summary.
4. **Gate 1**: asks `[y/N]` before any rsync into `trunk/`.
5. Applies: `rsync` staging → `trunk/`, copies `.wordpress-org/` asset files
   (screenshot, banner, icone — `.png` e `.jpg`) in `assets/`,
   `svn cp trunk → tags/{VERSION}`, `svn add --force` nuovi file,
   `svn rm` file eliminati.
6. Prints `svn status` preview.
7. **Gate 2**: asks `[y/N]` before `svn ci`.
8. Atomic commit of `trunk/ + assets/ + tags/{VERSION}/` in one go.

Authoritative reference: `.wordpress-org/PUBLISHING-GUIDE.md` §2 (the script
is a faithful automation of §2.2).

After the SVN commit:
- 5–30 min for the directory page to update.
- Up to 12 hours for installed sites to see the update prompt (via
  `wp_update_plugins` cron).

### Gotcha SVN: autenticazione e username

**Problema**: `svn ci` dentro lo script (o lanciato manualmente) usa il
tuo username macOS (es. `fabio`) invece del tuo username wordpress.org
(`fabiodalez`). SVN cerca credenziali per l'utente sbagliato e fallisce.

**Soluzione**: usa sempre `--username fabiodalez` in ogni `svn ci` manuale.

**Credenziali**: usa una **Application Password** di wordpress.org, NON la
password principale. Vai su wordpress.org → Il tuo profilo → Application
Passwords → crea una nuova password con nome "SVN". Il formato è `svn_XXXX`.
**Dopo ogni utilizzo via riga di comando, eliminala e creane una nuova** —
ha privilegi di scrittura sull'SVN.

### Fallback manuale se lo script si blocca o Gate 2 non appare

La prima volta che esegui lo script, `svn status` stampa centinaia di righe
(tutti i file di tag/1.x.x mai committati localmente). Gate 2 appare DOPO
quelle righe — il terminale sembra bloccato ma non lo è. Aspetta.

Se lo script termina senza aver committato (svn status mostra ancora `A` o `M`):

```bash
# 1. Se la working copy è bloccata (errore E155004):
cd ~/Sites/faz-cookie-manager-svn
svn cleanup

# 2. Commit manuale con username corretto e Application Password:
svn ci \
  --username fabiodalez \
  --password "svn_la_tua_app_password" \
  --no-auth-cache \
  -m "Release ${VERSION} — <descrizione breve>"

# 3. Verifica:
svn info trunk/ | grep "Last Changed Rev"
# Deve mostrare un numero di revisione recente (> 3519691 per release post-1.13.12)
```

**Nota**: `--no-auth-cache` evita che la password venga salvata in locale.

## 7. Post-release Checklist

- [ ] Version numbers consistent across all 4 file (faz-cookie-manager.php ×3, readme.txt)
- [ ] CHANGELOG.md e README.md aggiornati con la nuova sezione
- [ ] ZIP size ~1.4 MB (no dev bloat)
- [ ] Sanity check 4/4 (run-scan.php e polyfill assenti dal wp.org ZIP, presenti nel full)
- [ ] GitHub release ha tag, title, notes e **entrambi** i ZIP (wp.org + full)
- [ ] `svn status ~/Sites/faz-cookie-manager-svn/` è pulito (nessuna `A`/`M`)
- [ ] `svn info trunk/ | grep "Last Changed Rev"` mostra un numero di revisione recente
- [ ] SVN `assets/` contiene banner-772x250, banner-1544x500, icon-128x128, icon-256x256
- [ ] **Playground test passato** — dashboard FAZ carica senza crash in Playground (step 5b)
- [ ] https://wordpress.org/plugins/faz-cookie-manager mostra la nuova versione (5–30 min)
- [ ] `tags/{X.Y.Z}` visibile su https://plugins.svn.wordpress.org/faz-cookie-manager/tags/
- [ ] Application Password SVN eliminata e rigenerata (non riutilizzare la stessa)
