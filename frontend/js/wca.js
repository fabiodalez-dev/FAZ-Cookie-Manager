const consentType = {
    gdpr: 'optin',
    ccpa: 'optout',
};
// FAZ category → WP Consent API category mapping.
//   - `functional`  → 'preferences'
//   - `analytics`   → ['statistics', 'statistics-anonymous']
//   - `marketing`   → 'marketing'
//   - `advertisement` → 'marketing' (back-compat: cookies stored before
//                       the 1.13.5 advertisement→marketing rename still
//                       arrive here verbatim — see gcm.js + tcf-cmp.js
//                       for the same shim)
//   - `performance` → 'statistics' (was an inadvertent → 'functional'
//                     mapping pre-1.13.12; corrected because the
//                     Settings UI exposes `performance` as a runtime
//                     category and admins selecting it expect analytics
//                     gating, not preferences gating)
const categoryMap = {
    functional: 'preferences',
    analytics: ['statistics', 'statistics-anonymous'],
    marketing: 'marketing',
    advertisement: 'marketing',
    performance: 'statistics',
};
const gskEnabled = typeof _fazGsk !== 'undefined' && _fazGsk ? _fazGsk : false;
// Applying the same state twice is harmless for wp_set_consent, but it would
// also re-dispatch wp_consent_type_defined, and third-party listeners on that
// hook are not ours to reason about. Remember what was last pushed and skip a
// repeat: on a first visit both fazcookie_consent_ready and the init-flavoured
// fazcookie_consent_update arrive with identical values.
let lastAppliedConsent = null;
function applyConsentToWpApi() {
    const consentData = getFazConsent();
    const categories = consentData.categories;
    if ((consentData.isUserActionCompleted === false) && gskEnabled && !Object.values(categories).slice(1).includes(true)) {
        return;
    }
    const signature = JSON.stringify([consentData.activeLaw, consentData.isUserActionCompleted, categories]);
    if (signature === lastAppliedConsent) {
        return;
    }
    lastAppliedConsent = signature;
    window.wp_consent_type = consentData.activeLaw ? consentType[consentData.activeLaw] : 'optin';
    let event = new CustomEvent('wp_consent_type_defined');
    document.dispatchEvent( event );
    Object.entries(categories).forEach(([key, value]) => {
        if (!(key in categoryMap))
            return;
        setConsentStatus(key, value ? 'allow' : 'deny');
    });
    function setConsentStatus(key, status) {
        if (Array.isArray(categoryMap[key])) {
            categoryMap[key].forEach(el => {
                wp_set_consent(el, status);
            });
        } else {
            wp_set_consent(categoryMap[key], status);
        }
    }
}

// fazcookie_consent_update covers a first visit and every explicit choice.
// fazcookie_consent_ready covers the page loads AFTER one — every page but the
// first, where this file previously did nothing at all, so a visitor who had
// accepted was still reported to Consent-API-aware plugins under their own
// default (deny) for the rest of the session.
document.addEventListener("fazcookie_consent_update", applyConsentToWpApi);
document.addEventListener("fazcookie_consent_ready", applyConsentToWpApi);

// This file is not in the minification pipeline and loads as its own request,
// so a page optimiser or a slow network can land it AFTER script.js has already
// announced the consent state. Listening alone would then miss the only
// announcement there is. The state is recorded on window before dispatch, so
// catching up is a read; the duplicate guard inside applyConsentToWpApi keeps
// this from double-reporting when the listener did fire.
if (typeof window !== 'undefined' && window._fazConsentReady) {
    applyConsentToWpApi();
}