# GTM4WP / citationstyler.com — verifica e correzioni, 29 settembre 2026

## Risultato

Corretto il difetto riprodotto nel rilascio dopo consenso. Aggiunta la copertura dei file GTM4WP al controllo dell'HTML e al catalogo del blocco browser. La precedente correzione della precedenza nella mappa provider è già nel commit `715a3582` del branch `fix/provider-map-shadowing`.

Non è dimostrata la causa originaria del mancato blocco sul sito di Patrick. Le modifiche sono locali, non distribuite al sito e non pubblicate come release.

## Osservazione pubblica

Fonte: https://citationstyler.com/ (richiesta senza cookie di consenso).

I tre tag sono attualmente tutti `type="text/plain" data-faz-category="analytics"`:

- `gtm4wp-ecommerce-generic-js`, con `defer` e `data-wp-strategy="defer"`;
- `gtm4wp-woocommerce-js-before`;
- `gtm4wp-woocommerce-js`.

È coerente con il workaround dichiarato nel [thread](https://wordpress.org/support/topic/faz-blocks-only-one-of-gtm4wps-two-woocommerce-scripts/). L'HTML pubblico non permette di attribuire il blocco del terzo tag a FAZ oppure al filtro personalizzato. Non sono state modificate impostazioni, cache o file sul sito dell'utente.

Le due protezioni precedenti sono presenti nel repository: invalidazione cache/template/categorie su `faz_after_create_cookie` e inizializzazione preventiva di `window.dataLayer`.

## Riproduzione prima/dopo

Browser Chromium, fixture locale con jQuery e i due file GTM4WP scaricati dagli URL pubblici del sito. Un elemento prodotto sintetico consente di verificare `view_item_list`. Il file generico arriva con 700 ms di ritardo. Nessun contenitore GTM o endpoint di analytics viene chiamato: le risorse della fixture sono intercettate localmente.

Con il precedente `frontend/js/script.js` (HEAD prima di questa modifica):

- prima del consenso: zero eventi, helper non definita;
- dopo il consenso: `ReferenceError: gtm4wp_read_json_from_node is not defined`, zero eventi.

Con il nuovo `frontend/js/script.min.js`:

- prima del consenso: zero eventi, helper non definita;
- dopo il consenso: zero errori, WooCommerce inizializzato e un solo `view_item_list`;
- due richiami consecutivi al ripristino non duplicano l'evento.

Una seconda fixture con marcatori di esecuzione conferma l'ordine `generic → inline → woocommerce`.

Questa è una prova delle risorse reali in ambiente controllato, non un test end-to-end del sito pubblico con una nuova versione installata.

## Modifiche

- Coda condivisa per i tag bloccati nel DOM, gli script `data-faz-waitfor` e gli script contenuti nei template ripristinati.
- Gli script ordinati attendono `load`/`error` del precedente; gli inline classici eseguono al proprio turno. I moduli sono attesi, gli `async` espliciti restano asincroni.
- Nuovo controllo del consenso all'avvio di ogni tag, deduplicazione dei tag in attesa, recupero dei tag ancora bloccati quando il consenso viene nuovamente accordato.
- Errori di caricamento, tag rimossi, blocchi dati e fallback `nomodule` non lasciano la coda sospesa impropriamente. Il consenso per servizio del template viene trasmesso ai suoi script.
- I file `gtm4wp-ecommerce-generic.js`, `gtm4wp-woocommerce.js` e le varianti `.min.js` sono ora nel catalogo e nel template GTM. I vecchi pattern `gtm4wp` e `gtm4wp-` non bastavano al matcher con confini: il filtro WordPress riconosceva gli handle, ma il secondo strato non riconosceva questi URL. Nessuna modifica ai confini generali o all'esenzione degli script necessari di WooCommerce.
- Bundle minificato rigenerato; indicatore diagnostico `1.32.1+ordered-script-restore`; changelog aggiornato.

## Verifiche

- Suite unit completa: **187 suite superate, zero fallimenti**.
- Suite browser consenso Chromium: **94 test superati** (scelta, blocco rete, revoca e ricaricamento).
- Regressioni dedicate JavaScript: **25 asserzioni superate**; PHP/provider: **37 asserzioni superate** dopo la review CodeRabbit, incluse la precedenza tra categorie dello stesso pattern, il caricamento del catalogo a cache vuota e il controllo dell’attributo `type` effettivo.
- La prima serie di 16 asserzioni JavaScript produceva 8 fallimenti sul codice precedente; la riproduzione browser con i file GTM4WP reali conferma il ReferenceError prima della correzione.
- Sintassi PHP/JavaScript e `git diff --check`: validi. ESLint: zero errori; warning nel file esistente.

## Limite rimasto

Per chiudere il caso originale occorre verificare il sito dopo l'installazione della correzione e lo svuotamento delle cache, disabilitando temporaneamente il workaround in un ambiente controllato. Servono configurazione server e pipeline dei filtri per attribuire con certezza il mancato blocco iniziale: il solo HTML attuale non consente di ricostruirlo. Non è corretto dichiarare il thread risolto soltanto sulla base di questa patch.
