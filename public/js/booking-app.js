/**
 * TC Booking - customer-facing app.
 *
 * Flow: Location (+map, guide preview) -> Availability grid -> Extras ->
 * Details -> Review -> checkout (redirect to WooCommerce). No time-of-day
 * picker - booking is by date only, per final scope.
 *
 * No build step by design, for now - this is meant to be dropped straight
 * into VS Code and iterated on directly; a bundler/framework can be added
 * later without changing the REST contract it talks to.
 */
(function () {
	'use strict';

	var root = document.getElementById( 'tc-booking-root' );
	if ( ! root ) {
		return;
	}

	var API_ROOT = window.tcBooking.restRoot;
	var NONCE    = window.tcBooking.nonce;

	// Step list is dynamic, not fixed - "party" (group size) and "guests"
	// (their contact details) only appear for services with "bring anyone
	// with you" enabled (see GitHub issue #6), and "guests" only once the
	// customer has actually said they're bringing someone. getActiveSteps()
	// below computes the real sequence for the current state.
	var state = {
		step: 'location',
		locations: [],
		services: [],
		guidesByLocation: {},
		locationId: null,
		guide: null,
		serviceId: null,
		date: null,
		weekOffset: 0,
		grid: [],
		gridLoading: false,
		extraQty: {},
		partySize: 1,
		guests: [],
		info: { firstName: '', lastName: '', email: '', phone: '' },
		submitting: false,
		error: null,
	};

	function getActiveSteps() {
		var svc   = getService( state.serviceId );
		var steps = [ 'location', 'availability' ];
		if ( svc && svc.allow_party ) {
			steps.push( 'party' );
		}
		steps.push( 'extras' );
		if ( svc && svc.allow_party && state.partySize > 1 ) {
			steps.push( 'guests' );
		}
		steps.push( 'details', 'review' );
		return steps;
	}

	function stepLabel( key ) {
		var steps = getActiveSteps();
		var idx   = steps.indexOf( key );
		return 'Step ' + ( idx + 1 ) + ' of ' + steps.length;
	}

	function goNext() {
		var steps = getActiveSteps();
		var idx   = steps.indexOf( state.step );
		if ( idx > -1 && idx < steps.length - 1 ) {
			setStep( steps[ idx + 1 ] );
		}
	}

	function goBack() {
		var steps = getActiveSteps();
		var idx   = steps.indexOf( state.step );
		if ( idx > 0 ) {
			setStep( steps[ idx - 1 ] );
		}
	}

	/* ------------------------------------------------------------------ */
	/* API helpers                                                          */
	/* ------------------------------------------------------------------ */

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

	function handleResponse( res ) {
		return res.json().then( function ( data ) {
			if ( ! res.ok ) {
				var message = ( data && data.message ) ? data.message : 'Something went wrong. Please try again.';
				throw new Error( message );
			}
			return data;
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Netherlands map projection (Mercator, matched to the pre-rendered
	 * outline path below - see PROJECT_NOTES.md for how these constants
	 * were derived from Natural Earth boundary data). */
	/* ------------------------------------------------------------------ */

	var MERC_SCALE     = 4287.7610144274795;
	var MERC_TRANSLATE = [ -234.6866230121613, 4792.798657991405 ];

	function project( lon, lat ) {
		var lambda = ( lon * Math.PI ) / 180;
		var phi    = ( lat * Math.PI ) / 180;
		var x      = MERC_TRANSLATE[ 0 ] + MERC_SCALE * lambda;
		var y      = MERC_TRANSLATE[ 1 ] - MERC_SCALE * Math.log( Math.tan( Math.PI / 4 + phi / 2 ) );
		return { x: x, y: y };
	}

	var NL_OUTLINE = 'M303.731,67.89L303.731,94.222L295.109,116.45L290.529,144.628L271.94,142.962L264.666,155.027L269.785,166.028L287.297,166.235L291.068,193.33L264.935,217.21L274.904,223.779L270.054,232.389L242.844,242.212L223.716,233.822L210.514,242.825L209.706,253.854L221.83,264.454L228.834,280.72L228.834,300.379L219.136,313.917L224.254,325.005L204.857,345.71L214.556,344.707L219.136,357.936L214.017,371.134L191.117,369.935L185.998,359.137L193.542,352.127L201.624,322.991L180.341,315.329L174.683,307.253L155.555,310.687L141.545,284.576L132.655,294.712L119.454,283.562L111.91,291.673L100.864,284.779L95.476,298.963L61.53,287.011L51.832,294.914L31.356,287.416L23.543,278.283L31.626,270.356L51.832,268.321L74.193,288.835L82.544,279.502L63.686,270.966L77.964,259.36L63.147,244.461L70.421,240.371L65.302,223.779L75.27,220.7L95.207,194.155L109.216,159.388L120.531,102.411L132.924,110.169L167.948,86.231L173.066,70.845L185.19,60.071L212.939,47.579L240.15,46.943L268.977,40.791L281.639,54.569ZM16,296.736L30.279,291.876L53.987,300.379L63.416,292.483L81.197,297.546L73.654,309.071L50.215,317.145L35.398,306.849L19.233,312.1ZM67.457,259.36L61.8,266.897L41.325,254.67L61.8,253.038ZM131.577,84.547L124.303,98.423L119.454,87.914L128.614,75.065Z';

	/* ------------------------------------------------------------------ */
	/* Derived helpers                                                      */
	/* ------------------------------------------------------------------ */

	function getService( id ) {
		var found = null;
		state.services.forEach( function ( s ) {
			if ( s.id === id ) found = s;
		} );
		return found;
	}
	function getLocation( id ) {
		var found = null;
		state.locations.forEach( function ( l ) {
			if ( l.id === id ) found = l;
		} );
		return found;
	}
	function fmt( n ) {
		return '\u20ac' + Number( n ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}
	function extrasTotal() {
		var service = getService( state.serviceId );
		if ( ! service ) return 0;
		var total = 0;
		service.extras.forEach( function ( e ) {
			total += ( state.extraQty[ e.key ] || 0 ) * e.price;
		} );
		return total;
	}
	function partyMultiplier() {
		var service = getService( state.serviceId );
		return ( service && service.allow_party ) ? Math.max( 1, state.partySize ) : 1;
	}
	function grandTotal() {
		var service = getService( state.serviceId );
		return ( service ? service.price * partyMultiplier() : 0 ) + extrasTotal();
	}
	function addDays( d, n ) {
		var r = new Date( d );
		r.setDate( r.getDate() + n );
		return r;
	}
	function isoDate( d ) {
		return d.toISOString().slice( 0, 10 );
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                               */
	/* ------------------------------------------------------------------ */

	function setStep( key ) {
		state.step = key;
		render();
		if ( 'availability' === key ) {
			loadGrid();
		}
		window.scrollTo( { top: root.offsetTop - 20, behavior: 'smooth' } );
	}

	function render() {
		var activeSteps = getActiveSteps();
		var currentIdx  = activeSteps.indexOf( state.step );
		var progress    = activeSteps.map( function ( _, i ) {
			return '<div class="seg' + ( i <= currentIdx ? ' done' : '' ) + '"></div>';
		} ).join( '' );

		var body = '';
		switch ( state.step ) {
			case 'location': body = renderLocation(); break;
			case 'availability': body = renderGrid(); break;
			case 'party': body = renderParty(); break;
			case 'extras': body = renderExtras(); break;
			case 'guests': body = renderGuests(); break;
			case 'details': body = renderInfo(); break;
			case 'review': body = renderReview(); break;
		}

		root.innerHTML = '<div class="tc-progress">' + progress + '</div><div class="tc-card">' +
			( state.error ? '<div class="tc-error">' + escapeHtml( state.error ) + '</div>' : '' ) +
			body + '</div>';

		attachHandlers();
	}

	function renderLocation() {
		var loc = getLocation( state.locationId );
		var pins = state.locations.map( function ( l ) {
			if ( ! l.lat || ! l.lng ) return '';
			var p        = project( l.lng, l.lat );
			var selected = state.locationId === l.id;
			return '<g class="tc-pin-group' + ( selected ? ' selected' : '' ) + '" data-loc="' + l.id + '">' +
				'<circle class="tc-pin" cx="' + p.x + '" cy="' + p.y + '" r="' + ( selected ? 9 : 6.5 ) + '"></circle>' +
				'<text class="tc-pin-label" x="' + ( p.x + 11 ) + '" y="' + p.y + '" dominant-baseline="middle">' + escapeHtml( l.name.split( ' (' )[ 0 ] ) + '</text>' +
				'</g>';
		} ).join( '' );

		var list = state.locations.map( function ( l ) {
			return '<div class="tc-loc-row' + ( state.locationId === l.id ? ' selected' : '' ) + '" data-loc="' + l.id + '">' +
				'<div><div class="name">' + escapeHtml( l.name ) + '</div><div class="addr">' + escapeHtml( l.address || '' ) + '</div></div>' +
				'</div>';
		} ).join( '' );

		var employeeCard = '';
		if ( loc && state.guide ) {
			employeeCard = '<div class="tc-employee-card">' +
				'<div class="photo">' + ( state.guide.photo ? '<img src="' + escapeAttr( state.guide.photo ) + '" alt="">' : escapeHtml( initials( state.guide.name ) ) ) + '</div>' +
				'<div><div class="ecf-label">Your guide at this location</div><div class="ecf-name">' + escapeHtml( state.guide.name ) + '</div><div class="ecf-bio">' + escapeHtml( state.guide.bio || '' ) + '</div></div>' +
				'</div>';
		}

		return '<p class="tc-eyebrow">' + stepLabel( 'location' ) + '</p>' +
			'<h2 class="tc-title">Pick a location</h2>' +
			'<p class="tc-sub">This determines which guide and calendar you\u2019ll see next.</p>' +
			'<div class="tc-map-wrap"><svg class="tc-map-svg" viewBox="0 0 320 400" role="img" aria-label="Map of the Netherlands with ceremony locations">' +
			'<path class="tc-map-outline" d="' + NL_OUTLINE + '"></path>' + pins + '</svg>' +
			'<p class="tc-map-caption">Tap a pin, or pick from the list below</p></div>' +
			employeeCard +
			'<div class="tc-loc-list">' + list + '</div>' +
			'<div class="tc-nav"><span></span><button class="tc-btn primary" id="tc-next"' + ( state.locationId ? '' : ' disabled' ) + '>Continue</button></div>';
	}

	function renderGrid() {
		var loc   = getLocation( state.locationId );
		var today = new Date();
		today.setHours( 0, 0, 0, 0 );
		var start = addDays( today, state.weekOffset * 7 );
		var days  = [];
		for ( var i = 0; i < 7; i++ ) days.push( addDays( start, i ) );
		var rangeLabel = start.toLocaleDateString( 'en-US', { day: 'numeric', month: 'short' } ) + ' \u2013 ' +
			addDays( start, 6 ).toLocaleDateString( 'en-US', { day: 'numeric', month: 'short' } );

		var head = days.map( function ( d ) {
			return '<th>' + d.toLocaleDateString( 'en-US', { weekday: 'short' } ) + '<br>' + d.getDate() + '</th>';
		} ).join( '' );

		var rows = state.services.map( function ( svc ) {
			var cells = days.map( function ( d ) {
				var iso    = isoDate( d );
				var cell   = null;
				state.grid.forEach( function ( g ) {
					if ( g.service_id === svc.id && g.date === iso ) cell = g;
				} );
				if ( ! cell || 'off' === cell.status ) {
					return '<td><div class="tc-cell off">&mdash;</div></td>';
				}
				return '<td><div class="tc-cell ' + cell.status + '" data-svc="' + svc.id + '" data-date="' + iso + '">' + fmt( cell.price ) + '</div></td>';
			} ).join( '' );
			return '<tr><td>' + escapeHtml( svc.name ) + '<small>' + svc.duration_days + ( svc.duration_days > 1 ? ' days' : ' day' ) + '</small></td>' + cells + '</tr>';
		} ).join( '' );

		return '<p class="tc-eyebrow">' + stepLabel( 'availability' ) + '</p>' +
			'<h2 class="tc-title">Availability at ' + escapeHtml( loc ? loc.name.split( ' (' )[ 0 ] : '' ) + '</h2>' +
			'<p class="tc-sub">Pick a ceremony and date together \u2014 tap any open cell.</p>' +
			'<div class="tc-grid-nav"><button id="tc-prev-week"' + ( 0 === state.weekOffset ? ' disabled' : '' ) + '>\u2190 earlier</button>' +
			'<span class="range">' + rangeLabel + '</span><button id="tc-next-week"' + ( state.weekOffset >= 7 ? ' disabled' : '' ) + '>later \u2192</button></div>' +
			'<div class="tc-grid-scroll">' + ( state.gridLoading ? '<p>Loading availability\u2026</p>' :
				'<table class="tc-avail"><thead><tr><th></th>' + head + '</tr></thead><tbody>' + rows + '</tbody></table>' ) + '</div>' +
			'<div class="tc-legend"><span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Almost full</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Not available</span></div>' +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">Back</button><span></span></div>';
	}

	function renderParty() {
		var service = getService( state.serviceId );
		if ( ! service ) return '<p>Please go back and pick a date.</p>';
		var max = Math.max( 1, service.max_capacity );

		return '<p class="tc-eyebrow">' + stepLabel( 'party' ) + '</p><h2 class="tc-title">How many people are you bringing?</h2>' +
			'<p class="tc-sub">Includes you — up to ' + max + ' ' + ( 1 === max ? 'person' : 'people' ) + ' total for this ceremony. The base price is charged per person.</p>' +
			'<div class="tc-extra-row"><div class="tc-extra-info"><div class="en">Total in your group</div>' +
			'<div class="ep">' + fmt( service.price ) + ' per person</div></div>' +
			'<div class="tc-qty"><button type="button" id="tc-party-minus"' + ( state.partySize <= 1 ? ' disabled' : '' ) + '>−</button>' +
			'<span class="val">' + state.partySize + '</span>' +
			'<button type="button" id="tc-party-plus"' + ( state.partySize >= max ? ' disabled' : '' ) + '>+</button></div></div>' +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}

	function guestField( idx, key, label, value, placeholder ) {
		return '<div class="tc-field"><label>' + label + '</label>' +
			'<input data-guest-index="' + idx + '" data-guest-field="' + key + '" value="' + escapeAttr( value ) + '" placeholder="' + placeholder + '"></div>';
	}

	function renderGuests() {
		var count  = Math.max( 0, state.partySize - 1 );
		var blocks = '';
		for ( var i = 0; i < count; i++ ) {
			var g = state.guests[ i ] || { name: '', email: '', phone: '' };
			blocks += '<div class="tc-guest-block">' +
				'<p class="tc-guest-heading">Guest ' + ( i + 1 ) + '</p>' +
				guestField( i, 'name', 'Full name', g.name, 'Guest name' ) +
				guestField( i, 'email', 'Email', g.email, 'guest@example.com' ) +
				guestField( i, 'phone', 'Phone (optional)', g.phone, '+31 6 12345678' ) +
				'</div>';
		}

		return '<p class="tc-eyebrow">' + stepLabel( 'guests' ) + '</p><h2 class="tc-title">Your group’s details</h2>' +
			'<p class="tc-sub">We need contact details for everyone joining you, so we can reach them if needed.</p>' +
			blocks +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}

	function renderExtras() {
		var service = getService( state.serviceId );
		if ( ! service ) return '<p>Please go back and pick a date.</p>';
		var rows = service.extras.length === 0
			? '<p style="color:var(--ink-soft);font-size:14px">No extras for this ceremony.</p>'
			: service.extras.map( function ( e ) {
				var qty = state.extraQty[ e.key ] || 0;
				return '<div class="tc-extra-row"><div class="tc-extra-info"><div class="en">' + escapeHtml( e.label ) + '</div>' +
					'<div class="ep">' + fmt( e.price ) + ' each \u00b7 max ' + e.max + '</div>' +
					( e.description ? '<div class="ed">' + escapeHtml( e.description ) + '</div>' : '' ) + '</div>' +
					'<div class="tc-qty"><button data-extra="' + e.key + '" data-dir="-1"' + ( 0 === qty ? ' disabled' : '' ) + '>\u2212</button>' +
					'<span class="val">' + qty + '</span>' +
					'<button data-extra="' + e.key + '" data-dir="1"' + ( qty >= e.max ? ' disabled' : '' ) + '>+</button></div></div>';
			} ).join( '' );

		return '<p class="tc-eyebrow">' + stepLabel( 'extras' ) + '</p><h2 class="tc-title">Extras &amp; quantity</h2>' + rows +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}

	function renderInfo() {
		var i = state.info;
		return '<p class="tc-eyebrow">' + stepLabel( 'details' ) + '</p><h2 class="tc-title">Your details</h2>' +
			field( 'firstName', 'First name', i.firstName, 'Jane' ) +
			field( 'lastName', 'Last name', i.lastName, 'Doe' ) +
			field( 'email', 'Email', i.email, 'jane@example.com' ) +
			field( 'phone', 'Phone', i.phone, '+31 6 12345678' ) +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}
	function field( id, label, value, placeholder ) {
		return '<div class="tc-field"><label>' + label + '</label><input id="tc-' + id + '" value="' + escapeAttr( value ) + '" placeholder="' + placeholder + '"></div>';
	}

	function renderReview() {
		var service = getService( state.serviceId );
		var loc     = getLocation( state.locationId );
		var dateStr = new Date( state.date ).toLocaleDateString( 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );
		var extraLines = service.extras.filter( function ( e ) {
			return ( state.extraQty[ e.key ] || 0 ) > 0;
		} ).map( function ( e ) {
			var qty = state.extraQty[ e.key ];
			return '<div class="tc-rline"><span class="l">' + escapeHtml( e.label ) + ' \u00d7 ' + qty + '</span><span>' + fmt( e.price * qty ) + '</span></div>';
		} ).join( '' );

		var isParty     = service.allow_party && state.partySize > 1;
		var basePriceLine = isParty
			? '<div class="tc-rline"><span class="l">Base price \u00d7 ' + state.partySize + ' people</span><span>' + fmt( service.price * state.partySize ) + '</span></div>'
			: '<div class="tc-rline"><span class="l">Base price</span><span>' + fmt( service.price ) + '</span></div>';

		var guestLines = isParty
			? state.guests.slice( 0, state.partySize - 1 ).map( function ( g, i ) {
				return '<div class="tc-rline"><span class="l">Guest ' + ( i + 1 ) + '</span><span>' + escapeHtml( g.name || '\u2014' ) + '</span></div>';
			} ).join( '' )
			: '';

		return '<p class="tc-eyebrow">' + stepLabel( 'review' ) + '</p><h2 class="tc-title">Review your booking</h2>' +
			'<div class="tc-rline"><span class="l">Location</span><span>' + escapeHtml( loc.name ) + '</span></div>' +
			( state.guide ? '<div class="tc-rline"><span class="l">Guide</span><span>' + escapeHtml( state.guide.name ) + '</span></div>' : '' ) +
			'<div class="tc-rline"><span class="l">Ceremony</span><span>' + escapeHtml( service.name ) + '</span></div>' +
			'<div class="tc-rline"><span class="l">Date</span><span>' + dateStr + '</span></div>' +
			( isParty ? '<div class="tc-rline"><span class="l">Group size</span><span>' + state.partySize + '</span></div>' : '' ) +
			guestLines +
			basePriceLine +
			extraLines +
			'<div class="tc-rline total"><span class="l">Total</span><span class="r">' + fmt( grandTotal() ) + '</span></div>' +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">Back</button>' +
			'<button class="tc-btn primary" id="tc-checkout"' + ( state.submitting ? ' disabled' : '' ) + '>' +
			( state.submitting ? 'Processing\u2026' : 'Continue to checkout' ) + '</button></div>';
	}

	/* ------------------------------------------------------------------ */
	/* Event handling                                                       */
	/* ------------------------------------------------------------------ */

	function attachHandlers() {
		var back = document.getElementById( 'tc-back' );
		if ( back ) back.onclick = goBack;

		var next = document.getElementById( 'tc-next' );
		if ( next ) next.onclick = goNext;

		root.querySelectorAll( '[data-loc]' ).forEach( function ( el ) {
			el.onclick = function () { selectLocation( parseInt( el.dataset.loc, 10 ) ); };
		} );

		var prevWeek = document.getElementById( 'tc-prev-week' );
		if ( prevWeek ) prevWeek.onclick = function () { state.weekOffset = Math.max( 0, state.weekOffset - 1 ); loadGrid(); };
		var nextWeek = document.getElementById( 'tc-next-week' );
		if ( nextWeek ) nextWeek.onclick = function () { state.weekOffset = Math.min( 7, state.weekOffset + 1 ); loadGrid(); };

		root.querySelectorAll( '[data-svc][data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
				state.serviceId = parseInt( el.dataset.svc, 10 );
				state.date      = el.dataset.date;
				state.extraQty  = {};
				state.partySize = 1;
				state.guests    = [];
				goNext();
			};
		} );

		var partyMinus = document.getElementById( 'tc-party-minus' );
		if ( partyMinus ) partyMinus.onclick = function () { adjustPartySize( -1 ); };
		var partyPlus = document.getElementById( 'tc-party-plus' );
		if ( partyPlus ) partyPlus.onclick = function () { adjustPartySize( 1 ); };

		root.querySelectorAll( '[data-extra]' ).forEach( function ( el ) {
			el.onclick = function () {
				var key   = el.dataset.extra;
				var dir   = parseInt( el.dataset.dir, 10 );
				var svc   = getService( state.serviceId );
				var extra = null;
				svc.extras.forEach( function ( e ) { if ( e.key === key ) extra = e; } );
				var v = ( state.extraQty[ key ] || 0 ) + dir;
				v = Math.max( 0, Math.min( extra.max, v ) );
				state.extraQty[ key ] = v;
				render();
			};
		} );

		root.querySelectorAll( '[data-guest-field]' ).forEach( function ( el ) {
			el.oninput = function () {
				var idx = parseInt( el.dataset.guestIndex, 10 );
				var key = el.dataset.guestField;
				if ( ! state.guests[ idx ] ) state.guests[ idx ] = { name: '', email: '', phone: '' };
				state.guests[ idx ][ key ] = el.value;
			};
		} );

		[ 'firstName', 'lastName', 'email', 'phone' ].forEach( function ( f ) {
			var el = document.getElementById( 'tc-' + f );
			if ( el ) el.oninput = function () { state.info[ f ] = el.value; };
		} );

		var checkout = document.getElementById( 'tc-checkout' );
		if ( checkout ) checkout.onclick = submitBooking;
	}

	function adjustPartySize( dir ) {
		var service = getService( state.serviceId );
		var max     = Math.max( 1, service.max_capacity );
		var v       = Math.max( 1, Math.min( max, state.partySize + dir ) );
		state.partySize = v;
		state.guests.length = Math.max( 0, v - 1 ); // trim/grow to match, blanks fill in at render time
		render();
	}

	function selectLocation( id ) {
		state.locationId = id;
		state.error = null;
		// Guide preview comes from the bulk /guides prefetch at init, not a
		// fresh request per click - that per-click fetch was visibly slow
		// (see GitHub issue #4).
		state.guide = state.guidesByLocation[ id ] || null;
		render();
	}

	function loadGrid() {
		state.gridLoading = true;
		render();
		var today = new Date();
		today.setHours( 0, 0, 0, 0 );
		var start = addDays( today, state.weekOffset * 7 );
		var end   = addDays( start, 6 );

		var requests = state.services.map( function ( svc ) {
			return apiGet( '/availability?service_id=' + svc.id + '&location_id=' + state.locationId +
				'&start=' + isoDate( start ) + '&end=' + isoDate( end ) )
				.then( function ( rows ) {
					return rows.map( function ( r ) {
						r.service_id = svc.id;
						return r;
					} );
				} );
		} );

		Promise.all( requests ).then( function ( results ) {
			state.grid        = [].concat.apply( [], results );
			state.gridLoading = false;
			render();
		} ).catch( function ( err ) {
			state.error        = err.message;
			state.gridLoading  = false;
			render();
		} );
	}

	function submitBooking() {
		state.submitting = true;
		state.error      = null;
		render();

		var service = getService( state.serviceId );
		var extras  = Object.keys( state.extraQty ).filter( function ( k ) {
			return state.extraQty[ k ] > 0;
		} ).map( function ( k ) {
			return { key: k, qty: state.extraQty[ k ] };
		} );

		apiPost( '/bookings', {
			service_id: state.serviceId,
			location_id: state.locationId,
			date: state.date,
			first_name: state.info.firstName,
			last_name: state.info.lastName,
			email: state.info.email,
			phone: state.info.phone,
			extras: extras,
			party_size: ( service && service.allow_party ) ? state.partySize : 1,
			guests: ( service && service.allow_party ) ? state.guests.slice( 0, Math.max( 0, state.partySize - 1 ) ) : [],
		} ).then( function ( result ) {
			window.location.href = result.checkout_url;
		} ).catch( function ( err ) {
			state.submitting = false;
			state.error      = err.message;
			render();
		} );
	}

	/* ------------------------------------------------------------------ */
	/* Utilities                                                            */
	/* ------------------------------------------------------------------ */

	function initials( name ) {
		return ( name || '' ).split( ' ' ).map( function ( w ) { return w[ 0 ]; } ).join( '' );
	}
	function escapeHtml( str ) {
		var div = document.createElement( 'div' );
		div.textContent = str == null ? '' : String( str );
		return div.innerHTML;
	}
	function escapeAttr( str ) {
		return escapeHtml( str ).replace( /"/g, '&quot;' );
	}

	/* ------------------------------------------------------------------ */
	/* Init                                                                 */
	/* ------------------------------------------------------------------ */

	Promise.all( [ apiGet( '/locations' ), apiGet( '/services' ), apiGet( '/guides' ) ] ).then( function ( results ) {
		state.locations        = results[ 0 ];
		state.services         = results[ 1 ];
		state.guidesByLocation = results[ 2 ];
		render();
	} ).catch( function ( err ) {
		root.innerHTML = '<div class="tc-card"><p class="tc-error">Could not load the booking form: ' + escapeHtml( err.message ) + '</p></div>';
	} );
})();
