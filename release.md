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

- `faz-cookie-manager.php` — lines `Version:`, `Stable tag:`, and `define( 'FAZ_VERSION', '...' )`
- `readme.txt` — line `Stable tag:`
- `README.md` — **MANDATORY**: add new version entry to the Changelog section (this is NOT optional — every release MUST have a corresponding entry in README.md)
- `CHANGELOG.md` — add new version section with full details

## 2. Build Minified JS

```bash
cd faz-cookie-manager
npm run build:min
```

Regenerates `frontend/js/gcm.min.js` and `frontend/js/tcf-cmp.min.js`.

## 3. Create Release ZIPs (TWO variants — wp.org + GitHub)

We ship **two** ZIPs per release. They differ by exactly two files:

| Variant | Filename | `run-scan.php` | `cp-api-fetch-polyfill.js` | Audience |
|---------|----------|---------------:|---------------------------:|----------|
| **wp.org** | `faz-cookie-manager-{version}.zip` | excluded | excluded | wp.org submission + SVN |
| **GitHub (full)** | `faz-cookie-manager-{version}-full.zip` | included | included | developers who clone/download the GH release ZIP, ClassicPress users |

**Why two?**
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

> **Critical:** always delete an existing ZIP with the same name before creating
> a new one. The `zip -r` command *updates* an existing archive instead of
> replacing it — deleted files will persist as ghost entries.

```bash
cd "/Users/fabio/Documents/GitHub/Cookie Crawler"
VERSION=1.5.0

# Shared exclude list (everything except `run-scan.php` and `cp-api-fetch-polyfill.js`).
COMMON_EXCLUDES=(
  -x "faz-cookie-manager/.git/*"
  -x "faz-cookie-manager/.github/*"
  -x "faz-cookie-manager/.githooks/*"
  -x "faz-cookie-manager/.claude/*"
  -x "faz-cookie-manager/.wordpress-org/*"
  -x "faz-cookie-manager/.coderabbit.yaml"
  -x "faz-cookie-manager/.distignore"
  -x "faz-cookie-manager/assets/*"
  -x "faz-cookie-manager/node_modules/*"
  -x "faz-cookie-manager/vendor/*"
  -x "faz-cookie-manager/tests/*"
  -x "faz-cookie-manager/test-results/*"
  -x "faz-cookie-manager/.playwright-mcp/*"
  -x "faz-cookie-manager/.playwright-cli/*"
  -x "faz-cookie-manager/.code-review-graph/*"
  -x "faz-cookie-manager/graphify-out/*"
  -x "faz-cookie-manager/.serena/*"
  -x "faz-cookie-manager/.gitignore"
  -x "faz-cookie-manager/.gitattributes"
  -x "faz-cookie-manager/.env*"
  -x "faz-cookie-manager/package*.json"
  -x "faz-cookie-manager/tsconfig.json"
  -x "faz-cookie-manager/composer.json"
  -x "faz-cookie-manager/composer.lock"
  -x "faz-cookie-manager/phpstan.neon"
  -x "faz-cookie-manager/phpstan-bootstrap.php"
  -x "faz-cookie-manager/.DS_Store"
  -x "faz-cookie-manager/**/.DS_Store"
  -x "faz-cookie-manager/docs/*"
  -x "faz-cookie-manager/scripts/*"
  -x "faz-cookie-manager/social-preview.png"
  -x "faz-cookie-manager/release.md"
  -x "faz-cookie-manager/plan.md"
  -x "faz-cookie-manager/eslint.config.mjs"
  -x "faz-cookie-manager/cookie-banner-compliance-checklist.md"
  -x "faz-cookie-manager/languages/*.po~"
  -x "faz-cookie-manager/languages/messages.mo"
  -x "faz-cookie-manager/biome.json"
  -x "faz-cookie-manager/CLAUDE.md"
  -x "faz-cookie-manager/report.md"
  -x "faz-cookie-manager/README.md"
  -x "faz-cookie-manager/CHANGELOG.md"
  -x "faz-cookie-manager/revisit.svg"
)

# 1) wp.org variant — `run-scan.php` AND `cp-api-fetch-polyfill.js` EXCLUDED.
rm -f "faz-cookie-manager-${VERSION}.zip"
zip -r "faz-cookie-manager-${VERSION}.zip" faz-cookie-manager/ \
  "${COMMON_EXCLUDES[@]}" \
  -x "faz-cookie-manager/admin/modules/scanner/run-scan.php" \
  -x "faz-cookie-manager/admin/assets/js/cp-api-fetch-polyfill.js"

# 2) GitHub full variant — both files INCLUDED.
rm -f "faz-cookie-manager-${VERSION}-full.zip"
zip -r "faz-cookie-manager-${VERSION}-full.zip" faz-cookie-manager/ \
  "${COMMON_EXCLUDES[@]}"

# Sanity:
echo "wp.org variant: should NOT contain run-scan.php"
unzip -l "faz-cookie-manager-${VERSION}.zip"     | grep -q 'run-scan\.php' && echo "  FAIL"  || echo "  OK"
echo "wp.org variant: should NOT contain cp-api-fetch-polyfill.js"
unzip -l "faz-cookie-manager-${VERSION}.zip"     | grep -q 'cp-api-fetch-polyfill\.js' && echo "  FAIL"  || echo "  OK"
echo "GitHub variant: SHOULD contain run-scan.php"
unzip -l "faz-cookie-manager-${VERSION}-full.zip" | grep -q 'run-scan\.php' && echo "  OK"   || echo "  FAIL"
echo "GitHub variant: SHOULD contain cp-api-fetch-polyfill.js"
unzip -l "faz-cookie-manager-${VERSION}-full.zip" | grep -q 'cp-api-fetch-polyfill\.js' && echo "  OK"   || echo "  FAIL"
```

> Both variants must be uploaded as assets on the GitHub release. wp.org
> submission/SVN uses ONLY `faz-cookie-manager-{version}.zip` (no suffix).

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
  "../faz-cookie-manager-${VERSION}-full.zip"
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
