/**
 * TC Booking admin - extras repeater on the Service edit screen.
 *
 * Deliberately plain DOM/jQuery, no build step, consistent with the rest of
 * this v1 - matches class-tc-meta-boxes.php's render_service_extras(), which
 * renders a <script type="text/template"> containing one blank row using
 * the placeholder index "__INDEX__". We clone that, swap in a real index,
 * and append it. Removing a row just removes the DOM node - save_service()
 * in PHP already skips any row with a blank label, so leftover empty rows
 * from a page reload never get saved as phantom extras.
 */
( function ( $ ) {
	'use strict';

	function nextIndex( $container ) {
		return $container.find( '.tc-extra-row' ).length;
	}

	$( function () {
		var $container = $( '#tc-extras-rows' );
		var template    = document.getElementById( 'tc-extra-row-template' );
		if ( ! $container.length || ! template ) {
			return;
		}

		$( '#tc-add-extra' ).on( 'click', function () {
			var index = nextIndex( $container );
			var html  = template.innerHTML.replace( /__INDEX__/g, index );
			$container.append( html );
		} );

		$container.on( 'click', '.tc-remove-extra', function () {
			$( this ).closest( '.tc-extra-row' ).remove();
		} );
	} );
} )( jQuery );
