/* WP Affiliate Captain editor blocks (no build step). */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var SSR = wp.serverSideRender;
	var Inspector = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var Placeholder = wp.components.Placeholder;

	function staticPreview( icon, label, help ) {
		return function () {
			return el( Placeholder, { icon: icon, label: label, instructions: help } );
		};
	}

	wp.blocks.registerBlockType( 'wpac/registration', {
		title: __( 'Partner application form', 'wp-affiliate-captain' ),
		icon: 'groups',
		category: 'widgets',
		attributes: { group: { type: 'number', default: 0 } },
		edit: function ( props ) {
			return [
				el( Inspector, { key: 'i' },
					el( PanelBody, { title: __( 'Settings', 'wp-affiliate-captain' ) },
						el( TextControl, {
							label: __( 'Fixed partner group ID (0 = applicant chooses)', 'wp-affiliate-captain' ),
							type: 'number',
							value: props.attributes.group,
							onChange: function ( v ) { props.setAttributes( { group: parseInt( v, 10 ) || 0 } ); }
						} )
					)
				),
				el( Placeholder, { key: 'p', icon: 'groups', label: __( 'Partner application form', 'wp-affiliate-captain' ), instructions: __( 'Shows the application form for your partner program on the page.', 'wp-affiliate-captain' ) } )
			];
		},
		save: function () { return null; }
	} );

	wp.blocks.registerBlockType( 'wpac/portal', {
		title: __( 'Partner area', 'wp-affiliate-captain' ),
		icon: 'chart-bar',
		category: 'widgets',
		edit: staticPreview( 'chart-bar', __( 'Partner area', 'wp-affiliate-captain' ), __( 'Logged-in partners see statistics, links, creatives, commissions and payouts here.', 'wp-affiliate-captain' ) ),
		save: function () { return null; }
	} );

	wp.blocks.registerBlockType( 'wpac/partner-welcome', {
		title: __( 'Partner welcome', 'wp-affiliate-captain' ),
		icon: 'megaphone',
		category: 'widgets',
		attributes: { text: { type: 'string', default: '' } },
		edit: function ( props ) {
			return [
				el( Inspector, { key: 'i' },
					el( PanelBody, { title: __( 'Settings', 'wp-affiliate-captain' ) },
						el( TextControl, {
							label: __( 'Text (%s = partner name)', 'wp-affiliate-captain' ),
							value: props.attributes.text,
							onChange: function ( v ) { props.setAttributes( { text: v } ); }
						} )
					)
				),
				el( SSR, { key: 's', block: 'wpac/partner-welcome', attributes: props.attributes } )
			];
		},
		save: function () { return null; }
	} );

	wp.blocks.registerBlockType( 'wpac/partner-targets', {
		title: __( 'Recommended partner targets', 'wp-affiliate-captain' ),
		icon: 'admin-links',
		category: 'widgets',
		edit: staticPreview( 'admin-links', __( 'Recommended partner targets', 'wp-affiliate-captain' ), __( 'Lists the pages marked as "Recommend as partner target" with personal partner links.', 'wp-affiliate-captain' ) ),
		save: function () { return null; }
	} );
} )( window.wp );
