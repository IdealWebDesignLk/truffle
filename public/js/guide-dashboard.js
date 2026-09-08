/**
 * TC Booking - guide self-service calendar.
 *
 * A guide sees a month at a time. Green = available (the default - no
 * action needed). Clicking a date toggles it to blocked (a day off) and
 * back. Dates with an existing booking are shown (amber, with the
 * ceremony/customer as a tooltip) but not clickable - to change those,
 * the guide needs to contact admin, since cancelling/moving a paid
 * booking has consequences (refunds, notifying the customer) that
 * deliberately stay an admin action rather than a guide self-service one.
 * Also enforced server-side (guide_save_availability_bulk() in
 * class-tc-rest-api.php refuses to mark an already-booked date as a day
 * off) - this client-side omission of data-date is only the UI half.
 *
 * Past dates are already excluded from being clickable (isPast below),
 * and in fact not rendered at all (.tc-cal-day.past is visibility:hidden
 * in booking-app.css) - a guide can only ever set availability for today
 * or a future date.
 *
 * GitHub feedback - this used to auto-save one date per tap via AJAX.
 * Toggling a date now only stages a LOCAL change (state.dirty); nothing
 * is sent to the server until the guide clicks the explicit Save button,
 * which submits every staged change together via /guide/availability/bulk.
 * state.dirty persists across month navigation (loadMonth() only ever
 * refreshes state.availability, never touches state.dirty), so a change
 * staged in one month survives browsing to another and back before Save
 * is clicked - see render()'s status-resolution line.
 */
(function () {
	'use strict';

	var root = document.getElementById( 'tc-guide-dashboard-root' );
	if ( ! root ) {
		return;
	}

	var API_ROOT = window.tcGuideDashboard.restRoot;
	var NONCE    = window.tcGuideDashboard.nonce;

	var state = {
		monthOffset: 0,
		availability: {}, // date -> 'blocked' | 'available', last known SAVED value from the server
		dirty: {}, // date -> 'blocked' | 'available', staged but not yet saved
		bookings: {}, // date -> summary string, for dates with a real booking
		loading: true,
		saving: false, // true while the bulk save request is in flight - locks the whole grid
		error: null,
		status: null, // 'saved' | null - small status message near the title
	};
	var savedTimer = null;

	function apiGet( path ) {
		return fetch( API_ROOT + path, { headers: { 'X-WP-Nonce': NONCE } } ).then( handleResponse );
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
	// Surfaced as its own message rather than the generic fallback so it's
	// obvious what to actually do about it.
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

	function dirtyCount() {
		return Object.keys( state.dirty ).length;
	}

	function loadMonth() {
		state.loading = true;
		render();
		var bounds = monthBounds( state.monthOffset );
		apiGet( '/guide/availability?start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( data ) {
				// Only the fetched range is replaced, not the whole object -
				// state.availability can hold entries from other months
				// already visited this session, and there's no reason to
				// throw those away just because a different month's data
				// came back.
				( data.availability || [] ).forEach( function ( r ) { state.availability[ r.date ] = r.status; } );
				( data.bookings || [] ).forEach( function ( r ) { state.bookings[ r.date ] = r.summary; } );
				state.loading = false;
				render();
			} )
			.catch( function ( err ) {
				state.error   = err.message;
				state.loading = false;
				render();
			} );
	}

	// Stages a local change only - see this file's top-of-file comment.
	// Toggling a date already staged (dirty) just changes what it's staged
	// as; toggling it back to its last-saved value un-stages it entirely
	// (nothing to save for that date after all).
	function toggleDate( iso, currentlyBlocked ) {
		if ( state.saving ) {
			return;
		}
		var newStatus = currentlyBlocked ? 'available' : 'blocked';
		var saved     = state.availability[ iso ] || 'available';
		if ( newStatus === saved ) {
			delete state.dirty[ iso ];
		} else {
			state.dirty[ iso ] = newStatus;
		}
		render();
	}

	// Submits every staged change together. Each date is validated/applied
	// independently server-side, so one date that became booked between
	// page load and clicking Save (e.g. an admin booked it in the
	// meantime) doesn't block the rest - only that date's change reverts,
	// with the reason shown, while everything else that succeeded commits.
	function saveChanges() {
		var dates = Object.keys( state.dirty );
		if ( ! dates.length || state.saving ) {
			return;
		}
		var changes = dates.map( function ( date ) {
			return { date: date, status: state.dirty[ date ] };
		} );
		state.saving = true;
		state.error  = null;
		state.status = null;
		clearTimeout( savedTimer );
		render();

		apiPost( '/guide/availability/bulk', { changes: changes } )
			.then( function ( data ) {
				var failures = [];
				( data.results || [] ).forEach( function ( r ) {
					if ( r.success ) {
						state.availability[ r.date ] = state.dirty[ r.date ];
						delete state.dirty[ r.date ];
					} else {
						delete state.dirty[ r.date ]; // revert to last-saved value
						failures.push( r.message || r.date );
					}
				} );
				state.saving = false;
				if ( failures.length ) {
					state.error = failures.join( ' ' );
				} else {
					state.status = 'saved';
					savedTimer = setTimeout( function () {
						state.status = null;
						render();
					}, 2000 );
				}
				render();
			} )
			.catch( function ( err ) {
				// A total failure (network error, expired session, etc.) -
				// every staged change stays staged so nothing already typed
				// in is lost; the guide can just try Save again.
				state.saving = false;
				state.error  = err.message;
				render();
			} );
	}

	function render() {
		var bounds    = monthBounds( state.monthOffset );
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
			var isDirty = Object.prototype.hasOwnProperty.call( state.dirty, iso );
			var status  = isDirty ? state.dirty[ iso ] : ( state.availability[ iso ] || 'available' );
			var booking = state.bookings[ iso ];
			var isPast  = dateObj < today;
			// A booked date always shows as "booked" and is never
			// clickable, regardless of what the availability table says -
			// see the top-of-file comment.
			var cls = isPast ? 'past' : ( booking ? 'booked' : ( 'blocked' === status ? 'blocked' : 'available' ) );
			if ( isDirty ) {
				cls += ' dirty';
			}
			var attrs = ( isPast || booking || state.saving ) ? '' : ' data-date="' + iso + '" data-blocked="' + ( 'blocked' === status ? '1' : '0' ) + '"';
			if ( booking && ! isPast ) {
				attrs += ' title="' + escapeAttr( booking ) + '"';
			} else if ( isDirty ) {
				attrs += ' title="' + escapeAttr( 'Not saved yet' ) + '"';
			}
			cells += '<div class="tc-cal-day ' + cls + '"' + attrs + '>' + d + '</div>';
		}

		var dirty      = dirtyCount();
		var statusHtml = '';
		if ( state.saving ) {
			statusHtml = '<div class="tc-cal-status saving" aria-live="polite">Saving…</div>';
		} else if ( 'saved' === state.status ) {
			statusHtml = '<div class="tc-cal-status saved" aria-live="polite">✓ Saved</div>';
		}

		root.innerHTML = '<div class="tc-card">' +
			( state.error ? '<div class="tc-error">' + escapeHtml( state.error ) + '</div>' : '' ) +
			'<h2 class="tc-title">Your availability</h2>' +
			'<p class="tc-sub">This is your own calendar - tap a date to mark it as a day off, or tap again to reopen it, then click Save. Everything is available by default. Booked dates (hover for details) can’t be changed here - contact admin if one needs to move.</p>' +
			statusHtml +
			'<div class="tc-grid-nav"><button id="tc-prev-month">←</button><span class="range">' + monthName + '</span><button id="tc-next-month">→</button></div>' +
			( state.loading ? '<p>Loading…</p>' : '<div class="tc-cal-grid">' +
				[ 'M', 'T', 'W', 'T', 'F', 'S', 'S' ].map( function ( l ) { return '<div class="tc-cal-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend" style="margin-top:16px;">' +
			'<span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Day off</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Booked</span>' +
			'</div>' +
			'<div class="tc-nav"><span>' + ( dirty ? escapeHtml( dirty + ( 1 === dirty ? ' change' : ' changes' ) + ' not saved yet' ) : '' ) + '</span>' +
			'<button class="tc-btn primary" id="tc-save-availability"' + ( dirty && ! state.saving ? '' : ' disabled' ) + '>' + ( state.saving ? 'Saving…' : 'Save' ) + '</button></div>' +
			'</div>';

		var prev = document.getElementById( 'tc-prev-month' );
		if ( prev ) prev.onclick = function () { state.monthOffset -= 1; loadMonth(); };
		var next = document.getElementById( 'tc-next-month' );
		if ( next ) next.onclick = function () { state.monthOffset += 1; loadMonth(); };

		var save = document.getElementById( 'tc-save-availability' );
		if ( save ) save.onclick = saveChanges;

		root.querySelectorAll( '[data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				toggleDate( el.dataset.date, '1' === el.dataset.blocked );
			};
		} );
	}

	// Confirms before leaving the page with unsaved changes - easy to lose
	// a few toggled days by navigating away without noticing there's no
	// longer an auto-save doing that for you.
	window.addEventListener( 'beforeunload', function ( e ) {
		if ( dirtyCount() > 0 ) {
			e.preventDefault();
			e.returnValue = '';
		}
	} );

	loadMonth();
})();
