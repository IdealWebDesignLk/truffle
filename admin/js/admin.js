/**
 * TC Booking admin - repeaters on the Service edit screen (extras, and
 * GitHub issue #74's per-location capacity overrides).
 *
 * Deliberately plain DOM/jQuery, no build step, consistent with the rest of
 * this v1 - matches class-tc-meta-boxes.php's render_service_extras() /
 * render_service_capacity_overrides(), each of which renders a
 * <script type="text/template"> containing one blank row using the
 * placeholder index "__INDEX__". We clone that, swap in a real index, and
 * append it. Removing a row just removes the DOM node - the matching PHP
 * save method already skips any row with no real value (a blank label for
 * extras, no location picked for capacity overrides), so leftover empty
 * rows from a page reload never get saved as phantom entries.
 */
( function ( $ ) {
	'use strict';

	function initRepeater( containerId, templateId, rowClass, addButtonId, removeButtonClass ) {
		var $container = $( '#' + containerId );
		var template    = document.getElementById( templateId );
		if ( ! $container.length || ! template ) {
			return;
		}

		$( '#' + addButtonId ).on( 'click', function () {
			var index = $container.find( '.' + rowClass ).length;
			var html  = template.innerHTML.replace( /__INDEX__/g, index );
			$container.append( html );
		} );

		$container.on( 'click', '.' + removeButtonClass, function () {
			$( this ).closest( '.' + rowClass ).remove();
		} );
	}

	$( function () {
		initRepeater( 'tc-extras-rows', 'tc-extra-row-template', 'tc-extra-row', 'tc-add-extra', 'tc-remove-extra' );
		initRepeater( 'tc-capacity-override-rows', 'tc-capacity-override-row-template', 'tc-capacity-override-row', 'tc-add-capacity-override', 'tc-remove-capacity-override' );
	} );
} )( jQuery );
