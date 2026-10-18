(function () {
"use strict";

var data = window._fazGcm;
if (!data) {
    return;
}
var setDefaultSetting = true;
var regionSettings = Array.isArray(data.default_settings) ? data.default_settings : [];
var waitForTime = data.wait_for_update || 0;

function getCookieValues(cookieName) {
    cookieName = ((window._fazConfig || {})._cookieNames || {})[cookieName] || cookieName;
    var values = [];
    var name = cookieName + "=";
    var parts = document.cookie.split(';');

    for (var i = 0; i < parts.length; i++) {
        var cookie = parts[i];
        while (cookie.charAt(0) === ' ') {
            cookie = cookie.substring(1);
        }
        if (cookie.indexOf(name) === 0) {
            var raw = cookie.substring(name.length, cookie.length);
            try {
                values.push(decodeURIComponent(raw));
            } catch (e) {
                values.push(raw);
            }
        }
    }
    return values;
}

function getConsentStateForCategory(categoryConsent) {
    return categoryConsent === "yes" ? "granted" : "denied";
}

var dataLayerName =
  data.data_layer_name ||
  (window.fazSettings && window.fazSettings.dataLayerName) ||
  "dataLayer";
window[dataLayerName] = window[dataLayerName] || [];
function gtag() {
    window[dataLayerName].push(arguments);
}

function setConsentInitStates(consentData) {
    // Advanced Consent Mode (#165): the synchronous denied `consent default`
    // is already emitted inline in <head> (print_gcm_default_inline) so it
    // runs before the page's gtag snippet. Skip here so we never emit a
    // SECOND `consent default`, which Consent Mode tooling flags as resetting
    // consent (issue #149). The `consent update` path is unchanged.
    if (data.advanced_mode) return;
    if (waitForTime > 0) consentData.wait_for_update = waitForTime;
    gtag("consent", "default", consentData);
}

// Non-personalized-ads fallback signal for LEGACY (non-Consent-Mode) ad tags,
// which don't read ad_storage. When the publisher enabled the fallback, mirror
// the ad_storage decision into `npa`: 1 (non-personalized) while ad_storage is
// denied, 0 once granted. Two-sided + called from every path (first-visit
// default, region rows, and the post-action update) so the signal is consistent
// and self-clears within the session — Consent Mode v2 tags already read
// ad_storage directly.
function setNpaIfDenied(adStorage) {
    if (!data || !data.non_personalized_ads_fallback) return;
    gtag("set", { npa: adStorage === "denied" ? 1 : 0 });
}

// In Advanced Consent Mode these are already emitted by the synchronous
// inline <head> bootstrap (print_gcm_default_inline); skip here to avoid a
// duplicate set (#165 F6). Both paths use the same dataLayer array (F4), so
// the skip is purely cosmetic, but keeping a single emitter is cleaner.
if (!data.advanced_mode) {
    gtag("set", "ads_data_redaction", !!data.ads_data_redaction);
    gtag("set", "url_passthrough", !!data.url_passthrough);
}

// IMPORTANT: we must parse the consent cookie BEFORE emitting any consent
// states. We follow the standard Consent Mode pattern (matching CookieYes
// upstream): a single baseline `consent default` (region-specific / denied,
// carrying wait_for_update), then — for a returning visitor with a stored
// cookie — a `consent update` carrying their granted states. wait_for_update
// holds ad tags (AdSense, GTM) until the update lands, so there is no
// denied-ad race. Emitting a SECOND `consent default` with granted values
// instead is flagged by Consent Mode tooling as resetting consent (issue #149).
//
// Order matters:
//   1. parseConsentCookie() -> read cookie synchronously
//   2.  emit the region-specific / denied baseline `consent default`
//       (Basic mode). In Advanced Consent Mode (#165) this step is SKIPPED
//       here — setConsentInitStates() early-returns — because the denied
//       baseline is already emitted synchronously in <head> by
//       print_gcm_default_inline(), as a single GLOBAL most-restrictive
//       default rather than per-region.
//   3.  if cookie present -> emit `consent update` with the stored grants
//
// FAZ_META_KEYS must be declared BEFORE this call: parseConsentCookie() reads
// it, and although the function is hoisted, the `var` only hoists the
// declaration (not the value). Declaring it lower in the file left it
// `undefined` at this call site, so a returning visitor (or a PMP-exempt member
// with an auto-granted cookie) hit "Cannot read properties of undefined" on the
// first key and gcm.js threw before emitting any consent.
// Non-category meta keys stored in the consent cookie (not consent states).
// `gpc` flags a consent recorded automatically from a Global Privacy Control
// signal; like rev/action/consentid it must not be coerced to granted/denied.
var FAZ_META_KEYS = { rev: 1, action: 1, consentid: 1, gpc: 1 };

var initialCookieObj = parseConsentCookie();

// Baseline consent DEFAULT (region-specific / denied) is emitted first in
// Basic mode — for first-time AND returning visitors. (In Advanced Consent
// Mode it is emitted synchronously in <head> instead; setConsentInitStates()
// below no-ops.) Consent Mode expects exactly one
// well-formed `consent default` before any update; emitting a SECOND
// `consent default` with granted values to restore a returning visitor (issue
// #149) is flagged by Consent Mode tooling as resetting consent. The returning
// visitor's stored grants are restored via `consent update` below instead — the
// brief denied window is covered by wait_for_update on this baseline default.
{
    // Region-specific defaults as configured by the admin.
    //
    // ad_storage stays at the admin-configured (typically "denied") region
    // value before the visitor interacts. The "non-personalized ads fallback"
    // does NOT grant ad_storage (that would write ad cookies without consent —
    // unlawful under ePrivacy/Consent Mode v2 in EEA/UK/CH). Under Consent Mode
    // v2 a denied ad_storage already lets Google serve non-personalized,
    // cookieless ads; for legacy (non-Consent-Mode) ad tags we additionally
    // signal `npa`. Unlike the Consent Mode `ad_storage` states above, `npa` is
    // a GLOBAL gtag('set') value — it CANNOT be region-scoped — so emitting it
    // per region row would let the last-iterated row win for everyone. Instead
    // we track the most-restrictive ad stance across all applicable defaults and
    // emit `npa` ONCE after the loop: npa:1 if ANY default denies ads. This
    // protects denied regions from personalized legacy ads pre-consent without
    // geofencing. buildConsentState() (update path) re-evaluates `npa` two-sided
    // from the returning visitor's real choice.
    var anyAdDenied = false;
    for (var index = 0; index < regionSettings.length; index++) {
        var regionSetting = regionSettings[index];
        if (!regionSetting || typeof regionSetting !== "object") continue;
        // Category-mirror keys, see regionConsentData().
        var consentRegionData = regionConsentData(regionSetting);
        var regionsRaw = typeof regionSetting.regions === "string" ? regionSetting.regions : "";
        var regionsToSetFor = regionsRaw
            .split(",")
            .map(function (region) { return region.trim(); })
            .filter(function (region) { return region; });
        if (regionsToSetFor.length > 0 && regionsToSetFor[0].toLowerCase() !== "all")
            consentRegionData.region = regionsToSetFor;
        else setDefaultSetting = false;
        setConsentInitStates(consentRegionData);
        if (consentRegionData.ad_storage === "denied") anyAdDenied = true;
    }

    if (setDefaultSetting) {
        setConsentInitStates(fallbackDefault());
        anyAdDenied = true;
    }

    // Single global npa for the pre-consent default stage (see comment above):
    // most-restrictive wins, since gtag('set', { npa }) is not region-scoped.
    setNpaIfDenied(anyAdDenied ? "denied" : "granted");
}

// The consent signals one admin-configured region row stands for. Read the
// category-mirror keys: these are the source of truth for the storage-type
// signals. The non-personalized-ads fallback deliberately keeps the
// `marketing` mirror and the canonical `ad_storage` OUT of sync (marketing
// drives ad serving here), so reading the mirrors is load-bearing — do NOT
// switch this to canonical-first. The runtime geo-routing override writes these
// same mirror keys (see Geo_Runtime::apply_cmv2_to_gcm) so its CMv2 signals
// reach gtag too.
function regionConsentData(regionSetting) {
    return {
        ad_storage: regionSetting.marketing || regionSetting.advertisement || "denied",
        analytics_storage: regionSetting.analytics,
        functionality_storage: regionSetting.functional,
        personalization_storage: regionSetting.functional,
        security_storage: regionSetting.necessary,
        ad_user_data: regionSetting.ad_user_data,
        ad_personalization: regionSetting.ad_personalization
    };
}

// The default applied everywhere no region row covers (no "all" row), and the
// whole default in Advanced Consent Mode (print_gcm_default_inline emits the
// same values).
function fallbackDefault() {
    return {
        ad_storage: "denied",
        analytics_storage: "denied",
        functionality_storage: "denied",
        personalization_storage: "denied",
        security_storage: "granted",
        ad_user_data: "denied",
        ad_personalization: "denied"
    };
}

// What to go back to when a restored record is invalidated on this page view.
//
// It used to be the hard-coded global denial above, whatever the admin had set
// per region: an install whose defaults grant, say, analytics_storage
// everywhere had it withdrawn from a visitor whose record merely went stale,
// a state the visitor never had before the restore. The right target is the
// region default the page started from — but `consent update` cannot be
// region-scoped (`region` is a `consent default` parameter only) and this
// script does not know the visitor's region; a second `consent default`
// instead is what issue #149 removed. So each signal goes back to the value
// every applicable default row agrees on, and to denied where they disagree:
// exactly the region default whenever the visitor's own row could not have
// said anything else, the most restrictive of the candidates otherwise.
// Advanced Consent Mode has a single global denied default, so it is that.
function baselineConsentState() {
    var rows = [];
    if (!data.advanced_mode) {
        for (var i = 0; i < regionSettings.length; i++) {
            if (regionSettings[i] && typeof regionSettings[i] === "object") {
                rows.push(regionConsentData(regionSettings[i]));
            }
        }
    }
    if (data.advanced_mode || setDefaultSetting) rows.push(fallbackDefault());
    var state = fallbackDefault();
    for (var key in state) {
        if (!state.hasOwnProperty(key)) continue;
        var agreed = rows.length > 0;
        for (var r = 0; r < rows.length; r++) {
            if (rows[r][key] !== "granted") { agreed = false; break; }
        }
        state[key] = agreed ? "granted" : "denied";
    }
    return state;
}

// Returning visitor with saved consent: restore it via `consent update` (the
// GCM-correct restoration signal), NOT a second `consent default`.
// buildConsentState() also emits the non-personalized-ads npa signal (two-sided)
// so it self-clears here when marketing was granted.
// Whether a `consent update` carrying a stored record has been pushed. When
// the runtime later invalidates that record (scope change, revision bump) the
// cookie is gone by the time the listener below re-reads it, and "nothing to
// push" would leave the restored grants standing for the whole page view.
var restoredFromCookie = false;
if (initialCookieObj) {
    updateConsentState(buildConsentState(initialCookieObj));
    restoredFromCookie = true;
}

function parseConsentCookieParts() {
    var raw = getCookieValues("fazcookie-consent")[0];
    if (!raw || typeof raw !== "string") return null;
    return raw.split(",").reduce(function (acc, curr) {
        var trimmed = curr.trim();
        // Match PHP's faz_parse_consent_cookie() which uses
        // explode(':', $pair, 2) — split on the FIRST colon, not the last,
        // so values containing colons (e.g. a future "source:pmp:L2" token)
        // round-trip consistently between server and client.
        var sepIdx = trimmed.indexOf(":");
        if (sepIdx === -1) return acc;
        var key = trimmed.substring(0, sepIdx).trim();
        if (!key) return acc;
        acc[key] = trimmed.substring(sepIdx + 1).trim();
        return acc;
    }, {});
}

function isConsentCookieStale(parsed) {
    if (!parsed) return false;
    var config = window._fazConfig || {};
    // The shell's fingerprint is provisional on every topology. Restore
    // nothing until the no-store payload has installed the visitor's scope.
    if (config._geoBootstrap && !config._geoBootstrapResolved) return true;
    if (config._siteScopeBound && !isPmpNecessaryOnly(parsed) && isSiteBindingBroken(parsed, config)) return true;
    // wp_localize_script often stringifies numeric values ("1" instead of 1),
    // so we can't rely on typeof === "number". Coerce and fall back to 1.
    var serverRevisionRaw = config && config._consentRevision;
    var serverRevision = parseInt(serverRevisionRaw, 10);
    if (isNaN(serverRevision) || serverRevision < 1) serverRevision = 1;
    var storedRevision = parseInt(parsed.rev, 10);
    if (serverRevision > 1 && (isNaN(storedRevision) || storedRevision < serverRevision)) return true;
    return isConsentCookieOutOfScope(parsed, config);
}

// Read a scope key, honouring the pre-1.14 unprefixed slot the way script.js
// (_fazReadScopedCookieValue) does: a bare `banner` / `law` key can also be a
// category slug, so a value shaped like a category decision is not a scope.
function readScopedCookieValue(parsed, key, legacyKey, strict) {
    var primary = parsed[key] || "";
    if (primary || strict) return primary;
    var legacy = parsed[legacyKey] || "";
    if (!legacy || /^(yes|no)(:.*)?$/i.test(legacy)) return "";
    return legacy;
}

// Twin of _fazConsentScopeChanged() in script.js and of
// faz_consent_cookie_scope_changed() on the server. This bundle runs
// synchronously in <head>, BEFORE the deferred runtime, so it is the first
// layer to read a returning visitor's cookie: if it restored the grants of a
// record acknowledged under another banner/law (a CCPA notice closed in the
// US, the page now served under the GDPR banner), Google would be told
// ad_storage/analytics_storage granted for a visitor the runtime is about to
// classify as unconsented — and in Advanced Consent Mode nothing else gates
// the Google tags for that page view. Same strict flag, legacy tolerance
// and necessary-only PMP exception as the runtime, so both
// layers reject the same cookie on the same page. The cached jurisdiction
// shell advertises a placeholder scope until the live payload lands, so the
// cookie is held at the baseline until that scope resolves. With geographic
// routing (country-dependent output or the jurisdiction bootstrap) banner,
// law and fingerprint must all match; without it only the law is compared.
// Multisite: the record must carry the site-bound fingerprint, and where this
// layer can recompute it — the record names the banner and law served here, so
// the published _scopeFingerprint is the HMAC the server would check — it must
// match. A record written under another banner slug cannot be verified here;
// the server verifies its HMAC (faz_is_consent_cookie_stale), and the cookie
// name already carries the network and blog ids. Twin of script.js.
function isSiteBindingBroken(parsed, config) {
    var storedFp = parsed["__scope.fp"] || "";
    if (!storedFp) return true;
    var sameScope = (parsed["__scope.banner"] || "") === (config._bannerSlug ? String(config._bannerSlug) : "") &&
        (parsed["__scope.law"] || "") === (config._activeLaw ? String(config._activeLaw) : "");
    return sameScope && storedFp !== (config._scopeFingerprint || "");
}

function isPmpNecessaryOnly(parsed) {
    return parsed.source === "pmp" && parsed.action === "auto" && parsed.consent === "no" && !Object.keys(parsed).some(function(key) { return key !== "necessary" && /^yes(?::|$)/.test(parsed[key]); });
}

function isConsentCookieOutOfScope(parsed, config) {
    if (!config) return false;
    if (isPmpNecessaryOnly(parsed)) return false;
    var strict = !!config._strictScopeFp;
    var currentSlug = config._bannerSlug ? String(config._bannerSlug) : "";
    var currentLaw = config._activeLaw ? String(config._activeLaw) : "";
    var currentFp = typeof config._scopeFingerprint === "string" ? config._scopeFingerprint : "";
    var storedFp = parsed["__scope.fp"] || "";
    var storedSlug = readScopedCookieValue(parsed, "__scope.banner", "banner", strict);
    var storedLaw = readScopedCookieValue(parsed, "__scope.law", "law", strict);
    if (strict && !storedFp) return true;
    // Pre-1.14 cookie, no scope recorded: the existing consent stands.
    if (!storedSlug && !storedLaw && !storedFp) return false;
    // Without geographic routing every visitor gets the same banner, so only
    // a change of LAW makes the record answer a different question. A new
    // banner slug (an A/B test ended, a banner re-created) or a new fingerprint
    // (AUTH salts rotated) does not.
    if (!(config._geoRouting || config._geoBootstrap)) {
        return !!(currentLaw && storedLaw && storedLaw !== currentLaw);
    }
    if (storedFp && currentFp && storedFp !== currentFp) return true;
    return !!(
        (currentSlug && storedSlug && storedSlug !== currentSlug) ||
        (currentLaw && storedLaw && storedLaw !== currentLaw)
    );
}

function parseConsentCookie() {
    var parsed = parseConsentCookieParts();
    if (!parsed || isConsentCookieStale(parsed)) return null;
    Object.keys(parsed).forEach(function(key) {
        // Leave meta keys (rev/action/consentid) as their raw value; only
        // category slugs are coerced to granted/denied.
        if (!FAZ_META_KEYS[key]) parsed[key] = getConsentStateForCategory(parsed[key]);
    });
    // Backward compat: accept old "advertisement" key as alias for "marketing".
    if (!parsed.marketing && parsed.advertisement) {
        parsed.marketing = parsed.advertisement;
    }
    // Require only `necessary` — the one category guaranteed on every install.
    // Other categories (analytics, marketing, functional, performance, or any
    // custom slug) are optional; absent ones default to "denied" downstream in
    // buildConsentState(). Hard-requiring a fixed slug list rejected the cookie
    // on installs that renamed/replaced a default category (e.g. "performance").
    if (parsed.necessary !== "granted" && parsed.necessary !== "denied") {
        return null;
    }
    return parsed;
}

// Read a category's consent state, defaulting absent categories to "denied".
function fazCat(cookieObj, slug) {
    return cookieObj && cookieObj[slug] === "granted" ? "granted" : "denied";
}

function buildConsentState(cookieObj) {
    var marketing = fazCat(cookieObj, "marketing");
    // analytics_storage is granted when EITHER the "analytics" OR the
    // "performance" category is granted (performance is a valid analytics-class
    // slug on some installs); previously "performance" was dropped entirely.
    var analytics =
        fazCat(cookieObj, "analytics") === "granted" || fazCat(cookieObj, "performance") === "granted"
            ? "granted"
            : "denied";

    // ADVANCED CONSENT MODE ONLY: the `analytics` category decides on its own.
    //
    // In Basic mode the granted-wins merge above is right. `performance` is an
    // analytics-class category, so granting it is a consent to analytics-class
    // storage, and the tags belonging to `analytics` are hard-blocked by the
    // script gate regardless of what this signal says — the signal cannot let
    // anything through.
    //
    // Advanced mode removes exactly that backstop: Consent-Mode-aware Google
    // tags (gtag.js / GA4 / Ads) are deliberately allowed to load
    // (class-frontend.php, is_gcm_managed_script) so they can send modeled
    // pings. GA4 is classified `analytics`. So a visitor who denied Analytics
    // and granted Performance had GA4 loaded AND told analytics_storage was
    // granted, and it wrote its cookies against that denial — the signal became
    // the only control, and it was pointing the wrong way.
    //
    // Deliberately keyed on the `analytics` category rather than made
    // most-restrictive: the exempted tags are Google's own and that is the
    // category they belong to, so an install that offers only `performance`
    // keeps working (analytics absent -> the merge above still decides), while
    // one that offers both honours the decision made about the tag actually
    // running.
    if (data.advanced_mode) {
        var analyticsRaw = cookieObj ? cookieObj.analytics : undefined;
        if ("granted" === analyticsRaw || "denied" === analyticsRaw) {
            analytics = analyticsRaw;
        }
    }
    // Non-personalized ads fallback: when enabled and marketing is denied, we
    // do NOT grant ad_storage (that would set ad cookies without consent —
    // unlawful in EEA/UK/CH). ad_storage stays "denied"; Consent Mode v2 serves
    // non-personalized, cookieless ads in that state. For legacy ad tags that
    // don't read Consent Mode we signal `npa` (two-sided: set to 0 when
    // marketing is later granted so it self-clears within the session).
    setNpaIfDenied(marketing);
    return {
        ad_storage: marketing,
        analytics_storage: analytics,
        functionality_storage: fazCat(cookieObj, "functional"),
        personalization_storage: fazCat(cookieObj, "functional"),
        security_storage: fazCat(cookieObj, "necessary"),
        ad_user_data: marketing,
        ad_personalization: marketing,
    };
}

function updateConsentState(consentState) {
    gtag("consent", "update", consentState);
}

// NOTE: the baseline `consent default` (region / denied) has already been
// emitted above, and a returning visitor's stored grants were restored via
// `consent update`. We only need to handle live consent changes below.

// Go back to the region defaults after the runtime discarded a record this
// bundle had already restored (see the listeners below).
function restoreBaseline() {
    restoredFromCookie = false;
    // The region defaults, not a global denial (see baselineConsentState).
    var baseline = baselineConsentState();
    setNpaIfDenied(baseline.ad_storage);
    updateConsentState(baseline);
    if (data.gacm_enabled && data.gacm_provider_ids) {
        // No record: no additional-consent provider is consented.
        setAdditionalConsent({ necessary: "granted" });
    }
}

// Reconcile once with the state the runtime announces for this page load.
//
// A returning visitor's stored choice is announced with
// fazcookie_consent_ready ('restore'), never with fazcookie_consent_update:
// that event means "the consent just changed" and the runtime keeps it for
// real changes. Two cases depend on this announcement:
//
//  - the cached jurisdiction shell (_geoBootstrap). isConsentCookieStale()
//    holds every record while the live scope is pending, which is the only
//    moment this bundle runs. When the runtime has installed the visitor's
//    banner and law it fires `ready`; the cookie is read again here with the
//    resolved scope and, when it is valid, its grants are pushed with ONE
//    `consent update`. Without this a visitor who had accepted stayed denied
//    in Google Consent Mode for the whole page view on every cached page;
//  - a record this bundle restored in <head> that the runtime then discarded
//    without a change event (scope, revision or TCF vendor list): the cookie is
//    gone, so go back to the baseline instead of leaving the grants standing.
//
// `action: 'update'` is a live change and is handled by the listener below.
var readyReconciled = false;
document.addEventListener("fazcookie_consent_ready", function (event) {
    var action = event && event.detail ? event.detail.action : "";
    if (readyReconciled || action === "update") return;
    readyReconciled = true;
    var current = parseConsentCookie();
    if (restoredFromCookie) {
        if (!current) restoreBaseline();
        return;
    }
    var config = window._fazConfig || {};
    if (!current || !config._geoBootstrap) return;
    updateConsentState(buildConsentState(current));
    restoredFromCookie = true;
    if (data.gacm_enabled && data.gacm_provider_ids) {
        setAdditionalConsent(current);
    }
});

// Re-apply on consent changes (banner interaction).
document.addEventListener("fazcookie_consent_update", function () {
    var updated = parseConsentCookie();
    if (!updated) {
        // parseConsentCookie() returns null when there is no cookie, or when it
        // is stale (revision bump), out of scope or malformed.
        //
        // If this bundle never restored anything there is nothing actionable:
        // the baseline `consent default` already stands, and pushing a denied
        // update here would clobber the region defaults and the GACM provider
        // list with "1~" (empty) on a first visit.
        //
        // If it DID restore a stored record and a live change has now left no
        // valid cookie behind (a withdrawal, or a record from another scope or
        // revision), the grants pushed above no longer apply: go back to the
        // region baseline (baselineConsentState(): a type stays granted only
        // where every configured region grants it) on the same page view
        // instead of leaving them standing. A record the runtime discards on
        // load, without a change, is reconciled by the fazcookie_consent_ready
        // listener above.
        if (!restoredFromCookie) return;
        restoreBaseline();
        return;
    }
    updateConsentState(buildConsentState(updated));
    restoredFromCookie = true;
    // Also update GACM additional consent string if enabled.
    if (data.gacm_enabled && data.gacm_provider_ids) {
        setAdditionalConsent(updated);
    }
});

// Google Additional Consent Mode (GACM).
// The Additional Consent string format: "1~id.id.id..."
// Version 1 + tilde + dot-separated ATP IDs the user consented to.
function setAdditionalConsent(consentObj) {
    if (!data.gacm_enabled) return;
    var providerRaw = data.gacm_provider_ids;
    var providerStr = typeof providerRaw === "string" ? providerRaw.trim() : "";
    if (!providerStr) return;

    // Only include provider IDs when marketing consent is granted.
    var adsGranted = consentObj && consentObj.marketing === "granted";
    var acString;
    if (adsGranted) {
        // Include all configured provider IDs.
        acString = "1~" + providerStr.split(/[,\s]+/).filter(Boolean).join(".");
    } else {
        // No consent - empty provider list.
        acString = "1~";
    }

    gtag("set", "addtl_consent", acString);
}

// Apply GACM on page load if enabled.
if (data.gacm_enabled && data.gacm_provider_ids) {
    setAdditionalConsent(initialCookieObj);
}

})();
