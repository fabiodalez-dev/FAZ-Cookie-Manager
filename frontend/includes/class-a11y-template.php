<?php
/**
 * Static accessibility improvements applied to the banner template DOM at build time.
 *
 * Called from Template::prepare_html() inside the existing DOMDocument pipeline.
 * Fixes are baked into the cached template stored in the options table, so they
 * carry zero per-request runtime cost.
 *
 * @package FazCookie\Frontend\Includes
 */

namespace FazCookie\Frontend\Includes;

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

/**
 * Applies static accessibility improvements to the banner template DOM.
 */
class A11y_Template {

	/**
	 * Entry point — apply all static a11y fixes to the DOMDocument.
	 * Called just before DOMDocument::saveHTML() in Template::prepare_html().
	 */
	public static function apply( \DOMDocument $dom, \DOMXPath $finder ): void {
		self::transform_banner_title( $dom, $finder );
		self::transform_modal_title( $dom, $finder );
		self::wrap_accordion_buttons_in_h3( $dom, $finder );
		self::add_role_switch_to_checkboxes( $finder );
		self::add_description_wrapper_id( $finder );
	}

	/**
	 * Replace <p data-faz-tag="title"> with <h2 id="faz-banner-title">.
	 * A real heading element is required so aria-labelledby on the banner
	 * container can reference it and screen readers announce it correctly.
	 */
	private static function transform_banner_title( \DOMDocument $dom, \DOMXPath $finder ): void {
		$nodes = $finder->query( '//*[@data-faz-tag="title"]' );
		if ( $nodes && $nodes->length > 0 ) {
			self::replace_tag( $dom, $nodes->item( 0 ), 'h2', array(
				'id'     => 'faz-banner-title',
				'remove' => array( 'role', 'aria-level' ),
			) );
		}
	}

	/**
	 * Replace <span data-faz-tag="detail-title"> with <h2 id="faz-modal-title">.
	 * Same reasoning as the banner title — real heading, stable id for labelledby.
	 */
	private static function transform_modal_title( \DOMDocument $dom, \DOMXPath $finder ): void {
		$nodes = $finder->query( '//*[@data-faz-tag="detail-title"]' );
		if ( $nodes && $nodes->length > 0 ) {
			self::replace_tag( $dom, $nodes->item( 0 ), 'h2', array(
				'id'     => 'faz-modal-title',
				'remove' => array( 'role', 'aria-level' ),
			) );
		}
	}

	/**
	 * Wrap each accordion category button in an <h3> so category names appear
	 * in the page heading hierarchy and can be navigated by screen reader users.
	 */
	private static function wrap_accordion_buttons_in_h3( \DOMDocument $dom, \DOMXPath $finder ): void {
		$buttons = $finder->query( '//*[@data-faz-tag="detail-category-title"]' );
		if ( ! $buttons ) {
			return;
		}
		// Collect first to avoid modifying the NodeList during iteration.
		$button_list = iterator_to_array( $buttons );
		foreach ( $button_list as $button ) {
			$h3 = $dom->createElement( 'h3' );
			$h3->setAttribute( 'class', 'faz-accordion-heading' );
			$button->parentNode->insertBefore( $h3, $button ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$h3->appendChild( $button );
		}
	}

	/**
	 * Add role="switch" to all category toggle checkboxes.
	 * Combined with the state-aware aria-label set by a11y.js at runtime,
	 * this communicates toggle semantics to switch-aware screen readers.
	 */
	private static function add_role_switch_to_checkboxes( \DOMXPath $finder ): void {
		$checkboxes = $finder->query( '//*[@data-faz-tag="detail-category-toggle"]//input[@type="checkbox"]' );
		if ( ! $checkboxes ) {
			return;
		}
		foreach ( $checkboxes as $checkbox ) {
			$checkbox->setAttribute( 'role', 'switch' );
		}
	}

	/**
	 * Add a stable id to the modal description wrapper so the show/hide button's
	 * aria-controls attribute (set by a11y.js) always has a valid target element.
	 */
	private static function add_description_wrapper_id( \DOMXPath $finder ): void {
		$nodes = $finder->query( '//*[@data-faz-tag="detail-description"]' );
		if ( $nodes && $nodes->length > 0 ) {
			$nodes->item( 0 )->setAttribute( 'id', 'faz-desc-content' );
		}
	}

	/**
	 * Generic helper: replace a DOM node with a new element of a different tag name,
	 * copying all existing attributes and moving all child nodes across.
	 */
	private static function replace_tag( \DOMDocument $dom, \DOMElement $node, string $new_tag, array $options = array() ): void {
		$new_element = $dom->createElement( $new_tag );

		// Copy all existing attributes to the new element.
		if ( $node->hasAttributes() ) {
			foreach ( $node->attributes as $attr ) {
				$new_element->setAttribute( $attr->nodeName, $attr->nodeValue ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			}
		}

		// Remove semantically incorrect attributes that should not carry over.
		foreach ( $options['remove'] ?? array() as $attr_name ) {
			if ( $new_element->hasAttribute( $attr_name ) ) {
				$new_element->removeAttribute( $attr_name );
			}
		}

		// Set the stable id used by aria-labelledby references.
		if ( ! empty( $options['id'] ) ) {
			$new_element->setAttribute( 'id', $options['id'] );
		}

		// Move all child nodes from the original to the new element.
		while ( $node->firstChild ) { // phpcs:ignore WordPress.NamingConventions.ValidVariableName
			$new_element->appendChild( $node->firstChild ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
		}

		// Swap the original node out of the tree.
		$node->parentNode->replaceChild( $new_element, $node ); // phpcs:ignore WordPress.NamingConventions.ValidVariableName
	}
}
