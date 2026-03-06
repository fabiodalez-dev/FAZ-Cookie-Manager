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

function parseConsentCookie() {
    var raw = getCookieValues("fazcookie-consent")[0];
    if (!raw || typeof raw !== "string") return null;
    var parsed = raw.split(",").reduce(function (acc, curr) {
        var kv = curr.trim().split(":");
        if (kv.length !== 2) return acc;
        var key = kv[0].trim();
        if (!key) return acc;
        acc[key] = getConsentStateForCategory(kv[1].trim());
        return acc;
    }, {});
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
    return {
        ad_storage: cookieObj.marketing,
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

// Apply consent from cookie on page load.
var cookieObj = parseConsentCookie();
if (cookieObj) {
    updateConsentState(buildConsentState(cookieObj));
}

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
    setAdditionalConsent(cookieObj);
}

})();
