/**
 * FAZ Cookie Manager — Gutenberg block registrations.
 *
 * Three server-side rendered blocks registered without a build step.
 * Uses wp.serverSideRender for live previews in the block editor.
 *
 * @package FazCookie
 */
( function( blocks, element, serverSideRender, blockEditor, components, i18n ) {
	var el                = element.createElement;
	var SSR               = serverSideRender;
	var InspectorControls = blockEditor.InspectorControls;
	var PanelBody         = components.PanelBody;
	var TextControl       = components.TextControl;
	var SelectControl     = components.SelectControl;
	var ToggleControl     = components.ToggleControl;
	var Placeholder       = components.Placeholder;
	var __                = i18n.__;

	/* ------------------------------------------------------------------ */
	/*  Block 1: Cookie Table                                             */
	/* ------------------------------------------------------------------ */
	blocks.registerBlockType( 'faz/cookie-table', {
		edit: function( props ) {
			return el( 'div', { className: 'faz-block-wrap' },
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings', 'faz-cookie-manager' ) },
						el( TextControl, {
							label:    __( 'Columns', 'faz-cookie-manager' ),
							help:     __( 'Comma-separated: name, domain, duration, description, category', 'faz-cookie-manager' ),
							value:    props.attributes.columns,
							onChange: function( v ) { props.setAttributes( { columns: v } ); }
						} ),
						el( TextControl, {
							label:    __( 'Category filter', 'faz-cookie-manager' ),
							help:     __( 'Filter by category slug (e.g. analytics)', 'faz-cookie-manager' ),
							value:    props.attributes.category,
							onChange: function( v ) { props.setAttributes( { category: v } ); }
						} ),
						el( TextControl, {
							label:    __( 'Heading', 'faz-cookie-manager' ),
							value:    props.attributes.heading,
							onChange: function( v ) { props.setAttributes( { heading: v } ); }
						} )
					)
				),
				el( SSR, { block: 'faz/cookie-table', attributes: props.attributes } )
			);
		},
		save: function() { return null; }
	} );

	/* ------------------------------------------------------------------ */
	/*  Block 2: Cookie Policy                                            */
	/* ------------------------------------------------------------------ */
	blocks.registerBlockType( 'faz/cookie-policy', {
		edit: function( props ) {
			return el( 'div', { className: 'faz-block-wrap' },
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings', 'faz-cookie-manager' ) },
						el( ToggleControl, {
							label:    __( 'Show cookie table', 'faz-cookie-manager' ),
							checked:  props.attributes.show_table !== 'no',
							onChange: function( v ) { props.setAttributes( { show_table: v ? 'yes' : 'no' } ); }
						} ),
						el( TextControl, {
							label:    __( 'Site name', 'faz-cookie-manager' ),
							help:     __( 'Leave empty to use the site title', 'faz-cookie-manager' ),
							value:    props.attributes.site_name,
							onChange: function( v ) { props.setAttributes( { site_name: v } ); }
						} ),
						el( TextControl, {
							label:    __( 'Contact email', 'faz-cookie-manager' ),
							help:     __( 'Leave empty to use the admin email', 'faz-cookie-manager' ),
							value:    props.attributes.contact,
							onChange: function( v ) { props.setAttributes( { contact: v } ); }
						} )
					)
				),
				el( SSR, { block: 'faz/cookie-policy', attributes: props.attributes } )
			);
		},
		save: function() { return null; }
	} );

	/* ------------------------------------------------------------------ */
	/*  Block 3: Manage Consent Button                                    */
	/* ------------------------------------------------------------------ */
	blocks.registerBlockType( 'faz/consent-button', {
		edit: function( props ) {
			return el( 'div', { className: 'faz-block-wrap' },
				el( InspectorControls, null,
					el( PanelBody, { title: __( 'Settings', 'faz-cookie-manager' ) },
						el( TextControl, {
							label:    __( 'Button label', 'faz-cookie-manager' ),
							help:     __( 'Leave empty for default: "Manage Cookie Preferences"', 'faz-cookie-manager' ),
							value:    props.attributes.label,
							onChange: function( v ) { props.setAttributes( { label: v } ); }
						} ),
						el( SelectControl, {
							label:   __( 'Style', 'faz-cookie-manager' ),
							value:   props.attributes.style,
							options: [
								{ value: 'button', label: __( 'Button', 'faz-cookie-manager' ) },
								{ value: 'link',   label: __( 'Link', 'faz-cookie-manager' ) }
							],
							onChange: function( v ) { props.setAttributes( { style: v } ); }
						} )
					)
				),
				el( SSR, { block: 'faz/consent-button', attributes: props.attributes } )
			);
		},
		save: function() { return null; }
	} );

} )(
	window.wp.blocks,
	window.wp.element,
	window.wp.serverSideRender,
	window.wp.blockEditor,
	window.wp.components,
	window.wp.i18n
);
