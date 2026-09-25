/**
 * JS of the Settings > Nube360 screen.
 *
 * For now it only adds a light confirmation before "Test connection" when
 * the form has unsaved changes, so the user does not get confused thinking
 * the new key was tested when the old one actually was.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		var $form = $( '.nube360-wc-settings form' );

		if ( ! $form.length ) {
			return;
		}

		var initialValues = $form.serialize();

		$form.on( 'click', 'button[name="nube360_wc_test"]', function ( event ) {
			if ( $form.serialize() !== initialValues ) {
				// eslint-disable-next-line no-alert
				var saveFirst = window.confirm( window.nube360WcAdmin.unsavedChanges );
				if ( saveFirst ) {
					event.preventDefault();
					$form.find( 'button[name="nube360_wc_save"]' ).trigger( 'click' );
				}
			}
		} );
	} );
} )( jQuery );
