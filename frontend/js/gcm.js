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
  window.fazSettings && window.fazSettings.dataLayerName
    ? window.fazSettings.dataLayerName
    : "dataLayer";
window[dataLayerName] = window[dataLayerName] || [];
function gtag() {
    window[dataLayerName].push(arguments);
}

function setConsentInitStates(consentData) {
    if (waitForTime > 0) consentData.wait_for_update = waitForTime;
    gtag("consent", "default", consentData);
}

gtag("set", "ads_data_redaction", !!data.ads_data_redaction);
gtag("set", "url_passthrough", !!data.url_passthrough);

// IMPORTANT: we must parse the consent cookie BEFORE emitting any consent
// defaults. If the visitor already consented in a previous session, we emit
// `consent default` directly with their granted states instead of the classic
// `default denied -> update granted` pair. This removes a race window where
// ad tags (AdSense, GTM) can fire the first request while consent is still
// "denied" because the update has not been processed yet.
//
// Order matters:
//   1. parseConsentCookie() -> read cookie synchronously
//   2a. if cookie present -> emit consent default with granted states (once)
//   2b. if cookie absent  -> emit region-specific denied defaults (legacy path)
var initialCookieObj = parseConsentCookie();

if (initialCookieObj) {
    // Returning visitor with saved consent: skip region defaults and emit the
    // final state directly. buildConsentState() handles the non-personalized
    // ads fallback when applicable.
    setConsentInitStates(buildConsentState(initialCookieObj));
} else {
    // First-time visitor (or cookie expired/cleared): emit region-specific
    // defaults as configured by the admin.
    for (var index = 0; index < regionSettings.length; index++) {
        var regionSetting = regionSettings[index];
        if (!regionSetting || typeof regionSetting !== "object") continue;
        var consentRegionData = {
            ad_storage: regionSetting.marketing || regionSetting.advertisement,
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
    }
}

function parseConsentCookieParts() {
    var raw = getCookieValues("fazcookie-consent")[0];
    if (!raw || typeof raw !== "string") return null;
    return raw.split(",").reduce(function (acc, curr) {
        var trimmed = curr.trim();
        var sepIdx = trimmed.lastIndexOf(":");
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
    var serverRevision = typeof config._consentRevision === "number"
        ? config._consentRevision
        : 1;
    var storedRevision = parseInt(parsed.rev, 10);
    return serverRevision > 1 && (isNaN(storedRevision) || storedRevision < serverRevision);
}

function parseConsentCookie() {
    var parsed = parseConsentCookieParts();
    if (!parsed || isConsentCookieStale(parsed)) return null;
    Object.keys(parsed).forEach(function(key) {
        parsed[key] = getConsentStateForCategory(parsed[key]);
    });
    // Backward compat: accept old "advertisement" key as alias for "marketing".
    if (!parsed.marketing && parsed.advertisement) {
        parsed.marketing = parsed.advertisement;
    }
    var required = ["marketing", "analytics", "functional", "necessary"];
    for (var i = 0; i < required.length; i++) {
        if (parsed[required[i]] !== "granted" && parsed[required[i]] !== "denied") {
            return null;
        }
    }
    return parsed;
}

function buildConsentState(cookieObj) {
    // Non-personalized ads fallback: when enabled and the user has denied
    // marketing consent, keep ad_storage = "granted" (so AdSense can serve
    // non-personalized ads and preserve frequency capping) while keeping
    // ad_user_data and ad_personalization = "denied".
    // See https://support.google.com/adsense/answer/13554116
    var adStorage = cookieObj.marketing;
    if (data.non_personalized_ads_fallback && cookieObj.marketing === "denied") {
        adStorage = "granted";
    }
    return {
        ad_storage: adStorage,
        analytics_storage: cookieObj.analytics,
        functionality_storage: cookieObj.functional,
        personalization_storage: cookieObj.functional,
        security_storage: cookieObj.necessary,
        ad_user_data: cookieObj.marketing,
        ad_personalization: cookieObj.marketing,
    };
}

function updateConsentState(consentState) {
    gtag("consent", "update", consentState);
}

// NOTE: consent default has already been emitted above with the correct
// granted/denied states (from cookie when present, or region defaults
// otherwise). We only need to handle live consent changes below.

// Re-apply on consent changes (banner interaction).
document.addEventListener("fazcookie_consent_update", function () {
    var updated = parseConsentCookie();
    if (updated) {
        updateConsentState(buildConsentState(updated));
    }
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
