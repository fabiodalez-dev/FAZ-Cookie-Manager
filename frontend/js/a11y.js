/**
 * @file Native accessibility enhancements for the faz-cookie-manager banner and modal.
 *
 * Handles dynamic ARIA improvements applied after the banner is injected into the DOM:
 *   - Overrides banner container role to "dialog" and adds aria-labelledby
 *   - Adds aria-labelledby to the modal preference center
 *   - ESC key handlers for banner and modal
 *   - State-aware aria-label sync on category toggle checkboxes
 *   - aria-controls on the show/hide description button
 *
 * Static fixes (heading tags, role="switch", h3 wrappers, stable IDs) are handled
 * by A11y_Template::apply() in PHP at template build time.
 *
 * Translatable strings are passed via wp_localize_script as window.fazA11yConfig.
 */
( function () {
    'use strict';

    // Translatable label templates — {name} is replaced with the category name in JS.
    // Populated by wp_localize_script in class-frontend.php.
    var config = window.fazA11yConfig || {};
    var LABEL_ENABLED  = config.checkboxEnabled  || '{name} enabled, disable {name}';
    var LABEL_DISABLED = config.checkboxDisabled || '{name} disabled, enable {name}';

    /**
     * Run all accessibility enhancements.
     * Called once after fazcookie_banner_loaded fires.
     */
    function init() {
        fixBannerRole();
        fixModalLabelledby();
        initEscHandlers();
        initCheckboxAriaLabels();
        initShowHideAriaControls();
    }

    /**
     * Override role="region" with role="dialog" on the banner container and add
     * aria-labelledby pointing to the <h2 id="faz-banner-title"> set by PHP.
     * script.js sets role="region"; we override it here after banner_loaded fires.
     */
    function fixBannerRole() {
        var banner = document.querySelector( '.faz-consent-container' );
        if ( ! banner ) return;
        banner.setAttribute( 'role', 'dialog' );
        banner.setAttribute( 'aria-labelledby', 'faz-banner-title' );
    }

    /**
     * Add aria-labelledby to the preference center dialog element, pointing to
     * <h2 id="faz-modal-title"> set by PHP. script.js sets aria-label on this
     * element; aria-labelledby takes precedence per the ARIA spec.
     */
    function fixModalLabelledby() {
        var prefCenter = document.querySelector( '.faz-preference-center' );
        if ( ! prefCenter ) return;
        prefCenter.setAttribute( 'aria-labelledby', 'faz-modal-title' );
    }

    /**
     * Attach ESC key handlers to the banner and modal.
     * ESC clicks the plugin's own close button so all internal state cleanup runs.
     */
    function initEscHandlers() {
        // Close the banner when Escape is pressed while focus is inside it.
        var banner = document.querySelector( '.faz-consent-container' );
        if ( banner ) {
            banner.addEventListener( 'keydown', function ( event ) {
                if ( event.key !== 'Escape' ) return;
                var closeBtn = document.querySelector( '[data-faz-tag="close-button"]' );
                if ( closeBtn && ! banner.classList.contains( 'faz-hide' ) ) {
                    closeBtn.click();
                }
            } );
        }

        // Close the modal when Escape is pressed while focus is inside it.
        var modal = document.querySelector( '.faz-modal' );
        if ( modal ) {
            modal.addEventListener( 'keydown', function ( event ) {
                if ( event.key !== 'Escape' ) return;
                var closeBtn = modal.querySelector( '[data-faz-tag="detail-close"]' );
                if ( closeBtn ) {
                    closeBtn.click();
                }
            } );
        }
    }

    /**
     * Set a state-aware aria-label on each category toggle checkbox.
     * Labels reflect whether the category is currently enabled or disabled so
     * screen reader users know what will happen when they activate the switch.
     * Re-syncs on every change event.
     */
    function initCheckboxAriaLabels() {
        var accordions = document.querySelectorAll( '.faz-accordion' );
        accordions.forEach( function ( accordion ) {
            var button   = accordion.querySelector( '[data-faz-tag="detail-category-title"]' );
            var checkbox = accordion.querySelector( '[data-faz-tag="detail-category-toggle"] input[type="checkbox"]' );
            if ( ! button || ! checkbox ) return;

            syncCheckboxAriaLabel( button, checkbox );

            // Update the label whenever the checkbox state changes.
            checkbox.addEventListener( 'change', function () {
                syncCheckboxAriaLabel( button, checkbox );
            } );
        } );
    }

    /**
     * Update a single checkbox's aria-label based on its current checked state.
     * Uses the translatable LABEL_ENABLED / LABEL_DISABLED templates from fazA11yConfig.
     */
    function syncCheckboxAriaLabel( button, checkbox ) {
        var name     = ( button.getAttribute( 'aria-label' ) || button.textContent || '' ).trim();
        var template = checkbox.checked ? LABEL_ENABLED : LABEL_DISABLED;
        checkbox.setAttribute( 'aria-label', template.replace( /\{name\}/g, name ) );
    }

    /**
     * Set aria-controls="faz-desc-content" on the show/hide description button.
     * The id "faz-desc-content" is added to the wrapper by A11y_Template::apply() in PHP.
     * A MutationObserver re-applies the attribute when the plugin swaps between
     * the "show more" and "show less" button variants.
     */
    function initShowHideAriaControls() {
        var wrapper = document.querySelector( '[data-faz-tag="detail-description"]' );
        if ( ! wrapper ) return;

        function applyAriaControls() {
            var btn = wrapper.querySelector( '[data-faz-tag="show-desc-button"], [data-faz-tag="hide-desc-button"]' );
            if ( btn ) btn.setAttribute( 'aria-controls', 'faz-desc-content' );
        }

        applyAriaControls();

        // The observer is intentionally not disconnected — the banner is not re-injected
        // in the current plugin design, so the lifecycle ends with the page.
        new MutationObserver( applyAriaControls ).observe( wrapper, { childList: true, subtree: true } );
    }

    // Run once after script.js has finished building and injecting the banner/modal.
    // fazcookie_banner_loaded is dispatched by _fazInit() in script.js on DOMContentLoaded.
    // Since a11y.js is loaded after script.js, this listener is always registered before
    // the event fires.
    document.addEventListener( 'fazcookie_banner_loaded', init, { once: true } );
} )();
