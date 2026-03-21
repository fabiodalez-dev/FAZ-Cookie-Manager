/**
 * WordPress localize object mapped to a constant.
 */
const _fazStore = window._fazConfig;
const _fazStyle = window._fazStyles;

_fazStore._backupNodes = [];
_fazStore._resetConsentID = false;
_fazStore._bannerState = false;
_fazStore._preferenceOriginTag = false;

window.fazcookie = window.fazcookie || {};
const ref = window.fazcookie;
ref._fazConsentStore = new Map();

ref._fazGetCookieMap = function () {
    const cookieMap = {};
    try {
        document.cookie.split(";").forEach((cookie) => {
            const [key, value] = cookie.split("=");
            if (!key) return;
            cookieMap[key.trim()] = value;
        });
    } catch (_unused) { /* malformed cookie string */ }
    return cookieMap;
};

const currentCookieMap = ref._fazGetCookieMap();
ref._fazGetFromStore = function (key) {
    return ref._fazConsentStore.get(key) || "";
};

ref._fazSetInStore = function (key, value) {
    ref._fazConsentStore.set(key, value);
    let cookieStringArray = [];
    for (const [key, value] of ref._fazConsentStore) {
        cookieStringArray.push(`${key}:${value}`);
    }
    const scriptExpiry =
        _fazStore && _fazStore._expiry
            ? _fazStore._expiry
            : 180;
    ref._fazSetCookie(
        "fazcookie-consent",
        cookieStringArray.join(","),
        scriptExpiry
    );
};

const fazcookieConsentMap = (currentCookieMap["fazcookie-consent"] || "")
    .split(",")
    .reduce((prev, curr) => {
        if (!curr) return prev;
        const sepIdx = curr.lastIndexOf(":");
        if (sepIdx === -1) return prev;
        const key = curr.substring(0, sepIdx);
        const value = curr.substring(sepIdx + 1);
        prev[key] = value;
        return prev;
    }, {});
["consentid", "consent", "action"]
    .concat(_fazStore._categories.map(({ slug }) => slug))
    .forEach((item) =>
        ref._fazConsentStore.set(item, fazcookieConsentMap[item] || "")
    );
// Restore per-service consent keys (svc.service-id) from existing cookie.
if (_fazStore._perServiceConsent && _fazStore._services) {
    _fazStore._services.forEach(function(svc) {
        const svcKey = "svc." + svc.id;
        if (fazcookieConsentMap[svcKey]) {
            ref._fazConsentStore.set(svcKey, fazcookieConsentMap[svcKey]);
        }
    });
}


/**
 * Get the value of cookie by it's name.
 * 
 * @param {string} name Name of the cookie
 * @returns {string}
 */
ref._fazGetCookie = function (name) {
    const prefix = name + '=';
    const cookies = document.cookie.split('; ');
    for (var i = 0; i < cookies.length; i++) {
        if (cookies[i].indexOf(prefix) === 0) {
            var val = cookies[i].substring(prefix.length);
            try { return decodeURIComponent(val); } catch (_) { return val; }
        }
    }
    return null;
}

/**
 * Set a cookie on document.cookie object.
 * 
 * @param {*} name Name of the cookie.
 * @param {*} value Value to be set.
 * @param {*} days Expiry in days.
 * @param {*} domain Cookie domain.
 */
ref._fazSetCookie = function (name, value, days = 0, domain = _fazStore._rootDomain) {
    const date = new Date();
    if (!!domain) {
        domain = `domain=${domain}`;
    }
    const toSetTime =
        days === 0 ? 0 : date.setTime(date.getTime() + days * 24 * 60 * 60 * 1000);
    const secure = location.protocol === 'https:' ? ' Secure;' : '';
    document.cookie = `${name}=${value}; expires=${new Date(
        toSetTime
    ).toUTCString()}; path=/;${domain}; SameSite=Lax;${secure}`;
}

function _fazSetConsentID() {
    const fazcookieID = ref._fazGetFromStore("consentid");
    if (fazcookieID) return;
    const consentID = ref._fazRandomString(32);
    ref._fazSetInStore("consentid", consentID);
    _fazStore._resetConsentID = true;
}

var _revisitFazConsent = function () {
    _fazShowBanner();
    _fazToggleRevisit();
    _fazUpdateVendorCheckboxStates();
};
/**
 * Search an element by it's data-faz-tag attribute
 * 
 * @param {string} tag data-faz-tag of an element. 
 * @returns {object}
 */
function _fazGetElementByTag(tag) {
    const item = document.querySelector('[data-faz-tag=' + tag + ']');
    return item ? item : false;
}

/**
 * Parse a trusted HTML string into a DocumentFragment.
 *
 * Used to convert server-rendered shortcode HTML (buttons, links) into DOM
 * nodes for safe insertion without innerHTML/insertAdjacentHTML.  The HTML
 * originates from PHP wp_kses-sanitized shortcodes and template JSON.
 *
 * @param {string} html  Trusted HTML string from server shortcodes.
 * @returns {DocumentFragment}
 */
function _fazParseHTML(html) {
    var tpl = document.createElement('template');
    tpl.innerHTML = html;
    return tpl.content.cloneNode(true);
}

/**
 * Bind click event to banner elements.
 * 
 * @param {string} tag data-faz-tag of the element
 * @param {function} fn callback function
 */
function _fazAttachListener(selector, fn) {
    const item = _fazFindElement(selector);
    item && item.addEventListener("click", fn);
}

function _fazClassAdd() {
    return _fazClassAction("add", ...arguments);
}

function _fazClassRemove() {
    return _fazClassAction("remove", ...arguments);
}

function _fazClassToggle() {
    return _fazClassAction("toggle", ...arguments);
}

function _fazClassAction(action, selector, className, forParent = true) {
    const item = _fazFindElement(selector, forParent);
    return item && item.classList[action](className);
}

function _fazFindElement(selector, forParent) {
    let createdSelector = selector;
    switch (true) {
        case selector.startsWith("="):
            createdSelector = `[data-faz-tag="${selector.substring(1)}"]`;
            break;
        default:
            break;
    }
    const element = document.querySelector(createdSelector);
    if (!element || (forParent && !element.parentElement)) return null;
    return forParent ? element.parentElement : element;
}
/**
 * Remove an element from the DOM.
 * 
 * @param {string} tag data-faz-tag of the element.
 */
function _fazRemoveElement(tag) {    const item = _fazGetElementByTag(tag);
    item && item.remove();
}

function _fazFireEvent(responseCategories) {
    const consentUpdate = new CustomEvent("fazcookie_consent_update", {
        detail: responseCategories
    });
    document.dispatchEvent(consentUpdate);
}

/**
 * Remove styles by it's id.
 */
function _fazRemoveStyles() {
    const item = document.getElementById('faz-style-inline');
    item && item.remove();
}

/**
 * Generate a random string for logging purposes.
 * 
 * @param {integer} length Length of the string to be generated.
 * @returns 
 */
ref._fazRandomString = function (length, allChars = true) {
    const chars = `${allChars ? `0123456789` : ""
        }ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghijklmnopqrstuvwxyz`;
    const response = [];
    var rng;
    if (typeof crypto !== "undefined" && crypto.getRandomValues) {
        var u32 = new Uint32Array(1);
        var limit = Math.floor(0x100000000 / chars.length) * chars.length;
        rng = function() {
            var v;
            do {
                crypto.getRandomValues(u32);
                v = u32[0];
            } while (v >= limit);
            return v % chars.length;
        };
    } else {
        rng = function() { return Math.floor(Math.random() * chars.length); };
    }
    for (let i = 0; i < length; i++)
        response.push(chars[rng()]);
    if (!allChars) return response.join("");
    return btoa(response.join("")).replace(/\=+$/, "");
}

/**
 * Remove banner if necessary.
 */
function _fazRemoveBanner() {
    _fazHideBanner();
    if (_fazStore._bannerConfig.config.revisitConsent.status === true) {
        _fazShowRevisit();
    }
}

/**
 * Initialize the plugin front-end operations.
 * 
 * @returns {boolean}
 */
function _fazInitOperations() {
    _fazAttachNoticeStyles();
    _fazAttachShortCodeStyles();
    _fazRenderBanner();
    _fazSetShowMoreLess();
    if (!ref._fazGetFromStore("action") || _fazPreviewEnabled()) {
        _fazShowBanner();
        _fazSetInitialState();
        _fazSetConsentID();
    } else {
        _fazRemoveBanner();
    }
}
function _fazPreviewEnabled() {
    let params = (new URL(document.location)).searchParams;
    return params.get("faz_preview") && params.get("faz_preview") === 'true';
}
function _fazToggleAriaExpandStatus(selector, forceDefault = null) {
    const element = _fazFindElement(selector);

    if (!element) return;

    if (element.classList.contains('faz-accordion-btn')) {
        const accordionItem = element.closest('.faz-accordion');
        if (accordionItem) {
            const accordionBody = accordionItem.querySelector('.faz-accordion-body');
            if (accordionBody) {
                // Generate unique ID for the accordion body if it doesn't have one
                let bodyId = accordionBody.id;
                if (!bodyId) {
                    bodyId = `fazDetailCategory${accordionItem.id.replace('fazDetailCategory', '')}Body`;
                    accordionBody.id = bodyId;
                }
                // Always set aria-controls - the relationship is permanent
                element.setAttribute("aria-controls", bodyId);
            }
        }
    }

    const currentExpanded = element.getAttribute("aria-expanded");
    const newExpandedValue = forceDefault || (currentExpanded === "true" ? "false" : "true");
    element.setAttribute("aria-expanded", newExpandedValue);
}
/**
 * Sets the initial state of the plugin.
 */
function _fazSetInitialState() {
    const activeLaw = _fazGetLaw()
    ref._fazSetInStore("consent", "no");
    const ccpaCheckBoxValue = _fazFindCheckBoxValue();
    const responseCategories = { accepted: [], rejected: [], action: 'init' };
    for (const category of _fazStore._categories) {
        let valueToSet = "yes";
        if (
            (activeLaw === "gdpr" &&
                !category.isNecessary &&
                !category.defaultConsent[activeLaw]) ||
            (activeLaw === "ccpa" &&
                ccpaCheckBoxValue &&
                !category.defaultConsent.ccpa)
        ) {
            valueToSet = "no";
        }
        if (valueToSet === "no") responseCategories.rejected.push(category.slug);
        else responseCategories.accepted.push(category.slug);
        ref._fazSetInStore(`${category.slug}`, valueToSet);
    }
    // Set initial per-service consent (all "no" = blocked before consent, matching category).
    if (_fazStore._perServiceConsent && _fazStore._services) {
        _fazStore._services.forEach(function(svc) {
            var catValue = ref._fazGetFromStore(svc.category);
            ref._fazSetInStore("svc." + svc.id, catValue || "no");
        });
    }
    _fazUnblock();
    _fazFireEvent(responseCategories);
}

/**
 * Add a class based on the banner type and position. Eg: 'faz-banner-top'
 * 
 * @returns {boolean}
 */
function _fazAddPositionClass() {
    const notice = _fazGetElementByTag('notice');
    if (!notice) return false;
    const container = notice.closest('.faz-consent-container');
    if (!container) return false;
    
    container.setAttribute("aria-label", "We value your privacy");
    container.setAttribute("role", "region");
    
    const type = _fazStore._bannerConfig.settings.type;
    let position = _fazStore._bannerConfig.settings.position;
    let bannerType = type;
    if (bannerType === 'popup') {
        position = 'center';
    }
    // Banner + pushdown uses classic template (for pushdown expansion support).
    // The CSS position classes are .faz-classic-*, so match the class name.
    if (bannerType === 'banner' && _fazGetPtype() === 'pushdown') {
        bannerType = 'classic';
    }
    // Non-box types use simplified top/bottom positioning
    if (bannerType !== 'box') {
        position = position.startsWith('top') ? 'top' : 'bottom';
    }
    const noticeClass = `faz-${bannerType}-${position}`;
    container.classList.add(noticeClass);
    const revisitConsent = _fazGetElementByTag('revisit-consent');
    if (!revisitConsent) return false;
    const revisitPosition = 'faz-revisit-' + _fazStore._bannerConfig.config.revisitConsent.position;
    revisitConsent.classList.add(revisitPosition);

    // Replace <img> with inline SVG so icon color inherits from CSS `color` property.
    // Buttons don't inherit `color` by default (browser uses `buttontext`), so force it.
    const revisitBtn = revisitConsent.querySelector('.faz-btn-revisit');
    if (revisitBtn) revisitBtn.style.color = 'inherit';
    const revisitImg = revisitConsent.querySelector('.faz-btn-revisit img[src*="revisit"]');
    if (revisitImg) {
        const svgMarkup = '<svg xmlns="http://www.w3.org/2000/svg" viewBox="0 0 50 50" fill="currentColor" aria-hidden="true">'
            + '<circle cx="23.5" cy="11.5" r="2.6"/><circle cx="27" cy="25" r="2.6"/>'
            + '<circle cx="14" cy="29.8" r="2.6"/><circle cx="14" cy="18.4" r="2.6"/>'
            + '<circle cx="20.9" cy="39" r="2.6"/><circle cx="36" cy="36.3" r="2.6"/>'
            + '<path d="M25.2,48.9c-12.6,0-23-9.8-23.8-22.4-.4-6.9,2.2-13.6,7.1-18.5C13.5,3.1,20.3.7,27.2,1.2c2.3.2,4.5.7,6.6,1.5.4.2.6.5.7.8s-.1.7-.4,1c-.7.6-1.2,1.5-1.2,2.5s.4,1.9,1.2,2.5c.2.2.4.5.4.7s0,.6-.3.8c-.5.6-.8,1.4-.8,2.1s.5,1.9,1.3,2.6c.3.2.4.5.4.8s-.2.6-.4.8c-.8.6-1.3,1.6-1.3,2.6,0,1.8,1.4,3.2,3.2,3.2h.1c.5,0,.9.3,1,.7.4,1.4,1.7,2.3,3,2.3s1.6-.3,2.3-.9c.3-.3.7-.4,1-.3s.6.4.7.7c.4,1.3,1.5,2.3,2.9,2.4.3,0,.6.2.8.4.2.2.3.5.2.8-2,11.3-11.9,19.5-23.4,19.5ZM25.3,3.2c-5.7,0-11.2,2.3-15.2,6.3-4.6,4.5-7,10.6-6.5,16.9.7,11.4,10.3,20.4,21.7,20.4s19-7,21.2-16.8c-1.3-.4-2.4-1.2-3-2.4-.8.4-1.6.6-2.6.6-2.1,0-3.9-1.3-4.8-3.1-2.7-.3-4.7-2.5-4.7-5.2s.4-2.5,1.3-3.4c-.8-.9-1.3-2.1-1.3-3.4s.3-1.9.8-2.7c-.8-.9-1.3-2.1-1.3-3.4s.3-2,.8-2.8c-1.5-.5-3-.8-4.6-.9-.6,0-1.2-.1-1.7-.1Z"/>'
            + '</svg>';
        const doc = new DOMParser().parseFromString(svgMarkup, 'image/svg+xml');
        const svg = doc.documentElement;
        svg.style.height = '30px';
        svg.style.width = '30px';
        svg.style.margin = '0';
        if (revisitImg.parentNode) revisitImg.parentNode.replaceChild(document.importNode(svg, true), revisitImg);
    }
}

/**
 * Add a class based on the preference center type and position. Eg: 'faz-sidebar-left'
 * 
 * @returns {boolean}
 */
function _fazAddPreferenceCenterClass() {
    const detail = _fazGetLaw() === 'ccpa' ? _fazGetElementByTag("optout-popup") : _fazGetElementByTag("detail");
    if (!detail) return false;
    const modal = detail.closest('.faz-modal');
    if (!modal) return false;
    if (_fazGetPtype() !== "pushdown" && _fazGetPtype() !== "popup") {
        const pType = _fazStore._bannerConfig.settings.preferenceCenterType;
        const modalClass = `faz-${pType}`;
        modal.classList.add(modalClass);
        // Sidebar needs a directional class for CSS positioning (faz-sidebar-left / faz-sidebar-right)
        if (pType === 'sidebar') {
            const pos = _fazStore._bannerConfig.settings.position || '';
            const dir = pos.includes('left') ? 'left' : 'right';
            modal.classList.add(`faz-sidebar-${dir}`);
        }
    }

    // Ensure ARIA attributes are always present on the preference center div
    const preferenceCenter = modal.querySelector('.faz-preference-center');
    if (preferenceCenter) {
        preferenceCenter.setAttribute('role', 'dialog');
        preferenceCenter.setAttribute('aria-modal', 'true');
        const ariaLabel = _fazGetLaw() === 'ccpa' ? 'Opt-out Preferences' : 'Customise Consent Preferences';
        preferenceCenter.setAttribute('aria-label', ariaLabel);
    }
}

/**
 * Initialize the plugin operations.
 */
async function _fazInit() {
    try {
        _fazInitOperations();
        _fazRemoveAllDeadCookies();
        _fazWatchBannerElement();
    } catch (err) {
        console.error(err);
    }
}

/**
 * Domready event, alternative to jQuery(document).ready() function
 * 
 * @param {function} callback 
 * @returns 
 */
function _fazDomReady(callback) {
    if (typeof document === 'undefined') {
        return;
    }
    if (document.readyState === 'complete' || /** DOMContentLoaded + Images/Styles/etc loaded, so we call directly. */
        document.readyState === 'interactive' /** DOMContentLoaded fires at this point, so we call directly. */
    ) {
        return void callback();
    } /** DOMContentLoaded has not fired yet, delay callback until then. */
    document.addEventListener('DOMContentLoaded', callback);
}

/**
 * Callback function to Domready event.
 */
_fazDomReady(async function () {
    try {
        await _fazInit();
    } catch (err) {
        console.error(err);
    }
});

/**
 * Register event handler for all the action elements.
 */
function _fazRegisterListeners() {
    for (const { slug } of _fazStore._categories) {
        _fazAttachListener('detail-category-title', () =>
            document
                .getElementById(`fazCategory${slug}`)
                .classList.toggle("faz-tab-active")
        );
    }
    _fazAttachListener("=settings-button", () => _fazSetPreferenceAction('settings-button'));
    _fazAttachListener("=detail-close", () => _fazHidePreferenceCenter());
    _fazAttachListener("=optout-cancel-button", () => _fazHidePreferenceCenter());
    _fazAttachListener("=close-button", () => _fazActionClose());
    _fazAttachListener("=donotsell-button", () => _fazSetPreferenceAction('donotsell-button'));
    _fazAttachListener("=reject-button", _fazAcceptReject("reject"));
    _fazAttachListener("=accept-button", _fazAcceptReject("all"));
    _fazAttachListener("=detail-accept-button", _fazAcceptReject("all"));
    _fazAttachListener("=detail-save-button", _fazAcceptReject());
    _fazAttachListener("=detail-category-preview-save-button", _fazAcceptReject());
    _fazAttachListener("=optout-confirm-button", _fazAcceptReject());
    _fazAttachListener("=detail-reject-button", _fazAcceptReject("reject"));
    _fazAttachListener("=revisit-consent", () => _revisitFazConsent());
    _fazAttachListener("=optout-close", () => _fazHidePreferenceCenter());
}

function _fazAttachCategoryListeners() {
    if (!_fazStore._bannerConfig.config.auditTable.status) return;
    const categoryNames = _fazStore._categories.map(({ slug }) => slug);
    categoryNames.forEach((category) => {
        const selector = `#fazDetailCategory${category}`;
        const accordionButtonSelector = `${selector}  .faz-accordion-btn`;

        // Set initial aria-controls and aria-expanded for accordion buttons
        const accordionButton = document.querySelector(accordionButtonSelector);
        if (accordionButton) {
            const accordionItem = accordionButton.closest('.faz-accordion');
            if (accordionItem) {
                const accordionBody = accordionItem.querySelector('.faz-accordion-body');
                if (accordionBody) {
                    // Generate unique ID for the accordion body if it doesn't have one
                    let bodyId = accordionBody.id;
                    if (!bodyId) {
                        bodyId = `fazDetailCategory${accordionItem.id.replace('fazDetailCategory', '')}Body`;
                        accordionBody.id = bodyId;
                    }
                    // Always set aria-controls - the relationship is permanent
                    accordionButton.setAttribute("aria-controls", bodyId);
                }
            }
        }

        _fazToggleAriaExpandStatus(accordionButtonSelector, "false");
        _fazAttachListener(selector, ({ target: { id } }) => {
            if (
                id === `fazSwitch${category}` ||
                !_fazClassToggle(selector, "faz-accordion-active", false)
            ) {
                _fazToggleAriaExpandStatus(accordionButtonSelector, "false");
                return;
            }
            _fazToggleAriaExpandStatus(accordionButtonSelector, "true");
            categoryNames
                .filter((categoryName) => categoryName !== category)
                .forEach(filteredName => {
                    _fazClassRemove(
                        `#fazDetailCategory${filteredName}`,
                        "faz-accordion-active",
                        false
                    );
                    _fazToggleAriaExpandStatus(
                        `#fazDetailCategory${filteredName} .faz-accordion-btn`,
                        "false"
                    );
                });
        });
    });
}
/**
 * Add support for accordion tabs on the privacy overview screen.
 */
function _fazInitiAccordionTabs() {    document.querySelectorAll(".faz-accordion").forEach((item) => (
        item.addEventListener('click', function (event) {
            if (event.target.type === 'checkbox') return;
            this.classList.toggle('faz-accordion-active');
        })
    ));
}

function _fazToggleBanner(force = false) {    const notice = _fazGetElementByTag('notice');
    const container = notice && notice.closest('.faz-consent-container') || false;
    if (container) {
        force === true ? container.classList.add('faz-hide') : container.classList.toggle('faz-hide');
    }

}

function _fazToggleRevisit(force = false) {
    const revisit = _fazGetRevisit();
    if (revisit) {
        force === true ? _fazHideRevisit() : revisit.classList.toggle('faz-revisit-hide');
    }
}
function _fazGetLaw() {
    return _fazStore._bannerConfig.settings.applicableLaw;
}
function _fazGetType() {
    return _fazStore._bannerConfig.settings.type;
}
function _fazGetPtype() {
    if (_fazGetType() === 'classic') {
        return 'pushdown';
    }
    return _fazStore._bannerConfig.settings.preferenceCenterType;
}
function _fazGetBanner() {
    const notice = _fazGetElementByTag('notice');
    const container = notice && notice.closest('.faz-consent-container') || false;
    return container && container || false;
}
function _fazHideBanner() {
    const notice = _fazGetBanner();
    notice && notice.classList.add('faz-hide');
}
var _fazBannerLoadedFired = false;
function _fazShowBanner() {
    const notice = _fazGetBanner();
    if (notice) {
        notice.classList.remove('faz-hide');
        if (!_fazBannerLoadedFired) {
            _fazBannerLoadedFired = true;
            document.dispatchEvent(new CustomEvent("fazcookie_banner_loaded"));
        }
    }
}
function _fazHideOverLay() {
    const overlay = document.querySelector('.faz-overlay');
    overlay && overlay.classList.add('faz-hide');
}
function _fazShowOverLay() {
    const overlay = document.querySelector('.faz-overlay');
    overlay && overlay.classList.remove('faz-hide');
}
function _fazToggleOverLay() {
    const overlay = document.querySelector('.faz-overlay');
    overlay && overlay.classList.toggle('faz-hide');
}
function _fazGetPreferenceCenter() {
    if (_fazGetPtype() === 'pushdown' && _fazGetType() !== 'box') {
        return _fazGetBanner();
    }
    let element = _fazGetLaw() === 'ccpa' ? _fazGetElementByTag("optout-popup") : _fazGetElementByTag("detail");
    return element && element.closest('.faz-modal') || false;
}
function _fazHidePreferenceCenter() {
    const element = _fazGetPreferenceCenter();
    element && element.classList.remove(_fazGetPreferenceClass());

    // ARIA attributes remain always present - only aria-expanded on settings button changes
    // The modal relationship is permanent, only visibility changes
    const isPushdown = _fazGetPtype() === 'pushdown' && _fazGetType() !== 'box';

    if (!isPushdown) {
        _fazHideOverLay();
        if (!ref._fazGetFromStore("action")) _fazShowBanner();
    } else {
        _fazToggleAriaExpandStatus("=settings-button", "false");
    }
    if (ref._fazGetFromStore("action")) _fazShowRevisit();
    const origin = _fazStore._preferenceOriginTag;
    origin && _fazSetFocus(origin)
}
function _fazShowPreferenceCenter() {
    const element = _fazGetPreferenceCenter();
    element && element.classList.add(_fazGetPreferenceClass());

    // Ensure ARIA attributes are always present on the preference center div
    if (element) {
        const preferenceCenter = element.querySelector('.faz-preference-center');
        if (preferenceCenter) {
            preferenceCenter.setAttribute('role', 'dialog');
            preferenceCenter.setAttribute('aria-modal', 'true');
            const ariaLabel = _fazGetLaw() === 'ccpa' ? 'Opt-out Preferences' : 'Customise Consent Preferences';
            preferenceCenter.setAttribute('aria-label', ariaLabel);
        }
    }
    const isPushdown = _fazGetPtype() === 'pushdown' && _fazGetType() !== 'box';

    if (!isPushdown) {
        _fazShowOverLay();
        _fazHideBanner();
    } else {
        _fazToggleAriaExpandStatus("=settings-button");
    }
}
function _fazTogglePreferenceCenter() {
    const element = _fazGetPreferenceCenter();
    if (!element) return;
    const isOpen = element.classList.contains(_fazGetPreferenceClass());
    element.classList.toggle(_fazGetPreferenceClass());
    const isPushdown = _fazGetPtype() === 'pushdown' && _fazGetType() !== 'box';
    if (isPushdown) {
        const preferenceCenter = element.querySelector('.faz-preference-center');
        if (preferenceCenter) {
            preferenceCenter.setAttribute('role', 'dialog');
            preferenceCenter.setAttribute('aria-modal', 'true');
            const ariaLabel = _fazGetLaw() === 'ccpa' ? 'Opt-out Preferences' : 'Customise Consent Preferences';
            preferenceCenter.setAttribute('aria-label', ariaLabel);
        }
        _fazToggleAriaExpandStatus("=settings-button");
    } else {
        if (!isOpen) {
            _fazShowOverLay();
            _fazHideBanner();
        } else {
            _fazHideOverLay();
            if (!ref._fazGetFromStore("action")) _fazShowBanner();
        }
    }
    if (ref._fazGetFromStore("action")) _fazShowRevisit();
    const origin = _fazStore._preferenceOriginTag;
    origin && _fazSetFocus(origin)
}
function _fazGetPreferenceClass() {
    // Pushdown (expand) only works for classic/full-width; box falls back to popup modal
    if (_fazGetPtype() === 'pushdown' && _fazGetType() !== 'box') {
        return 'faz-consent-bar-expand';
    }
    return 'faz-modal-open';
}

function _fazGetRevisit() {
    const revisit = _fazGetElementByTag('revisit-consent');
    return revisit && revisit || false;
}
function _fazHideRevisit() {    const revisit = _fazGetRevisit();
    revisit && revisit.classList.add('faz-revisit-hide')
}
function _fazShowRevisit() {
    const revisit = _fazGetRevisit();
    revisit && revisit.classList.remove('faz-revisit-hide')
}
function _fazSetPreferenceAction(tagName = false) {
    _fazStore._preferenceOriginTag = tagName;
    const isPushdown = _fazGetPtype() === 'pushdown' && _fazGetType() !== 'box';
    if (isPushdown) {
        _fazTogglePreferenceCenter();
    } else {
        _fazShowPreferenceCenter();
    }
}
function _fazGetFocusableElements(element) {
    const wrapperElement = document.querySelector(`[data-faz-tag="${element}"]`);
    if (!wrapperElement) return [];
    const focussableElements = Array.from(
        wrapperElement.querySelectorAll(
            'a:not([disabled]), button:not([disabled]), [tabindex]:not([disabled]):not([tabindex="-1"])'
        )
    ).filter((element) => element.style.display !== "none");
    if (focussableElements.length <= 0) return [];
    return [
        focussableElements[0],
        focussableElements[focussableElements.length - 1],
    ];
}
function _fazLoopFocus() {
    const activeLaw = _fazGetLaw();
    const bannerType = _fazGetType();
    if (bannerType === "classic") return;
    if (bannerType === "popup") {
        const [firstElementBanner, lastElementBanner] =
            _fazGetFocusableElements("notice");
        _fazAttachFocusLoop(firstElementBanner, lastElementBanner, true);
        _fazAttachFocusLoop(lastElementBanner, firstElementBanner);
    }
    const [firstElementPopup, lastElementPopup] = _fazGetFocusableElements(
        activeLaw === "ccpa" ? "optout-popup" : "detail"
    );
    _fazAttachFocusLoop(firstElementPopup, lastElementPopup, true);
    _fazAttachFocusLoop(lastElementPopup, firstElementPopup);
}
function _fazAttachFocusLoop(element, targetElement, isReverse = false) {
    if (!element || !targetElement) return;
    element.addEventListener("keydown", (event) => {
        if (
            event.key !== 'Tab' ||
            (isReverse && !event.shiftKey) ||
            (!isReverse && event.shiftKey)
        )
            return;
        event.preventDefault();
        targetElement.focus();
    });
}

/**
 * Replace footer shadow with current preference center background.
 * 
 * @param {object} $doc Dom node.
 * @returns 
 */
function _fazSetFooterShadow($doc) {
    const footer = $doc.querySelector('[data-faz-tag="detail"] .faz-footer-shadow');
    const preference = $doc.querySelector('[data-faz-tag="detail"]');
    if (!footer) return;
    const background = preference && preference.style.backgroundColor || '#ffffff';
    footer.style.background = `linear-gradient(180deg, rgba(255, 255, 255, 0) 0%, ${background
        } 100%)`;
}

/**
 * Remove all the rejected cookies.
 * 
 * @param {object} cookies Cookies list.
 */
function _fazRemoveDeadCookies({ cookies }) {
    const currentCookieMap = ref._fazGetCookieMap();
    for (const { cookieID, domain } of cookies) {
        // Never delete the plugin's own consent-mechanism cookies.
        if (cookieID === "fazcookie-consent" || cookieID === "fazVendorConsent" || cookieID === "euconsent-v2") continue;
        if (currentCookieMap[cookieID])
            [domain, ""].forEach((cookieDomain) =>
                ref._fazSetCookie(cookieID, "", 0, cookieDomain)
            );
    }
}
function _fazSetPreferenceCheckBoxStates(revisit = false) {
    for (const category of _fazStore._categories) {
        const cookieValue = ref._fazGetFromStore(category.slug);
        const checked =
            cookieValue === "yes" ||
            (!cookieValue &&
                category.defaultConsent[_fazGetLaw()]) || category.isNecessary;

        const disabled = category.isNecessary;
        const shortCodeData = _fazStore._shortCodes.find(
            (code) => code.key === 'faz_category_toggle_label'
        );
        if (!shortCodeData) return;
        const toggleTextFormatted = shortCodeData.content.replace(
            `[faz_preference_{{category_slug}}_title]`,
            category.name
        );
        _fazSetCheckboxes(
            category,
            checked,
            disabled,
            toggleTextFormatted,
            revisit
        );
        _fazSetPreferenceState(category);
    }
}

function _fazSetCheckboxes(
    category,
    checked,
    disabled,
    formattedLabel,
    revisit = false
) {
    const prefToggle = _fazStore._bannerConfig.config.preferenceCenter.toggle;
    const previewToggle = _fazStore._bannerConfig.config.categoryPreview?.toggle;

    [`fazCategoryDirect`, `fazSwitch`].forEach((key) => {
        const boxElem = document.getElementById(`${key}${category.slug}`);
        if (!boxElem) return;
        const toggle = key === 'fazCategoryDirect' ? (previewToggle || prefToggle) : prefToggle;
        const activeColor = toggle?.states?.active?.styles?.['background-color'] || '#1863dc';
        const inactiveColor = toggle?.states?.inactive?.styles?.['background-color'] || '#d0d5d2';
        _fazSetCategoryToggle(
            boxElem,
            category,
            revisit);
        boxElem.checked = checked;
        boxElem.disabled = disabled;
        if (disabled) {
            // Necessary toggles: use active (blue) color to indicate "always on".
            boxElem.style.backgroundColor = activeColor;
            boxElem.style.opacity = '1';
            boxElem.style.cursor = 'not-allowed';
        } else {
            boxElem.style.backgroundColor = checked ? activeColor : inactiveColor;
        }
        _fazSetCheckBoxAriaLabel(boxElem, checked, formattedLabel);
        if (revisit || disabled) return;
        boxElem.addEventListener("change", ({ currentTarget: elem }) => {
            const isChecked = elem.checked;
            elem.style.backgroundColor = isChecked ? activeColor : inactiveColor;
            _fazSetCheckBoxAriaLabel(boxElem, isChecked, formattedLabel);

            // Sync the paired toggle (fazSwitch ↔ fazCategoryDirect).
            const slug = category.slug;
            const pairedId = key === 'fazCategoryDirect'
                ? `fazSwitch${slug}`
                : `fazCategoryDirect${slug}`;
            const paired = document.getElementById(pairedId);
            if (paired && paired.checked !== isChecked) {
                paired.checked = isChecked;
                const pairedToggle = key === 'fazCategoryDirect' ? prefToggle : (previewToggle || prefToggle);
                const pairedActive = pairedToggle?.states?.active?.styles?.['background-color'] || '#1863dc';
                const pairedInactive = pairedToggle?.states?.inactive?.styles?.['background-color'] || '#d0d5d2';
                paired.style.backgroundColor = isChecked ? pairedActive : pairedInactive;
                _fazSetCheckBoxAriaLabel(paired, isChecked, formattedLabel);
            }
        });
    });
}
function _fazSetCategoryToggle(element, category = {}, revisit = false) {
    if (revisit) return;
    if (element.parentElement.getAttribute('data-faz-tag') === 'detail-category-preview-toggle') {
        _fazSetCategoryPreview(element, category);
    }
    if (!category.isNecessary) {
        const categoryName = category.name;
        const categoryTitle = document.querySelector(`[data-faz-tag="detail-category-title"][aria-label="${categoryName}"]`);
        if (categoryTitle) {
            const toggleContainer = categoryTitle.closest('.faz-accordion-item');
            const necessaryText = toggleContainer.querySelector('.faz-always-active');
            necessaryText && necessaryText.remove();
        }
    }
}
function _fazSetPreferenceState(category) {
    if (_fazStore._bannerConfig.config.auditTable.status === false) {
        const tableElement = document.querySelector(
            `#fazDetailCategory${category.slug} [data-faz-tag="audit-table"]`
        );
        tableElement && tableElement.remove();
        const chevronElement = document.querySelector(
            `#fazDetailCategory${category.slug} .faz-accordion-chevron`
        );
        chevronElement && chevronElement.classList.add("faz-accordion-chevron-hide");
    }
}
function _fazSetCategoryPreview(element, category) {
    if ((category.cookies && category.cookies.length === 0) && !category.isNecessary)
        element.parentElement.parentElement.remove();
    // Necessary toggles are styled gray/disabled centrally in _fazSetCheckboxes
}

function _fazSetCheckBoxAriaLabel(boxElem, isChecked, formattedLabel, isCCPA = false) {

    if (!boxElem) return;
    const keyName = isChecked ? "disable" : "enable";
    const textCode = `faz_${keyName}_${isCCPA ? "optout" : "category"}_label`;
    const shortCodeData = _fazStore._shortCodes.find(
        (code) => code.key === textCode
    );
    if (!shortCodeData) return;
    const labelText = formattedLabel
        .replace(/{{status}}/g, keyName)
        .replace(`[${textCode}]`, shortCodeData.content);
    boxElem.setAttribute("aria-label", labelText);
}
/**
 * Render banner after processing.
 */
function _fazRenderBanner() {
    const template = document.getElementById('fazBannerTemplate');
    const templateHtml = template.innerHTML;
    const doc = new DOMParser().parseFromString(templateHtml, 'text/html');
    _fazSetFooterShadow(doc);
    // Insert parsed DOM nodes instead of re-serializing to HTML string.
    // The template content originates from PHP wp_kses-sanitized HTML in
    // a <template> element; DOMParser is used only to apply footer shadow
    // styles before insertion.
    var fragment = document.createDocumentFragment();
    while (doc.body.firstChild) {
        fragment.appendChild(doc.body.firstChild);
    }
    document.body.insertBefore(fragment, document.body.firstChild);
    if (_fazGetPtype() === 'pushdown' && _fazGetType() !== 'box') _fazToggleAriaExpandStatus("=settings-button", "false");
    _fazSetPreferenceCheckBoxStates();
    _fazRenderVendorSection();
    _fazRenderServiceToggles();
    _fazAttachCategoryListeners();
    _fazRegisterListeners();
    _fazSetCCPAOptions();
    _fazSetPlaceHolder();
    _fazAttachReadMore();
    _fazAttachShowMoreLessStyles();
    _fazAttachAlwaysActiveStyles();
    _fazAttachManualLinksStyles();
    _fazRemoveStyles();
    _fazAddPositionClass();
    _fazAddRtlClass();
    _fazSetPoweredBy();
    _fazLoopFocus();
    _fazAddPreferenceCenterClass();
}

/**
 * Simple translation helper — checks _fazStore._shortCodes first, falls back to default.
 *
 * @param {string} key      Shortcode key (without faz_ prefix).
 * @param {string} fallback Default text if no shortcode found.
 * @returns {string}
 */
function _fazTranslate(key, fallback) {
    if (_fazStore._shortCodes) {
        var found = _fazStore._shortCodes.find(function(s) { return s.key === 'faz_' + key; });
        if (found && found.content) return found.content;
    }
    return fallback;
}

/**
 * Show the age verification modal (GDPR Art. 8).
 * Under-age visitors are treated as reject (only necessary cookies).
 *
 * @param {string} pendingChoice  The consent choice to execute if age-verified.
 */
function _fazShowAgeGate(pendingChoice) {
    var minAge = (_fazStore._ageGate && _fazStore._ageGate.minAge)
        ? _fazStore._ageGate.minAge
        : 16;

    // Create modal overlay
    var overlay = document.createElement('div');
    overlay.id = 'faz-age-gate';
    overlay.style.cssText = 'position:fixed;inset:0;z-index:999999;background:rgba(0,0,0,.6);display:flex;align-items:center;justify-content:center;';

    var modal = document.createElement('div');
    modal.style.cssText = 'background:#fff;border-radius:12px;padding:32px;max-width:400px;width:90%;text-align:center;box-shadow:0 20px 60px rgba(0,0,0,.3);';

    var title = document.createElement('h3');
    title.style.cssText = 'margin:0 0 12px;font-size:18px;';
    title.textContent = _fazTranslate('age_gate_title', 'Age Verification');
    modal.appendChild(title);

    var msg = document.createElement('p');
    msg.style.cssText = 'margin:0 0 20px;color:#666;font-size:14px;line-height:1.5;';
    msg.textContent = _fazTranslate('age_gate_message', 'You must be at least ' + minAge + ' years old to accept optional cookies on this site.');
    modal.appendChild(msg);

    var btnYes = document.createElement('button');
    btnYes.type = 'button';
    btnYes.style.cssText = 'background:#1863DC;color:#fff;border:none;padding:10px 24px;border-radius:6px;cursor:pointer;font-size:14px;margin:0 6px;';
    btnYes.textContent = _fazTranslate('age_gate_yes', 'I am ' + minAge + ' or older');
    btnYes.addEventListener('click', function() {
        sessionStorage.setItem('faz_age_verified', '1');
        overlay.remove();
        _fazAcceptCookies(pendingChoice);
    });
    modal.appendChild(btnYes);

    var btnNo = document.createElement('button');
    btnNo.type = 'button';
    btnNo.style.cssText = 'background:transparent;color:#666;border:1px solid #ccc;padding:10px 24px;border-radius:6px;cursor:pointer;font-size:14px;margin:0 6px;';
    btnNo.textContent = _fazTranslate('age_gate_no', 'I am under ' + minAge);
    btnNo.addEventListener('click', function() {
        overlay.remove();
        // Under-age: treat as reject (only necessary cookies)
        _fazAcceptCookies('reject');
        _fazRemoveBanner();
        _fazHidePreferenceCenter();
        _fazAfterConsent();
    });
    modal.appendChild(btnNo);

    overlay.appendChild(modal);
    document.body.appendChild(overlay);
}

/**
 * Accept or reject the consent based on the option.
 *
 * @param {string} option Type of consent.
 * @returns {void}
 */
function _fazAcceptReject(option = "custom") {
    return () => {
        _fazAcceptCookies(option);
        _fazRemoveBanner();
        _fazHidePreferenceCenter();
        _fazAfterConsent();
    };
}

function _fazActionClose() {
    _fazAcceptCookies("reject");
    _fazRemoveBanner();
    _fazHidePreferenceCenter();
    _fazAfterConsent();
}
/**
 * Consent accept callback.
 *
 * @param {string} choice  Type of consent.
 */
function _fazAcceptCookies(choice = "all") {
    // Age gate check (GDPR Art. 8): only on accept/partial, never on reject.
    if (choice !== 'reject' && _fazStore._ageGate && _fazStore._ageGate.enabled) {
        if (!sessionStorage.getItem('faz_age_verified')) {
            _fazShowAgeGate(choice);
            return;
        }
    }

    // Snapshot accepted categories before updating consent, so _fazAfterConsent
    // can detect revocations (executed JS cannot be unloaded — needs page reload).
    _fazCategoriesBeforeConsent = [];
    var _cats = _fazStore._categories || [];
    for (var _ci = 0; _ci < _cats.length; _ci++) {
        if (_cats[_ci].slug !== 'necessary' && !_fazIsCategoryToBeBlocked(_cats[_ci].slug)) {
            _fazCategoriesBeforeConsent.push(_cats[_ci].slug);
        }
    }
    const activeLaw = _fazGetLaw();
    const ccpaCheckBoxValue = _fazFindCheckBoxValue();

    ref._fazSetInStore("action", "yes");
    if (activeLaw === 'gdpr') {
        ref._fazSetInStore("consent", choice === "reject" ? "no" : "yes");
    } else {
        ref._fazSetInStore("consent", ccpaCheckBoxValue ? "yes" : "no");
    }
    const responseCategories = { accepted: [], rejected: [], action: choice };
    for (const category of _fazStore._categories) {
        let valueToSet = "no";
        if (activeLaw === 'gdpr') {
            valueToSet =
                !category.isNecessary &&
                    (choice === "reject" ||
                        (choice === "custom" && !_fazFindCheckBoxValue(category.slug)))
                    ? "no"
                    : "yes";
        } else {
            valueToSet = ccpaCheckBoxValue && !category.defaultConsent.ccpa ? "no" : "yes";
        }
        ref._fazSetInStore(`${category.slug}`, valueToSet);
        if (valueToSet === "no") {
            responseCategories.rejected.push(category.slug);
            _fazRemoveDeadCookies(category);
        } else responseCategories.accepted.push(category.slug);
    }
    // Handle per-service consent.
    if (_fazStore._perServiceConsent && _fazStore._services) {
        _fazStore._services.forEach(function(svc) {
            var svcKey = "svc." + svc.id;
            if (choice === "all") {
                ref._fazSetInStore(svcKey, "yes");
            } else if (choice === "reject") {
                ref._fazSetInStore(svcKey, "no");
            } else if (choice === "custom") {
                var svcToggle = document.querySelector('.faz-service-toggle[data-service="' + svc.id + '"]');
                if (svcToggle) {
                    ref._fazSetInStore(svcKey, svcToggle.checked ? "yes" : "no");
                } else {
                    // No toggle found — follow category consent.
                    var catConsent = ref._fazGetFromStore(svc.category);
                    ref._fazSetInStore(svcKey, catConsent || "no");
                }
            }
        });
    }

    // Handle IAB vendor consent.
    _fazSaveVendorConsent(choice);

    _fazUnblock();
    _fazFireEvent(responseCategories);
}
function _fazSetShowMoreLess() {
    const activeLaw = _fazGetLaw();
    const showCode = _fazStore._shortCodes.find(
        (code) => code.key === "faz_show_desc"
    );
    const hideCode = _fazStore._shortCodes.find(
        (code) => code.key === "faz_hide_desc"
    );

    if (!showCode || !hideCode) return;
    const hideButtonContent = hideCode.content;
    const showButtonContent = showCode.content;

    const contentLimit = window.innerWidth < 376 ? 150 : 300;
    const element = document.querySelector(
        `[data-faz-tag="${activeLaw === "gdpr" ? "detail" : "optout"}-description"]`
    );
    if (!element) return;
    const content = element.textContent;
    if (content.length < contentLimit) return;

    // Snapshot the original DOM content (already rendered, safe).
    const originalNodes = document.createDocumentFragment();
    Array.from(element.childNodes).forEach(function (n) {
        originalNodes.appendChild(n.cloneNode(true));
    });

    const contentHTML = element.innerHTML;
    const htmlDoc = new DOMParser().parseFromString(contentHTML, "text/html");
    const innerElements = htmlDoc.querySelectorAll("body > p");
    if (innerElements.length <= 1) return;

    // Build truncated DOM fragment from paragraphs.
    let strippedLen = 0;
    const truncatedFragment = document.createDocumentFragment();
    for (let index = 0; index < innerElements.length; index++) {
        if (index === innerElements.length - 1) return;
        const para = innerElements[index];
        const paraHTML = para.outerHTML;
        if (strippedLen + paraHTML.length > contentLimit) {
            // Append ellipsis and show-more button to this paragraph via DOM.
            para.appendChild(document.createTextNode('...\u00A0'));
            var showBtnNodes = _fazParseHTML(showButtonContent);
            para.appendChild(showBtnNodes);
        }
        // Adopt the paragraph node into the live document.
        truncatedFragment.appendChild(document.adoptNode(para));
        strippedLen += paraHTML.length;
        if (strippedLen > contentLimit) break;
    }

    function showMoreHandler() {
        // Replace content with full original nodes + hide button.
        while (element.firstChild) element.removeChild(element.firstChild);
        Array.from(originalNodes.childNodes).forEach(function (n) {
            element.appendChild(n.cloneNode(true));
        });
        element.appendChild(_fazParseHTML(hideButtonContent));
        _fazAttachListener("=hide-desc-button", showLessHandler);
        _fazAttachShowMoreLessStyles();
    }
    function showLessHandler() {
        // Replace content with truncated nodes (cloned each time).
        while (element.firstChild) element.removeChild(element.firstChild);
        Array.from(truncatedFragment.childNodes).forEach(function (n) {
            element.appendChild(n.cloneNode(true));
        });
        _fazAttachListener("=show-desc-button", showMoreHandler);
        _fazAttachShowMoreLessStyles();
    }
    showLessHandler();
}
/**
 * Add styles to the shortcode HTML rendered outside of the banner.
 * 
 * @returns {void}
 */
function _fazAttachShortCodeStyles() {
    const shortCodes = _fazStore._tags;
    Array.prototype.forEach.call(shortCodes, function (shortcode) {
        document.querySelectorAll('[data-faz-tag=' + shortcode.tag + ']').forEach(function (item) {
            let styles = '';
            for (const key in shortcode.styles) {
                styles += `${key}: ${shortcode.styles[key]};`;
            }
            item.style.cssText = styles;
        });
    });
}

/** Script blocker Version 2 */

const _fazCreateElementBackup = document.createElement;
document.createElement = (...args) => {
    const createdElement = _fazCreateElementBackup.call(document, ...args);
    if (createdElement.nodeName.toLowerCase() !== "script") return createdElement;
    const originalSetAttribute = createdElement.setAttribute.bind(createdElement);
    Object.defineProperties(createdElement, {
        src: {
            get: function () {
                return createdElement.getAttribute("src");
            },
            set: function (value) {
                if (_fazShouldChangeType(createdElement, value))
                    originalSetAttribute("type", "javascript/blocked");
                originalSetAttribute("src", value);
                return true;
            },
        },
        type: {
            get: function () {
                return createdElement.getAttribute("type");
            },
            set: function (value) {
                value = _fazShouldChangeType(createdElement)
                    ? "javascript/blocked"
                    : value;
                originalSetAttribute("type", value);
                return true;
            },
        },
    });
    createdElement.setAttribute = (name, value) => {
        if (name === "type" || name === "src")
            return (createdElement[name] = value);
        originalSetAttribute(name, value);
        if (name === "data-fazcookie" && !_fazShouldChangeType(createdElement))
            originalSetAttribute("type", "text/javascript");
    };
    return createdElement;
};

function _fazMutationObserver(mutations) {
    for (const { addedNodes } of mutations) {
        for (const node of addedNodes) {
            if (
                !node.src ||
                !node.nodeName ||
                !["script", "iframe"].includes(node.nodeName.toLowerCase())
            )
                continue;
            try {
                const urlToParse = node.src.startsWith("//")
                    ? `${window.location.protocol}${node.src}`
                    : node.src;
                const { hostname, pathname } = new URL(urlToParse);
                const cleanedHostname = _fazCleanHostName(`${hostname}${pathname}`);
                _fazAddProviderToList(node, cleanedHostname);
                if (_fazIsUserWhitelisted(node.src)) continue;
                if (!_fazShouldBlockProvider(cleanedHostname)) continue;
                const uniqueID = ref._fazRandomString(8, false);
                if (node.nodeName.toLowerCase() === "iframe")
                    _fazAddPlaceholder(node, uniqueID);
                else {
                    node.type = "javascript/blocked";
                    const scriptEventListener = function (event) {
                        event.preventDefault();
                        node.removeEventListener(
                            "beforescriptexecute",
                            scriptEventListener
                        );
                    };
                    node.addEventListener("beforescriptexecute", scriptEventListener);
                }
                const position =
                    document.head.compareDocumentPosition(node) &
                        Node.DOCUMENT_POSITION_CONTAINED_BY
                        ? "head"
                        : "body";
                node.remove();
                _fazStore._backupNodes.push({
                    position: position,
                    node: node.cloneNode(),
                    uniqueID,
                });
            } catch (_unused) { /* node backup failed, skip */ }
        }
    }
}

function _fazUnblock() {
    const fazconsent = ref._fazGetFromStore("consent");
    if (
        _fazGetLaw() === "gdpr" &&
        (!fazconsent || fazconsent !== "yes")
    )
        return;
    _fazStore._backupNodes = _fazStore._backupNodes.filter(
        ({ position, node, uniqueID }) => {
            try {
                if (_fazShouldBlockProvider(node.src)) return true;
                if (node.nodeName.toLowerCase() === "script") {
                    const scriptNode = document.createElement("script");
                    scriptNode.src = node.src;
                    scriptNode.type = "text/javascript";
                    document[position].appendChild(scriptNode);
                } else {
                    const frame = document.getElementById(uniqueID);
                    if (!frame) return false;
                    const iframe = document.createElement("iframe");
                    iframe.src = node.src;
                    iframe.width = frame.offsetWidth;
                    iframe.height = frame.offsetHeight;
                    frame.parentNode.insertBefore(iframe, frame);
                    frame.parentNode.removeChild(frame);
                }
                return false;
            } catch (error) {
                console.error(error);
                return false;
            }
        }
    );
    // Unblock server-side blocked scripts (type="text/plain" with data-faz-category).
    _fazUnblockServerSide();
}

/**
 * Check if a URL has a safe scheme (http, https, relative, or protocol-relative).
 * Blocks dangerous schemes like javascript: and data:.
 */
function _fazIsAllowedScheme(url) {
    if (!url || typeof url !== "string") return false;
    var colonPos = url.indexOf(':');
    if (colonPos < 0) return true;
    if (url.indexOf('//') === 0) return true;
    var scheme = url.substring(0, colonPos).toLowerCase();
    return scheme === 'http' || scheme === 'https';
}

/**
 * Re-enable resources that were blocked server-side via PHP output buffering.
 *
 * Handles four element types:
 * - Scripts:     type="text/plain" + data-faz-category → clone with type="text/javascript"
 * - Iframes:     data-faz-src + data-faz-category     → restore src
 * - Images:      data-faz-src + data-faz-category      → restore src (tracking pixels)
 * - Stylesheets: data-faz-href + data-faz-category     → restore href
 */
function _fazUnblockServerSide() {
    // 1. Scripts (data-faz-category from server-side, data-fazcookie from client-side).
    document.querySelectorAll('script[type="text/plain"][data-faz-category], script[type="javascript/blocked"][data-fazcookie]')
        .forEach(function (script) {
            var category = script.getAttribute("data-faz-category")
                || (script.getAttribute("data-fazcookie") || "").replace("fazcookie-", "");
            if (_fazIsCategoryToBeBlocked(category)) return;
            var clone = _fazCreateElementBackup.call(document, "script");
            var origType = script.getAttribute("data-faz-original-type");
            clone.type = origType || "text/javascript";
            // Copy attributes before src so integrity/crossorigin/nonce are set for SRI/CSP.
            for (var i = 0; i < script.attributes.length; i++) {
                var attr = script.attributes[i];
                if (attr.name === "type" || attr.name === "src" || attr.name === "data-faz-category" || attr.name === "data-faz-original-type") continue;
                clone.setAttribute(attr.name, attr.value);
            }
            var scriptSrc = script.getAttribute('src') || script.src;
            if (scriptSrc) {
                // data: URIs cannot be loaded as external script src in most
                // browsers; decode and execute as inline content instead.
                if (scriptSrc.indexOf('data:') === 0) {
                    try {
                        clone.textContent = decodeURIComponent(scriptSrc.split(',').slice(1).join(','));
                    } catch (_e) {
                        clone.src = scriptSrc;
                    }
                } else {
                    clone.src = scriptSrc;
                }
            } else {
                clone.textContent = script.textContent;
            }
            if (script.parentNode) script.parentNode.replaceChild(clone, script);
        });

    // 2. Placeholders with <template> content (iframes, oEmbeds).
    // The Placeholder_Builder wraps blocked content in a <template> inside
    // a .faz-placeholder div. Restore by replacing the placeholder with the
    // template content, then process the unblocked iframes/scripts within.
    document.querySelectorAll('.faz-placeholder[data-faz-category]')
        .forEach(function (placeholder) {
            // Skip social placeholders — handled separately in step 6.
            if (placeholder.classList.contains('faz-social-placeholder')) return;
            var cat = placeholder.getAttribute("data-faz-category");
            if (_fazIsCategoryToBeBlocked(cat)) return;
            var tpl = placeholder.querySelector('template.faz-placeholder-content');
            if (!tpl) return;
            // Clone template content into a document fragment for safe DOM insertion.
            // The template content is trusted server-rendered markup (the original
            // blocked iframe/oEmbed HTML), not user-supplied input.
            var fragment = tpl.content.cloneNode(true);
            // Restore blocked iframes inside the template content.
            fragment.querySelectorAll('iframe[data-faz-src]').forEach(function (iframe) {
                var fazSrc = iframe.getAttribute("data-faz-src");
                if (!_fazIsAllowedScheme(fazSrc)) return;
                iframe.src = fazSrc;
                iframe.removeAttribute("data-faz-src");
                iframe.style.display = "";
            });
            // Restore blocked scripts inside the template content.
            fragment.querySelectorAll('script[type="text/plain"][data-faz-category]').forEach(function (script) {
                var clone = _fazCreateElementBackup.call(document, "script");
                var origType = script.getAttribute("data-faz-original-type");
                clone.type = origType || "text/javascript";
                for (var i = 0; i < script.attributes.length; i++) {
                    var attr = script.attributes[i];
                    if (attr.name === "type" || attr.name === "src" || attr.name === "data-faz-category" || attr.name === "data-faz-original-type") continue;
                    clone.setAttribute(attr.name, attr.value);
                }
                if (script.src) clone.src = script.src;
                else clone.textContent = script.textContent;
                script.parentNode.replaceChild(clone, script);
            });
            // Replace placeholder with restored content.
            placeholder.parentNode.insertBefore(fragment, placeholder);
            placeholder.remove();
        });

    // 2b. Standalone iframes with data-faz-src (not inside a placeholder).
    document.querySelectorAll('iframe[data-faz-src][data-faz-category]')
        .forEach(function (el) {
            var cat = el.getAttribute("data-faz-category");
            if (_fazIsCategoryToBeBlocked(cat)) return;
            var fazSrc = el.getAttribute("data-faz-src");
            if (!_fazIsAllowedScheme(fazSrc)) return;
            el.src = fazSrc;
            el.removeAttribute("data-faz-src");
            el.style.display = "";
            // Remove legacy placeholder wrapper if present.
            var placeholder = el.closest('.faz-iframe-placeholder');
            if (placeholder) {
                placeholder.parentNode.insertBefore(el, placeholder);
                placeholder.remove();
            }
        });

    // 3. Images (tracking pixels inside noscript tags that JS can see).
    document.querySelectorAll('img[data-faz-src][data-faz-category]')
        .forEach(function (el) {
            var cat = el.getAttribute("data-faz-category");
            if (_fazIsCategoryToBeBlocked(cat)) return;
            var imgSrc = el.getAttribute("data-faz-src");
            if (!_fazIsAllowedScheme(imgSrc)) return;
            el.src = imgSrc;
            el.removeAttribute("data-faz-src");
        });

    // 4. Stylesheets.
    document.querySelectorAll('link[data-faz-href][data-faz-category]')
        .forEach(function (el) {
            var cat = el.getAttribute("data-faz-category");
            if (_fazIsCategoryToBeBlocked(cat)) return;
            var fazHref = el.getAttribute("data-faz-href");
            if (!_fazIsAllowedScheme(fazHref)) return;
            el.href = fazHref;
            el.removeAttribute("data-faz-href");
        });

    // 5. Deferred scripts with data-faz-waitfor (script dependency chains).
    // Usage: <script data-faz-waitfor="analytics" src="..."> loads only after
    // the "analytics" category is accepted. Useful for scripts that depend on
    // a consent-blocked tracker (e.g. a GTM plugin that needs GTM loaded first).
    document.querySelectorAll('script[data-faz-waitfor]')
        .forEach(function (script) {
            var waitCat = script.getAttribute("data-faz-waitfor");
            if (_fazIsCategoryToBeBlocked(waitCat)) return;
            if (script.getAttribute("data-faz-loaded")) return;
            script.setAttribute("data-faz-loaded", "1");
            var clone = _fazCreateElementBackup.call(document, "script");
            var origType = script.getAttribute("data-faz-original-type");
            clone.type = origType || "text/javascript";
            for (var i = 0; i < script.attributes.length; i++) {
                var attr = script.attributes[i];
                if (attr.name === "type" || attr.name === "src" || attr.name === "data-faz-waitfor" || attr.name === "data-faz-loaded" || attr.name === "data-faz-original-type") continue;
                clone.setAttribute(attr.name, attr.value);
            }
            if (script.src) clone.src = script.src;
            else clone.textContent = script.textContent;
            script.parentNode.replaceChild(clone, script);
        });

    // 6. Social embeds (Facebook, Instagram, Twitter/X).
    // Hidden elements with data-faz-category preceded by .faz-social-placeholder.
    document.querySelectorAll('.faz-social-placeholder[data-faz-category]')
        .forEach(function (placeholder) {
            var cat = placeholder.getAttribute("data-faz-category");
            if (_fazIsCategoryToBeBlocked(cat)) return;
            // Show the hidden social element that follows the placeholder.
            var next = placeholder.nextElementSibling;
            if (next && next.getAttribute("data-faz-category") === cat) {
                next.style.display = "";
                next.removeAttribute("data-faz-category");
            }
            placeholder.remove();
        });
}

function _fazAddProviderToList(node, cleanedHostname) {
    const nodeCategory =
        node.hasAttribute("data-fazcookie") && node.getAttribute("data-fazcookie");
    if (!nodeCategory) return;
    const categoryName = nodeCategory.replace("fazcookie-", "");
    for (const category of _fazStore._categories)
        if (category.isNecessary && category.slug === categoryName) return;
    const provider = _fazStore._providersToBlock.find(
        ({ re }) => re === cleanedHostname
    );
    if (!provider)
        _fazStore._providersToBlock.push({
            re: cleanedHostname,
            categories: [categoryName],
            fullPath: false,
        });
    else if (!provider.isOverridden) {
        provider.categories = [categoryName];
        provider.isOverridden = true;
    } else if (!provider.categories.includes(categoryName))
        provider.categories.push(categoryName);
}

const _nodeListObserver = new MutationObserver(_fazMutationObserver);
_nodeListObserver.observe(document.documentElement, {
    childList: true,
    subtree: true,
});
function _fazCleanHostName(name) {
    return name.replace(/^www./, "");
}

function _fazIsCategoryToBeBlocked(category) {
    const cookieValue = ref._fazGetFromStore(category);
    return (
        cookieValue === "no" ||
        (!cookieValue &&
            _fazStore._categories.some(
                (cat) => cat.slug === category && !cat.isNecessary
            ))
    );
}

/**
 * Build a lookup map from provider pattern → service ID (lazily cached).
 * Used by _fazShouldBlockProvider when per-service consent is active.
 */
var _fazPatternServiceMap = null;
function _fazGetPatternServiceMap() {
    if (_fazPatternServiceMap) return _fazPatternServiceMap;
    _fazPatternServiceMap = {};
    if (!_fazStore._services) return _fazPatternServiceMap;
    _fazStore._services.forEach(function(svc) {
        if (!svc.patterns) return;
        svc.patterns.forEach(function(p) {
            _fazPatternServiceMap[p] = svc.id;
        });
    });
    return _fazPatternServiceMap;
}

function _fazShouldBlockProvider(formattedRE) {
    if (!formattedRE || typeof formattedRE !== "string") return false;
    if (!_fazStore._providersToBlock || !_fazStore._providersToBlock.length) return false;
    const provider = _fazStore._providersToBlock.find(({ re }) => {
        if (!re) return false;
        var idx = formattedRE.indexOf(re);
        if (idx === -1) return false;
        // Boundary check: character before must be empty, /, . or protocol separator.
        if (idx > 0) {
            var before = formattedRE.charAt(idx - 1);
            if (before !== '/' && before !== '.' && before !== ':') return false;
        }
        return true;
    });
    if (!provider) return false;

    // Per-service consent: check the specific service first.
    if (_fazStore._perServiceConsent && _fazStore._services && provider.re) {
        var psMap = _fazGetPatternServiceMap();
        var serviceId = psMap[provider.re];
        if (serviceId) {
            var svcConsent = ref._fazGetFromStore("svc." + serviceId);
            if (svcConsent === "yes") return false; // Explicitly allowed.
            if (svcConsent === "no") return true;   // Explicitly blocked.
            // No specific consent — fall through to category check.
        }
    }

    return provider.categories.some((category) => _fazIsCategoryToBeBlocked(category));
}
/**
 * Check if the URL matches a user-defined whitelist pattern.
 * Defined at module scope so both _fazShouldChangeType, _fazMutationObserver,
 * and _fazNetworkInterceptors can all access it.
 */
function _fazIsUserWhitelisted(url) {
    if (typeof url !== "string") return false;
    var wl = _fazStore._userWhitelist;
    if (!Array.isArray(wl) || !wl.length) return false;
    for (var i = 0; i < wl.length; i++) {
        if (typeof wl[i] === "string" && wl[i] && url.indexOf(wl[i]) !== -1) return true;
    }
    return false;
}
function _fazShouldChangeType(element, src) {
    var url = src ? src : element.src;
    if (_fazIsUserWhitelisted(url)) return false;
    return (
        (element.hasAttribute("data-fazcookie") &&
            _fazIsCategoryToBeBlocked(
                element.getAttribute("data-fazcookie").replace("fazcookie-", "")
            )) ||
        _fazShouldBlockProvider(url)
    );
}

/**
 * Network-level consent enforcement.
 *
 * Wraps navigator.sendBeacon, fetch, and XMLHttpRequest.open to block
 * requests to known tracking endpoints when consent has not been given.
 * This is a defense-in-depth layer: even scripts that loaded before
 * the consent plugin can be prevented from phoning home.
 */
(function _fazNetworkInterceptors() {
    /**
     * Extract a clean hostname+path from a URL string for provider matching.
     * Returns empty string on failure (non-blocking).
     */
    function _fazExtractEndpoint(url) {
        if (!url || typeof url !== "string") return "";
        try {
            var full = url.startsWith("//") ? window.location.protocol + url : url;
            if (!/^https?:\/\//i.test(full)) return "";
            var u = new URL(full);
            return _fazCleanHostName(u.hostname + u.pathname);
        } catch (e) {
            return "";
        }
    }

    // --- sendBeacon ---
    if (navigator.sendBeacon) {
        var _fazOrigSendBeacon = navigator.sendBeacon.bind(navigator);
        navigator.sendBeacon = function (url, data) {
            var endpoint = _fazExtractEndpoint(url);
            if (endpoint && !_fazIsUserWhitelisted(url) && _fazShouldBlockProvider(endpoint)) {
                return true; // Pretend success — silently drop.
            }
            return _fazOrigSendBeacon(url, data);
        };
    }

    // --- fetch ---
    if (window.fetch) {
        var _fazOrigFetch = window.fetch.bind(window);
        window.fetch = function (input, init) {
            var url = typeof input === "string" ? input : (input && input.url ? input.url : "");
            var endpoint = _fazExtractEndpoint(url);
            if (endpoint && !_fazIsUserWhitelisted(url) && _fazShouldBlockProvider(endpoint)) {
                return Promise.resolve(new Response("", { status: 200, statusText: "Blocked by consent" }));
            }
            return _fazOrigFetch(input, init);
        };
    }

    // --- XMLHttpRequest ---
    var _fazOrigXHROpen = XMLHttpRequest.prototype.open;
    XMLHttpRequest.prototype.open = function (method, url) {
        // Clean up synthetic properties from a previous blocked request
        // so this XHR instance can be reused for a legitimate request.
        if (this._fazBlocked) {
            try { delete this.status; } catch (e) { /* non-configurable fallback */ }
            try { delete this.readyState; } catch (e) { /* non-configurable fallback */ }
            try { delete this.responseText; } catch (e) { /* non-configurable fallback */ }
        }
        var endpoint = _fazExtractEndpoint(url);
        this._fazBlocked = !!(endpoint && !_fazIsUserWhitelisted(url) && _fazShouldBlockProvider(endpoint));
        return _fazOrigXHROpen.apply(this, arguments);
    };
    var _fazOrigXHRSend = XMLHttpRequest.prototype.send;
    XMLHttpRequest.prototype.send = function (body) {
        if (this._fazBlocked) {
            Object.defineProperty(this, "status", { configurable: true, get: function () { return 200; } });
            Object.defineProperty(this, "readyState", { configurable: true, get: function () { return 4; } });
            Object.defineProperty(this, "responseText", { configurable: true, get: function () { return ""; } });
            if (typeof this.onreadystatechange === "function") {
                this.onreadystatechange();
            }
            this.dispatchEvent(new Event("load"));
            return;
        }
        return _fazOrigXHRSend.apply(this, arguments);
    };
})();

/**
 * Add readmore button to consent notice.
 * 
 * @returns void
 */
function _fazAttachReadMore() {
    const readMoreButton = _fazStore._shortCodes.find(
        (code) => code.key === "faz_readmore"
    );
    if (!readMoreButton || !readMoreButton.status) return;
    const content = readMoreButton.content;
    const styles = _fazStore._bannerConfig.config.readMore.styles;
    const readMoreElement = document.querySelector(
        '[data-faz-tag="description"]'
    );
    if (!readMoreElement) return;
    // Append the readmore button/link via DOM nodes instead of insertAdjacentHTML.
    // The content is a PHP wp_kses-sanitized shortcode (<a> or <button> tag).
    var readMoreNodes = _fazParseHTML('\u00A0' + content);
    if (readMoreElement.childNodes.length > 1) {
        const innerElement = document.querySelector(
            '[data-faz-tag="description"] p:last-child'
        );
        if (innerElement) innerElement.appendChild(readMoreNodes);
    } else {
        readMoreElement.appendChild(readMoreNodes);
    }
    const placeHolders = document.querySelectorAll(
        `[data-faz-tag="readmore-button"]`
    );
    if (placeHolders.length < 1) return;
    Array.from(placeHolders).forEach((placeHolder) => {
        for (const style in styles) {
            if (!styles[style]) continue;
            placeHolder.style[style] = styles[style];
        }
    });
}

/**
 * Apply styles to show more/show less buttons.
 * 
 * @returns void
 */
function _fazAttachShowMoreLessStyles() {
    if (!_fazStore._bannerConfig.config.showMore || !_fazStore._bannerConfig.config.showLess) return;
    
    const showMoreStyles = _fazStore._bannerConfig.config.showMore.styles;
    const showLessStyles = _fazStore._bannerConfig.config.showLess.styles;
    
    if (showMoreStyles) {
        const showMoreButtons = document.querySelectorAll('[data-faz-tag="show-desc-button"]');
        if (showMoreButtons.length > 0) {
            Array.from(showMoreButtons).forEach((button) => {
                for (const style in showMoreStyles) {
                    if (!showMoreStyles[style]) continue;
                    button.style[style] = showMoreStyles[style];
                }
            });
        }
    }
    
    if (showLessStyles) {
        const showLessButtons = document.querySelectorAll('[data-faz-tag="hide-desc-button"]');
        if (showLessButtons.length > 0) {
            Array.from(showLessButtons).forEach((button) => {
                for (const style in showLessStyles) {
                    if (!showLessStyles[style]) continue;
                    button.style[style] = showLessStyles[style];
                }
            });
        }
    }
}

/**
 * Apply styles to Always Active text.
 * 
 * @returns void
 */
function _fazAttachAlwaysActiveStyles() {
    if (!_fazStore._bannerConfig.config.alwaysActive) return;
    
    const alwaysActiveStyles = _fazStore._bannerConfig.config.alwaysActive.styles;
    if (!alwaysActiveStyles) return;
    
    const alwaysActiveElements = document.querySelectorAll('.faz-always-active');
    if (alwaysActiveElements.length < 1) return;
    Array.from(alwaysActiveElements).forEach((element) => {
        for (const style in alwaysActiveStyles) {
            if (!alwaysActiveStyles[style]) continue;
            element.style[style] = alwaysActiveStyles[style];
        }
    });
}

/**
 * Apply styles to manually added links.
 * 
 * @returns void
 */
function _fazAttachManualLinksStyles() {
    if (!_fazStore._bannerConfig.config.manualLinks) return;
    
    const manualLinksStyles = _fazStore._bannerConfig.config.manualLinks.styles;
    if (!manualLinksStyles) return;
    
    const manualLinks = document.querySelectorAll('.faz-link, a.faz-link, [data-faz-tag="detail"] a, [data-faz-tag="optout-popup"] a, [data-faz-tag="notice"] a:not([data-faz-tag="donotsell-button"])');
    if (manualLinks.length < 1) return;
    Array.from(manualLinks).forEach((link) => {
        for (const style in manualLinksStyles) {
            if (!manualLinksStyles[style]) continue;
            link.style[style] = manualLinksStyles[style];
        }
        if (manualLinksStyles.color) {
            link.style.textDecorationColor = manualLinksStyles.color;
        }
    });
}

var _fazCategoriesBeforeConsent = null;

function _fazAfterConsent() {
    if (_fazGetLaw() === 'gdpr') _fazSetPreferenceCheckBoxStates(true);
    _fazUpdateServiceToggleStates();
    _fazUpdateVendorCheckboxStates();

    // GTM Data Layer integration — push consent state after every consent action (configurable).
    if (_fazStore._gtmDataLayer && typeof window.dataLayer !== 'undefined') {
        var consentData = { event: 'faz_consent_update' };
        var cats = _fazStore._categories || [];
        for (var i = 0; i < cats.length; i++) {
            consentData['faz_' + cats[i].slug] = ref._fazGetFromStore(cats[i].slug) === 'yes' ? 'granted' : 'denied';
        }
        window.dataLayer.push(consentData);
    }

    // Clean up cookies from categories the user has not consented to.
    _fazCleanupRevokedCookies();

    // Detect category revocation: executed JavaScript cannot be unloaded,
    // so we must reload the page for the server to omit those scripts.
    var revoked = false;
    if (_fazCategoriesBeforeConsent && _fazCategoriesBeforeConsent.length) {
        for (var ri = 0; ri < _fazCategoriesBeforeConsent.length; ri++) {
            if (_fazIsCategoryToBeBlocked(_fazCategoriesBeforeConsent[ri])) {
                revoked = true;
                break;
            }
        }
    }

    // Re-run server-side unblocking for newly accepted categories.
    _fazUnblockServerSide();

    if (revoked || _fazStore._bannerConfig.behaviours.reloadBannerOnAccept === true) {
        window.location.reload();
        return;
    }

    // Clean up script interception if no categories remain blocked.
    // Revocations always trigger a page reload above, so the interceptors
    // will be reinstated on the fresh page load if needed.
    var anyBlocked = _fazStore._categories.some(
        function (cat) { return !cat.isNecessary && _fazIsCategoryToBeBlocked(cat.slug); }
    );
    if (!anyBlocked) {
        _nodeListObserver.disconnect();
        document.createElement = _fazCreateElementBackup;
    }

    // Cross-domain consent forwarding: send consent to configured target domains.
    if (_fazStore._consentForwarding && _fazStore._consentForwarding.enabled) {
        var targets = _fazStore._consentForwarding.targets || [];
        var consentMatch = document.cookie.match(/fazcookie-consent=([^;]+)/);
        if (consentMatch && targets.length > 0) {
            targets.forEach(function(targetUrl) {
                if (!_fazIsAllowedScheme(targetUrl)) return;
                var iframe = document.createElement('iframe');
                iframe.style.cssText = 'display:none;width:0;height:0;border:0;';
                iframe.src = targetUrl + '?faz_consent_forward=1';
                iframe.addEventListener('load', function() {
                    try {
                        iframe.contentWindow.postMessage({
                            type: 'faz_consent_forward',
                            consent: consentMatch[1]
                        }, new URL(targetUrl).origin);
                    } catch(e) { /* cross-origin error — ignore */ }
                    setTimeout(function() { if (iframe.parentNode) iframe.parentNode.removeChild(iframe); }, 1000);
                });
                document.body.appendChild(iframe);
            });
        }
    }
}

/**
 * Delete a single cookie by name, trying multiple path and domain combinations
 * to ensure deletion regardless of how the cookie was originally set.
 */
function _fazDeleteCookie(name) {
    var paths = ['/', window.location.pathname];
    var hostname = window.location.hostname;
    var rootDomain = _fazStore._rootDomain || '';
    var domains = ['', hostname];
    if (hostname.indexOf('.') !== -1) {
        domains.push('.' + hostname);
    }
    if (rootDomain && domains.indexOf(rootDomain) === -1) {
        domains.push(rootDomain);
    }
    if (rootDomain && rootDomain.charAt(0) !== '.') {
        var dotRoot = '.' + rootDomain;
        if (domains.indexOf(dotRoot) === -1) {
            domains.push(dotRoot);
        }
    }

    var expires = '=;expires=Thu, 01 Jan 1970 00:00:00 GMT;path=';
    for (var pi = 0; pi < paths.length; pi++) {
        for (var di = 0; di < domains.length; di++) {
            var cookieStr = name + expires + paths[pi];
            if (domains[di]) cookieStr += ';domain=' + domains[di];
            document.cookie = cookieStr;
        }
    }
}

/**
 * Delete cookies belonging to categories the user has NOT consented to.
 * Uses the _cookieCategoryMap provided by the server (Known Providers cookie map).
 *
 * Skips the plugin's own consent-tracking cookies (fazcookie-consent,
 * fazVendorConsent, euconsent-v2) so that consent state is preserved.
 */
function _fazCleanupRevokedCookies() {
    var cookieMap = _fazStore._cookieCategoryMap;
    if (!cookieMap || typeof cookieMap !== "object") return;

    // Plugin cookies that must never be deleted.
    var protectedCookies = ['fazcookie-consent', 'fazVendorConsent', 'euconsent-v2'];

    var currentCookies = document.cookie.split(";");

    for (var i = 0; i < currentCookies.length; i++) {
        var parts = currentCookies[i].split("=");
        var cookieName = (parts[0] || "").trim();
        if (!cookieName) continue;

        // Never delete the plugin's own cookies.
        if (protectedCookies.indexOf(cookieName) !== -1) continue;

        for (var pattern in cookieMap) {
            if (!cookieMap.hasOwnProperty(pattern)) continue;
            var category = cookieMap[pattern];

            if (!_fazIsCategoryToBeBlocked(category)) continue;

            if (_fazCookieNameMatches(cookieName, pattern)) {
                _fazDeleteCookie(cookieName);
                break;
            }
        }
    }
}

/**
 * Check if a cookie name matches a pattern (supports * wildcard).
 */
function _fazCookieNameMatches(name, pattern) {
    if (name === pattern) return true;
    if (pattern.indexOf("*") === -1) return false;
    var escaped = pattern.replace(/[.+?^${}()|[\]\\]/g, "\\$&").replace(/\*/g, ".*");
    var regex = new RegExp("^" + escaped + "$");
    return regex.test(name);
}

function _fazAttachNoticeStyles() {
    if (document.getElementById("faz-style") || !_fazStyle) return;
    var styleEl = document.createElement('style');
    styleEl.id = 'faz-style';
    styleEl.textContent = _fazStyle.css;
    document.head.appendChild(styleEl);
}

function _fazFindCheckBoxValue(id = "") {
    const elementsToCheck = id
        ? [`fazSwitch`, `fazCategoryDirect`]
        : [`fazCCPAOptOut`];
    return elementsToCheck.some((key) => {
        const checkBox = document.getElementById(`${key}${id}`);
        return checkBox && checkBox.checked;
    });
}

function _fazAddPlaceholder(htmlElm, uniqueID) {
    const shortCodeData = _fazStore._shortCodes.find(
        (code) => code.key === 'faz_video_placeholder'
    );
    const videoPlaceHolderDataCode = shortCodeData.content;
    const { offsetWidth, offsetHeight } = htmlElm;
    if (offsetWidth === 0 || offsetHeight === 0) return;
    // Insert placeholder via DOM nodes instead of insertAdjacentHTML.
    // The HTML is a PHP wp_kses-sanitized shortcode template.
    var placeholderNodes = _fazParseHTML(
        `${videoPlaceHolderDataCode}`.replace("[UNIQUEID]", uniqueID)
    );
    htmlElm.parentNode.insertBefore(placeholderNodes, htmlElm);
    const addedNode = document.getElementById(uniqueID);
    addedNode.style.width = `${offsetWidth}px`;
    addedNode.style.height = `${offsetHeight}px`;
    const innerTextElement = document.querySelector(
        `#${uniqueID} .video-placeholder-text-normal`
    );
    innerTextElement.style.display = "none";
    const youtubeID = _fazGetYoutubeID(htmlElm.src);
    if (!youtubeID) return;
    addedNode.classList.replace(
        "video-placeholder-normal",
        "video-placeholder-youtube"
    );
    addedNode.style.backgroundImage = `linear-gradient(rgba(76,72,72,.7),rgba(76,72,72,.7)),url('https://img.youtube.com/vi/${youtubeID}/maxresdefault.jpg')`;
    innerTextElement.classList.replace(
        "video-placeholder-text-normal",
        "video-placeholder-text-youtube"
    );
}
function _fazGetYoutubeID(src) {
    const match = src.match(
        /^.*(youtu\.be\/|v\/|u\/\w\/|embed\/|watch\?v=|\&v=)([^#\&\?]*).*/
    );
    if (match && Array.isArray(match) && match[2] && match[2].length === 11)
        return match[2];
    return false;
}

function _fazSetPlaceHolder() {
    const status = _fazStore._bannerConfig.config.videoPlaceholder.status;
    const styles = _fazStore._bannerConfig.config.videoPlaceholder.styles;
    if (!status) return;
    const placeHolders = document.querySelectorAll(
        `[data-faz-tag="placeholder-title"]`
    );
    if (placeHolders.length < 1) return;
    Array.from(placeHolders).forEach((placeHolder) => {
        placeHolder.style.display = "block";
        placeHolder.addEventListener("click", () => {
            if (ref._fazGetFromStore("action")) _revisitFazConsent();
        });
        for (const style in styles) {
            if (!styles[style]) continue;
            placeHolder.style[style] = styles[style];
        }
    });
}
function _fazAddRtlClass() {
    if (!_fazStore._rtl) return;
    const rtlElements = ['notice', 'detail', 'optout-popup', 'revisit-consent', 'video-placeholder']
    rtlElements.forEach(function (item) {
        var el = _fazGetElementByTag(item);
        if (el) el.classList.add('faz-rtl');
    });
}

function _fazSetFocus(tagName) {
    const element = _fazGetElementByTag(tagName);
    if (!element) return;
    element.focus();
}

function _fazSetPoweredBy() {
    let position = 'flex-end';
    ['detail-powered-by', 'optout-powered-by'].forEach((key) => {
        const element = document.querySelector(
            `[data-faz-tag="${key}"]`
        );
        if (!element) return;
        element.style.display = "flex";
        element.style.justifyContent = position;
        element.style.alignItems = "center";
    });

}
function _fazWatchBannerElement() {
    document.querySelector("body").addEventListener("click", (event) => {
        const selector = ".faz-banner-element, .faz-banner-element *";
        if (
            event.target.matches
                ? event.target.matches(selector)
                : event.target.msMatchesSelector(selector)
        )
            _revisitFazConsent();
    });

    // Delegate clicks on .faz-consent-trigger elements (blocks, shortcodes).
    document.querySelector("body").addEventListener("click", function (event) {
        var trigger = event.target.closest(".faz-consent-trigger");
        if (!trigger) return;
        event.preventDefault();
        _revisitFazConsent();
    });

    // Delegate clicks on placeholder "Accept cookies" buttons.
    document.querySelector("body").addEventListener("click", function (event) {
        var btn = event.target.closest("[data-faz-accept]");
        if (!btn) return;
        var cat = btn.getAttribute("data-faz-accept");
        if (cat && typeof window._fazAcceptCategory === "function") {
            window._fazAcceptCategory(cat);
        }
    });
}

function _fazRemoveAllDeadCookies() {
    for (const category of _fazStore._categories) {
        if (ref._fazGetFromStore(category.slug) !== "yes")
            _fazRemoveDeadCookies(category);
    }
}

function _fazSetCCPAOptions() {
    var optOption = _fazStore._bannerConfig && _fazStore._bannerConfig.config && _fazStore._bannerConfig.config.optOption;
    if (!optOption) return;
    const toggle = optOption.toggle;
    const activeColor = toggle.states.active.styles['background-color'];
    const inactiveColor = toggle.states.inactive.styles['background-color'];
    _fazClassRemove("=optout-option", "faz-disabled", false);
    const toggleDataCode = _fazStore._shortCodes.find(
        (code) => code.key === "faz_optout_toggle_label"
    );
    const optOutTitle = _fazStore._shortCodes.find(
        (code) => code.key === "faz_optout_option_title"
    );
    if (!toggleDataCode || !optOutTitle) return;
    const formattedLabel = toggleDataCode.content.replace(
        `[faz_optout_option_title]`,
        optOutTitle.content
    );
    const checked = ref._fazGetFromStore("consent") === "yes";
    _fazSetCheckBoxInfo(
        document.getElementById(`fazCCPAOptOut`),
        formattedLabel,
        {
            checked,
            disabled: false,
            addListeners: true,
        },
        { activeColor, inactiveColor },
        true
    );
}
function _fazSetCheckBoxInfo(
    boxElem,
    formattedLabel,
    { checked, disabled, addListeners },
    { activeColor, inactiveColor },
    isCCPA = false
) {
    if (!boxElem) return;
    if (isCCPA && addListeners)
        _fazAttachListener("=optout-option-title", () => boxElem.click());
    boxElem.checked = checked;
    boxElem.disabled = disabled;
    boxElem.style.backgroundColor = checked ? activeColor : inactiveColor;
    _fazSetCheckBoxAriaLabel(boxElem, checked, formattedLabel, isCCPA);
    if (!addListeners) return;
    boxElem.addEventListener("change", ({ currentTarget: elem }) => {
        const isChecked = elem.checked;
        elem.style.backgroundColor = isChecked ? activeColor : inactiveColor;
        _fazSetCheckBoxAriaLabel(boxElem, isChecked, formattedLabel, isCCPA);
    });
}

window.revisitFazConsent = () => _revisitFazConsent();

/**
 * Render per-service toggles inside each category accordion (if per-service consent enabled).
 */
function _fazRenderServiceToggles() {
    if (!_fazStore._perServiceConsent || !_fazStore._services || !_fazStore._services.length) return;

    // Get toggle colors from banner config (matching category toggles).
    var prefToggle = _fazStore._bannerConfig && _fazStore._bannerConfig.config
        ? _fazStore._bannerConfig.config.preferenceCenter && _fazStore._bannerConfig.config.preferenceCenter.toggle
        : null;
    var activeColor = (prefToggle && prefToggle.states && prefToggle.states.active && prefToggle.states.active.styles)
        ? prefToggle.states.active.styles['background-color'] || '#1863dc' : '#1863dc';
    var inactiveColor = (prefToggle && prefToggle.states && prefToggle.states.inactive && prefToggle.states.inactive.styles)
        ? prefToggle.states.inactive.styles['background-color'] || '#d0d5d2' : '#d0d5d2';

    _fazStore._categories.forEach(function(category) {
        if (category.isNecessary || category.slug === 'necessary') return;

        // Find the accordion body for this category.
        var accordionEl = document.getElementById('fazDetailCategory' + category.slug);
        if (!accordionEl) return;
        var accordionBody = accordionEl.querySelector('.faz-accordion-body');
        if (!accordionBody) return;

        // Get services for this category.
        var categoryServices = _fazStore._services.filter(function(s) { return s.category === category.slug; });
        if (!categoryServices.length) return;

        // Create service toggles container.
        var serviceList = document.createElement('div');
        serviceList.className = 'faz-service-list';
        serviceList.setAttribute('data-faz-category', category.slug);
        serviceList.style.cssText = 'margin-top:12px;padding-top:12px;border-top:1px solid rgba(0,0,0,.1);';

        var serviceTitle = document.createElement('div');
        serviceTitle.style.cssText = 'font-size:12px;color:#888;margin-bottom:8px;font-weight:600;text-transform:uppercase;letter-spacing:0.5px;';
        serviceTitle.textContent = 'Services';
        serviceList.appendChild(serviceTitle);

        categoryServices.forEach(function(service) {
            var row = document.createElement('div');
            row.className = 'faz-service-row';
            row.style.cssText = 'display:flex;align-items:center;justify-content:space-between;padding:4px 0;';

            var label = document.createElement('span');
            label.style.cssText = 'font-size:13px;';
            label.textContent = service.label;
            row.appendChild(label);

            // Toggle switch (same visual structure as category toggles).
            var switchWrap = document.createElement('div');
            switchWrap.className = 'faz-switch';
            switchWrap.style.cssText = 'flex-shrink:0;';

            var checkbox = document.createElement('input');
            checkbox.type = 'checkbox';
            checkbox.className = 'faz-service-toggle';
            checkbox.setAttribute('data-service', service.id);
            checkbox.setAttribute('data-category', service.category);
            checkbox.setAttribute('aria-label', 'Service consent: ' + service.label);

            // Determine checked state: explicit service consent > category consent.
            var svcConsent = ref._fazGetFromStore('svc.' + service.id);
            var catConsent = ref._fazGetFromStore(service.category);
            checkbox.checked = svcConsent ? svcConsent === 'yes' : catConsent === 'yes';
            checkbox.style.backgroundColor = checkbox.checked ? activeColor : inactiveColor;

            checkbox.addEventListener('change', function() {
                this.style.backgroundColor = this.checked ? activeColor : inactiveColor;
                // When a service is unchecked but category is checked, keep the category
                // on — individual service opt-out within an accepted category.
            });

            switchWrap.appendChild(checkbox);
            row.appendChild(switchWrap);
            serviceList.appendChild(row);
        });

        accordionBody.appendChild(serviceList);
    });

    // Sync: when a category toggle changes, update all its service toggles.
    _fazStore._categories.forEach(function(category) {
        if (category.isNecessary || category.slug === 'necessary') return;

        ['fazSwitch', 'fazCategoryDirect'].forEach(function(prefix) {
            var catToggle = document.getElementById(prefix + category.slug);
            if (!catToggle) return;
            catToggle.addEventListener('change', function() {
                var isChecked = catToggle.checked;
                document.querySelectorAll('.faz-service-toggle[data-category="' + category.slug + '"]')
                    .forEach(function(svcToggle) {
                        svcToggle.checked = isChecked;
                        svcToggle.style.backgroundColor = isChecked ? activeColor : inactiveColor;
                    });
            });
        });
    });
}

/**
 * Update per-service toggle states from the consent store (e.g., on revisit).
 */
function _fazUpdateServiceToggleStates() {
    if (!_fazStore._perServiceConsent || !_fazStore._services) return;
    var prefToggle = _fazStore._bannerConfig && _fazStore._bannerConfig.config
        ? _fazStore._bannerConfig.config.preferenceCenter && _fazStore._bannerConfig.config.preferenceCenter.toggle
        : null;
    var activeColor = (prefToggle && prefToggle.states && prefToggle.states.active && prefToggle.states.active.styles)
        ? prefToggle.states.active.styles['background-color'] || '#1863dc' : '#1863dc';
    var inactiveColor = (prefToggle && prefToggle.states && prefToggle.states.inactive && prefToggle.states.inactive.styles)
        ? prefToggle.states.inactive.styles['background-color'] || '#d0d5d2' : '#d0d5d2';

    document.querySelectorAll('.faz-service-toggle').forEach(function(toggle) {
        var serviceId = toggle.getAttribute('data-service');
        var category = toggle.getAttribute('data-category');
        var svcConsent = ref._fazGetFromStore('svc.' + serviceId);
        var catConsent = ref._fazGetFromStore(category);
        var isChecked = svcConsent ? svcConsent === 'yes' : catConsent === 'yes';
        toggle.checked = isChecked;
        toggle.style.backgroundColor = isChecked ? activeColor : inactiveColor;
    });
}

/**
 * Render IAB vendor section in preference center (if IAB enabled).
 */
function _fazRenderVendorSection() {
    if (!_fazStore._iabEnabled || !_fazStore._iabVendors || !_fazStore._iabVendors.length) return;

    // Insert vendor section into the scrollable body area (not the footer).
    const scrollBody = document.querySelector('.faz-preference-body-wrapper') ||
                       document.querySelector('.faz-preference-wrapper') ||
                       document.querySelector('.faz-modal');
    if (!scrollBody) return;

    // Insert after the accordion wrapper (categories), inside the scrollable area.
    const accordionWrapper = scrollBody.querySelector('.faz-accordion-wrapper') ||
                             scrollBody.querySelector('[data-faz-tag="detail-categories"]');

    const section = document.createElement('div');
    section.className = 'faz-iab-vendors-section';
    section.style.cssText = 'margin:16px 0;padding:0 16px;';

    const heading = document.createElement('h4');
    heading.className = 'faz-preference-title';
    heading.style.cssText = 'margin:16px 0 8px;font-size:14px;font-weight:600;';
    heading.textContent = 'IAB Vendor Consent';
    section.appendChild(heading);

    const count = document.createElement('p');
    count.style.cssText = 'margin:0 0 12px;font-size:12px;color:#6b7280;';
    count.textContent = _fazStore._iabVendors.length + ' vendor' +
        (_fazStore._iabVendors.length !== 1 ? 's' : '') + ' use your data for advertising and measurement purposes';
    section.appendChild(count);

    // Build purpose name lookup.
    const purposeNames = {};
    if (_fazStore._iabPurposes) {
        _fazStore._iabPurposes.forEach(function(p) { purposeNames[p.id] = p.name; });
    }

    // Get toggle colors from banner config (matching category toggles).
    const prefToggle = _fazStore._bannerConfig?.config?.preferenceCenter?.toggle;
    const activeColor = prefToggle?.states?.active?.styles?.['background-color'] || '#1863dc';
    const inactiveColor = prefToggle?.states?.inactive?.styles?.['background-color'] || '#d0d5d2';

    // Read existing vendor consent.
    const existingConsent = _fazReadVendorConsent();

    _fazStore._iabVendors.forEach(function(vendor) {
        const accordion = document.createElement('div');
        accordion.className = 'faz-accordion';
        accordion.id = 'fazVendor' + vendor.id;

        const item = document.createElement('div');
        item.className = 'faz-accordion-item';

        // Chevron (matches category accordions).
        const chevron = document.createElement('div');
        chevron.className = 'faz-accordion-chevron';
        const chevronIcon = document.createElement('i');
        chevronIcon.className = 'faz-chevron-right';
        chevron.appendChild(chevronIcon);
        item.appendChild(chevron);

        // Header wrapper (matches category accordions).
        const headerWrapper = document.createElement('div');
        headerWrapper.className = 'faz-accordion-header-wrapper';

        const header = document.createElement('div');
        header.className = 'faz-accordion-header';

        const nameBtn = document.createElement('button');
        nameBtn.className = 'faz-accordion-btn';
        nameBtn.type = 'button';
        nameBtn.textContent = vendor.name;
        nameBtn.setAttribute('aria-label', vendor.name);
        nameBtn.setAttribute('aria-expanded', 'false');
        header.appendChild(nameBtn);

        // Toggle switch (same structure as category toggles).
        const switchWrap = document.createElement('div');
        switchWrap.className = 'faz-switch';
        const cb = document.createElement('input');
        cb.type = 'checkbox';
        cb.id = 'fazVendorSwitch' + vendor.id;
        cb.setAttribute('aria-label', 'Vendor consent: ' + vendor.name);
        cb.checked = existingConsent[vendor.id] === true;
        cb.style.backgroundColor = cb.checked ? activeColor : inactiveColor;
        cb.addEventListener('change', function() {
            cb.style.backgroundColor = cb.checked ? activeColor : inactiveColor;
        });
        switchWrap.appendChild(cb);
        header.appendChild(switchWrap);
        headerWrapper.appendChild(header);

        // Short purpose summary (matches category description area).
        const purposeLabels = (vendor.purposes || []).map(function(pid) {
            return purposeNames[pid] || ('Purpose ' + pid);
        });
        const liLabels = (vendor.legIntPurposes || []).map(function(pid) {
            return purposeNames[pid] || ('Purpose ' + pid);
        });
        const allPurposeCount = purposeLabels.length + liLabels.length;
        if (allPurposeCount > 0) {
            const desc = document.createElement('div');
            desc.className = 'faz-accordion-header-des';
            const descP = document.createElement('p');
            descP.textContent = allPurposeCount + ' purpose' + (allPurposeCount !== 1 ? 's' : '') +
                (vendor.features && vendor.features.length ? ', ' + vendor.features.length + ' feature' + (vendor.features.length !== 1 ? 's' : '') : '');
            desc.appendChild(descP);
            headerWrapper.appendChild(desc);
        }

        item.appendChild(headerWrapper);

        // Expandable body (details on click).
        const bodyId = 'fazVendor' + vendor.id + 'Body';
        const body = document.createElement('div');
        body.className = 'faz-accordion-body';
        body.id = bodyId;
        body.style.cssText = 'font-size:12px;color:#374151;padding:8px 0 8px 24px;';
        nameBtn.setAttribute('aria-controls', bodyId);

        let safePolicyUrl = '';
        if (vendor.policyUrl) {
            try {
                const parsedUrl = new URL(vendor.policyUrl, window.location.origin);
                if (parsedUrl.protocol === 'http:' || parsedUrl.protocol === 'https:') {
                    safePolicyUrl = parsedUrl.href;
                }
            } catch (_unused) { /* invalid URL */ }
        }
        if (safePolicyUrl) {
            const pLink = document.createElement('a');
            pLink.href = safePolicyUrl;
            pLink.target = '_blank';
            pLink.rel = 'noopener noreferrer';
            pLink.textContent = 'Privacy Policy';
            pLink.style.cssText = 'color:#1863dc;text-decoration:underline;';
            body.appendChild(pLink);
            body.appendChild(document.createElement('br'));
        }

        function appendDetail(parent, label, text) {
            const p = document.createElement('p');
            p.style.margin = '4px 0 0';
            const b = document.createElement('strong');
            b.textContent = label + ': ';
            p.appendChild(b);
            p.appendChild(document.createTextNode(text));
            parent.appendChild(p);
        }
        if (purposeLabels.length) appendDetail(body, 'Consent', purposeLabels.join(', '));
        if (liLabels.length) appendDetail(body, 'Legitimate Interest', liLabels.join(', '));
        if (vendor.features && vendor.features.length) {
            appendDetail(body, 'Features', vendor.features.map(function(fid) { return 'Feature ' + fid; }).join(', '));
        }
        if (vendor.cookieMaxAgeSeconds != null) {
            appendDetail(body, 'Cookie retention', Math.round(vendor.cookieMaxAgeSeconds / 86400) + ' days');
        }

        accordion.appendChild(item);
        accordion.appendChild(body);

        // Toggle body on chevron/name click.
        function toggleBody() {
            const isOpen = body.style.display === 'block';
            body.style.display = isOpen ? 'none' : 'block';
            nameBtn.setAttribute('aria-expanded', isOpen ? 'false' : 'true');
            if (isOpen) {
                chevronIcon.classList.remove('faz-chevron-down');
                chevronIcon.classList.add('faz-chevron-right');
            } else {
                chevronIcon.classList.remove('faz-chevron-right');
                chevronIcon.classList.add('faz-chevron-down');
            }
        }
        nameBtn.addEventListener('click', toggleBody);
        chevron.addEventListener('click', toggleBody);

        section.appendChild(accordion);
    });

    if (accordionWrapper) {
        // Insert right after the category accordion list, inside the scrollable area.
        accordionWrapper.parentNode.insertBefore(section, accordionWrapper.nextSibling);
    } else {
        scrollBody.appendChild(section);
    }
}

/**
 * Read vendor consent from cookie.
 */
function _fazReadVendorConsent() {
    const result = {};
    const match = document.cookie.match(/fazVendorConsent=([^;]+)/);
    if (!match) return result;
    match[1].split(',').forEach(function(pair) {
        const kv = pair.split(':');
        if (kv.length === 2) {
            result[parseInt(kv[0], 10)] = kv[1].trim() === 'yes';
        }
    });
    return result;
}

/**
 * Sync vendor checkbox UI states from the fazVendorConsent cookie.
 * Called after Accept All / Reject All and when reopening the preference center.
 */
function _fazUpdateVendorCheckboxStates() {
    if (!_fazStore._iabEnabled || !_fazStore._iabVendors || !_fazStore._iabVendors.length) return;
    const consent = _fazReadVendorConsent();
    const prefToggle = _fazStore._bannerConfig?.config?.preferenceCenter?.toggle;
    const activeColor = prefToggle?.states?.active?.styles?.['background-color'] || '#1863dc';
    const inactiveColor = prefToggle?.states?.inactive?.styles?.['background-color'] || '#d0d5d2';
    _fazStore._iabVendors.forEach(function(vendor) {
        const cb = document.getElementById('fazVendorSwitch' + vendor.id);
        if (!cb) return;
        cb.checked = consent[vendor.id] === true;
        cb.style.backgroundColor = cb.checked ? activeColor : inactiveColor;
    });
}

/**
 * Save vendor consent based on choice.
 * @param {string} choice 'all', 'reject', or 'custom'
 */
function _fazSaveVendorConsent(choice) {
    if (!_fazStore._iabEnabled || !_fazStore._iabVendors || !_fazStore._iabVendors.length) return;

    const parts = [];
    _fazStore._iabVendors.forEach(function(vendor) {
        let value = 'no';
        if (choice === 'all') {
            value = 'yes';
        } else if (choice === 'reject') {
            value = 'no';
        } else {
            // Custom: read checkbox state.
            const cb = document.getElementById('fazVendorSwitch' + vendor.id);
            value = (cb && cb.checked) ? 'yes' : 'no';
        }
        parts.push(vendor.id + ':' + value);
    });

    const expiry = _fazStore._expiry || 180;
    const date = new Date();
    date.setTime(date.getTime() + (expiry * 24 * 60 * 60 * 1000));
    let domain = '';
    if (_fazStore._rootDomain) {
        domain = ';domain=' + _fazStore._rootDomain;
    }
    const payload = parts.join(',');
    if (payload.length > 3800) {
        console.warn('fazVendorConsent cookie too large (' + payload.length + ' bytes), vendor consent may not persist reliably.');
        return;
    }
    const secure = location.protocol === 'https:' ? ';Secure' : '';
    document.cookie = 'fazVendorConsent=' + payload + ';expires=' + date.toUTCString() + ';path=/' + domain + ';SameSite=Lax' + secure;
}

/**
 * Accept a single consent category programmatically (used by iframe placeholders).
 */
window._fazAcceptCategory = function (categorySlug) {
    var matched = false;
    for (const cat of _fazStore._categories) {
        if (cat.slug === categorySlug && !cat.isNecessary) {
            matched = true;
            ref._fazSetInStore(cat.slug, "yes");
            // Sync checkbox so _fazAcceptCookies("custom") reads the correct state.
            var cb = document.getElementById("fazSwitch" + cat.slug);
            if (cb) cb.checked = true;
            var cbDirect = document.getElementById("fazCategoryDirect" + cat.slug);
            if (cbDirect) cbDirect.checked = true;
            // Sync service toggles for this category.
            document.querySelectorAll('.faz-service-toggle[data-category="' + cat.slug + '"]')
                .forEach(function(svcToggle) { svcToggle.checked = true; });
            break;
        }
    }
    if (!matched) return;
    _fazAcceptCookies("custom");
    _fazRemoveBanner();
    _fazHidePreferenceCenter();
    _fazAfterConsent();
};

window.getFazConsent = function () {
    const cookieConsent = {
        activeLaw: "",
        categories: {},
        isUserActionCompleted: false,
        consentID: "",
        languageCode: ""
    };

    try {
        cookieConsent.activeLaw = _fazGetLaw();

        _fazStore._categories.forEach(category => {
            cookieConsent.categories[category.slug] = ref._fazGetFromStore(category.slug) === "yes";
        });

        cookieConsent.isUserActionCompleted = ref._fazGetFromStore("action") === "yes";
        cookieConsent.consentID = ref._fazGetFromStore("consentid") || "";
        cookieConsent.languageCode = _fazStore._language || "";
    } catch (_unused) { /* consent data unavailable */ }

    return cookieConsent;
};

// Cross-domain consent forwarding: listen for incoming consent from other domains.
window.addEventListener('message', function(event) {
    if (!_fazStore._consentForwarding || !_fazStore._consentForwarding.enabled) return;
    var targets = _fazStore._consentForwarding.targets || [];
    var originAllowed = targets.some(function(t) {
        try { return new URL(t).origin === event.origin; } catch(e) { return false; }
    });
    if (!originAllowed) return;

    if (event.data && event.data.type === 'faz_consent_forward' && event.data.consent) {
        // Validate consent string format and length before writing to cookie.
        var consent = event.data.consent;
        if (typeof consent !== 'string' || consent.length > 2048) return;
        if (!/^[a-zA-Z0-9._:\-]+(,[a-zA-Z0-9._:\-]+)*$/.test(consent)) return;

        // Apply forwarded consent cookie.
        var d = new Date();
        d.setTime(d.getTime() + (_fazStore._expiry || 180) * 24 * 60 * 60 * 1000);
        document.cookie = 'fazcookie-consent=' + consent + '; expires=' + d.toUTCString() + '; path=/; SameSite=Lax';
        // Reload to apply the forwarded consent state.
        window.location.reload();
    }
});