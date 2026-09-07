# Consent intent verification — 2026-09-07

This PR combines open PRs #267–#274 and tests the resulting code together. Source PR branches remain open; nothing is merged into main or released by this work. Merge conflicts retain both category and banner translation-cache invalidation, all changelog entries, and the category editor refresh after renaming its translation helper.

## Defects reproduced and fixed

1. An opt-out banner could bundle a jurisdiction's separately gated sensitive category into Accept All. Separate opt-in now applies under both banner laws.
2. Explicit Reject could restore permissive jurisdiction defaults. Reject, including the existing close-as-reject handler, now denies all optional categories regardless of law.
3. Do Not Sell relied on the geo-overlaid CCPA default, which is not the sale/share classification. This could preserve a marketing grant or revoke unrelated analytics. The popup now revokes marked sale/share categories and preserves unrelated choices.
4. Accept All under an opt-out banner could retain denial of unrelated categories when an opt-out was already recorded. Explicit acceptance grants ordinary purposes while GPC/DNSMPI continue to deny sale/share and separate sensitive opt-in stays gated.
5. A targeted sale/share opt-out cleared unrelated per-cookie choices or read stale hidden preference controls. It now keeps unrelated granular choices and clears only sale/share overrides.
6. A malformed persisted category value could be denied by PHP but treated as allowed by the JavaScript resource gate. The client now requires the literal `yes` before allowing a known optional category.

7. With runtime geolocation disabled, a manually configured CCPA detail panel could ignore granular toggles and grant denied categories. Detail-panel Save now respects the explicit toggles regardless of geolocation. The added matrix reproduced 705 failures before this fix.

8. The synchronous iframe gate parked `src` in `data-faz-src` before the observer ran; the observer then missed the dynamic placeholder. It now processes parked iframes, preserves their inert URL through backup and restores a visible iframe only after consent. Vimeo/YouTube regressions now assert zero provider requests before consent and a visible restored iframe afterward; they cannot silently skip missing placeholders.

## Reproducible coverage

- `npm test` / `npm run test:e2e`: the `pretest:e2e` lifecycle automatically runs all unit suites and all three consent browsers before the full WordPress suite. Any failure stops the run.
- `npm run test:unit`: automatically includes both new jurisdiction suites, alongside all existing PHP and JavaScript suites.
- `tests/unit/test-jurisdiction-server-intent-php.php`: **6,392 cases**, invoking real `Frontend::get_blocked_categories()` and real `Geo_Runtime`. Covers 47 rulesets × both banner laws × all 16 optional-category combinations × four independent GPC/DNSMPI signal states; first visit, absent/invalid category entries, and cache shells warmed with grants. Only the database catalogue, resolved-ruleset input and validated-cookie input boundary are fixtures. This does not test WordPress cookie validation or geolocation detection itself; existing suites cover those boundaries.
- `tests/unit/js/jurisdiction-user-intent.test.mjs`: **3,854 scenarios**, using actual PHP-generated runtime payloads and the unmodified source frontend engine. Covers initial state/no consent identifier, malformed grants, Accept All/separate sensitive consent, targeted Do Not Sell, all 16 category selections and reconstruction from the persisted cookie in a fresh window, rejection, age gate, DNSMPI, GPC precedence, service revocation, and signal disappearance. An additional 1,504 cases cover all 16 granular selections with runtime geolocation disabled. The 16 selections are deliberate state transitions, not random samples. jsdom suppresses automatic banner bootstrap; it is not a network/browser integration test.
- `npm run test:consent:browser`: **282 browser flows** = 47 rulesets × both banner laws × Chromium/Firefox/WebKit. Loads the production minified frontend, drives consent handlers through fixture buttons, observes locally intercepted script requests, and checks initial gating, acceptance, targeted opt-out, withdrawal, persistence across navigation, GPC and malformed grants. Blocked requests are observed over a bounded 100 ms settling window and parked/removed script state is checked. It does not claim to detect arbitrary delayed tracking on every website.
- `tests/unit/js/full-suite-entrypoints.test.mjs`: **9 executable harness cases** prove the real npm entrypoints run unit → browser → WordPress, propagate failures, and make batch runs stop before site mutation when either consent stage fails. The real unit runner also fails if the required Node runtime is unavailable.
- CI runs the browser suite as three independent required-to-pass job results, with no retries or skipped browsers. Repository branch-protection settings determine whether these checks are mandatory for merging.
- Full WordPress E2E: execution result to be recorded below. An interrupted run on earlier code is not accepted as final evidence.

The tests discover all shipped profiles; adding a profile automatically adds cases. Assertions about explicit user intent are independent of jurisdiction defaults. Initial-state tests additionally check PHP/client agreement with the shipped catalogue; agreement is not proof that the catalogue's legal interpretation is correct.

## Primary-source scope and limits

Checked on 2026-09-07:

- [Garante cookie guidelines, 10 June 2021](https://www.garanteprivacy.it/home/docweb/-/docweb-display/docweb/9677876): informs the opt-in/choice and close-as-reject protections. The existing opt-out close handler also routes through rejection; this PR makes that path conservative.
- [EDPB consent guidelines 05/2020](https://www.edpb.europa.eu/documents/guideline/guidelines-052020-on-consent-under-regulation-2016679_fr): freely given, specific consent and withdrawal are the relevant principles; no bundled sensitive grant is inferred from a generic button.
- [California Privacy Protection Agency regulations](https://cppa.ca.gov/regulations/) and [consumer FAQ](https://cppa.ca.gov/faq): sale/sharing opt-out and user-enabled preference signals. The catalogue's sensitive-category gating is a conservative product policy; it is not a claim that every jurisdiction has an identical sensitive-data legal basis.
- [ICO storage/access guidance update, 29 April 2026](https://ico.org.uk/about-the-ico/media-centre/news-and-blogs/2026/04/final-storage-and-access-technologies-guidance-published/): UK guidance has changed after the Data (Use and Access) Act. This PR preserves the stricter opt-in preset and does not introduce new exemptions.
- [Canadian OPC consent principle](https://www.priv.gc.ca/en/privacy-topics/privacy-laws-in-canada/the-personal-information-protection-and-electronic-documents-act-pipeda/p_principle/principles/p_consent/): meaningful consent and withdrawal, subject to legal/contractual restrictions.
- [ANPD cookies guidance](https://www.gov.br/anpd/pt-br/centrais-de-conteudo/materiais-educativos-e-publicacoes/processo-guia-orientativo-cookies-e-protecao-de-dados-pessoais.pdf): revocation through a free, simplified process.
- [Singapore PDPC individual rights](https://www.pdpc.gov.sg/overview-of-pdpa/data-protection/individual/individuals-overview): withdrawal and cessation of the relevant collection/use/disclosure.

This is an engineering verification of the **47 supported profiles**, not certification of every law worldwide or a completed legal audit of each profile. Legal applicability, classification of necessary cookies, accurate disclosures/translations, purposes and vendors, data transfers, retention, age verification, deployment configuration, external scripts and provider-side deletion/cessation still require site-specific verification. The existing tests for GCM, TCF, consent logs, age gates, cookies/storage, cache and geolocation are also retained. Passing tests cannot prove that arbitrary third-party software or server-side tracking respects a browser preference.

## Profile inventory

Each row participates in the PHP, JavaScript and three-browser matrices. The sensitive column records the shipped product configuration, not a fresh legal opinion.

| Profile | Shipped model | Separate sensitive opt-in |
| --- | --- | --- |
| appi-japan | hybrid | true |
| ccpa-california | opt-out-with-sensitive-opt-in | true |
| cpa-colorado | opt-out-with-sensitive-opt-in | true |
| ctdpa-connecticut | opt-out-with-sensitive-opt-in | true |
| delaware-dpdpa | opt-out-with-sensitive-opt-in | true |
| dpdpa-india | opt-in | false |
| fallback-gdpr-most-protective | opt-in | true |
| fdbr-florida | opt-out-with-sensitive-opt-in | true |
| gdpr-france | opt-in | false |
| gdpr-germany | opt-in | false |
| gdpr-ireland | opt-in | false |
| gdpr-italy | opt-in | false |
| gdpr-netherlands | opt-in | false |
| gdpr-poland | opt-in | false |
| gdpr-spain | opt-in | false |
| gdpr-strict | opt-in | false |
| icdpa-indiana | opt-out-with-sensitive-opt-in | true |
| icdpa-iowa | opt-out-with-sensitive-opt-in | true |
| israel-ppl | opt-in | false |
| kcdpa-kentucky | opt-out-with-sensitive-opt-in | true |
| ksa-pdpl | opt-in | true |
| law25-quebec | hybrid | false |
| lgpd-brazil | opt-in | false |
| mcdpa-minnesota | opt-out-with-sensitive-opt-in | true |
| mcdpa-montana | opt-out-with-sensitive-opt-in | true |
| modpa-maryland | opt-out-with-sensitive-opt-in | true |
| nhpl-newhampshire | opt-out-with-sensitive-opt-in | true |
| njdpl-newjersey | opt-out-with-sensitive-opt-in | true |
| ocpa-oregon | opt-out-with-sensitive-opt-in | true |
| pdpa-malaysia | opt-in | false |
| pdpa-singapore | opt-in | false |
| pdpa-thailand | opt-in | false |
| pdpd-vietnam | opt-in | true |
| pipa-korea | opt-in | true |
| pipeda-canada | hybrid | false |
| pipl-china | opt-in | true |
| popia-southafrica | opt-in | false |
| privacy-act-australia | hybrid | false |
| privacy-act-newzealand | opt-in | false |
| ridtppa-rhodeisland | opt-out-with-sensitive-opt-in | true |
| tdpsa-texas | opt-out-with-sensitive-opt-in | true |
| tipa-tennessee | opt-out-with-sensitive-opt-in | true |
| turkey-kvkk | opt-in | false |
| uae-pdpl | opt-in | true |
| ucpa-utah | opt-out-with-sensitive-opt-in | true |
| uk-gdpr-pecr | opt-in | false |
| vcdpa-virginia | opt-out-with-sensitive-opt-in | true |

## Execution results

- Complete ordinary `npm test` chain on consent runtime `7615e3eb`: all 156 unit suites pass; all 282 production-minified browser flows pass without retries/skips (4.8 minutes); WordPress completes with **1,153 passed, 1 flaky, 8 skipped, 0 unexpected failures** in 81.97 minutes.
- Server matrix: 6,392/6,392 pass. JavaScript matrix: 3,854/3,854 pass, including 705 failures reproduced before the manual-banner fix.
- The flaky per-cookie test read `document.cookie` during the automatic reload triggered by withdrawal. It now reads the context cookie jar across navigation. The affected 10-test group (per-cookie and PR #92) passes without retries/skips after this fix and the parked-iframe fix.
- The final parked-iframe runtime is `48900e85`. After that delta, the ordinary `npm run test:e2e -- <17 affected spec files>` command automatically passed all **156 unit suites**, **282 browser flows** with zero retries/skips (3.9 minutes), and **163 WordPress tests** with zero failures/retries plus the one baseline-disabled TCF ping skip (5.9 minutes). This covers resource gates, actual provider requests, per-category/per-service/per-cookie consent, GCM/TCF, iframe restoration and storage. The earlier full run is not presented as a fresh full pass on this later delta. Subsequent changes only enforce the missing-Node gate and update this report; the nine gate tests pass and CI Quality runs the full unit runner again.
- The resource gate unit suite passes 161 assertions, including parked-iframe restoration and service-marker preservation.
- Full-suite entrypoint harness: 9/9 pass; missing Node is a hard failure, never a silent JavaScript skip.
- Isolated multisite: 1/1 pass; disposable network/database cleaned by the runner.
- PHP syntax, PHPStan, shellcheck and strict JSON schemas pass (47 profiles plus routing index; zero schema warnings).

The eight full-run skips are explicit:
- two PR #92 dynamic placeholders: exposed and fixed above; both now execute and pass;
- the conditional TCF ping test: TCF is disabled in baseline; the following test explicitly enables it and passes ping/timestamp/withdrawal checks;
- multisite: requires its dedicated disposable network and passes there;
- Koko Analytics and Instagram Feed integrations: installed but inactive in the full-run environment; both subsequently pass with the installed real plugins temporarily activated and then restored (2/2 tests, no retries/skips);
- one banner focus-loop test: pre-existing `fixme` for issue #62, not executed and not claimed as covered;
- online Playground: tests the version published on WordPress.org, not this branch, and is intentionally excluded from candidate evidence.

All source heads of PRs #267–#274 are retained as ancestors. CI Quality, three consent browsers, PHP/WordPress checks and the available security scans pass on the final code. The original checkout’s six uncommitted policy edits were preserved outside this isolated consolidation.

No release or universal-compliance certification is asserted.
