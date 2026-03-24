# Playwright Legacy Cleanup Plan

## Obiettivo
Ridurre bloat e dead code della vecchia suite ad-hoc mantenendo solo una baseline CI-grade.

## Cosa tenere
- `tests/e2e/**` (suite Playwright ufficiale)
- eventuale documentazione tecnica utile (`cookie-banner-compliance-checklist.md`)

## Cosa archiviare (non cancellare subito)
- `../debug-*.mjs`
- `../check-*.mjs`
- screenshot legacy `../*.png`
- CSV diagnostici e script one-shot non CI

## Cosa eliminare dopo validazione (fase 2)
- script duplicati/copiati che sovrappongono i nuovi spec CI
- artefatti immagini non più usati nei report

## Strategia raccomandata
1. Archiviare in cartella timestampata (`../legacy-playwright-archive/YYYYMMDD-HHMMSS`).
2. Eseguire `npm run test:e2e` e confermare copertura minima.
3. Solo dopo conferma, rimuovere definitivamente materiale legacy non necessario.

## Criteri anti-dead-code
- ogni test deve essere eseguibile via `playwright test`
- niente credenziali hardcoded
- niente SQL diretto su DB nei test
- niente `waitForTimeout` come sincronizzazione primaria
