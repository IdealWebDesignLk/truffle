/**
 * TC Booking admin - Guide edit screen's "Calendar" meta box.
 *
 * Replaces what used to be two separate scripts/meta boxes (Availability
 * Calendar, Special Service Dates - GitHub issues #67/#68/#71) with one
 * tabbed widget: stacking every calendar open on the page at once got
 * unwieldy the moment a guide had more than one special service/location
 * combination, each with its own full month grid. "Beschikbaarheid" (the
 * regular calendar) is always the first tab; one tab per (special service,
 * location) the guide currently has checked under Locations covered /
 * Services provided (render_guide()'s own meta box, elsewhere on this same
 * page) follows, appearing/disappearing live as those checkboxes change,
 * with no page reload.
 *
 * Both halves keep their original staged-changes model (no AJAX, no
 * separate save action) - toggling a date only updates local state; hidden
 * inputs for BOTH the availability calendar's dirty dates and every special
 * calendar's offered dates are always rendered into the DOM regardless of
 * which tab is currently visible, so switching tabs never drops a staged
 * change that isn't the one currently on screen. Everything submits with
 * the rest of this screen's own Update button, applied in
 * TC_Meta_Boxes::save_guide() (save_guide_availability_changes() /
 * save_guide_special_dates_changes()).
 */
( function () {
	'use strict';

	var root = document.getElementById( 'tc-guide-calendar-root' );
	if ( ! root ) {
		return;
	}

	var AVAIL_CFG   = window.tcGuideAvailabilityAdmin;
	var SPECIAL_CFG = window.tcGuideSpecialDatesAdmin || { services: [], locations: [], specialDates: [] };
	var API_ROOT    = AVAIL_CFG.restRoot;
	var NONCE       = AVAIL_CFG.nonce;
	var GUIDE_ID    = AVAIL_CFG.guideId;
	var AVAIL_BASE  = '/admin/guides/' + GUIDE_ID + '/availability';

	var activeTab = 'availability'; // 'availability' | a special (service,location) pair key

	/* ---------------------------------------------------------------- */
	/* Shared helpers                                                    */
	/* ---------------------------------------------------------------- */

	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}
	function escapeAttr( str ) {
		return escapeHtml( str ).replace( /"/g, '&quot;' );
	}

	var SITE_TZ = 'Europe/Amsterdam';

	// This business only operates in the Netherlands, so "today"/"this
	// month" must always mean the Netherlands' calendar day, not the
	// visitor's own device timezone.
	function nlToday() {
		var parts = new Intl.DateTimeFormat( 'en-CA', { timeZone: SITE_TZ, year: 'numeric', month: '2-digit', day: '2-digit' } ).formatToParts( new Date() );
		var y, m, d;
		parts.forEach( function ( p ) {
			if ( 'year' === p.type ) y = parseInt( p.value, 10 );
			if ( 'month' === p.type ) m = parseInt( p.value, 10 );
			if ( 'day' === p.type ) d = parseInt( p.value, 10 );
		} );
		return new Date( y, m - 1, d );
	}
	function isoDate( d ) {
		var y = d.getFullYear();
		var m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		var day = String( d.getDate() ).padStart( 2, '0' );
		return y + '-' + m + '-' + day;
	}
	function monthBounds( offset ) {
		var now   = nlToday();
		var first = new Date( now.getFullYear(), now.getMonth() + offset, 1 );
		var last  = new Date( now.getFullYear(), now.getMonth() + offset + 1, 0 );
		return { first: first, last: last };
	}
	function apiGet( path ) {
		// See the matching comment previously in this file's guide-
		// availability.js half - a caching plugin/CDN was serving a stale
		// cached response for this exact GET despite WordPress's own
		// Cache-Control: no-store, which is what actually caused "it says
		// saved but reverts on refresh."
		var bust = path.indexOf( '?' ) === -1 ? '?' : '&';
		return fetch( API_ROOT + path + bust + '_=' + Date.now(), { headers: { 'X-WP-Nonce': NONCE }, cache: 'no-store' } ).then( handleResponse );
	}
	function handleResponse( res ) {
		return res.json().then( function ( data ) {
			if ( ! res.ok ) {
				throw new Error( ( data && data.message ) || 'Something went wrong.' );
			}
			return data;
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Availability tab                                                  */
	/* ---------------------------------------------------------------- */

	var avail = {
		monthOffset: 0,
		availability: {}, // date -> 'blocked' | 'available', as loaded from the server
		dirty: {}, // date -> 'blocked' | 'available', staged - submitted with the post form's own Update
		bookings: {}, // date -> summary string, for dates with a real booking
		loading: true,
		error: null,
	};

	function loadAvailMonth() {
		avail.loading = true;
		render();
		var bounds = monthBounds( avail.monthOffset );
		apiGet( AVAIL_BASE + '?start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( data ) {
				( data.availability || [] ).forEach( function ( r ) { avail.availability[ r.date ] = r.status; } );
				( data.bookings || [] ).forEach( function ( r ) { avail.bookings[ r.date ] = r.summary; } );
				avail.loading = false;
				render();
			} )
			.catch( function ( err ) {
				avail.error   = err.message;
				avail.loading = false;
				render();
			} );
	}

	// Stages a local change only - nothing is sent to the server here.
	// Toggling a date back to its last-loaded value un-stages it entirely.
	function toggleAvailDate( iso, currentlyBlocked ) {
		var newStatus = currentlyBlocked ? 'available' : 'blocked';
		var saved     = avail.availability[ iso ] || 'available';
		if ( newStatus === saved ) {
			delete avail.dirty[ iso ];
		} else {
			avail.dirty[ iso ] = newStatus;
		}
		render();
	}

	function availHiddenInputs() {
		return Object.keys( avail.dirty ).map( function ( date ) {
			return '<input type="hidden" name="tc_availability[' + escapeAttr( date ) + ']" value="' + escapeAttr( avail.dirty[ date ] ) + '">';
		} ).join( '' );
	}

	function renderAvailPanel() {
		var bounds    = monthBounds( avail.monthOffset );
		var monthName = bounds.first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric' } );
		var firstDow  = ( bounds.first.getDay() + 6 ) % 7; // Monday-first
		var daysInMo  = bounds.last.getDate();
		var today     = nlToday();

		var cells = '';
		for ( var i = 0; i < firstDow; i++ ) {
			cells += '<div class="tc-cal-day past"></div>';
		}
		for ( var d = 1; d <= daysInMo; d++ ) {
			var dateObj = new Date( bounds.first.getFullYear(), bounds.first.getMonth(), d );
			var iso     = isoDate( dateObj );
			var isDirty = Object.prototype.hasOwnProperty.call( avail.dirty, iso );
			var status  = isDirty ? avail.dirty[ iso ] : ( avail.availability[ iso ] || 'available' );
			var booking = avail.bookings[ iso ];
			var isPast  = dateObj < today;
			var cls = isPast ? 'past' : ( booking ? 'booked' : ( 'blocked' === status ? 'blocked' : 'available' ) );
			if ( isDirty ) {
				cls += ' dirty';
			}
			var attrs = ( isPast || booking ) ? '' : ' data-date="' + iso + '" data-blocked="' + ( 'blocked' === status ? '1' : '0' ) + '"';
			if ( booking && ! isPast ) {
				attrs += ' title="' + escapeAttr( booking ) + '"';
			} else if ( isDirty ) {
				attrs += ' title="' + escapeAttr( 'Not saved yet - click Update to save' ) + '"';
			}
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		var dirtyCount = Object.keys( avail.dirty ).length;

		return '<div class="tc-card">' +
			( avail.error ? '<div class="tc-error">' + escapeHtml( avail.error ) + '</div>' : '' ) +
			'<p class="tc-sub">Tap a date to toggle it between available and a day off, on this guide’s behalf - changes save when you click Update below, same as the rest of this screen. Booked dates (hover for details) can’t be changed here.</p>' +
			( dirtyCount ? '<div class="tc-cal-status saving" style="position:static;display:inline-block;">' + escapeHtml( dirtyCount + ( 1 === dirtyCount ? ' change' : ' changes' ) + ' will be saved when you click Update' ) + '</div>' : '' ) +
			'<div class="tc-grid-nav"><button id="tc-admin-prev-month" type="button">←</button><span class="range">' + monthName + '</span><button id="tc-admin-next-month" type="button">→</button></div>' +
			( avail.loading ? '<p>Loading…</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Booked</span>' +
			'</div></div>';
	}

	function wireAvailEvents() {
		var prev = document.getElementById( 'tc-admin-prev-month' );
		if ( prev ) prev.onclick = function () { avail.monthOffset -= 1; loadAvailMonth(); };
		var next = document.getElementById( 'tc-admin-next-month' );
		if ( next ) next.onclick = function () { avail.monthOffset += 1; loadAvailMonth(); };
		root.querySelectorAll( '.tc-cal-tab-panel [data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				toggleAvailDate( el.dataset.date, '1' === el.dataset.blocked );
			};
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Special-service date tabs (GitHub issue #71)                      */
	/* ---------------------------------------------------------------- */

	var SERVICES  = SPECIAL_CFG.services || [];
	var LOCATIONS = SPECIAL_CFG.locations || [];

	function pairKey( serviceId, locationId ) {
		return serviceId + ':' + locationId;
	}

	var initialByPair = {};
	( SPECIAL_CFG.specialDates || [] ).forEach( function ( row ) {
		var k = pairKey( row.service_id, row.location_id );
		if ( ! initialByPair[ k ] ) {
			initialByPair[ k ] = {};
		}
		initialByPair[ k ][ row.date ] = true;
	} );

	// One entry per (service, location) pair ever shown, so toggled state
	// and month position survive a checkbox being unchecked and re-checked.
	var specialWidgets = {};
	function getSpecialWidget( serviceId, locationId ) {
		var k = pairKey( serviceId, locationId );
		if ( ! specialWidgets[ k ] ) {
			specialWidgets[ k ] = {
				serviceId: serviceId,
				locationId: locationId,
				monthOffset: 0,
				dates: Object.assign( {}, initialByPair[ k ] || {} ),
			};
		}
		return specialWidgets[ k ];
	}

	function checkedValues( name ) {
		var out = [];
		document.querySelectorAll( 'input[name="' + name + '"]:checked' ).forEach( function ( el ) {
			out.push( String( el.value ) );
		} );
		return out;
	}

	// The (service, location) pairs that currently deserve a tab - both
	// must be checked in Guide Details' own Locations covered/Services
	// provided lists elsewhere on this page, AND (follow-up to #71) the
	// location must be one this service is actually available at -
	// service.specialLocations empty means no restriction, matching
	// TC_Availability::special_location_allowed() server-side.
	function activeSpecialPairs() {
		var checkedServiceIds  = checkedValues( 'tc_service_ids[]' );
		var checkedLocationIds = checkedValues( 'tc_location_ids[]' );
		var pairs = [];
		SERVICES.filter( function ( s ) { return checkedServiceIds.indexOf( String( s.id ) ) !== -1; } ).forEach( function ( service ) {
			var allowedLocations = ( service.specialLocations && service.specialLocations.length )
				? LOCATIONS.filter( function ( l ) { return service.specialLocations.indexOf( l.id ) !== -1; } )
				: LOCATIONS;
			allowedLocations.filter( function ( l ) { return checkedLocationIds.indexOf( String( l.id ) ) !== -1; } ).forEach( function ( location ) {
				pairs.push( { key: pairKey( service.id, location.id ), service: service, location: location } );
			} );
		} );
		return pairs;
	}

	function specialHiddenInputs( w ) {
		return Object.keys( w.dates ).map( function ( date ) {
			return '<input type="hidden" name="tc_special_dates[' + escapeAttr( w.serviceId ) + '][' + escapeAttr( w.locationId ) + '][]" value="' + escapeAttr( date ) + '">';
		} ).join( '' );
	}

	function renderSpecialPanel( w, service, location ) {
		var bounds    = monthBounds( w.monthOffset );
		var monthName = bounds.first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric' } );
		var firstDow  = ( bounds.first.getDay() + 6 ) % 7; // Monday-first
		var daysInMo  = bounds.last.getDate();
		var today     = nlToday();

		var cells = '';
		for ( var i = 0; i < firstDow; i++ ) {
			cells += '<div class="tc-cal-day past"></div>';
		}
		for ( var d = 1; d <= daysInMo; d++ ) {
			var dateObj = new Date( bounds.first.getFullYear(), bounds.first.getMonth(), d );
			var iso     = isoDate( dateObj );
			var isPast  = dateObj < today;
			// Every future cell here is clickable regardless of state
			// (toggling this special service on/off for that date) - 'off'
			// is reused purely for its neutral gray styling so "not
			// offered" reads clearly as the default, so the cursor is
			// forced back to pointer for it below.
			var offered = Object.prototype.hasOwnProperty.call( w.dates, iso );
			var cls     = isPast ? 'past' : ( offered ? 'dirty available' : 'off' );
			var attrs   = isPast ? '' : ' data-date="' + iso + '" data-key="' + escapeAttr( pairKey( w.serviceId, w.locationId ) ) + '" style="cursor:pointer;"';
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		var offeredCount = Object.keys( w.dates ).length;

		return '<div class="tc-card">' +
			'<p><strong>' + escapeHtml( service.name ) + '</strong> — ' + escapeHtml( location.name ) + '</p>' +
			'<p class="tc-sub">Click a date to mark this special service as offered there - changes save when you click Update below.</p>' +
			( offeredCount ? '<div class="tc-cal-status saving" style="position:static;display:inline-block;">' + escapeHtml( offeredCount + ( 1 === offeredCount ? ' date' : ' dates' ) + ' offered' ) + '</div>' : '' ) +
			'<div class="tc-grid-nav"><button type="button" id="tc-sd-prev">←</button><span class="range">' + monthName + '</span><button type="button" id="tc-sd-next">→</button></div>' +
			'<div class="tc-cal-grid">' +
			[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
			cells + '</div></div>';
	}

	function wireSpecialEvents( w ) {
		var prev = document.getElementById( 'tc-sd-prev' );
		if ( prev ) prev.onclick = function () { w.monthOffset -= 1; render(); };
		var next = document.getElementById( 'tc-sd-next' );
		if ( next ) next.onclick = function () { w.monthOffset += 1; render(); };
		root.querySelectorAll( '.tc-cal-tab-panel [data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				if ( Object.prototype.hasOwnProperty.call( w.dates, el.dataset.date ) ) {
					delete w.dates[ el.dataset.date ];
				} else {
					w.dates[ el.dataset.date ] = true;
				}
				render();
			};
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Tab shell                                                         */
	/* ---------------------------------------------------------------- */

	function render() {
		var pairs = activeSpecialPairs();
		var tabs  = [ { key: 'availability', label: 'Beschikbaarheid' } ].concat(
			pairs.map( function ( p ) { return { key: p.key, label: p.service.name + ' — ' + p.location.name }; } )
		);
		if ( ! tabs.some( function ( t ) { return t.key === activeTab; } ) ) {
			activeTab = 'availability';
		}

		var tabbar = '<div class="tc-cal-tabbar">' + tabs.map( function ( t ) {
			return '<button type="button" class="tc-cal-tab' + ( t.key === activeTab ? ' active' : '' ) + '" data-tab="' + escapeAttr( t.key ) + '">' + escapeHtml( t.label ) + '</button>';
		} ).join( '' ) + '</div>';

		var panel;
		var activePair = pairs.filter( function ( p ) { return p.key === activeTab; } )[ 0 ];
		if ( activePair ) {
			panel = renderSpecialPanel( getSpecialWidget( activePair.service.id, activePair.location.id ), activePair.service, activePair.location );
		} else {
			panel = renderAvailPanel();
		}

		// Hidden inputs for every staged change - both the availability
		// calendar's and every special widget's (whether or not that
		// widget's own tab happens to be the one currently visible), so
		// switching tabs never drops a change made on another one before
		// the form is actually submitted.
		var allSpecialHidden = Object.keys( specialWidgets ).map( function ( k ) {
			return specialHiddenInputs( specialWidgets[ k ] );
		} ).join( '' );

		root.innerHTML = tabbar + '<div class="tc-cal-tab-panel">' + panel + '</div>' + availHiddenInputs() + allSpecialHidden;

		root.querySelectorAll( '.tc-cal-tab' ).forEach( function ( btn ) {
			btn.onclick = function () { activeTab = btn.dataset.tab; render(); };
		} );

		if ( activePair ) {
			wireSpecialEvents( getSpecialWidget( activePair.service.id, activePair.location.id ) );
		} else {
			wireAvailEvents();
		}
	}

	// The Locations covered / Services provided checkboxes live in a
	// different meta box on the same page (render_guide(), not
	// render_guide_calendars()) - both are just sections of the one
	// post-edit <form>, so a document-level listener reaches them fine.
	document.addEventListener( 'change', function ( e ) {
		if ( 'tc_service_ids[]' === e.target.name || 'tc_location_ids[]' === e.target.name ) {
			render();
		}
	} );

	loadAvailMonth();
} )();
