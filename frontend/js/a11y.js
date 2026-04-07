/**
 * @file Native accessibility enhancements for the faz-cookie-manager banner and modal.
 *
 * Handles all accessibility improvements applied after the banner is injected into the DOM:
 *
 * Structural fixes (run first in init):
 *   - Banner title element replaced with <h2 id="faz-banner-title">
 *   - Modal title element replaced with <h2 id="faz-modal-title">
 *   - Accordion category buttons wrapped in <h3 class="faz-accordion-heading">
 *   - Category toggle checkboxes get role="switch"
 *   - Description wrapper gets id="faz-desc-content"
 *
 * ARIA attribute and behaviour fixes (run after structural fixes):
 *   - Banner container role overridden to "dialog" with aria-labelledby
 *   - Modal preference center gets aria-labelledby
 *   - State-aware aria-label sync on category toggle checkboxes
 *   - aria-controls on the show/hide description button
 *
 * Translatable strings are passed via wp_localize_script as window.fazA11yConfig.
 */
( function () {
    'use strict';

	/**
     * Run all accessibility enhancements.
     * Called once after fazcookie_banner_loaded fires.
     */
    function init() {
        // Structural fixes
        transformBannerTitle();
        transformModalTitle();
        wrapAccordionButtonsInH3();
        addRoleSwitchToCheckboxes();
        addDescriptionWrapperId();
        // ARIA attribute and behavior fixes.
		initEscHandlers();
        fixBannerRole();
        fixModalLabelledby();
        initCheckboxAriaLabels();
        initShowHideAriaControls();
    }

    // ---------------------------------------------------------------------------
    // Structural DOM fixes — replace/wrap/annotate elements before ARIA work runs.
    // ---------------------------------------------------------------------------

    /**
     * Replace a DOM element with a new element of a different tag, copying all
     * attributes across, removing specified ones, and moving all child nodes.
     *
     * @param {Element} node      The element to replace.
     * @param {string}  newTag    Tag name for the replacement (e.g. 'h2').
     * @param {Object}  options
     * @param {string}  [options.id]     Optional id to set on the new element.
     * @param {Array}   [options.remove] Attribute names to strip.
     */
    function replaceTag( node, newTag, options ) {
        var newEl = document.createElement( newTag );
        // Copy all existing attributes to the new element.
        Array.from( node.attributes ).forEach( function ( attr ) {
            newEl.setAttribute( attr.name, attr.value );
        } );
        // Remove semantically incorrect attributes that should not carry over.
        ( options.remove || [] ).forEach( function ( attrName ) {
            newEl.removeAttribute( attrName );
        } );
        // Set the stable id used by aria-labelledby references.
        if ( options.id ) {
            newEl.setAttribute( 'id', options.id );
        }
        // Move all child nodes from the original to the new element.
        while ( node.firstChild ) {
            newEl.appendChild( node.firstChild );
        }
        // Swap the original node out of the tree.
        node.parentNode.replaceChild( newEl, node );
    }

    /**
     * Replace <p data-faz-tag="title"> with <h2 id="faz-banner-title">.
     * A real heading element is required so aria-labelledby on the banner
     * container can reference it and screen readers announce it correctly.
     */
    function transformBannerTitle() {
        var node = document.querySelector( '[data-faz-tag="title"]' );
        if ( ! node ) return;
        replaceTag( node, 'h2', { id: 'faz-banner-title', remove: [ 'role', 'aria-level' ] } );
    }

    /**
     * Replace <span data-faz-tag="detail-title"> with <h2 id="faz-modal-title">.
     * Same reasoning as the banner title — real heading, stable id for labelledby.
     */
    function transformModalTitle() {
        var node = document.querySelector( '[data-faz-tag="detail-title"]' );
        if ( ! node ) return;
        replaceTag( node, 'h2', { id: 'faz-modal-title', remove: [ 'role', 'aria-level' ] } );
    }

    /**
     * Wrap each accordion category button in an <h3> so category names appear
     * in the page heading hierarchy and can be navigated by screen reader users.
     */
    function wrapAccordionButtonsInH3() {
        var buttons = document.querySelectorAll( '[data-faz-tag="detail-category-title"]' );
        buttons.forEach( function ( button ) {
            var h3 = document.createElement( 'h3' );
            h3.className = 'faz-accordion-heading';
            button.parentNode.insertBefore( h3, button );
            h3.appendChild( button );
        } );
    }

    /**
     * Add role="switch" to all category toggle checkboxes.
     * Combined with the state-aware aria-label set at runtime, this communicates
     * toggle semantics to switch-aware screen readers.
     */
    function addRoleSwitchToCheckboxes() {
        var checkboxes = document.querySelectorAll(
			'[data-faz-tag="detail-category-toggle"] input[type="checkbox"], [data-faz-tag="detail-category-preview-toggle"] input[type="checkbox"]'
		);
        checkboxes.forEach( function ( checkbox ) {
            checkbox.setAttribute( 'role', 'switch' );
        } );
    }

    /**
     * Add a stable id to the modal description wrapper so the show/hide button's
     * aria-controls attribute always has a valid target element.
     */
    function addDescriptionWrapperId() {
        var wrapper = document.querySelector(
			'[data-faz-tag="detail-description"], [data-faz-tag="optout-description"]'
		);
        if ( ! wrapper ) return;
        wrapper.setAttribute( 'id', 'faz-desc-content' );
    }

    /**
     * Override role="region" with role="dialog" on the banner container and add
     * aria-labelledby pointing to the <h2 id="faz-banner-title"> set by transformBannerTitle().
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
     * <h2 id="faz-modal-title"> set by transformModalTitle(). script.js sets aria-label on this
     * element; aria-labelledby takes precedence per the ARIA spec.
     */
    function fixModalLabelledby() {
        var prefCenter = document.querySelector( '.faz-preference-center' );
        if ( ! prefCenter ) return;
        prefCenter.setAttribute( 'aria-labelledby', 'faz-modal-title' );
    }

	 /**
     * Attach ESC key handlers to the banner.
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
            var checkbox = accordion.querySelector( '[data-faz-tag="detail-category-toggle"] input[type="checkbox"], [data-faz-tag="detail-category-preview-toggle"] input[type="checkbox"]' );
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
		var config = window.fazA11yConfig || {};
		var LABEL_ENABLED  = config.checkboxEnabled  || '{name} enabled, disable {name}';
		var LABEL_DISABLED = config.checkboxDisabled || '{name} disabled, enable {name}';
        var name     = ( button.getAttribute( 'aria-label' ) || button.textContent || '' ).trim();
        var template = checkbox.checked ? LABEL_ENABLED : LABEL_DISABLED;
        checkbox.setAttribute( 'aria-label', template.replace( /\{name\}/g, name ) );
    }

    /**
     * Set aria-controls="faz-desc-content" on the show/hide description button.
     * The id "faz-desc-content" is added to the wrapper by addDescriptionWrapperId().
     * A MutationObserver re-applies the attribute when the plugin swaps between
     * the "show more" and "show less" button variants.
     */
    function initShowHideAriaControls() {
        var wrapper = document.querySelector('[data-faz-tag="detail-description"], [data-faz-tag="optout-description"]' );
        if ( ! wrapper ) return;

        function applyAriaControls() {
            var btn = wrapper.querySelector(
				'[data-faz-tag="show-desc-button"], [data-faz-tag="hide-desc-button"], [data-faz-tag="optout-show-desc-button"], [data-faz-tag="optout-hide-desc-button"]'
			);
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
