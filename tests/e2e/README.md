# Playwright E2E (CI-grade)

## Obiettivo
Suite end-to-end stabile per validare il plugin cookie in ambiente WordPress reale, con output adatto a CI.

## Requisiti
- WordPress avviato (default: `http://127.0.0.1:9998`)
- Plugin FAZ Cookie Manager attivo
- Utente admin disponibile

## Configurazione env
Copia `.env.e2e.example` e imposta:
- `WP_BASE_URL`
- `WP_ADMIN_USER`
- `WP_ADMIN_PASS`
- `FAZ_PLUGIN_DEPLOY_PATH` (richiesto dai lifecycle tests, ad esempio `/path/to/wp-content/plugins/faz-cookie-manager`)

## Comandi

- `npm install`
- `npm run test:e2e`
- `npm run test:e2e:headed`
- `npm run test:e2e:report`

### Verifica scanner di rilascio

- Matrice browser reale per il cookie PHP `HttpOnly` annuale:
  `FAZ_E2E_BROWSERS=chromium,firefox,webkit npx playwright test -c tests/e2e/playwright.config.ts tests/e2e/specs/scan-catalog-deep.spec.ts -g '06b\.'`
- Crawl integrato da almeno 330 pagine con TTL compresso:
  `npx playwright test -c tests/e2e/playwright.config.ts tests/e2e/specs/release-verify-long-crawl.spec.ts --project=chromium`
- Multisite isolato (crea una rete e un database temporanei e li elimina sempre):
  `WP_PATH=/path/to/reference-wordpress npm run test:e2e:multisite`

La suite multisite non va lanciata con la configurazione E2E ordinaria: il runner
crea una rete temporanea, passa al test gli URL main/child e usa il relativo
`playwright.multisite.config.ts`. Eseguire direttamente lo spec contro il sito
single-site produce correttamente un errore di topologia (`/child` mancante).

Prima della suite E2E ordinaria, sincronizza il worktree nel WordPress di test:
`FAZ_DEPLOY_TARGET="$FAZ_PLUGIN_DEPLOY_PATH" npm run test:e2e:deploy`. Il preflight
confronta poi i checksum e interrompe il run se anche un solo file, incluso
`admin/assets/js/modules/scan-engine.js`, diverge. Non usare
`FAZ_E2E_SKIP_DEPLOY_CHECK=1` per una verifica di release.

`FAZ_E2E_BROWSERS` accetta una lista separata da virgole tra `chromium`,
`firefox` e `webkit`; il default resta `chromium`, quindi il costo della suite
storica non cambia.

## Output report

- HTML: `tests/e2e/reports/html`
- JUnit: `tests/e2e/reports/junit/results.xml`
- JSON: `tests/e2e/reports/results.json`
- Trace/video/screenshot fail: `tests/e2e/reports/artifacts`

## Garanzie CI

- `retries` configurati
- `trace` su primo retry
- screenshot/video solo su failure
- report multipli (`list`, `html`, `junit`, `json`)
