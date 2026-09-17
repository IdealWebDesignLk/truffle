/**
 * TC Booking admin - Guide edit screen's "Calendar" meta box.
 *
 * Replaces what used to be two separate scripts/meta boxes (Availability
 * Calendar, Special Service Dates - GitHub issues #67/#68/#71) with one
 * tabbed widget: stacking every calendar open on the page at once got
 * unwieldy the moment a guide had more than one special service/location
 * combination, each with its own full month grid.
 *
 * GitHub follow-up - regular availability is now scoped per location too,
 * same as special-service dates already were ("if he provide normal
 * service for 2 location we need 2 normal calenders for that two
 * locations. no matter how many normal services he provides we just need
 * it only by location") - so this is now one "Beschikbaarheid" tab PER
 * location the guide covers, not a single shared one. Tab order: each
 * location's own regular tab first (in the order Locations covered lists
 * them), then one tab per (special service, location) pair - all of them
 * appearing/disappearing live as the Locations covered / Services
 * provided checkboxes (render_guide()'s own meta box, elsewhere on this
 * same page) change, with no page reload.
 *
 * Every tab keeps the same staged-changes model (no AJAX, no separate
 * save action) - toggling a date only updates local state; hidden inputs
 * for every tab's dirty dates are always rendered into the DOM regardless
 * of which tab is currently visible, so switching tabs never drops a
 * staged change that isn't the one currently on screen. Everything
 * submits with the rest of this screen's own Update button, applied in
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

	// GitHub follow-up - "close all dates after 2027 jan 01, if guide
	// wants they can enable it." Regular availability defaults to
	// 'available' up to and including this date, then to 'blocked' past
	// it, unless a saved row says otherwise - see defaultAvailStatus()
	// and the matching server-side rule in
	// TC_Availability::guide_available_on(). Empty/unset disables this
	// entirely (every date defaults 'available', the original behavior).
	var CUTOFF       = AVAIL_CFG.bookingHorizonCutoff || '';
	var CUTOFF_LABEL = CUTOFF ? new Date( CUTOFF + 'T00:00:00' ).toLocaleDateString( 'en-GB', { day: 'numeric', month: 'long', year: 'numeric' } ) : '';
	function defaultAvailStatus( iso ) {
		return ( CUTOFF && iso > CUTOFF ) ? 'blocked' : 'available';
	}

	var activeTab = null; // set once the first render() computes the real default tab key

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
	function checkedValues( name ) {
		var out = [];
		document.querySelectorAll( 'input[name="' + name + '"]:checked' ).forEach( function ( el ) {
			out.push( String( el.value ) );
		} );
		return out;
	}

	var LOCATIONS = SPECIAL_CFG.locations || [];

	function avKey( locationId ) {
		return 'avail:' + locationId;
	}

	/* ---------------------------------------------------------------- */
	/* Availability tabs - one per location                              */
	/* ---------------------------------------------------------------- */

	// One entry per location ever shown, so toggled state and month
	// position survive a checkbox being unchecked and re-checked - same
	// idea as specialWidgets below.
	var availWidgets = {};
	function getAvailWidget( locationId ) {
		if ( ! availWidgets[ locationId ] ) {
			availWidgets[ locationId ] = {
				locationId: locationId,
				monthOffset: 0,
				availability: {}, // date -> 'blocked' | 'available', as loaded from the server
				dirty: {}, // date -> 'blocked' | 'available', staged - submitted with the post form's own Update
				bookings: {}, // date -> summary string, for dates with a real booking
				loading: true,
				error: null,
			};
			loadAvailMonth( availWidgets[ locationId ] );
		}
		return availWidgets[ locationId ];
	}

	function loadAvailMonth( w ) {
		w.loading = true;
		render();
		var bounds = monthBounds( w.monthOffset );
		apiGet( AVAIL_BASE + '?location_id=' + w.locationId + '&start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( data ) {
				( data.availability || [] ).forEach( function ( r ) { w.availability[ r.date ] = r.status; } );
				( data.bookings || [] ).forEach( function ( r ) { w.bookings[ r.date ] = r.summary; } );
				w.loading = false;
				render();
			} )
			.catch( function ( err ) {
				w.error   = err.message;
				w.loading = false;
				render();
			} );
	}

	// Stages a local change only - nothing is sent to the server here.
	// Toggling a date back to its last-loaded value un-stages it entirely.
	function toggleAvailDate( w, iso, currentlyBlocked ) {
		var newStatus = currentlyBlocked ? 'available' : 'blocked';
		var saved     = w.availability[ iso ] || defaultAvailStatus( iso );
		if ( newStatus === saved ) {
			delete w.dirty[ iso ];
		} else {
			w.dirty[ iso ] = newStatus;
		}
		render();
	}

	function availHiddenInputs( w ) {
		return Object.keys( w.dirty ).map( function ( date ) {
			return '<input type="hidden" name="tc_availability[' + escapeAttr( w.locationId ) + '][' + escapeAttr( date ) + ']" value="' + escapeAttr( w.dirty[ date ] ) + '">';
		} ).join( '' );
	}

	function renderAvailPanel( w, location ) {
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
			var isDirty = Object.prototype.hasOwnProperty.call( w.dirty, iso );
			var status  = isDirty ? w.dirty[ iso ] : ( w.availability[ iso ] || defaultAvailStatus( iso ) );
			var booking = w.bookings[ iso ];
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
			} else if ( ! w.availability[ iso ] && CUTOFF && iso > CUTOFF ) {
				attrs += ' title="' + escapeAttr( 'Not open for booking yet - click to enable this date' ) + '"';
			}
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		var dirtyCount = Object.keys( w.dirty ).length;

		return '<div class="tc-card">' +
			( w.error ? '<div class="tc-error">' + escapeHtml( w.error ) + '</div>' : '' ) +
			'<p><strong>' + escapeHtml( location.name ) + '</strong></p>' +
			'<p class="tc-sub">Tap a date to toggle it between available and a day off, on this guide’s behalf - changes save when you click Update below, same as the rest of this screen. Booked dates (hover for details) can’t be changed here.' +
				( CUTOFF ? ' Dates from ' + escapeHtml( CUTOFF_LABEL ) + ' onward are closed by default until this guide opens them individually here.' : '' ) +
			'</p>' +
			( dirtyCount ? '<div class="tc-cal-status saving" style="position:static;display:inline-block;">' + escapeHtml( dirtyCount + ( 1 === dirtyCount ? ' change' : ' changes' ) + ' will be saved when you click Update' ) + '</div>' : '' ) +
			'<div class="tc-grid-nav"><button id="tc-admin-prev-month" type="button">←</button><span class="range">' + monthName + '</span><button id="tc-admin-next-month" type="button">→</button></div>' +
			( w.loading ? '<p>Loading…</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Booked</span>' +
			'</div></div>';
	}

	function wireAvailEvents( w ) {
		var prev = document.getElementById( 'tc-admin-prev-month' );
		if ( prev ) prev.onclick = function () { w.monthOffset -= 1; loadAvailMonth( w ); };
		var next = document.getElementById( 'tc-admin-next-month' );
		if ( next ) next.onclick = function () { w.monthOffset += 1; loadAvailMonth( w ); };
		root.querySelectorAll( '.tc-cal-tab-panel [data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				toggleAvailDate( w, el.dataset.date, '1' === el.dataset.blocked );
			};
		} );
	}

	// The locations that currently deserve a regular-availability tab -
	// every location checked in Guide Details' own Locations covered list,
	// regardless of how many (or which) normal services the guide offers -
	// "no matter how many normal services he provides we just need it
	// only by location."
	function activeAvailabilityLocations() {
		var checkedLocationIds = checkedValues( 'tc_location_ids[]' );
		return LOCATIONS.filter( function ( l ) { return checkedLocationIds.indexOf( String( l.id ) ) !== -1; } );
	}

	/* ---------------------------------------------------------------- */
	/* Special-service date tabs (GitHub issue #71)                      */
	/* ---------------------------------------------------------------- */

	var SERVICES = SPECIAL_CFG.services || [];

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
		var availLocations = activeAvailabilityLocations();
		var pairs          = activeSpecialPairs();
		var tabs = availLocations.map( function ( l ) {
			return { key: avKey( l.id ), label: 'Beschikbaarheid — ' + l.name, location: l };
		} ).concat(
			pairs.map( function ( p ) { return { key: p.key, label: p.service.name + ' — ' + p.location.name, pair: p }; } )
		);
		if ( ! activeTab || ! tabs.some( function ( t ) { return t.key === activeTab; } ) ) {
			activeTab = tabs.length ? tabs[ 0 ].key : null;
		}

		var tabbar = tabs.length
			? '<div class="tc-cal-tabbar">' + tabs.map( function ( t ) {
				return '<button type="button" class="tc-cal-tab' + ( t.key === activeTab ? ' active' : '' ) + '" data-tab="' + escapeAttr( t.key ) + '">' + escapeHtml( t.label ) + '</button>';
			} ).join( '' ) + '</div>'
			: '';

		var activeTabDef = tabs.filter( function ( t ) { return t.key === activeTab; } )[ 0 ];
		var panel;
		if ( activeTabDef && activeTabDef.pair ) {
			panel = renderSpecialPanel( getSpecialWidget( activeTabDef.pair.service.id, activeTabDef.pair.location.id ), activeTabDef.pair.service, activeTabDef.pair.location );
		} else if ( activeTabDef && activeTabDef.location ) {
			panel = renderAvailPanel( getAvailWidget( activeTabDef.location.id ), activeTabDef.location );
		} else {
			panel = '<p>' + escapeHtml( 'Check at least one location above to manage this guide’s calendar.' ) + '</p>';
		}

		// Hidden inputs for every staged change across every tab (whether
		// or not that tab happens to be the one currently visible), so
		// switching tabs never drops a change made on another one before
		// the form is actually submitted.
		var allAvailHidden = Object.keys( availWidgets ).map( function ( k ) {
			return availHiddenInputs( availWidgets[ k ] );
		} ).join( '' );
		var allSpecialHidden = Object.keys( specialWidgets ).map( function ( k ) {
			return specialHiddenInputs( specialWidgets[ k ] );
		} ).join( '' );

		root.innerHTML = tabbar + '<div class="tc-cal-tab-panel">' + panel + '</div>' + allAvailHidden + allSpecialHidden;

		root.querySelectorAll( '.tc-cal-tab' ).forEach( function ( btn ) {
			btn.onclick = function () { activeTab = btn.dataset.tab; render(); };
		} );

		if ( activeTabDef && activeTabDef.pair ) {
			wireSpecialEvents( getSpecialWidget( activeTabDef.pair.service.id, activeTabDef.pair.location.id ) );
		} else if ( activeTabDef && activeTabDef.location ) {
			wireAvailEvents( getAvailWidget( activeTabDef.location.id ) );
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

	render();
} )();
