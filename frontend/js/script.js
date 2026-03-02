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
        document.cookie.split(";").map((cookie) => {
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
        const [key, value] = curr.split(":");
        prev[key] = value;
        return prev;
    }, {});
["consentid", "consent", "action"]
    .concat(_fazStore._categories.map(({ slug }) => slug))
    .map((item) =>
        ref._fazConsentStore.set(item, fazcookieConsentMap[item] || "")
    );


/**
 * Get the value of cookie by it's name.
 * 
 * @param {string} name Name of the cookie
 * @returns {string}
 */
ref._fazGetCookie = function (name) {
    const value = new RegExp(`${name}=([^;]+)`).exec(document.cookie);
    return value && Array.isArray(value) && value[1] ? unescape(value[1]) : null;
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
    document.cookie = `${name}=${value}; expires=${new Date(
        toSetTime
    ).toUTCString()}; path=/;${domain}; SameSite=Strict;`;
}

function _fazSetConsentID() {
    const fazcookieID = ref._fazGetFromStore("consentid");
    if (fazcookieID) return;
    const consentID = ref._fazRandomString(32);
    ref._fazSetInStore("consentid", consentID);
    _fazStore._resetConsentID = true;
}

_revisitFazConsent = function () {
    _fazShowBanner();
    _fazToggleRevisit();
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

function _fazEscapeRegex(literal) {
    return literal.replace(/[.*+?^${}()[\]\\]/g, "\\$&");
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
        }ABCDEFGHIJKLMNOPQRSTUVWXYZabcdefghiklmnopqrstuvwxyz`;
    const response = [];
    for (let i = 0; i < length; i++)
        response.push(chars[Math.floor(Math.random() * chars.length)]);
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
    const responseCategories = { accepted: [], rejected: [] };
    let valueToSet = "yes";
    for (const category of _fazStore._categories) {
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
    // Pushdown pref-center forces classic layout only for full-width banner
    if (bannerType === 'banner' && _fazGetPtype() === "pushdown") {
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
    categoryNames.map((category) => {
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
                .map(filteredName => {
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
        force === true ? _fazRevisitHide() : revisit.classList.toggle('faz-revisit-hide');
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
function _fazShowBanner() {
    const notice = _fazGetBanner();
    notice && notice.classList.remove('faz-hide');
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
    if (_fazGetType() === 'classic') {
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

    if (_fazGetType() !== 'classic') {
        _fazHideOverLay();
        if (!ref._fazGetFromStore("action")) _fazShowBanner();
    } else {
        _fazToggleAriaExpandStatus("=settings-button", "false");
        _fazClassRemove("=notice", "faz-consent-bar-expand");
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

    if (_fazGetType() !== 'classic') {
        _fazShowOverLay();
        _fazHideBanner();
    } else {
        _fazToggleAriaExpandStatus("=settings-button");
        _fazClassAdd("=notice", "faz-consent-bar-expand");
    }
}
function _fazTogglePreferenceCenter() {
    const element = _fazGetPreferenceCenter();
    if (!element) return;
    element.classList.toggle(_fazGetPreferenceClass());
    if (_fazGetPtype() !== 'pushdown') _fazToggleOverLay();

    const isOpen = element.classList.contains(_fazGetPreferenceClass());
    element.classList.toggle(_fazGetPreferenceClass());
    if (_fazGetType() === 'classic') {
        const preferenceCenter = element.querySelector('.faz-preference-center');
        if (preferenceCenter) {
            preferenceCenter.setAttribute('role', 'dialog');
            preferenceCenter.setAttribute('aria-modal', 'true');
            const ariaLabel = _fazGetLaw() === 'ccpa' ? 'Opt-out Preferences' : 'Customise Consent Preferences';
            preferenceCenter.setAttribute('aria-label', ariaLabel);
        }
        _fazToggleAriaExpandStatus("=settings-button");
        _fazClassToggle("=notice", "faz-consent-bar-expand");
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
    return _fazGetPtype() === 'pushdown' ? 'faz-consent-bar-expand' : 'faz-modal-open';
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
    if (_fazGetType() === 'classic') {
        _fazTogglePreferenceCenter();
        _fazToggleAriaExpandStatus("=settings-button");
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
            event.which !== 9 ||
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
    for (const { cookieID, domain } of cookies)
        if (currentCookieMap[cookieID])
            [domain, ""].map((cookieDomain) =>
                ref._fazSetCookie(cookieID, "", 0, cookieDomain)
            );
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
    const toggle = _fazStore._bannerConfig.config.preferenceCenter.toggle;
    const activeColor = toggle.states.active.styles['background-color'];
    const inactiveColor = toggle.states.inactive.styles['background-color'];

    [`fazCategoryDirect`, `fazSwitch`].map((key) => {
        const boxElem = document.getElementById(`${key}${category.slug}`);
        if (!boxElem) return;
        _fazSetCategoryToggle(
            boxElem,
            category,
            revisit);
        boxElem.checked = checked;
        boxElem.disabled = disabled;
        boxElem.style.backgroundColor = checked ? activeColor : inactiveColor;
        _fazSetCheckBoxAriaLabel(boxElem, checked, formattedLabel);
        if (revisit) return;
        boxElem.addEventListener("change", ({ currentTarget: elem }) => {
            const isChecked = elem.checked;
            elem.style.backgroundColor = isChecked ? activeColor : inactiveColor;
            _fazSetCheckBoxAriaLabel(boxElem, isChecked, formattedLabel);
        });
    });
}
function _fazSetCategoryToggle(element, category = {}, revisit = false) {
    if (revisit) return;
    if (element.parentElement.getAttribute('data-faz-tag') === 'detail-category-toggle') {
        _fazSetCategoryPreferenceToggle(element, category);
    } else if (element.parentElement.getAttribute('data-faz-tag') === 'detail-category-preview-toggle') {
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
function _fazSetCategoryPreferenceToggle(element, category) {
    let toggleContainer = element.closest('.faz-accordion-item');
    if (!toggleContainer) return;
    const toggleSwitch = toggleContainer.querySelector('.faz-switch');
    if (category.isNecessary) {
        toggleSwitch && toggleSwitch.remove();
    } else {
        if (_fazGetType() === 'classic' && _fazStore._bannerConfig.config.categoryPreview.status || (category.cookies && category.cookies.length === 0)) {
            toggleSwitch && toggleSwitch.remove();
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
    document.body.insertAdjacentHTML(
        "afterbegin",
        doc.body.innerHTML
    );
    if (_fazGetType() === "classic") _fazToggleAriaExpandStatus("=settings-button", "false");
    _fazSetPreferenceCheckBoxStates();
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
    ref._fazSetInStore("action", "yes");
    _fazRemoveBanner();
}
/**
 * Consent accept callback.
 * 
 * @param {string} choice  Type of consent.
 */
function _fazAcceptCookies(choice = "all") {
    const activeLaw = _fazGetLaw();
    const ccpaCheckBoxValue = _fazFindCheckBoxValue();

    ref._fazSetInStore("action", "yes");
    if (activeLaw === 'gdpr') {
        ref._fazSetInStore("consent", choice === "reject" ? "no" : "yes");
    } else {
        ref._fazSetInStore("consent", ccpaCheckBoxValue ? "yes" : "no");
    }
    const responseCategories = { accepted: [], rejected: [] };
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
    const contentHTML = element.innerHTML;
    const htmlDoc = new DOMParser().parseFromString(contentHTML, "text/html");
    const innerElements = htmlDoc.querySelectorAll("body > p");
    if (innerElements.length <= 1) return;
    let strippedContent = ``;
    for (let index = 0; index < innerElements.length; index++) {
        if (index === innerElements.length - 1) return;
        const element = innerElements[index];
        if (`${strippedContent}${element.outerHTML}`.length > contentLimit)
            element.insertAdjacentHTML("beforeend", `...&nbsp;${showButtonContent}`);
        strippedContent = `${strippedContent}${element.outerHTML}`;
        if (strippedContent.length > contentLimit) break;
    }
    function showMoreHandler() {
        element.innerHTML = `${contentHTML}${hideButtonContent}`;
        _fazAttachListener("=hide-desc-button", showLessHandler);
        _fazAttachShowMoreLessStyles();
    }
    function showLessHandler() {
        element.innerHTML = strippedContent;
        _fazAttachListener("=show-desc-button", showMoreHandler);
        _fazAttachShowMoreLessStyles();
    }
    showLessHandler();
}
/**
 * Toggle show more or less on click event.
 * 
 * @param {object} object Object containing toggle buttons and texts.
 * @param {*} element Target element.
 */
function _fazToggleMoreLess(object, element) {    let {
        currentTarget,
        target
    } = element;
    if (target && target.tagName.toUpperCase() !== 'BUTTON') return;
    const ariaExpanded = currentTarget.getAttribute('aria-expanded');
    const trimmed = ariaExpanded === 'false';
    let btn = object.btnTrim;
    let text = object.originalText;
    if (!trimmed) {
        btn = object.btnExpand;
        text = object.truncatedText;
    }
    currentTarget.innerHTML = `${text}${btn}`;
    currentTarget.ariaExpanded = trimmed;
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
    if (navigator.doNotTrack === 1) return;
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
}

function _fazClearListeners() {    _nodeListObserver.disconnect();
    document.createElement = _fazCreateElementBackup;
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

function _fazShouldBlockProvider(formattedRE) {
    const provider = _fazStore._providersToBlock.find(({ re }) =>
        new RegExp(_fazEscapeRegex(re)).test(formattedRE)
    );
    return (
        provider &&
        provider.categories.some((category) => _fazIsCategoryToBeBlocked(category))
    );
}
function _fazShouldChangeType(element, src) {
    return (
        (element.hasAttribute("data-fazcookie") &&
            _fazIsCategoryToBeBlocked(
                element.getAttribute("data-fazcookie").replace("fazcookie-", "")
            )) ||
        _fazShouldBlockProvider(src ? src : element.src)
    );
}

/**
 * Add readmore button to consent notice.
 * 
 * @returns void
 */
function _fazAttachReadMore() {
    const readMoreButton = _fazStore._shortCodes.find(
        (code) => code.key === "faz_readmore"
    );
    if (!readMoreButton.status) return;
    const content = readMoreButton.content;
    const styles = _fazStore._bannerConfig.config.readMore.styles;
    const readMoreElement = document.querySelector(
        '[data-faz-tag="description"]'
    );
    if (!readMoreElement) return;
    if (readMoreElement.childNodes.length > 1) {
        const innerElement = document.querySelector(
            '[data-faz-tag="description"] p:last-child'
        );
        innerElement && innerElement.insertAdjacentHTML(
            "beforeend",
            `&nbsp;${content}`
        );
    } else {
        readMoreElement.insertAdjacentHTML(
            "beforeend",
            `&nbsp;${content}`
        );
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
    
    const manualLinks = document.querySelectorAll('.faz-link, a.faz-link, [data-faz-tag="detail"] a, [data-faz-tag="optout-popup"] a, [data-faz-tag="notice"] a');
    if (manualLinks.length < 1) return;
    Array.from(manualLinks).forEach((link) => {
        if (link.getAttribute('data-faz-tag') === 'readmore-button') return;
        for (const style in manualLinksStyles) {
            if (!manualLinksStyles[style]) continue;
            link.style[style] = manualLinksStyles[style];
        }
        if (manualLinksStyles.color) {
            link.style.textDecorationColor = manualLinksStyles.color;
        }
    });
}

function _fazAfterConsent() {
    if (_fazGetLaw() === 'gdpr') _fazSetPreferenceCheckBoxStates(true);

    if (_fazStore._bannerConfig.behaviours.reloadBannerOnAccept === true) {
        window.location.reload();
    }
}

function _fazAttachNoticeStyles() {
    if (document.getElementById("faz-style") || !_fazStyle) return;
    document.head.insertAdjacentHTML(
        "beforeend",
        ` <style id="faz-style">${_fazStyle.css}</style>`
    );
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
    htmlElm.insertAdjacentHTML(
        "beforebegin",
        `${videoPlaceHolderDataCode}`.replace("[UNIQUEID]", uniqueID)
    );
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
        _fazGetElementByTag(item) && _fazGetElementByTag(item).classList.add('faz-rtl');
    });
}

function _fazSetFocus(tagName) {
    const element = _fazGetElementByTag(tagName);
    if (!element) return;
    element.focus();
}

function _fazSetPoweredBy() {
    let position = 'flex-end';
    ['detail-powered-by', 'optout-powered-by'].map((key) => {
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
}

function _fazRemoveAllDeadCookies() {
    for (const category of _fazStore._categories) {
        if (ref._fazGetFromStore(category.slug) !== "yes")
            _fazRemoveDeadCookies(category);
    }
}

function _fazSetCCPAOptions() {
    const toggle = _fazStore._bannerConfig.config.optOption.toggle;
    const activeColor = toggle.states.active.styles['background-color'];
    const inactiveColor = toggle.states.inactive.styles['background-color'];
    _fazClassRemove("=optout-option", "faz-disabled", false);
    const toggleDataCode = _fazStore._shortCodes.find(
        (code) => code.key === "faz_optout_toggle_label"
    );
    const optOutTitle = _fazStore._shortCodes.find(
        (code) => code.key === "faz_optout_option_title"
    );
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