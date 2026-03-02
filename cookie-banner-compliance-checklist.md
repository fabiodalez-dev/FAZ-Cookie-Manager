# COOKIE BANNER COMPLIANCE CHECKLIST

Tutte le regole per un plugin di cookie consent conforme al diritto europeo e italiano.

**Versione:** 1.0 — Marzo 2026
**Standard:** GDPR • ePrivacy Directive • Linee Guida Garante Privacy 2021 • IAB TCF 2.2 • Google Consent Mode v2 • EDPB Guidelines

---

## 1. Quadro Normativo di Riferimento

Questo documento raccoglie tutte le regole applicabili a un cookie banner conforme al diritto europeo e italiano. Le fonti principali sono:

- **GDPR (Reg. UE 2016/679)** — Artt. 4(11), 5, 6, 7, 12, 13, 24, 25. Definisce il consenso, i principi di liceità, correttezza, trasparenza, privacy by design e by default.
- **Direttiva ePrivacy 2002/58/CE** — Art. 5(3). Richiede consenso informato per l'archiviazione o l'accesso a informazioni sul terminale dell'utente (cookie, pixel, fingerprinting, ecc.).
- **Codice Privacy italiano (D.Lgs. 196/2003)** — Art. 122. Recepisce la direttiva ePrivacy nell'ordinamento italiano.
- **Linee Guida Cookie del Garante Privacy** — Provvedimento 10 giugno 2021 (doc. web n. 9677876). In vigore dal 10 gennaio 2022. Fonte primaria per l'Italia.
- **Provvedimento Garante del 27 febbraio 2025** (doc. web n. 10118222). Caso sanzionatorio con ammonimento che chiarisce le violazioni concrete.
- **EDPB Guidelines 05/2020 sul consenso** — Linee guida europee sul consenso ai sensi del GDPR.
- **EDPB Cookie Banner Taskforce (2022)** — Coordinamento delle DPA europee per l'uniformità delle regole sui cookie banner.
- **IAB Transparency & Consent Framework (TCF) 2.2** — Standard tecnico per la gestione del consenso nell'ecosistema pubblicitario.
- **Google Consent Mode v2** — Requisiti tecnici per l'integrazione con i servizi Google (Analytics, Ads, Tag Manager).

---

## 2. Principi Fondamentali

### 2.1 Privacy by Default

Al primo accesso al sito web, nessun cookie o altro strumento di tracciamento diverso da quelli tecnici deve essere posizionato sul dispositivo dell'utente. Tutti i cookie non tecnici devono essere bloccati preventivamente (prior blocking) fino all'ottenimento di un consenso valido. Questo è un obbligo inderogabile.

### 2.2 Consenso Valido (art. 4(11) GDPR)

Il consenso deve essere: libero (senza condizionamenti o pressioni), specifico (per ciascuna finalità), informato (l'utente deve sapere a cosa acconsente), inequivocabile (manifestato tramite azione positiva chiara), e dimostrabile (il titolare deve poter provare che il consenso è stato prestato).

### 2.3 Trasparenza e Correttezza

L'utente deve essere messo nelle condizioni di comprendere chiaramente cosa accade ai suoi dati. Il banner non deve contenere comandi dal significato incerto o ambiguo. Nessun dark pattern è ammesso. I pulsanti di accettazione e rifiuto devono avere uguale evidenza grafica.

### 2.4 Accountability

Il titolare del trattamento deve essere in grado di dimostrare la conformità del proprio sistema di gestione dei cookie. Ciò include la registrazione dei consensi (proof of consent), la documentazione delle scelte implementative e la capacità di fornire evidenza in caso di ispezione.

---

## 3. Checklist: Banner di Primo Livello

Il banner è il meccanismo principale per l'acquisizione del consenso. Deve comparire immediatamente al primo accesso dell'utente.

### 3.1 Aspetto e Posizionamento

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| B01 | Il banner deve apparire immediatamente al primo accesso alla home page o a qualsiasi pagina del sito | UI | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| B02 | Le dimensioni devono creare una percettibile discontinuità nella fruizione dei contenuti, senza però impedire completamente la visualizzazione della pagina sottostante | UI | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| B03 | Il banner deve avere colori a contrasto con quelli del sito per essere chiaramente distinguibile | UI | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| B04 | Il banner non deve scomparire autonomamente né per timeout: deve restare visibile fino a quando l'utente non compie una scelta attiva | UI | 🔴 OBBLIGATORIO | EDPB, DPA Irlanda |
| B05 | Il banner deve essere accessibile anche a persone con disabilità (WCAG 2.1 AA minimo), compatibile con tecnologie assistive e screen reader | A11Y | 🔴 OBBLIGATORIO | Garante LG 2021, EAA |
| B06 | Il banner deve essere responsive e correttamente fruibile su dispositivi mobili (smartphone, tablet) | UI | 🔴 OBBLIGATORIO | Best practice |

### 3.2 Contenuto Informativo del Banner

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| I01 | Informativa breve (sintetica) sull'utilizzo di cookie tecnici e, se presenti, cookie di profilazione o altri strumenti di tracciamento | INFO | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| I02 | Indicazione chiara delle finalità dei cookie non tecnici (es. profilazione, marketing, analytics di terza parte) | INFO | 🔴 OBBLIGATORIO | Garante LG 2021, EDPB |
| I03 | Specificare se i cookie di profilazione sono anche di terze parti | INFO | 🔴 OBBLIGATORIO | Garante FAQ cookie |
| I04 | Link all'informativa estesa (cookie policy completa) | INFO | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| I05 | Link a un'area dove selezionare in modo analitico le funzionalità, terze parti e cookie cui prestare consenso (secondo livello) | INFO | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| I06 | Indicazione che chiudendo il banner con la X si mantengono le impostazioni predefinite (no cookie non tecnici) | INFO | 🔴 OBBLIGATORIO | Garante FAQ cookie |
| I07 | Menzione esplicita del diritto di revocare il consenso in qualsiasi momento | INFO | 🟠 RACCOMANDATO | GDPR art. 7(3), DPA Belgio |
| I08 | NON chiedere il consenso per i cookie tecnici (non è richiesto dalla normativa e confonde l'utente) | INFO | 🔴 OBBLIGATORIO | Garante Provv. 2025, art. 122 CP |
| I09 | Il linguaggio deve essere semplice, chiaro e non ambiguo. Non usare gergo tecnico incomprensibile | INFO | 🔴 OBBLIGATORIO | GDPR art. 12, Garante LG 2021 |

### 3.3 Comandi e Pulsanti

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| P01 | Pulsante "Accetta tutto" (o equivalente) per accettare tutti i cookie e strumenti di tracciamento | UI/CMD | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| P02 | Pulsante "Rifiuta tutto" (o equivalente) oppure una X in alto a destra con la stessa funzione di rifiuto | UI/CMD | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| P03 | Il comando di rifiuto (X o pulsante) deve avere UGUALE EVIDENZA GRAFICA del pulsante di accettazione: stesse dimensioni, stesso peso visivo, stesso livello di accessibilità | UI/CMD | 🔴 OBBLIGATORIO | Garante LG 2021, EDPB, CNIL |
| P04 | Pulsante/link "Personalizza" o "Gestisci preferenze" per accedere al secondo livello di scelta granulare | UI/CMD | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| P05 | NON usare pulsanti dal significato ambiguo o incerto (es. "Vai!", "Continua", "OK", "Declino" senza spiegazione) | UI/CMD | 🔴 OBBLIGATORIO | Garante Provv. 2025 |
| P06 | NON usare più comandi con diversa dicitura ma uguale funzionalità (confonde l'utente) | UI/CMD | 🔴 OBBLIGATORIO | Garante Provv. 2025 |
| P07 | Il rifiuto deve essere raggiungibile con lo stesso numero di clic dell'accettazione (massimo 1 clic) | UI/CMD | 🔴 OBBLIGATORIO | Garante, DPA Grecia, EDPB |
| P08 | NON usare dark patterns: colori diversi, dimensioni diverse, posizionamento sfavorevole per scoraggiare il rifiuto | UI/CMD | 🔴 OBBLIGATORIO | EDPB, CNIL, tutte le DPA |
| P09 | NON pre-flaggare checkbox di consenso per cookie non tecnici | UI/CMD | 🔴 OBBLIGATORIO | GDPR art. 7, EDPB GL 05/2020 |

---

## 4. Checklist: Secondo Livello (Preferenze Granulari)

Il secondo livello è l'area dove l'utente può gestire le proprie preferenze in modo analitico.

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| G01 | Il consenso deve essere GRANULARE: l'utente deve poter accettare/rifiutare per categoria di finalità (es. analytics, marketing, profilazione, social media, ecc.) | CONSENT | 🔴 OBBLIGATORIO | Garante LG 2021, EDPB |
| G02 | Nel secondo livello, offrire la possibilità di accettare/rifiutare anche per singolo cookie o per singola terza parte | CONSENT | 🟠 RACCOMANDATO | Garante LG 2021, DPA Belgio |
| G03 | I cookie tecnici/necessari devono essere mostrati come non disattivabili (toggle attivo e bloccato), con chiara spiegazione del motivo | CONSENT | 🔴 OBBLIGATORIO | Best practice consolidata |
| G04 | Per ogni categoria mostrare: finalità, elenco dei cookie, durata, titolare/terza parte responsabile | INFO | 🔴 OBBLIGATORIO | GDPR art. 13, Garante LG 2021 |
| G05 | Anche nel secondo livello devono essere presenti i comandi "Accetta tutto" e "Rifiuta tutto" | UI/CMD | 🟠 RACCOMANDATO | CNIL, DPA Danimarca |
| G06 | Pulsante "Salva preferenze" o equivalente per confermare le scelte granulari | UI/CMD | 🔴 OBBLIGATORIO | Best practice |
| G07 | I toggle/checkbox per i cookie non tecnici devono essere OFF per impostazione predefinita | CONSENT | 🔴 OBBLIGATORIO | GDPR art. 25, Garante LG 2021 |
| G08 | NON usare il "legitimate interest" come base giuridica alternativa per eludere il consenso ai cookie di tracciamento (è vietato per cookie ex art. 122 Codice Privacy) | LEGAL | 🔴 OBBLIGATORIO | ePrivacy Dir. art. 5(3) |

---

## 5. Checklist: Blocco Preventivo dei Cookie

Il blocco preventivo (prior blocking) è uno degli aspetti più critici. È l'obbligo di non installare alcun cookie non tecnico prima del consenso.

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| BL01 | Al primo caricamento della pagina, PRIMA del consenso, nessun cookie di profilazione, marketing o analytics di terza parte deve essere installato | TECH | 🔴 OBBLIGATORIO | Garante LG 2021, art. 122 CP |
| BL02 | Bloccare gli script di terze parti (Google Analytics, Facebook Pixel, Google Ads, ecc.) fino al consenso. Nessun tag deve fare fire prima del consenso | TECH | 🔴 OBBLIGATORIO | Tutte le DPA europee |
| BL03 | Bloccare embed e iframe che installano cookie (YouTube, Google Maps, social buttons, ecc.) fino al consenso, mostrando un placeholder informativo | TECH | 🔴 OBBLIGATORIO | Garante LG 2021 |
| BL04 | Il blocco deve essere verificabile tecnicamente: uno scan del sito senza consenso non deve rivelare cookie non tecnici nelle DevTools del browser | TECH | 🔴 OBBLIGATORIO | Test funzionale |
| BL05 | Dopo il consenso, attivare SOLO i cookie per le categorie effettivamente accettate dall'utente | TECH | 🔴 OBBLIGATORIO | GDPR art. 7 |
| BL06 | In caso di revoca del consenso, i cookie precedentemente installati devono essere rimossi o disattivati | TECH | 🔴 OBBLIGATORIO | GDPR art. 7(3) |
| BL07 | I cookie analytics di prima parte possono essere equiparati ai tecnici SE: utilizzati solo dal titolare, in forma aggregata, senza incrocio con altri dati, con IP anonimizzato (es. GA4 con IP anonymization) | TECH | 🟢 ECCEZIONE | Garante LG 2021 §5 |
| BL08 | I cookie analytics di terza parte possono essere equiparati ai tecnici SOLO SE: i dati sono minimizzati (es. IP troncato), non combinati con altri trattamenti, non trasmessi a ulteriori terzi | TECH | 🟢 ECCEZIONE | Garante LG 2021 §5 |

---

## 6. Checklist: Gestione e Memorizzazione del Consenso

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| C01 | Registrare una prova del consenso (proof of consent): data/ora, scelta effettuata, versione della cookie policy, identificativo univoco della sessione | CONSENT | 🔴 OBBLIGATORIO | GDPR art. 7(1), CNIL |
| C02 | Il consenso deve poter essere trasmesso/dimostrato anche alle terze parti che ne fanno affidamento per il trattamento | CONSENT | 🔴 OBBLIGATORIO | CNIL §29 |
| C03 | Memorizzare la scelta dell'utente (accettazione, rifiuto, o scelta granulare) tramite cookie tecnico o altro meccanismo equivalente | TECH | 🔴 OBBLIGATORIO | Garante LG 2021 |
| C04 | NON riproporre il banner se l'utente ha già effettuato una scelta (sia accettazione che rifiuto) | UX | 🔴 OBBLIGATORIO | Garante LG 2021 §6.2 |
| C05 | La riproposizione del banner è legittima SOLO se: (a) sono trascorsi almeno 6 mesi dall'ultima scelta; (b) sono cambiate le condizioni del trattamento (nuove terze parti, nuove finalità); (c) l'utente ha cancellato i cookie dal browser | UX | 🔴 OBBLIGATORIO | Garante LG 2021 §6.2 |
| C06 | Il consenso allo scrolling (scorrimento della pagina) NON è valido come manifestazione di consenso | CONSENT | 🔴 OBBLIGATORIO | Garante LG 2021, EDPB, tutte DPA |
| C07 | Il consenso tramite mera prosecuzione della navigazione NON è valido | CONSENT | 🔴 OBBLIGATORIO | Garante LG 2021, EDPB |
| C08 | Per utenti autenticati (con account): è vietato l'incrocio dei dati di navigazione tra più dispositivi senza consenso specifico | CONSENT | 🔴 OBBLIGATORIO | Garante LG 2021 |

---

## 7. Checklist: Revoca del Consenso e Widget Persistente

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| R01 | Revocare il consenso deve essere altrettanto facile quanto prestarlo: massimo lo stesso numero di clic | UX | 🔴 OBBLIGATORIO | GDPR art. 7(3), tutte le DPA |
| R02 | Un link/pulsante per riaprire le preferenze cookie deve essere sempre visibile e accessibile nel footer del sito | UI | 🔴 OBBLIGATORIO | Garante LG 2021 |
| R03 | Il Garante raccomanda un'icona sempre visibile (es. nel footer o floating) che riassuma lo stato dei consensi resi dall'utente | UI | 🟠 RACCOMANDATO | Garante LG 2021 |
| R04 | Cliccando sull'icona/link, l'utente deve poter rivedere e modificare tutte le proprie scelte | UI | 🔴 OBBLIGATORIO | Garante LG 2021 |
| R05 | La revoca del consenso deve comportare l'effettiva rimozione o disattivazione dei cookie precedentemente installati | TECH | 🔴 OBBLIGATORIO | GDPR art. 7(3) |
| R06 | L'utente deve essere informato prima di prestare il consenso su come potrà revocarlo | INFO | 🔴 OBBLIGATORIO | GDPR art. 7(3), DPA Spagna |

---

## 8. Checklist: Cookie Policy (Informativa Estesa)

La cookie policy è l'informativa completa di secondo livello, raggiungibile dal link nel banner.

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| CP01 | Identità e dati di contatto del titolare del trattamento | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(1)(a) |
| CP02 | Dati di contatto del DPO (se nominato) | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(1)(b) |
| CP03 | Finalità del trattamento per ciascuna tipologia di cookie | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(1)(c) |
| CP04 | Base giuridica del trattamento (consenso per i cookie non tecnici, legittimo interesse per i tecnici) | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(1)(c) |
| CP05 | Elenco analitico di tutti i cookie installati dal sito: nome, finalità, durata, prima parte o terza parte, dominio | INFO | 🔴 OBBLIGATORIO | GDPR art. 13, Garante LG 2021 |
| CP06 | Destinatari o categorie di destinatari dei dati | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(1)(e) |
| CP07 | Periodo di conservazione dei dati / durata dei cookie | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(2)(a) |
| CP08 | Diritti dell'interessato: accesso, rettifica, cancellazione, limitazione, portabilità, opposizione | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(2)(b-d) |
| CP09 | Diritto di reclamo presso l'autorità di controllo (Garante Privacy) | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(2)(d) |
| CP10 | Modalità di gestione, disabilitazione e cancellazione dei cookie (anche tramite browser) | INFO | 🔴 OBBLIGATORIO | Garante LG 2021 |
| CP11 | Trasferimento dati verso paesi terzi (se applicabile, es. Google USA) | INFO | 🔴 OBBLIGATORIO | GDPR art. 13(1)(f) |
| CP12 | L'informativa deve essere scritta in linguaggio semplice, accessibile e non discriminatorio | INFO | 🔴 OBBLIGATORIO | GDPR art. 12, Garante LG 2021 |
| CP13 | Se il sito usa solo cookie tecnici, l'informativa può essere nella privacy policy generale (senza banner) | INFO | 🟢 ECCEZIONE | Garante LG 2021 |
| CP14 | L'informativa può essere resa in modalità multilayer e multichannel (video, pop-up, interazioni vocali) | INFO | 🟠 RACCOMANDATO | Garante LG 2021 |

---

## 9. Checklist: Cookie Wall e Pratiche Vietate

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| V01 | I cookie wall (bloccare l'accesso al sito se l'utente non accetta i cookie) sono ILLECITI, salvo che il titolare offra un'alternativa equivalente per accedere ai contenuti (anche a pagamento, caso per caso) | LEGAL | 🔴 OBBLIGATORIO | Garante LG 2021 §6.1 |
| V02 | NON condizionare la navigazione all'accettazione dei cookie mostrando messaggi come "Devi accettare almeno i cookie tecnici" (i tecnici non richiedono consenso!) | LEGAL | 🔴 OBBLIGATORIO | Garante Provv. 2025 |
| V03 | NON usare il "nudging" o manipolazione visiva per orientare l'utente verso l'accettazione (colori, posizione, dimensioni diverse tra accetta e rifiuta) | LEGAL | 🔴 OBBLIGATORIO | EDPB, tutte le DPA |
| V04 | NON installare cookie prima del consenso nemmeno "per pochi secondi" o "solo nella home page" | LEGAL | 🔴 OBBLIGATORIO | Garante LG 2021 |
| V05 | NON considerare la chiusura del browser o l'abbandono del sito come manifestazione di consenso | LEGAL | 🔴 OBBLIGATORIO | EDPB GL 05/2020 |
| V06 | NON utilizzare caselle pre-selezionate (pre-ticked boxes) per cookie non tecnici | LEGAL | 🔴 OBBLIGATORIO | CGUE Planet49, GDPR Cons. 32 |
| V07 | NON raggruppare tutti i cookie in un unico consenso generico senza granularità | LEGAL | 🔴 OBBLIGATORIO | GDPR art. 7, Garante LG 2021 |
| V08 | NON nascondere l'opzione di rifiuto dietro più livelli di navigazione rispetto all'accettazione | LEGAL | 🔴 OBBLIGATORIO | CNIL, EDPB Taskforce |

---

## 10. Checklist: Requisiti Tecnici per il Plugin

### 10.1 IAB TCF 2.2

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| T01 | Implementare la CMP API (`__tcfapi`) conforme a IAB TCF 2.2 per comunicare lo stato del consenso ai vendor pubblicitari | TCF | 🟠 RACCOMANDATO | IAB TCF 2.2 spec |
| T02 | Generare la TC String (Transparency & Consent string) con le scelte dell'utente e renderla disponibile via API | TCF | 🟠 RACCOMANDATO | IAB TCF 2.2 spec |
| T03 | Supportare la GVL (Global Vendor List) per mostrare i vendor registrati e le loro finalità | TCF | 🟠 RACCOMANDATO | IAB TCF 2.2 spec |
| T04 | Le Special Features (es. geolocalizzazione precisa) richiedono opt-in esplicito, non legitimate interest | TCF | 🔴 OBBLIGATORIO | IAB TCF 2.2 Policy |

### 10.2 Google Consent Mode v2

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| GC01 | Impostare i default di Google Consent Mode prima del caricamento di gtag/GTM: `ad_storage=denied`, `analytics_storage=denied`, `ad_user_data=denied`, `ad_personalization=denied` | GMODE | 🔴 OBBLIGATORIO | Google Consent Mode v2 docs |
| GC02 | Aggiornare i segnali di consenso (`gtag('consent', 'update', {...})`) quando l'utente accetta specifiche categorie | GMODE | 🔴 OBBLIGATORIO | Google Consent Mode v2 docs |
| GC03 | Supportare `functionality_storage` e `personalization_storage` per le categorie di cookie funzionali | GMODE | 🟠 RACCOMANDATO | Google Consent Mode v2 docs |
| GC04 | Supportare `security_storage` (sempre granted) per i cookie di sicurezza | GMODE | 🟠 RACCOMANDATO | Google Consent Mode v2 docs |
| GC05 | Inviare il parametro `wait_for_update` per ritardare il firing dei tag fino all'interazione con il banner | GMODE | 🟠 RACCOMANDATO | Google Consent Mode v2 docs |

### 10.3 Implementazione Tecnica Generale

| # | Regola | Cat. | Livello | Riferimento |
|---|--------|------|---------|-------------|
| TG01 | Bloccare gli script di terze parti tramite modifica dell'attributo `type` (es. `text/plain`) o rimozione dal DOM fino al consenso | TECH | 🔴 OBBLIGATORIO | Best practice |
| TG02 | Fornire API JavaScript pubblica (eventi/callback) per attivare/disattivare script in base alle categorie di consenso | TECH | 🟠 RACCOMANDATO | Best practice |
| TG03 | Supportare l'auto-blocking tramite scan automatico degli script noti (Google Analytics, Facebook Pixel, ecc.) | TECH | 🟠 RACCOMANDATO | Best practice |
| TG04 | Generare automaticamente la lista dei cookie rilevati sul sito (cookie scanner) | TECH | 🟠 RACCOMANDATO | Best practice |
| TG05 | Supportare il blocco/sblocco degli iframe (YouTube, Google Maps, social widgets) con placeholder informativi | TECH | 🔴 OBBLIGATORIO | Garante LG 2021 |
| TG06 | Il cookie di preferenza (consent cookie) deve essere classificato come cookie tecnico necessario | TECH | 🔴 OBBLIGATORIO | Best practice |
| TG07 | Supportare il multilingua per siti in più lingue | TECH | 🟠 RACCOMANDATO | Best practice, GDPR art. 12 |
| TG08 | NON caricare risorse esterne (font, CSS, JS da CDN di terze parti) che installano cookie prima del consenso | TECH | 🔴 OBBLIGATORIO | Garante LG 2021 |
| TG09 | Loggare server-side le proof of consent con timestamp, versione policy, scelte, user agent, IP anonimizzato | TECH | 🔴 OBBLIGATORIO | GDPR art. 7(1) |

---

## 11. Test di Conformità da Eseguire

Questa sezione definisce i test pratici da eseguire per verificare la conformità del plugin.

### 11.1 Test Funzionali del Banner

| # | Test | Tipo | Risultato | Note |
|---|------|------|-----------|------|
| TF01 | Primo accesso in modalità incognito: verificare che il banner appaia immediatamente | FUNZ | ⬜ PASS/FAIL | |
| TF02 | Primo accesso: aprire DevTools > Application > Cookies e verificare che NON ci siano cookie non tecnici prima di qualsiasi azione | FUNZ | ⬜ PASS/FAIL | **Critico** |
| TF03 | Cliccare "Rifiuta tutto" o X: verificare che il banner scompaia e che NON vengano installati cookie non tecnici | FUNZ | ⬜ PASS/FAIL | |
| TF04 | Dopo il rifiuto, navigare su altre pagine: verificare che il banner NON riappaia | FUNZ | ⬜ PASS/FAIL | |
| TF05 | Cliccare "Accetta tutto": verificare che i cookie delle categorie accettate vengano installati | FUNZ | ⬜ PASS/FAIL | |
| TF06 | Personalizzare le preferenze (es. solo analytics, no marketing): verificare che vengano installati SOLO i cookie delle categorie selezionate | FUNZ | ⬜ PASS/FAIL | |
| TF07 | Verificare che lo scrolling o la prosecuzione della navigazione NON costituiscano consenso | FUNZ | ⬜ PASS/FAIL | |
| TF08 | Verificare che il pulsante di rifiuto e accettazione abbiano stesse dimensioni, colore, posizione equivalente | UI | ⬜ PASS/FAIL | |
| TF09 | Verificare che il banner sia completamente funzionante su mobile (touch, scroll, orientamento) | UI | ⬜ PASS/FAIL | |
| TF10 | Verificare che il banner sia navigabile da tastiera e leggibile da screen reader (NVDA, VoiceOver) | A11Y | ⬜ PASS/FAIL | |
| TF11 | Verificare il comportamento dopo cancellazione dei cookie dal browser: il banner deve riapparire | FUNZ | ⬜ PASS/FAIL | |
| TF12 | Verificare che dopo 6 mesi dalla scelta il banner riappaia (simulare con data di scadenza del cookie di consenso) | FUNZ | ⬜ PASS/FAIL | |
| TF13 | Verificare che il link nel footer per modificare le preferenze sia presente e funzionante su tutte le pagine | FUNZ | ⬜ PASS/FAIL | |
| TF14 | Verificare che la revoca del consenso cancelli effettivamente i cookie precedentemente installati | FUNZ | ⬜ PASS/FAIL | |
| TF15 | Verificare che le proof of consent vengano registrate correttamente nel database/log | FUNZ | ⬜ PASS/FAIL | |
| TF16 | Verificare che iframe bloccati (YouTube, Maps) mostrino un placeholder e non carichino contenuti prima del consenso | FUNZ | ⬜ PASS/FAIL | |
| TF17 | Verificare che Google Consent Mode riceva i segnali corretti (default denied, update dopo consenso) | TECH | ⬜ PASS/FAIL | Se applicabile |
| TF18 | Verificare la coerenza tra il numero/tipo di cookie dichiarati nella cookie policy e quelli effettivamente installati | AUDIT | ⬜ PASS/FAIL | |

---

## 12. Categorie di Cookie Standard

Per la granularità del consenso, le categorie standard da implementare nel plugin sono:

| Categoria | Descrizione | Consenso | Base Giuridica | Esempi |
|-----------|-------------|----------|----------------|--------|
| **Necessari / Tecnici** | Indispensabili per il funzionamento base del sito. Carrello, sessione, CSRF, preferenze lingua, cookie di consenso stesso. | 🟢 NON richiesto | Art. 122 CP, ePrivacy art. 5(3) esenzione | `PHPSESSID`, `csrf_token`, `cookie_consent` |
| **Analytics / Statistici** | Raccolta dati statistici anonimi/aggregati sull'uso del sito. Equiparabili ai tecnici solo se di prima parte, con IP anonimizzato, senza incrocio dati. | 🟠 Dipende | Consenso o esenzione (se minimizzati) | `_ga`, `_gid`, Matomo (self-hosted) |
| **Funzionali / Esperienza** | Migliorano l'esperienza utente: chat live, embed video, mappe, contenuti personalizzati non basati su profilazione. | 🔴 RICHIESTO | Consenso | YouTube embed, Google Maps, Crisp chat |
| **Marketing / Profilazione** | Tracciamento cross-site per pubblicità comportamentale, retargeting, remarketing. Cookie di terze parti per fini pubblicitari. | 🔴 RICHIESTO | Consenso esplicito | `_fbp`, `_gcl_au`, Google Ads, Meta Pixel |
| **Social Media** | Share button, social login, widget social che installano cookie di tracciamento. Anche i like/share button possono installare cookie. | 🔴 RICHIESTO | Consenso | Facebook SDK, Twitter widget, Instagram embed |

---

## 13. Sanzioni e Rischi

La non conformità alle regole sui cookie può comportare sanzioni significative:

- **GDPR art. 83(5):** Fino a 20 milioni di euro o il 4% del fatturato annuo mondiale (la cifra più alta tra le due) per violazioni dei principi di base del trattamento, incluso il consenso.
- **GDPR art. 83(4):** Fino a 10 milioni di euro o il 2% del fatturato annuo mondiale per violazioni degli obblighi del titolare del trattamento.
- **Codice Privacy art. 166:** Il Garante può adottare provvedimenti correttivi (ammonimento, ordine di conformità, limitazione del trattamento) e sanzioni pecuniarie.
- **Caso concreto (Provv. 27/02/2025):** Il Garante ha contestato violazioni degli artt. 4(11), 5, 7, 12, 13, 24 e 25 del GDPR e dell'art. 122 del Codice Privacy per un banner con comandi ambigui, richiesta di consenso per cookie tecnici, e riproposizione invasiva del banner.
- **Controlli attivi:** La Guardia di Finanza (Nucleo Speciale Tutela Privacy e Frodi Tecnologiche) effettua accertamenti on-line su campioni di operatori. I settori e-commerce, turismo e trasporti sono stati tra i primi target.

### Violazioni chiave dal caso Garante Provv. 2025:

1. Banner con 5 comandi di significato incerto ("Accetto cookie tecnici", "Accetto tutti", "Declino", "Vai!", "X")
2. Nessuna chiara indicazione di quale comando permetta la navigazione senza cookie non tecnici
3. Richiesta di consenso per cookie tecnici (non richiesto dalla normativa!)
4. Messaggio "Devi accettare almeno i cookie tecnici" (i cookie tecnici non richiedono consenso!)
5. Banner riproposto a ogni accesso dopo il rifiuto (invasivo, viola la regola dei 6 mesi)
6. Stesso numero di cookie installati indipendentemente dalla scelta (nessun blocco effettivo)

---

## 14. Riepilogo: Confronto Regole Italia vs Europa

| Regola | Italia (Garante) | Francia (CNIL) | EDPB |
|--------|-----------------|----------------|------|
| Scrolling come consenso | NO | NO | NO |
| Navigazione come consenso | Probabilmente no | NO | NO |
| Pulsanti Accetta/Rifiuta obbligatori | SI (o X equivalente) | SI con uguale rilievo | SI |
| Uguale evidenza grafica | SI | SI | SI |
| Blocco preventivo | SI | SI | SI |
| Cookie wall ammessi | NO (salvo alternativa) | Possibile (caso per caso) | NO |
| Consenso granulare | SI (per categoria) | SI | SI |
| Proof of consent | SI | SI (trasmissibile a terzi) | Probabile |
| Revoca facile come consenso | SI (link footer) | SI (link/pulsante visibile) | SI |
| Non reiterare banner | SI (min 6 mesi) | SI | SI |
| Cookie elencati singolarmente | Non specificato | NO | Non specificato |

---

## 15. Regole Critiche di Implementazione (Quick Reference)

1. **Privacy by default:** TUTTI i cookie non tecnici bloccati fino a consenso esplicito
2. **Uguale prominenza:** Il pulsante Rifiuta DEVE avere lo stesso peso visivo di Accetta
3. **No ambiguità:** Etichette dei comandi chiare, inequivocabili
4. **Granularità:** Consenso per categoria di finalità come minimo
5. **Proof of consent:** Log di tutti gli eventi di consenso con timestamp, versione, scelte
6. **Revoca:** Link nel footer sempre visibile, stessa facilità del consenso
7. **No riproposizione:** Rispettare la scelta dell'utente per minimo 6 mesi
8. **Cookie tecnici:** MAI chiedere consenso, separati chiaramente
9. **Accessibilità:** WCAG 2.1 AA minimo, compatibile con screen reader
10. **Testing:** Verifica via DevTools che zero cookie non tecnici vengano caricati pre-consenso

---

## 16. Fonti e Riferimenti Normativi

1. Regolamento (UE) 2016/679 (GDPR)
2. Direttiva 2002/58/CE (ePrivacy)
3. D.Lgs. 196/2003 (Codice Privacy italiano), art. 122
4. Garante Privacy, Linee Guida Cookie, 10/06/2021 (doc. web 9677876)
5. Garante Privacy, Provvedimento 27/02/2025 (doc. web 10118222)
6. Garante Privacy, [FAQ Cookie](https://www.garanteprivacy.it/faq/cookie)
7. EDPB, Guidelines 05/2020 on Consent under Regulation 2016/679
8. EDPB, Cookie Banner Taskforce (2022)
9. IAB Europe, Transparency & Consent Framework v2.2
10. Google, Consent Mode v2 Documentation
11. CNIL, Délibération n. 2020-091 (Lignes directrices cookies)
12. AEPD, Guía sobre el uso de las cookies (2024)
13. ICO (UK), Guidance on the use of cookies and similar technologies
14. CGUE, Causa C-673/17 (Planet49, 01/10/2019) — pre-ticked boxes
15. European Accessibility Act (EAA) — Dir. 2019/882

### Fonti informative non normative

1. iubenda.com, GDPR Cookie Consent Cheatsheet
2. e-cons.it, Banner Cookie non conformi: 5 errori da evitare
3. rubisco.it, GDPR e Cookie 2025

---

*Legenda livelli: 🔴 OBBLIGATORIO = obbligo normativo inderogabile | 🟠 RACCOMANDATO = best practice fortemente consigliata | 🟢 ECCEZIONE = regola con deroga applicabile a specifiche condizioni*
