/* global nube360WcFilterBlock, wp */
( function ( wp, config ) {
	'use strict';

	var el = wp.element.createElement;
	var strings = config.strings;

	wp.blocks.registerBlockType( 'nube360/attribute-filter', {
		apiVersion: 3,
		title: strings.title,
		icon: 'filter',
		category: 'woocommerce',
		attributes: {
			attribute: { type: 'string', default: '' },
			groupBy: { type: 'string', default: 'term' },
			display: { type: 'string', default: 'auto' }
		},
		edit: function ( props ) {
			var attributes = props.attributes;
			var blockProps = wp.blockEditor.useBlockProps();

			var options = [ { value: '', label: '—' } ].concat( config.attributes );

			var controls = el(
				wp.blockEditor.InspectorControls,
				null,
				el(
					wp.components.PanelBody,
					{ title: strings.title },
					el( wp.components.SelectControl, {
						label: strings.attribute,
						value: attributes.attribute,
						options: options,
						onChange: function ( value ) {
							props.setAttributes( { attribute: value } );
						}
					} ),
					el( wp.components.SelectControl, {
						label: strings.groupBy,
						value: attributes.groupBy,
						options: [
							{ value: 'term', label: strings.byTerm },
							{ value: 'group', label: strings.byGroup }
						],
						onChange: function ( value ) {
							props.setAttributes( { groupBy: value } );
						}
					} ),
					el( wp.components.SelectControl, {
						label: strings.display,
						value: attributes.display,
						options: [
							{ value: 'auto', label: strings.auto },
							{ value: 'swatch', label: strings.swatch },
							{ value: 'list', label: strings.list }
						],
						onChange: function ( value ) {
							props.setAttributes( { display: value } );
						}
					} )
				)
			);

			var preview = attributes.attribute
				? el( wp.serverSideRender, { block: 'nube360/attribute-filter', attributes: attributes } )
				: el( 'p', null, strings.pick );

			return el( 'div', blockProps, controls, preview );
		},
		save: function () {
			return null;
		}
	} );
}( window.wp, window.nube360WcFilterBlock ) );
