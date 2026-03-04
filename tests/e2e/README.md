# Playwright E2E (CI-grade)

## Obiettivo
Suite end-to-end stabile per validare il plugin cookie in ambiente WordPress reale, con output adatto a CI.

## Requisiti
- WordPress avviato (default: `http://localhost:9998`)
- Plugin FAZ Cookie Manager attivo
- Utente admin disponibile

## Configurazione env
Copia `.env.e2e.example` e imposta:
- `WP_BASE_URL`
- `WP_ADMIN_USER`
- `WP_ADMIN_PASS`

## Comandi
- `npm install`
- `npm run test:e2e`
- `npm run test:e2e:headed`
- `npm run test:e2e:report`

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
