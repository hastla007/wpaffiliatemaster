/* WP Affiliate Captain editor blocks (apiVersion 3, metadata in blocks/<name>/block.json, no build step). */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var SSR = wp.serverSideRender;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Inspector = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;

	function save() {
		return null; // Rendered on the server.
	}

	function placeholder( icon, label, help ) {
		return function () {
			return el( 'div', useBlockProps(), el( Placeholder, { icon: icon, label: label, instructions: help } ) );
		};
	}

	wp.blocks.registerBlockType( 'wpac/registration', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( Inspector, null,
					el( PanelBody, { title: __( 'Settings', 'wp-affiliate-captain' ) },
						el( TextControl, {
							label: __( 'Fixed partner group ID (0 = applicant chooses)', 'wp-affiliate-captain' ),
							type: 'number',
							value: props.attributes.group,
							onChange: function ( v ) { props.setAttributes( { group: parseInt( v, 10 ) || 0 } ); }
						} )
					)
				),
				el( Placeholder, { icon: 'groups', label: __( 'Partner application form', 'wp-affiliate-captain' ), instructions: __( 'Shows the application form for your partner program on the page.', 'wp-affiliate-captain' ) } )
			);
		},
		save: save
	} );

	wp.blocks.registerBlockType( 'wpac/portal', {
		edit: placeholder( 'chart-bar', __( 'Partner area', 'wp-affiliate-captain' ), __( 'Logged-in partners see statistics, links, creatives, commissions and payouts here.', 'wp-affiliate-captain' ) ),
		save: save
	} );

	wp.blocks.registerBlockType( 'wpac/partner-welcome', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( Inspector, null,
					el( PanelBody, { title: __( 'Settings', 'wp-affiliate-captain' ) },
						el( TextControl, {
							label: __( 'Text (%s = partner name)', 'wp-affiliate-captain' ),
							value: props.attributes.text,
							onChange: function ( v ) { props.setAttributes( { text: v } ); }
						} )
					)
				),
				el( SSR, { block: 'wpac/partner-welcome', attributes: props.attributes } )
			);
		},
		save: save
	} );

	wp.blocks.registerBlockType( 'wpac/partner-targets', {
		edit: placeholder( 'admin-links', __( 'Recommended partner targets', 'wp-affiliate-captain' ), __( 'Lists the pages marked as "Recommend as partner target" with personal partner links.', 'wp-affiliate-captain' ) ),
		save: save
	} );
} )( window.wp );
