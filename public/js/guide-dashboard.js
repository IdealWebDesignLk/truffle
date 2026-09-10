/**
 * TC Booking - guide self-service calendar.
 *
 * Tabbed - "Beschikbaarheid" (the regular calendar) is always first,
 * followed by one tab per special service/location this guide is set up
 * for (GitHub follow-up to issue #71 - special-service dates used to be
 * wp-admin only; guides can now manage these themselves too), matching
 * admin/js/guide-calendars.js's tabbed layout on the wp-admin side. Unlike
 * that admin screen, there are no live-editable "Locations covered"/
 * "Services provided" checkboxes on this page to react to - a guide's own
 * linked services/locations are admin-configured and fixed for this
 * request, so the special-service tab list is just whatever
 * /guide/special-dates returns.
 *
 * Both halves share the SAME staged-changes model and the SAME explicit
 * Save button: toggling a date (on either tab) only updates local state;
 * nothing is sent to the server until Save is clicked, at which point
 * every staged change across BOTH tabs is submitted together (two REST
 * calls, one per endpoint, only for whichever actually has changes).
 * Each date is validated/applied independently server-side, so one date
 * that became invalid between page load and clicking Save (e.g. an admin
 * booked it in the meantime) doesn't block the rest - see
 * guide_save_availability_bulk()/guide_save_special_dates_bulk() in
 * class-tc-rest-api.php.
 *
 * Availability tab specifics: green = available (the default - no action
 * needed). Clicking a date toggles it to blocked (a day off) and back.
 * Dates with an existing booking are shown (amber, with the
 * ceremony/customer as a tooltip) but not clickable - to change those,
 * the guide needs to contact admin, since cancelling/moving a paid
 * booking has consequences (refunds, notifying the customer) that
 * deliberately stay an admin action. Past dates are already excluded from
 * being clickable and in fact not rendered at all (.tc-cal-day.past is
 * visibility:hidden in booking-app.css).
 */
(function () {
	'use strict';

	var root = document.getElementById( 'tc-guide-dashboard-root' );
	if ( ! root ) {
		return;
	}

	var API_ROOT = window.tcGuideDashboard.restRoot;
	var NONCE    = window.tcGuideDashboard.nonce;

	var activeTab = 'availability'; // 'availability' | a special (service,location) pair key
	var saving  = false; // true while a save request is in flight - locks every tab
	var error   = null;
	var status  = null; // 'saved' | null - small status message near the title
	var savedTimer = null;

	// Live-site debugging turned up the actual cause of "it says saved but
	// reverts on refresh": the save itself was always working (confirmed
	// directly - the write showed up immediately via a request that bypassed
	// caching), but a GET kept being served a stale cached response by
	// something in front of WordPress (a caching plugin or CDN) that ignores
	// the no-store Cache-Control WordPress already sends for these routes -
	// so the calendar reloaded showing old data no matter how many times a
	// change actually saved correctly. `_=Date.now()` makes each request's
	// URL unique, which defeats a cache keyed on the full URL (almost all of
	// them are) regardless of what's ignoring the Cache-Control header;
	// `cache: 'no-store'` is the browser's own equivalent, for whatever a
	// URL-based cache alone wouldn't already catch.
	function apiGet( path ) {
		var bust = path.indexOf( '?' ) === -1 ? '?' : '&';
		return fetch( API_ROOT + path + bust + '_=' + Date.now(), { headers: { 'X-WP-Nonce': NONCE }, cache: 'no-store' } ).then( handleResponse );
	}
	function apiPost( path, body ) {
		return fetch( API_ROOT + path, {
			method: 'POST',
			headers: { 'Content-Type': 'application/json', 'X-WP-Nonce': NONCE },
			body: JSON.stringify( body ),
		} ).then( handleResponse );
	}
	// 403 here almost always means the REST nonce baked into this page at
	// load time (window.tcGuideDashboard.nonce) has gone stale - either the
	// page itself has just been sitting open long enough for WordPress's
	// nonce to rotate past its ~24h window, or a caching plugin/CDN is
	// serving a cached copy of this page with an old nonce baked into it.
	function handleResponse( res ) {
		return res.json().then( function ( data ) {
			if ( ! res.ok ) {
				if ( 403 === res.status ) {
					throw new Error( 'Your session has expired - please reload the page and try again.' );
				}
				throw new Error( ( data && data.message ) || 'Something went wrong.' );
			}
			return data;
		} );
	}

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
	// visitor's own device timezone. Built via Intl so CET/CEST
	// daylight-saving transitions are handled automatically and correctly.
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
		// Not toISOString() - that converts to UTC, which silently shifts the
		// date back a day for any browser ahead of UTC. Build the string from
		// local date parts instead, matching how the Date object was built
		// (nlToday()), so what's saved always matches the Netherlands
		// calendar day being displayed.
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

	/* ---------------------------------------------------------------- */
	/* Availability tab                                                  */
	/* ---------------------------------------------------------------- */

	var avail = {
		monthOffset: 0,
		availability: {}, // date -> 'blocked' | 'available', last known SAVED value from the server
		dirty: {}, // date -> 'blocked' | 'available', staged but not yet saved
		bookings: {}, // date -> summary string, for dates with a real booking
		loading: true,
	};

	function loadAvailMonth() {
		avail.loading = true;
		render();
		var bounds = monthBounds( avail.monthOffset );
		apiGet( '/guide/availability?start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( data ) {
				// Only the fetched range is replaced, not the whole object -
				// avail.availability can hold entries from other months
				// already visited this session, and there's no reason to
				// throw those away just because a different month's data
				// came back.
				( data.availability || [] ).forEach( function ( r ) { avail.availability[ r.date ] = r.status; } );
				( data.bookings || [] ).forEach( function ( r ) { avail.bookings[ r.date ] = r.summary; } );
				avail.loading = false;
				render();
			} )
			.catch( function ( err ) {
				error = err.message;
				avail.loading = false;
				render();
			} );
	}

	// Stages a local change only. Toggling a date already staged just
	// changes what it's staged as; toggling it back to its last-saved
	// value un-stages it entirely (nothing to save for that date after all).
	function toggleAvailDate( iso, currentlyBlocked ) {
		if ( saving ) {
			return;
		}
		var newStatus = currentlyBlocked ? 'available' : 'blocked';
		var saved     = avail.availability[ iso ] || 'available';
		if ( newStatus === saved ) {
			delete avail.dirty[ iso ];
		} else {
			avail.dirty[ iso ] = newStatus;
		}
		render();
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
			var st      = isDirty ? avail.dirty[ iso ] : ( avail.availability[ iso ] || 'available' );
			var booking = avail.bookings[ iso ];
			var isPast  = dateObj < today;
			// A booked date always shows as "booked" and is never
			// clickable, regardless of what the availability table says.
			var cls = isPast ? 'past' : ( booking ? 'booked' : ( 'blocked' === st ? 'blocked' : 'available' ) );
			if ( isDirty ) {
				cls += ' dirty';
			}
			var attrs = ( isPast || booking || saving ) ? '' : ' data-date="' + iso + '" data-blocked="' + ( 'blocked' === st ? '1' : '0' ) + '"';
			if ( booking && ! isPast ) {
				attrs += ' title="' + escapeAttr( booking ) + '"';
			} else if ( isDirty ) {
				attrs += ' title="' + escapeAttr( 'Not saved yet' ) + '"';
			}
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		return '<p class="tc-sub">This is your own calendar - tap a date to mark it as a day off, or tap again to reopen it, then click Save. Everything is available by default. Booked dates (hover for details) can’t be changed here - contact admin if one needs to move.</p>' +
			'<div class="tc-grid-nav"><button id="tc-prev-month">←</button><span class="range">' + monthName + '</span><button id="tc-next-month">→</button></div>' +
			( avail.loading ? '<p>Loading…</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Booked</span>' +
			'</div>';
	}

	function wireAvailEvents() {
		var prev = document.getElementById( 'tc-prev-month' );
		if ( prev ) prev.onclick = function () { avail.monthOffset -= 1; loadAvailMonth(); };
		var next = document.getElementById( 'tc-next-month' );
		if ( next ) next.onclick = function () { avail.monthOffset += 1; loadAvailMonth(); };
		root.querySelectorAll( '.tc-cal-tab-panel [data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				toggleAvailDate( el.dataset.date, '1' === el.dataset.blocked );
			};
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Special-service date tabs                                         */
	/* ---------------------------------------------------------------- */

	var special = {
		loading: true,
		pairs: [], // [{serviceId, serviceName, locationId, locationName}]
		widgets: {}, // pairKey -> { monthOffset, savedDates: {date:true}, dates: {date:true} (staged desired state) }
	};

	function pairKey( serviceId, locationId ) {
		return serviceId + ':' + locationId;
	}

	function loadSpecialPairs() {
		special.loading = true;
		render();
		apiGet( '/guide/special-dates' )
			.then( function ( data ) {
				special.pairs = data.pairs || [];
				var byPair = {};
				( data.specialDates || [] ).forEach( function ( row ) {
					var k = pairKey( row.service_id, row.location_id );
					if ( ! byPair[ k ] ) byPair[ k ] = {};
					byPair[ k ][ row.date ] = true;
				} );
				special.pairs.forEach( function ( p ) {
					var k = pairKey( p.serviceId, p.locationId );
					var saved = byPair[ k ] || {};
					special.widgets[ k ] = { monthOffset: 0, savedDates: saved, dates: Object.assign( {}, saved ) };
				} );
				special.loading = false;
				render();
			} )
			.catch( function ( err ) {
				error = err.message;
				special.loading = false;
				render();
			} );
	}

	// Toggles the desired state only - dirty-ness is derived by comparing
	// w.dates against w.savedDates wherever it matters (rendering, save),
	// not tracked as a separate flag.
	function toggleSpecialDate( w, iso ) {
		if ( saving ) {
			return;
		}
		if ( Object.prototype.hasOwnProperty.call( w.dates, iso ) ) {
			delete w.dates[ iso ];
		} else {
			w.dates[ iso ] = true;
		}
		render();
	}

	function specialWidgetDirtyCount( w ) {
		var count = 0;
		var seen  = {};
		Object.keys( w.dates ).concat( Object.keys( w.savedDates ) ).forEach( function ( iso ) {
			if ( seen[ iso ] ) return;
			seen[ iso ] = true;
			var inDates = Object.prototype.hasOwnProperty.call( w.dates, iso );
			var inSaved = Object.prototype.hasOwnProperty.call( w.savedDates, iso );
			if ( inDates !== inSaved ) count++;
		} );
		return count;
	}

	function renderSpecialPanel( w, pair ) {
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
			var offered = Object.prototype.hasOwnProperty.call( w.dates, iso );
			var wasSaved = Object.prototype.hasOwnProperty.call( w.savedDates, iso );
			var isDirty = offered !== wasSaved;
			// Every future cell is clickable regardless of state (toggling
			// this special service on/off for that date) - 'off' is reused
			// purely for its neutral gray styling so "not offered" reads
			// clearly as the default, so the cursor is forced back to
			// pointer for it below.
			var cls   = isPast ? 'past' : ( offered ? 'available' : 'off' );
			if ( isDirty ) {
				cls += ' dirty';
			}
			var attrs = ( isPast || saving ) ? '' : ' data-date="' + iso + '" style="cursor:pointer;"';
			if ( isDirty ) {
				attrs += ' title="' + escapeAttr( 'Not saved yet' ) + '"';
			}
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		return '<p class="tc-sub">Tap a date to offer <strong>' + escapeHtml( pair.serviceName ) + '</strong> at <strong>' + escapeHtml( pair.locationName ) + '</strong> - tap again to remove it, then click Save. Offering a date closes your regular calendar for that day.</p>' +
			'<div class="tc-grid-nav"><button type="button" id="tc-sd-prev">←</button><span class="range">' + monthName + '</span><button type="button" id="tc-sd-next">→</button></div>' +
			'<div class="tc-cal-grid">' +
			[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
			cells + '</div>' +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Offered</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Not offered</span>' +
			'</div>';
	}

	function wireSpecialEvents( w ) {
		var prev = document.getElementById( 'tc-sd-prev' );
		if ( prev ) prev.onclick = function () { w.monthOffset -= 1; render(); };
		var next = document.getElementById( 'tc-sd-next' );
		if ( next ) next.onclick = function () { w.monthOffset += 1; render(); };
		root.querySelectorAll( '.tc-cal-tab-panel [data-date]' ).forEach( function ( el ) {
			el.onclick = function () { toggleSpecialDate( w, el.dataset.date ); };
		} );
	}

	/* ---------------------------------------------------------------- */
	/* Save (both tabs together)                                         */
	/* ---------------------------------------------------------------- */

	function totalDirtyCount() {
		var count = Object.keys( avail.dirty ).length;
		Object.keys( special.widgets ).forEach( function ( k ) {
			count += specialWidgetDirtyCount( special.widgets[ k ] );
		} );
		return count;
	}

	// Submits every staged change across both tabs together. Each date is
	// validated/applied independently server-side, so one date that became
	// invalid between page load and clicking Save (e.g. an admin booked it
	// in the meantime) doesn't block the rest - only that date's change
	// reverts, with the reason shown, while everything else that succeeded
	// commits.
	function saveChanges() {
		if ( saving || ! totalDirtyCount() ) {
			return;
		}
		saving = true;
		error  = null;
		status = null;
		clearTimeout( savedTimer );
		render();

		var requests  = [];
		var failures  = [];

		var availDates = Object.keys( avail.dirty );
		if ( availDates.length ) {
			var availChanges = availDates.map( function ( date ) { return { date: date, status: avail.dirty[ date ] }; } );
			requests.push(
				apiPost( '/guide/availability/bulk', { changes: availChanges } ).then( function ( data ) {
					( data.results || [] ).forEach( function ( r ) {
						if ( r.success ) {
							avail.availability[ r.date ] = avail.dirty[ r.date ];
						} else {
							failures.push( r.message || r.date );
						}
						delete avail.dirty[ r.date ];
					} );
				} )
			);
		}

		var specialChanges = [];
		Object.keys( special.widgets ).forEach( function ( k ) {
			var w = special.widgets[ k ];
			var seen = {};
			Object.keys( w.dates ).concat( Object.keys( w.savedDates ) ).forEach( function ( iso ) {
				if ( seen[ iso ] ) return;
				seen[ iso ] = true;
				var inDates = Object.prototype.hasOwnProperty.call( w.dates, iso );
				var inSaved = Object.prototype.hasOwnProperty.call( w.savedDates, iso );
				if ( inDates !== inSaved ) {
					specialChanges.push( { serviceId: w.serviceId, locationId: w.locationId, date: iso, offered: inDates } );
				}
			} );
		} );
		if ( specialChanges.length ) {
			requests.push(
				apiPost( '/guide/special-dates/bulk', { changes: specialChanges } ).then( function ( data ) {
					( data.results || [] ).forEach( function ( r ) {
						var w = special.widgets[ pairKey( r.serviceId, r.locationId ) ];
						if ( ! w ) return;
						if ( r.success ) {
							if ( r.offered ) {
								w.savedDates[ r.date ] = true;
							} else {
								delete w.savedDates[ r.date ];
							}
						} else {
							failures.push( r.message || r.date );
						}
						// Either way, the staged value now matches
						// whatever's actually saved - success confirms it,
						// failure reverts to it.
						if ( Object.prototype.hasOwnProperty.call( w.savedDates, r.date ) ) {
							w.dates[ r.date ] = true;
						} else {
							delete w.dates[ r.date ];
						}
					} );
				} )
			);
		}

		Promise.all( requests )
			.then( function () {
				saving = false;
				if ( failures.length ) {
					error = failures.join( ' ' );
				} else {
					status = 'saved';
					savedTimer = setTimeout( function () {
						status = null;
						render();
					}, 2000 );
				}
				render();
			} )
			.catch( function ( err ) {
				// A total failure (network error, expired session, etc.) -
				// every staged change stays staged so nothing already
				// toggled is lost; the guide can just try Save again.
				saving = false;
				error  = err.message;
				render();
			} );
	}

	/* ---------------------------------------------------------------- */
	/* Tab shell                                                         */
	/* ---------------------------------------------------------------- */

	// pairs need their widget's serviceId/locationId available to
	// saveChanges() above without re-deriving them from the tab key.
	function ensureWidgetIds() {
		special.pairs.forEach( function ( p ) {
			var w = special.widgets[ pairKey( p.serviceId, p.locationId ) ];
			if ( w ) {
				w.serviceId  = p.serviceId;
				w.locationId = p.locationId;
			}
		} );
	}

	function render() {
		ensureWidgetIds();

		var tabs = [ { key: 'availability', label: 'Beschikbaarheid' } ].concat(
			special.pairs.map( function ( p ) { return { key: pairKey( p.serviceId, p.locationId ), label: p.serviceName + ' — ' + p.locationName, pair: p }; } )
		);
		if ( ! tabs.some( function ( t ) { return t.key === activeTab; } ) ) {
			activeTab = 'availability';
		}

		var tabbar = tabs.length > 1
			? '<div class="tc-cal-tabbar">' + tabs.map( function ( t ) {
				return '<button type="button" class="tc-cal-tab' + ( t.key === activeTab ? ' active' : '' ) + '" data-tab="' + escapeAttr( t.key ) + '">' + escapeHtml( t.label ) + '</button>';
			} ).join( '' ) + '</div>'
			: '';

		var activeTabDef = tabs.filter( function ( t ) { return t.key === activeTab; } )[ 0 ];
		var panel;
		if ( activeTabDef && activeTabDef.pair ) {
			panel = special.loading ? '<p>Loading…</p>' : renderSpecialPanel( special.widgets[ activeTabDef.key ], activeTabDef.pair );
		} else {
			panel = renderAvailPanel();
		}

		var dirty = totalDirtyCount();
		var statusHtml = '';
		if ( saving ) {
			statusHtml = '<div class="tc-cal-status saving" aria-live="polite">Saving…</div>';
		} else if ( 'saved' === status ) {
			statusHtml = '<div class="tc-cal-status saved" aria-live="polite">✓ Saved</div>';
		}

		root.innerHTML = '<div class="tc-card">' +
			( error ? '<div class="tc-error">' + escapeHtml( error ) + '</div>' : '' ) +
			'<h2 class="tc-title">Your availability</h2>' +
			statusHtml +
			tabbar +
			'<div class="tc-cal-tab-panel">' + panel + '</div>' +
			'<div class="tc-nav"><span>' + ( dirty ? escapeHtml( dirty + ( 1 === dirty ? ' change' : ' changes' ) + ' not saved yet' ) : '' ) + '</span>' +
			'<button class="tc-btn primary" id="tc-save-availability"' + ( dirty && ! saving ? '' : ' disabled' ) + '>' + ( saving ? 'Saving…' : 'Save' ) + '</button></div>' +
			'</div>';

		root.querySelectorAll( '.tc-cal-tab' ).forEach( function ( btn ) {
			btn.onclick = function () { activeTab = btn.dataset.tab; render(); };
		} );

		var save = document.getElementById( 'tc-save-availability' );
		if ( save ) save.onclick = saveChanges;

		if ( activeTabDef && activeTabDef.pair ) {
			if ( special.widgets[ activeTabDef.key ] ) {
				wireSpecialEvents( special.widgets[ activeTabDef.key ] );
			}
		} else {
			wireAvailEvents();
		}
	}

	// Confirms before leaving the page with unsaved changes - easy to lose
	// a few toggled days by navigating away without noticing there's no
	// longer an auto-save doing that for you.
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( totalDirtyCount() > 0 ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	loadAvailMonth();
	loadSpecialPairs();
})();
