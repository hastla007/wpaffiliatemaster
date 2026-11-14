/* Affiliate Captain editor blocks (apiVersion 3, metadata in blocks/<name>/block.json, no build step). */
( function ( wp ) {
	var el = wp.element.createElement;
	var __ = wp.i18n.__;
	var SSR = wp.serverSideRender;
	var useBlockProps = wp.blockEditor.useBlockProps;
	var Inspector = wp.blockEditor.InspectorControls;
	var PanelBody = wp.components.PanelBody;
	var TextControl = wp.components.TextControl;
	var SelectControl = wp.components.SelectControl;
	var data = window.wpacBlocks || { groups: [] };
	var Placeholder = wp.components.Placeholder;

	function save() {
		return null; // Rendered on the server.
	}

	function placeholder( icon, label, help ) {
		return function () {
			return el( 'div', useBlockProps(), el( Placeholder, { icon: icon, label: label, instructions: help } ) );
		};
	}

	// Inactive groups are shown but not selectable; a deleted group stays visible as such.
	function groupOptions( current ) {
		var found = false;
		var options = data.groups.map( function ( g ) {
			if ( g.value === ( current || 0 ) ) {
				found = true;
			}
			return { value: String( g.value ), label: g.label, disabled: !! g.disabled && g.value !== current };
		} );
		if ( current && ! found ) {
			options.push( { value: String( current ), label: ( data.missing || '#%d' ).replace( '%d', String( current ) ) } );
		}
		return options;
	}

	wp.blocks.registerBlockType( 'wpac/registration', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( Inspector, null,
					el( PanelBody, { title: __( 'Settings', 'affiliate-captain' ) },
						el( SelectControl, {
							label: __( 'Partner group', 'affiliate-captain' ),
							help: __( 'Fix the group of this form, or let applicants choose among the public groups.', 'affiliate-captain' ),
							value: String( props.attributes.group || 0 ),
							options: groupOptions( props.attributes.group ),
							onChange: function ( v ) { props.setAttributes( { group: parseInt( v, 10 ) || 0 } ); }
						} )
					)
				),
				el( Placeholder, { icon: 'groups', label: __( 'Partner application form', 'affiliate-captain' ), instructions: __( 'Shows the application form for your partner program on the page.', 'affiliate-captain' ) } )
			);
		},
		save: save
	} );

	wp.blocks.registerBlockType( 'wpac/portal', {
		edit: placeholder( 'chart-bar', __( 'Partner area', 'affiliate-captain' ), __( 'Logged-in partners see statistics, links, creatives, commissions and payouts here.', 'affiliate-captain' ) ),
		save: save
	} );

	wp.blocks.registerBlockType( 'wpac/partner-welcome', {
		edit: function ( props ) {
			return el(
				'div',
				useBlockProps(),
				el( Inspector, null,
					el( PanelBody, { title: __( 'Settings', 'affiliate-captain' ) },
						el( TextControl, {
							label: __( 'Text (%s = partner name)', 'affiliate-captain' ),
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
		edit: placeholder( 'admin-links', __( 'Recommended partner targets', 'affiliate-captain' ), __( 'Lists the pages marked as "Recommend as partner target" with personal partner links.', 'affiliate-captain' ) ),
		save: save
	} );
} )( window.wp );
