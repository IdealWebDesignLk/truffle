/**
 * TC Booking admin - color picker for the Service edit screen's "Special
 * service" calendar color field (GitHub issue #72).
 *
 * Just WordPress core's own wp-color-picker, initialized on the one input -
 * no custom UI of our own to build or maintain.
 */
( function ( $ ) {
	'use strict';

	$( function () {
		$( '.tc-color-picker' ).wpColorPicker();
	} );
} )( jQuery );
