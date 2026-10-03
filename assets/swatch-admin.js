/* global nube360WcSwatch, wp, jQuery */
( function ( $ ) {
	'use strict';

	function init( scope ) {
		$( '.nube360-wc-color-picker', scope ).wpColorPicker();
	}

	$( function () {
		init( document );

		$( document ).on( 'click', '.nube360-wc-image-select', function ( event ) {
			event.preventDefault();

			var $field = $( this ).closest( '.nube360-wc-image-field' );
			var frame = wp.media( {
				title: nube360WcSwatch.chooseImage,
				library: { type: 'image' },
				multiple: false
			} );

			frame.on( 'select', function () {
				var attachment = frame.state().get( 'selection' ).first().toJSON();
				var url = attachment.sizes && attachment.sizes.thumbnail ? attachment.sizes.thumbnail.url : attachment.url;

				$field.find( '.nube360-wc-image-id' ).val( attachment.id );
				$field.find( '.nube360-wc-image-preview' ).html( $( '<img>', { src: url, alt: '', width: 60, height: 60 } ) );
				$field.find( '.nube360-wc-image-remove' ).show();
			} );

			frame.open();
		} );

		$( document ).on( 'click', '.nube360-wc-image-remove', function ( event ) {
			event.preventDefault();

			var $field = $( this ).closest( '.nube360-wc-image-field' );
			$field.find( '.nube360-wc-image-id' ).val( '' );
			$field.find( '.nube360-wc-image-preview' ).empty();
			$( this ).hide();
		} );

		// The "add term" form is submitted by AJAX and stays on the page: clear it.
		$( document ).ajaxSuccess( function ( event, xhr, settings ) {
			if ( settings.data && String( settings.data ).indexOf( 'action=add-tag' ) !== -1 ) {
				$( '.nube360-wc-image-remove' ).trigger( 'click' );
				$( '.nube360-wc-color-picker' ).val( '' ).wpColorPicker( 'color', '' );
			}
		} );
	} );
}( jQuery ) );
