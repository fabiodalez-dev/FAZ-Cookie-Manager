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
        // Read the category-mirror keys: these are the source of truth for the
        // storage-type signals. The non-personalized-ads fallback deliberately
        // keeps the `marketing` mirror and the canonical `ad_storage` OUT of sync
        // (marketing drives ad serving here), so reading the mirrors is
        // load-bearing — do NOT switch this to canonical-first. The runtime
        // geo-routing override writes these same mirror keys (see
        // Geo_Runtime::apply_cmv2_to_gcm) so its CMv2 signals reach gtag too.
        var consentRegionData = {
            ad_storage: regionSetting.marketing || regionSetting.advertisement || "denied",
            analytics_storage: regionSetting.analytics,
            functionality_storage: regionSetting.functional,
            personalization_storage: regionSetting.functional,
            security_storage: regionSetting.necessary,
            ad_user_data: regionSetting.ad_user_data,
            ad_personalization: regionSetting.ad_personalization
        };
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
        setConsentInitStates({
          ad_storage: "denied",
          analytics_storage: "denied",
          functionality_storage: "denied",
          personalization_storage: "denied",
          security_storage: "granted",
          ad_user_data: "denied",
          ad_personalization: "denied"
        });
        anyAdDenied = true;
    }

    // Single global npa for the pre-consent default stage (see comment above):
    // most-restrictive wins, since gtag('set', { npa }) is not region-scoped.
    setNpaIfDenied(anyAdDenied ? "denied" : "granted");
}

// Returning visitor with saved consent: restore it via `consent update` (the
// GCM-correct restoration signal), NOT a second `consent default`.
// buildConsentState() also emits the non-personalized-ads npa signal (two-sided)
// so it self-clears here when marketing was granted.
if (initialCookieObj) {
    updateConsentState(buildConsentState(initialCookieObj));
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
    // wp_localize_script often stringifies numeric values ("1" instead of 1),
    // so we can't rely on typeof === "number". Coerce and fall back to 1.
    var serverRevisionRaw = config && config._consentRevision;
    var serverRevision = parseInt(serverRevisionRaw, 10);
    if (isNaN(serverRevision) || serverRevision < 1) serverRevision = 1;
    var storedRevision = parseInt(parsed.rev, 10);
    return serverRevision > 1 && (isNaN(storedRevision) || storedRevision < serverRevision);
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

// Re-apply on consent changes (banner interaction).
document.addEventListener("fazcookie_consent_update", function () {
    var updated = parseConsentCookie();
    if (!updated) {
        // parseConsentCookie() returns null when the cookie is stale (server
        // bumped consent_revision since it was written) or malformed. In that
        // window there is nothing actionable to push to gtag: leave the
        // previous consent state untouched and skip GACM too, otherwise we
        // would clobber the live provider list with "1~" (empty).
        return;
    }
    updateConsentState(buildConsentState(updated));
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
