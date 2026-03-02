const data = window._fazGcm;
if (!data) { console.warn('FAZ GCM: _fazGcm not available'); }
let setDefaultSetting = true;
const regionSettings = (data && data.default_settings) || [];
const waitForTime = data ? data.wait_for_update : 0;

function getCookieValues(cookieName) {
    const values = [];
    const name = cookieName + "=";
    const decodedCookie = decodeURIComponent(document.cookie);
    const cookieArray = decodedCookie.split(';');
    
    for (let i = 0; i < cookieArray.length; i++) {
        let cookie = cookieArray[i];
        while (cookie.charAt(0) === ' ') {
            cookie = cookie.substring(1);
        }
        if (cookie.indexOf(name) === 0) {
            values.push(cookie.substring(name.length, cookie.length));
        }
    }
    return values;
}

function getConsentStateForCategory(categoryConsent) {
    return categoryConsent === "yes" ? "granted" : "denied";
}

const dataLayerName =
  window.fazSettings && window.fazSettings.dataLayerName
    ? window.fazSettings.dataLayerName
    : "dataLayer";
 window[dataLayerName] = window[dataLayerName] || [];
 function gtag() {
  window[dataLayerName].push(arguments);
}

function setConsentInitStates(consentData) {
    if (waitForTime > 0) consentData.wait_for_update = waitForTime;
    gtag("consent", "default", consentData );
}

gtag("set", "ads_data_redaction", !!(data && data.ads_data_redaction));
gtag("set", "url_passthrough", !!(data && data.url_passthrough));

for (let index = 0; index < regionSettings.length; index++) {
    const regionSetting = regionSettings[index];
    const consentRegionData = {
        ad_storage: regionSetting.advertisement,
        analytics_storage: regionSetting.analytics,
        functionality_storage: regionSetting.functional,
        personalization_storage: regionSetting.functional,
        security_storage: regionSetting.necessary,
        ad_user_data: regionSetting.ad_user_data,
        ad_personalization: regionSetting.ad_personalization
    };
    const regionsToSetFor = regionSetting.regions
        .split(",")
        .map((region) => region.trim())
        .filter((region) => region);
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
    const raw = getCookieValues("fazcookie-consent")[0];
    if (!raw || typeof raw !== "string") return null;
    return raw.split(",").reduce(function (acc, curr) {
        const kv = curr.trim().split(":");
        acc[kv[0]] = getConsentStateForCategory(kv[1]);
        return acc;
    }, {});
}

function buildConsentState(cookieObj) {
    return {
        ad_storage: cookieObj.advertisement,
        analytics_storage: cookieObj.analytics,
        functionality_storage: cookieObj.functional,
        personalization_storage: cookieObj.functional,
        security_storage: cookieObj.necessary,
        ad_user_data: cookieObj.advertisement,
        ad_personalization: cookieObj.advertisement,
    };
}

function updateConsentState(consentState) {
    gtag("consent", "update", consentState);
}

// Apply consent from cookie on page load.
const cookieObj = parseConsentCookie();
if (cookieObj) {
    updateConsentState(buildConsentState(cookieObj));
}

// Re-apply on consent changes (banner interaction).
document.addEventListener("fazcookie_consent_update", function () {
    const updated = parseConsentCookie();
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
    var providerStr = (data.gacm_provider_ids || "").trim();
    if (!providerStr) return;

    // Only include provider IDs when advertisement consent is granted.
    var adsGranted = consentObj && consentObj.advertisement === "granted";
    var acString;
    if (adsGranted) {
        // Include all configured provider IDs.
        acString = "1~" + providerStr.split(/[,\s]+/).filter(Boolean).join(".");
    } else {
        // No consent — empty provider list.
        acString = "1~";
    }

    gtag("set", "addtl_consent", acString);
}

// Apply GACM on page load if enabled.
if (data.gacm_enabled && data.gacm_provider_ids) {
    setAdditionalConsent(cookieObj);
}