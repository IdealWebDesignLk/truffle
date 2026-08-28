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

	var EMAIL_RE = /^[^\s@]+@[^\s@]+\.[^\s@]+$/;
	var PHONE_RE = /^[0-9+()\-\s]{6,20}$/;

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
		monthOffset: 0,
		grid: [],
		gridLoading: false,
		extraQty: {},
		partySize: 1,
		guests: [],
		info: { firstName: '', lastName: '', email: '', phone: '' },
		fieldErrors: {},
		lightboxUrl: null,
		guideInfoOpen: false,
		extraInfoKey: null,
		submitting: false,
		error: null,
	};

	function getActiveSteps() {
		var svc   = getService( state.serviceId );
		// GitHub issue #38 previously split this into 'service' then
		// 'calendar' as two separate steps; GitHub issue #42 puts them back
		// in one view ("2nd and 3rd step to be in one view") - ceremony
		// cards and that ceremony's calendar both render on the 'service'
		// step again, see renderServicePicker().
		var steps = [ 'location', 'service' ];
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

	function validateDetails() {
		var errors = {};
		if ( ! state.info.firstName.trim() ) errors.firstName = 'First name is required.';
		if ( ! state.info.lastName.trim() ) errors.lastName = 'Last name is required.';
		if ( ! EMAIL_RE.test( state.info.email.trim() ) ) errors.email = 'Enter a valid email address.';
		if ( ! PHONE_RE.test( state.info.phone.trim() ) ) errors.phone = 'Enter a valid phone number.';
		return errors;
	}

	function validateGuests() {
		var errors = {};
		var count  = Math.max( 0, state.partySize - 1 );
		for ( var i = 0; i < count; i++ ) {
			var g = state.guests[ i ] || {};
			if ( ! ( g.name || '' ).trim() ) errors[ 'guest-' + i + '-name' ] = 'Name is required.';
			if ( ! EMAIL_RE.test( ( g.email || '' ).trim() ) ) errors[ 'guest-' + i + '-email' ] = 'Enter a valid email address.';
			if ( ( g.phone || '' ).trim() && ! PHONE_RE.test( g.phone.trim() ) ) errors[ 'guest-' + i + '-phone' ] = 'Enter a valid phone number.';
		}
		return errors;
	}

	// Required-field gating for GitHub issues #8/#9 - required fields must be
	// filled (and email/phone must look valid, #7/#10) before moving on.
	function getStepErrors( key ) {
		if ( 'details' === key ) return validateDetails();
		if ( 'guests' === key ) return validateGuests();
		return {};
	}

	function goNext() {
		var errors     = getStepErrors( state.step );
		var errorCount = Object.keys( errors ).length;
		if ( errorCount ) {
			state.fieldErrors = errors;
			state.error = errorCount > 1 ? 'Please fix the highlighted fields before continuing.' : 'Please fix the highlighted field before continuing.';
			render();
			return;
		}
		state.fieldErrors = {};
		state.error        = null;
		var steps = getActiveSteps();
		var idx   = steps.indexOf( state.step );
		if ( idx > -1 && idx < steps.length - 1 ) {
			setStep( steps[ idx + 1 ] );
		}
	}

	function goBack() {
		state.fieldErrors = {};
		state.error        = null;
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
	 * were derived from Natural Earth boundary data). GitHub issue #23:
	 * regenerated from world-atlas's countries-10m.json (Natural Earth
	 * 1:10m admin-0 boundaries) instead of the earlier hand-simplified
	 * outline - SCALE/TRANSLATE were refit to the new shape's bounding
	 * box, so they must stay in sync with NL_OUTLINE below; don't tweak
	 * one without the other. */
	/* ------------------------------------------------------------------ */

	var MERC_SCALE     = 4525.96995967345;
	var MERC_TRANSLATE = [ -256.613657623948, 5047.9541389909255 ];

	function project( lon, lat ) {
		var lambda = ( lon * Math.PI ) / 180;
		var phi    = ( lat * Math.PI ) / 180;
		var x      = MERC_TRANSLATE[ 0 ] + MERC_SCALE * lambda;
		var y      = MERC_TRANSLATE[ 1 ] - MERC_SCALE * Math.log( Math.tan( Math.PI / 4 + phi / 2 ) );
		return { x: x, y: y };
	}

	var NL_OUTLINE = 'M311.72,60.55L312.00,64.34L312.00,66.34L311.72,68.35L311.15,70.79L310.01,73.69L310.01,74.58L310.01,76.13L310.29,77.02L310.58,77.69L310.86,79.02L311.72,88.35L311.43,93.00L310.86,97.21L309.16,101.64L302.62,111.81L302.05,113.58L301.19,115.78L300.62,120.20L299.77,134.30L299.20,138.91L297.78,141.55L294.94,140.67L293.80,140.23L289.82,140.89L285.84,139.57L278.16,139.79L275.60,140.45L274.18,141.55L272.76,142.65L273.32,143.97L273.32,145.07L273.04,146.16L273.04,147.26L273.61,148.14L276.17,150.33L271.62,152.53L270.48,152.53L271.33,154.72L271.62,156.91L271.90,159.32L272.19,161.29L273.89,163.05L275.88,164.14L282.14,165.02L286.12,166.55L288.40,166.77L290.67,166.77L292.38,166.11L293.23,165.45L293.80,164.58L294.37,164.36L298.92,172.23L300.06,175.51L299.49,180.31L297.78,183.81L297.21,185.77L297.21,188.17L298.07,191.00L298.35,191.87L298.63,192.31L298.35,192.96L294.94,194.92L294.08,196.01L292.09,199.05L290.67,200.36L287.26,201.88L285.84,203.62L283.85,207.10L282.99,207.97L278.16,208.62L277.87,208.62L276.45,209.27L275.32,210.79L274.75,211.44L274.18,213.18L273.61,213.83L272.47,214.48L271.90,214.48L271.05,214.70L270.48,216.21L270.77,218.17L271.90,219.03L273.61,219.47L278.73,222.28L280.72,224.45L281.29,225.10L281.57,225.97L281.29,227.70L281.00,227.91L280.43,228.13L279.87,228.78L277.87,232.03L276.17,234.19L273.89,235.49L267.07,235.70L259.11,238.29L255.12,241.32L253.99,241.97L252.28,241.97L247.16,240.45L248.01,243.26L247.45,244.56L244.60,245.42L244.32,243.48L243.18,242.61L240.05,242.18L239.77,241.75L238.91,240.24L238.35,239.59L237.49,239.59L235.22,240.24L232.37,238.94L229.81,237.00L227.26,235.70L224.70,237.21L225.55,238.08L228.68,241.10L229.81,242.83L228.39,242.40L226.40,241.97L223.84,241.32L220.71,242.18L219.58,242.83L217.87,243.91L216.45,244.56L213.32,245.20L211.90,246.07L211.61,247.36L213.32,248.66L214.46,251.24L214.46,253.61L213.04,254.91L212.75,255.55L212.47,256.85L213.32,257.06L215.60,257.06L216.45,257.49L218.44,258.78L219.01,259.65L218.16,263.30L219.86,265.67L225.26,268.03L224.13,270.83L223.84,272.55L224.13,274.26L229.53,281.56L232.66,285.20L233.23,289.28L233.51,291.63L233.23,294.20L232.66,296.77L232.66,299.12L233.80,300.61L233.23,301.68L232.94,304.46L232.66,305.96L231.81,307.24L229.81,308.52L228.96,309.37L224.98,316.84L223.27,318.97L222.42,320.25L221.85,322.80L221.57,325.57L222.14,327.91L224.13,328.97L227.82,326.21L229.81,326.85L229.25,327.27L227.26,328.76L228.96,330.25L227.82,331.95L223.27,334.29L215.88,340.02L215.03,341.30L213.32,344.48L212.47,345.54L211.05,345.75L209.91,344.69L209.06,343.42L207.92,342.99L205.64,344.05L206.21,347.02L207.35,350.63L207.35,353.81L208.49,353.59L210.48,352.53L211.61,352.32L212.75,352.32L214.74,353.17L217.59,352.75L217.87,353.38L217.30,354.44L217.02,355.50L217.02,357.19L217.30,358.25L217.87,358.89L219.86,360.16L222.42,361.00L222.14,361.64L221.85,362.27L221.57,363.12L222.42,365.65L222.42,366.71L221.85,367.98L221.00,369.03L219.29,368.82L218.16,369.46L217.30,370.72L217.59,371.78L217.59,372.84L217.30,374.31L216.16,375.37L214.74,375.16L215.31,376.85L215.88,377.90L216.73,378.32L217.02,379.17L217.02,380.64L215.31,381.06L209.62,380.85L208.77,380.64L207.92,380.22L201.09,380.43L200.24,379.80L198.53,378.11L197.68,377.90L197.11,378.32L196.26,379.59L195.97,380.22L195.12,380.43L194.27,380.22L192.85,379.38L193.41,376.42L193.70,374.95L192.56,374.74L190.86,373.89L190.29,373.47L188.86,372.62L187.73,370.72L187.44,367.98L193.13,361.43L193.98,361.21L195.12,360.79L195.41,360.58L196.54,358.89L197.68,356.13L198.82,354.44L197.96,354.02L197.11,354.02L195.41,354.44L198.53,349.57L199.67,346.39L198.82,345.12L198.82,343.42L199.39,342.15L199.96,341.72L200.81,342.57L201.38,341.72L201.95,340.87L202.51,339.17L202.23,338.54L202.23,338.11L201.95,337.26L202.80,337.47L203.65,337.26L204.51,336.84L205.07,336.41L203.37,334.50L203.65,333.01L204.79,331.95L204.51,331.31L203.94,329.82L202.51,329.61L199.96,330.25L199.10,329.40L197.40,327.06L195.97,326.42L195.12,326.21L191.71,326.85L190.29,326.85L189.43,326.21L188.86,325.36L187.73,324.72L183.18,323.23L182.04,322.16L181.47,321.74L181.19,318.97L180.62,316.41L179.20,314.49L177.20,313.42L175.50,313.21L174.08,313.85L170.66,316.20L168.96,316.84L159.86,316.41L157.30,317.05L156.73,317.26L155.31,316.84L155.31,312.36L153.89,310.65L152.46,310.44L149.34,310.65L148.20,310.01L147.63,309.16L146.78,306.38L146.49,305.31L143.65,302.32L143.36,301.04L143.65,298.26L144.50,296.34L144.79,294.20L143.08,291.20L140.80,289.49L140.52,289.28L139.38,289.49L138.81,290.35L138.53,291.63L137.96,293.13L136.82,295.05L134.83,297.62L132.84,299.55L131.14,299.97L128.29,298.69L126.87,298.26L121.18,298.05L119.48,297.41L120.04,295.70L120.90,295.70L124.31,297.19L123.74,295.05L124.03,293.13L124.59,291.42L124.31,289.49L123.74,288.42L122.32,287.56L120.90,286.92L119.76,286.92L117.20,288.20L110.94,295.70L110.09,296.34L109.24,296.77L107.82,296.77L105.26,296.12L102.98,296.55L102.13,296.55L100.42,295.48L100.71,294.20L101.28,292.49L101.56,290.35L100.71,289.49L99.85,289.28L97.58,289.49L93.31,291.20L89.05,293.56L90.19,294.63L89.90,295.48L89.33,296.55L89.33,297.84L89.90,298.90L91.89,301.04L92.18,302.32L91.89,304.46L90.47,305.31L86.77,305.10L85.07,304.46L82.22,302.97L81.65,302.97L79.95,302.97L79.66,302.54L79.09,301.04L78.81,299.55L78.81,298.26L78.81,297.19L77.96,296.55L77.10,297.84L75.11,298.26L68.00,298.05L66.58,297.62L64.88,296.55L60.61,292.91L59.19,292.27L56.06,291.84L54.92,292.70L52.65,296.98L51.51,298.48L50.37,299.12L47.53,299.33L46.68,299.97L45.82,300.19L44.97,299.76L42.69,297.41L37.86,295.05L34.73,292.06L33.59,292.06L30.47,293.13L28.48,293.34L24.21,292.27L22.79,291.42L21.37,289.70L20.51,287.78L19.38,286.28L16.82,283.92L15.96,282.63L15.68,280.49L16.53,279.42L24.49,274.26L45.82,272.12L47.53,272.33L48.67,272.98L50.09,274.05L51.23,275.77L51.79,277.48L51.23,277.91L49.80,278.77L49.23,279.20L49.23,280.06L56.91,280.92L60.61,282.42L63.74,285.20L64.88,287.35L66.58,291.63L67.72,293.13L69.43,293.77L73.69,293.13L75.11,293.98L76.82,293.56L79.38,293.56L80.23,293.34L81.65,292.91L82.79,290.13L82.22,287.35L80.52,285.20L78.24,283.92L77.67,283.92L74.83,283.28L71.42,281.99L70.28,281.77L67.72,281.99L66.58,281.99L64.59,280.70L58.33,274.91L62.60,272.76L64.59,272.33L71.70,272.55L73.98,273.19L75.68,274.91L75.40,272.55L74.26,271.47L73.98,271.47L70.85,271.26L69.99,271.04L69.43,270.40L68.57,269.75L68.29,268.68L68.00,267.17L68.00,265.67L68.29,264.59L69.43,263.52L70.56,262.87L73.41,262.66L72.55,261.80L70.56,260.51L64.88,258.78L63.74,257.06L63.45,252.75L62.03,250.17L60.04,248.44L57.77,246.93L56.34,248.01L54.35,248.66L52.36,248.66L50.94,249.09L50.66,249.52L50.09,249.73L49.23,249.73L48.38,246.07L50.66,244.56L55.78,243.05L56.34,242.18L58.05,241.97L60.61,242.61L62.88,243.69L63.17,243.91L63.74,243.69L64.59,243.48L65.44,242.61L64.88,241.32L62.88,238.51L62.32,238.08L62.03,237.65L61.75,236.78L61.75,235.92L62.32,235.05L62.60,234.62L62.88,234.19L62.60,232.24L60.89,227.26L60.04,225.10L60.61,224.02L61.18,223.37L64.88,224.02L68.00,223.58L70.56,221.85L81.94,207.97L91.61,193.83L99.57,179.22L104.40,165.02L104.97,161.51L104.97,160.42L106.39,157.13L109.81,131.87L111.23,125.49L111.51,123.50L112.37,119.31L115.49,110.70L116.35,106.50L116.35,104.07L116.63,100.75L117.20,98.10L118.34,96.99L123.17,97.88L124.03,96.99L124.59,98.10L123.17,102.08L125.16,104.29L128.29,105.40L131.42,105.18L133.41,104.07L136.54,101.20L138.25,100.53L141.37,100.09L144.50,98.76L160.43,84.57L161.56,83.02L162.99,81.91L166.68,81.02L168.39,79.91L169.81,76.80L172.09,67.68L173.79,63.67L176.35,60.55L178.06,58.99L181.19,57.65L182.32,56.09L183.75,54.31L185.17,52.97L186.59,52.30L190.57,51.63L193.13,49.17L205.93,44.25L208.77,42.02L210.48,42.02L215.88,39.11L223.56,38.89L232.09,38.66L238.91,38.44L239.77,38.66L241.19,39.78L242.04,40.23L242.61,40.01L244.60,38.44L256.26,34.86L275.03,31.95L282.71,33.29L284.42,33.96L285.84,35.53L288.40,46.49L288.97,46.49L290.67,48.05L290.96,48.28L291.24,48.50L291.81,49.17L292.38,49.84L294.65,50.73L297.78,52.52L299.20,52.07L300.06,52.74L300.91,52.97L301.76,52.74L302.62,52.07L303.18,52.07L303.18,52.97L302.33,53.41L302.05,53.86L302.05,54.31L302.62,55.42L302.62,57.65L305.17,58.99L310.86,60.55ZM8.00,302.11L20.80,298.05L21.93,296.98L23.07,296.98L28.19,300.19L35.87,302.32L38.43,304.67L40.13,305.31L43.26,305.31L46.11,306.17L48.10,305.96L55.78,302.54L56.91,301.26L57.48,298.90L58.05,297.62L59.76,297.84L61.75,298.48L62.88,299.12L63.45,299.97L64.31,301.90L64.88,302.75L65.73,302.97L67.72,303.39L68.57,304.03L68.86,304.25L71.70,304.46L73.12,302.32L73.69,301.90L75.11,302.11L76.82,302.97L76.82,303.61L76.25,306.60L75.68,307.88L72.55,312.15L68.86,315.13L58.90,320.03L55.49,323.01L53.50,323.44L44.12,323.65L43.26,323.23L42.13,321.74L41.84,320.46L42.13,318.97L41.56,317.48L40.13,316.41L30.47,313.21L28.48,312.79L26.48,313.00L23.36,313.64L22.22,313.85L21.08,314.06L20.23,314.92L19.94,316.41L20.23,317.90L19.94,319.18L16.25,319.82L13.69,319.39L11.98,318.54L11.41,318.33L9.42,316.20L8.28,313.00L8.28,310.22L8.57,307.02ZM62.32,262.66L65.44,263.73L67.15,264.59L67.15,266.74L66.30,268.03L65.16,268.89L63.74,269.54L57.48,270.61L56.34,270.61L53.22,269.54L51.51,268.25L50.94,266.53L50.37,265.02L48.95,263.95L46.11,262.66L45.54,262.23L44.97,261.58L44.40,260.94L43.26,260.94L41.27,263.30L40.70,263.73L37.29,263.95L35.58,263.30L34.73,261.80L34.73,257.71L37.58,255.77L44.40,254.91L44.68,254.69L46.11,254.91L46.96,255.34L54.64,255.55L56.34,255.98L57.48,257.06L60.61,260.72L60.89,261.37L61.46,262.01ZM130.00,78.13L130.00,79.02L131.42,79.02L130.85,80.80L130.00,82.80L127.72,86.13L124.03,89.23L122.32,92.78L119.48,93.44L116.63,92.34L115.21,89.01L115.78,85.68L117.20,81.69L119.19,78.58L122.89,75.24L124.88,71.24L126.87,68.12L129.43,68.12L129.71,69.01L129.71,69.68L129.71,70.35L129.43,71.02L130.57,72.80L131.14,75.02L131.14,77.24ZM171.80,35.98L172.94,35.08L184.60,32.84L182.61,35.53L180.62,36.20L175.78,36.43L176.07,36.87L176.35,37.77L176.35,38.44L175.50,38.89L172.09,39.11L168.67,40.68L165.83,41.35L154.74,46.27L152.46,46.27L151.61,44.25L152.46,42.02L156.73,40.45L158.15,39.11L159.86,39.11L170.38,37.10ZM234.08,25.67L241.76,24.77L244.03,25.67L244.03,26.57L242.90,26.57L229.53,30.83L227.82,32.84L227.54,32.17L227.26,31.72L227.82,31.05L226.97,29.26L228.96,27.46L232.09,26.12ZM128.86,63.67L131.99,59.44L137.39,54.53L142.80,51.40L146.49,52.07L146.49,52.97L144.50,52.97L143.65,53.41L141.37,54.31L135.12,60.55L133.41,63.00L131.14,64.78ZM191.71,31.05L210.48,30.15L213.89,31.95L205.93,33.74L197.68,33.74L195.69,34.19L191.99,35.98L190.00,35.53L188.58,33.51L189.15,31.72L190.29,30.60ZM252.28,19.16L253.42,18.94L257.68,20.96L257.40,20.96L257.12,21.18L256.83,21.41L256.55,21.86L253.70,20.96L252.28,20.06Z';

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
	// The grid cell for the currently selected service+date - carries the
	// real remaining-seat count for shared services (null for exclusive
	// ones), straight from the same /availability data already loaded for
	// the grid.
	function selectedGridCell() {
		// state.grid only ever holds the currently selected service's
		// availability (GitHub issue #21 - one service's calendar at a
		// time), so matching by date alone is correct here.
		var found = null;
		state.grid.forEach( function ( g ) {
			if ( g.date === state.date ) found = g;
		} );
		return found;
	}
	// GitHub issue #20 - "how many people are you bringing" must be capped
	// at what's actually left for THIS date, not just the service's static
	// Max capacity - a shared service with 2 of 4 seats already taken must
	// only offer up to 2, not 4.
	function partySizeMax() {
		var service = getService( state.serviceId );
		if ( ! service ) return 1;
		var cap  = Math.max( 1, service.max_capacity );
		var cell = selectedGridCell();
		if ( cell && null !== cell.remaining && undefined !== cell.remaining ) {
			cap = Math.max( 1, Math.min( cap, cell.remaining ) );
		}
		return cap;
	}
	function fmt( n ) {
		return '\u20ac' + Number( n ).toLocaleString( 'en-US', { minimumFractionDigits: 2, maximumFractionDigits: 2 } );
	}
	// Not new Date(dateStr) directly - a bare "YYYY-MM-DD" string is parsed
	// as UTC midnight per spec, which can display the wrong calendar day
	// for any visitor whose browser is behind UTC once toLocaleDateString()
	// renders it back in local time. Parse the parts explicitly instead.
	function parseDateStr( dateStr ) {
		var bits = dateStr.split( '-' );
		return new Date( parseInt( bits[ 0 ], 10 ), parseInt( bits[ 1 ], 10 ) - 1, parseInt( bits[ 2 ], 10 ) );
	}
	// GitHub issue #29 - shown above the Extras step so the selected
	// ceremony/date doesn't disappear from view once you leave the grid.
	function selectionSummary() {
		var service = getService( state.serviceId );
		if ( ! service || ! state.date ) return '';
		var dateStr = parseDateStr( state.date ).toLocaleDateString( 'en-US', { weekday: 'short', day: 'numeric', month: 'short', year: 'numeric' } );
		return '<div class="tc-selection-summary">' + escapeHtml( service.name ) + ' \u00b7 ' + dateStr + '</div>';
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
	var SITE_TZ = 'Europe/Amsterdam';

	// This business only operates in the Netherlands, so "today" must always
	// mean the Netherlands' calendar day - not the visitor's own device
	// timezone (which plain `new Date()` reflects, and which is wrong for a
	// customer browsing from anywhere else). Built via Intl so CET/CEST
	// daylight-saving transitions are handled automatically and correctly.
	// The returned Date is anchored at *local device* midnight for that same
	// Netherlands calendar day, purely so isoDate() below keeps working
	// unchanged on top of it.
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
		// (nlToday()), so what's sent to the server always matches the
		// Netherlands calendar day being displayed.
		var y = d.getFullYear();
		var m = String( d.getMonth() + 1 ).padStart( 2, '0' );
		var day = String( d.getDate() ).padStart( 2, '0' );
		return y + '-' + m + '-' + day;
	}

	/* ------------------------------------------------------------------ */
	/* Render                                                               */
	/* ------------------------------------------------------------------ */

	function setStep( key ) {
		state.step = key;
		// GitHub issue #21 - the service step defaults to the first ceremony
		// instead of requiring a click to imply the service; GitHub issue
		// #42 - since the calendar is back on this same step, load its
		// grid right away too instead of waiting for a separate step.
		if ( 'service' === key && ! state.serviceId && state.services.length ) {
			state.serviceId = state.services[ 0 ].id;
		}
		render();
		if ( 'service' === key ) {
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
			case 'service': body = renderServicePicker(); break;
			case 'party': body = renderParty(); break;
			case 'extras': body = renderExtras(); break;
			case 'guests': body = renderGuests(); break;
			case 'details': body = renderInfo(); break;
			case 'review': body = renderReview(); break;
		}

		root.innerHTML = '<div class="tc-progress">' + progress + '</div><div class="tc-card">' +
			( state.error ? '<div class="tc-error">' + escapeHtml( state.error ) + '</div>' : '' ) +
			body + '</div>' + renderLightbox() + renderGuideInfoModal() + renderExtraInfoModal();

		attachHandlers();
	}

	// GitHub issue #26 - full-size view of the guide photo. Click the
	// backdrop or the close button to dismiss; the image itself stays
	// clickable-inert so a tap on it doesn't close the lightbox.
	function renderLightbox() {
		if ( ! state.lightboxUrl ) return '';
		return '<div class="tc-lightbox-overlay" id="tc-lightbox-overlay">' +
			'<img src="' + escapeAttr( state.lightboxUrl ) + '" alt="">' +
			'<button type="button" class="tc-lightbox-close" aria-label="Close">&times;</button>' +
			'</div>';
	}

	// GitHub issue #36 - the full guide card used to sit above the map and
	// pushed it down whenever a guide loaded. It's now a compact avatar +
	// name next to the "Pick a location" heading instead, with the full
	// bio available on demand here rather than always taking up space.
	function renderGuideInfoModal() {
		if ( ! state.guideInfoOpen || ! state.guide ) return '';
		var g          = state.guide;
		var photoInner = g.photo
			? '<img src="' + escapeAttr( g.photo ) + '" alt="' + escapeAttr( g.name ) + '" data-photo-zoom="' + escapeAttr( g.photo ) + '">'
			: escapeHtml( initials( g.name ) );
		return '<div class="tc-modal-overlay" id="tc-guide-modal">' +
			'<div class="tc-modal-card">' +
			'<button type="button" class="tc-modal-close" id="tc-guide-modal-close" aria-label="Close">&times;</button>' +
			'<div class="tc-modal-photo' + ( g.photo ? ' zoomable' : '' ) + '">' + photoInner + '</div>' +
			'<div class="tc-modal-label">Your guide at this location</div>' +
			'<div class="tc-modal-name">' + escapeHtml( g.name ) + '</div>' +
			'<div class="tc-modal-bio">' + escapeHtml( g.bio || '' ) + '</div>' +
			'</div></div>';
	}

	// GitHub issue #53 - the extra's own description used to always render
	// inline under the price, adding clutter (and unpredictable row height)
	// to a list that's otherwise just label/price/qty. It now opens in a
	// popup via the (i) info button instead, only when the customer asks
	// for it.
	function renderExtraInfoModal() {
		if ( ! state.extraInfoKey ) return '';
		var service = getService( state.serviceId );
		var extra   = null;
		if ( service ) {
			service.extras.forEach( function ( e ) { if ( e.key === state.extraInfoKey ) extra = e; } );
		}
		if ( ! extra ) return '';
		return '<div class="tc-modal-overlay" id="tc-extra-info-modal">' +
			'<div class="tc-modal-card tc-extra-info-card">' +
			'<div class="tc-extra-info-head"><div class="tc-modal-name">' + escapeHtml( extra.label ) + '</div>' +
			'<button type="button" class="tc-modal-close" id="tc-extra-info-modal-close" aria-label="Close">&times;</button></div>' +
			'<div class="tc-modal-bio">' + escapeHtml( extra.description || '' ) + '</div>' +
			'<button type="button" class="tc-btn primary tc-extra-info-close-btn" id="tc-extra-info-modal-close-btn">Close</button>' +
			'</div></div>';
	}

	function renderLocation() {
		var loc = getLocation( state.locationId );
		// GitHub issue #37 - the outer viewBox is padded out beyond the
		// map's own 320x400 coordinate space (which NL_OUTLINE/MERC_SCALE/
		// MERC_TRANSLATE are fitted to - see PROJECT_NOTES.md, don't touch
		// those without refitting all three together). This is a display
		// -level "zoom out": it doesn't move anything, it just shows more
		// empty margin around the same map, so a pin label near the coast
		// (e.g. the NE edge) has room to render instead of getting clipped
		// by the SVG's edge. Because .tc-map-svg's width/height:auto keeps
		// the same aspect ratio as this viewBox, padding both dimensions
		// equally also shrinks the whole map (labels included) on screen -
		// which doubles as "make city names little bit smaller".
		var padX = 24, padY = 30;
		var viewBox = ( -padX ) + ' ' + ( -padY ) + ' ' + ( 320 + 2 * padX ) + ' ' + ( 400 + 2 * padY );
		var pins = state.locations.map( function ( l ) {
			if ( ! l.lat || ! l.lng ) return '';
			var p        = project( l.lng, l.lat );
			var selected = state.locationId === l.id;
			// GitHub issue #37 - padding the viewBox alone isn't enough for a
			// pin near the coastline on the right/east side: the label text
			// runs further right than the pin itself, easily past even a
			// padded edge for a longer place name (this was the actual cause
			// of the "N" seen cut down to one letter). Flip the label to the
			// LEFT of the pin once it's in roughly the rightmost quarter of
			// the map's own (unpadded) coordinate space, instead of relying
			// on padding to outrun arbitrarily long names.
			var flip     = p.x > 230;
			// GitHub issue #40 - dot and label text both sized down a little.
			var labelX   = flip ? ( p.x - 9 ) : ( p.x + 9 );
			var anchor   = flip ? ' text-anchor="end"' : '';
			// GitHub issue #45 - a multi-word city name now breaks onto two
			// tspan lines instead of running on as one long line; the two
			// dy offsets keep the pair vertically centered on the pin
			// (dominant-baseline only centers a single line, not a
			// multi-tspan block).
			var cityName = l.name.split( ' (' )[ 0 ];
			var words    = cityName.split( ' ' );
			var labelInner = words.length > 1
				? '<tspan x="' + labelX + '" dy="-0.35em">' + escapeHtml( words[ 0 ] ) + '</tspan>' +
					'<tspan x="' + labelX + '" dy="1.1em">' + escapeHtml( words.slice( 1 ).join( ' ' ) ) + '</tspan>'
				: escapeHtml( cityName );
			return '<g class="tc-pin-group' + ( selected ? ' selected' : '' ) + '" data-loc="' + l.id + '">' +
				'<circle class="tc-pin" cx="' + p.x + '" cy="' + p.y + '" r="' + ( selected ? 7.5 : 5.5 ) + '"></circle>' +
				'<text class="tc-pin-label" x="' + labelX + '" y="' + p.y + '" dominant-baseline="middle"' + anchor + '>' + labelInner + '</text>' +
				'</g>';
		} ).join( '' );

		var list = state.locations.map( function ( l ) {
			// GitHub issue #27 - address is intentionally not shown to
			// customers, just the location name. GitHub issue #46 - the
			// province is dropped too (city name only, same as the map
			// pin labels already did) so the list is compact enough for a
			// two-column layout without needing to scroll to reach the
			// last few cities.
			return '<div class="tc-loc-row' + ( state.locationId === l.id ? ' selected' : '' ) + '" data-loc="' + l.id + '">' +
				'<div><div class="name">' + escapeHtml( l.name.split( ' (' )[ 0 ] ) + '</div></div>' +
				'</div>';
		} ).join( '' );

		// GitHub issue #36 - the full guide card used to live above the map
		// and pushed it down every time a guide loaded in, so it moved next
		// to the heading instead (#42), then had its column matched to the
		// map's (#36/#42's follow-ups). GitHub issues #54/#55 move it again:
		// under the map on mobile, under the location list on desktop (with
		// the map moved flush to the top of that column, no gap where the
		// guide box used to sit in the heading row). Rather than rendering
		// it twice for the two breakpoints, it's now a single grid item -
		// .tc-loc-layout below is a CSS grid whose grid-template-areas
		// differs per breakpoint (see the CSS), placing this same element
		// wherever each breakpoint wants it without duplicating markup.
		// Kept the "Read more" popup (renderGuideInfoModal()) and the bio
		// preview from #39.
		var guideMini = '';
		if ( loc && state.guide ) {
			var photoInner = state.guide.photo
				? '<img src="' + escapeAttr( state.guide.photo ) + '" alt="' + escapeAttr( state.guide.name ) + '">'
				: escapeHtml( initials( state.guide.name ) );
			guideMini = '<div class="tc-guide-mini">' +
				'<div class="photo">' + photoInner + '</div>' +
				'<div class="gm-text"><div class="gm-label">Your guide</div><div class="gm-name">' + escapeHtml( state.guide.name ) + '</div>' +
				( state.guide.bio ? '<div class="gm-bio">' + escapeHtml( state.guide.bio ) + '</div>' : '' ) +
				'<button type="button" class="tc-link-btn" id="tc-guide-readmore">Read more</button></div>' +
				'</div>';
		}

		return '<p class="tc-eyebrow">' + stepLabel( 'location' ) + '</p>' +
			'<h2 class="tc-title">Pick a location</h2>' +
			'<p class="tc-sub">This determines which guide and calendar you\u2019ll see next.</p>' +
			// GitHub issue #24 - map/list wrapped as a grid so CSS can put
			// the map on the right and the list on the left on desktop;
			// issue #25 - larger .tc-map-svg cap lets the map grow bigger in
			// that wider column.
			'<div class="tc-loc-layout">' +
			'<div class="tc-loc-map-col">' +
			'<div class="tc-map-wrap"><svg class="tc-map-svg" viewBox="' + viewBox + '" role="img" aria-label="Map of the Netherlands with ceremony locations">' +
			'<path class="tc-map-outline" d="' + NL_OUTLINE + '"></path>' + pins + '</svg>' +
			'<p class="tc-map-caption">Tap a pin, or pick from the list</p></div>' +
			'</div>' +
			guideMini +
			'<div class="tc-loc-list-col"><div class="tc-loc-list">' + list + '</div></div>' +
			'</div>' +
			'<div class="tc-nav"><span></span><button class="tc-btn primary" id="tc-next"' + ( state.locationId ? '' : ' disabled' ) + '>Continue</button></div>';
	}

	// GitHub issue #21 - "pick a ceremony and date in one click on a 7-day
	// table showing every service as a row" was hard to read, especially
	// on mobile with several services. Replaced with: pick a ceremony from
	// a card grid, then a single-service month calendar appears below it.
	// (GitHub issue #38 briefly split these into two separate steps;
	// GitHub issue #42 merged them back into one view.)
	function serviceDurationLabel( svc ) {
		var d = Math.max( 1, svc.duration_days );
		return d + ( d > 1 ? ' days' : ' day' );
	}

	function monthBoundsFromOffset( offset ) {
		var t     = nlToday();
		var first = new Date( t.getFullYear(), t.getMonth() + offset, 1 );
		var last  = new Date( t.getFullYear(), t.getMonth() + offset + 1, 0 );
		return { first: first, last: last };
	}

	function renderServicePicker() {
		var loc     = getLocation( state.locationId );
		var service = getService( state.serviceId );

		var cards = state.services.map( function ( svc ) {
			var selected = svc.id === state.serviceId;
			return '<div class="tc-svc-card' + ( selected ? ' selected' : '' ) + '" data-svc-select="' + svc.id + '">' +
				'<div class="svc-name">' + escapeHtml( svc.name ) + '</div>' +
				'<div class="svc-meta"><span class="svc-duration">' + serviceDurationLabel( svc ) + '</span><span class="svc-price">' + fmt( svc.price ) + '</span></div>' +
				'</div>';
		} ).join( '' );

		return '<p class="tc-eyebrow">' + stepLabel( 'service' ) + '</p>' +
			'<h2 class="tc-title">Availability at ' + escapeHtml( loc ? loc.name.split( ' (' )[ 0 ] : '' ) + '</h2>' +
			'<p class="tc-sub">First choose a ceremony. Its available dates will appear directly below.</p>' +
			'<p class="tc-section-label">Choose a ceremony</p>' +
			'<div class="tc-svc-cards">' + cards + '</div>' +
			( service ? '<p class="tc-cal-heading">Available dates for ' + escapeHtml( service.name ) + '</p>' +
				'<p class="tc-sub">Select an open day to continue.</p>' +
				renderAvailabilityCalendar( service ) : '' ) +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">← Back</button><span></span></div>';
	}

	function renderAvailabilityCalendar( service ) {
		var bounds    = monthBoundsFromOffset( state.monthOffset );
		var monthName = bounds.first.toLocaleDateString( 'en-US', { month: 'long', year: 'numeric' } );
		var firstDow  = ( bounds.first.getDay() + 6 ) % 7; // Monday-first
		var daysInMo  = bounds.last.getDate();
		var today     = nlToday();

		var cells = '';
		for ( var i = 0; i < firstDow; i++ ) {
			cells += '<div class="tc-avail-day empty"></div>';
		}
		for ( var d = 1; d <= daysInMo; d++ ) {
			var dateObj = new Date( bounds.first.getFullYear(), bounds.first.getMonth(), d );
			var iso     = isoDate( dateObj );
			var cell    = null;
			state.grid.forEach( function ( g ) { if ( g.date === iso ) cell = g; } );
			var status    = cell ? cell.status : 'off';
			var isPast    = dateObj < today;
			var clickable = ! isPast && 'off' !== status;
			var cls       = isPast ? 'past' : status;
			if ( iso === state.date ) cls += ' selected';
			var label = 'available' === status ? 'Open' : ( 'limited' === status ? 'Almost full' : 'Closed' );
			var remainingSub = ( cell && null !== cell.remaining && undefined !== cell.remaining && 'limited' === status )
				? '<span class="rem">' + cell.remaining + ' left</span>' : '';
			cells += '<div class="tc-avail-day ' + cls + '"' + ( clickable ? ' data-date="' + iso + '"' : '' ) + '>' +
				'<span class="d">' + d + '</span>' + ( isPast ? '' : '<span class="status">' + label + '</span>' + remainingSub ) + '</div>';
		}

		var minMonth = 0;
		var maxMonth = 6; // ~6 months ahead, same booking-horizon idea as the old 7-week cap

		return '<div class="tc-grid-nav"><button type="button" id="tc-prev-month"' + ( state.monthOffset <= minMonth ? ' disabled' : '' ) + '>\u2190</button>' +
			'<span class="range">' + monthName + '</span><button type="button" id="tc-next-month"' + ( state.monthOffset >= maxMonth ? ' disabled' : '' ) + '>\u2192</button></div>' +
			( state.gridLoading ? '<p>Loading availability\u2026</p>' :
				'<div class="tc-avail-cal">' +
				[ 'Mon', 'Tue', 'Wed', 'Thu', 'Fri', 'Sat', 'Sun' ].map( function ( l ) { return '<div class="tc-avail-dow">' + l + '</div>'; } ).join( '' ) +
				cells + '</div>' ) +
			'<div class="tc-legend"><span><span class="tc-swatch" style="background:var(--available)"></span>Available</span>' +
			'<span><span class="tc-swatch" style="background:var(--limited)"></span>Almost full</span>' +
			'<span><span class="tc-swatch" style="background:var(--unavailable)"></span>Not available</span></div>';
	}

	function renderParty() {
		var service = getService( state.serviceId );
		if ( ! service ) return '<p>Please go back and pick a date.</p>';
		var max = partySizeMax();
		if ( state.partySize > max ) {
			state.partySize = max;
			state.guests.length = Math.max( 0, max - 1 );
		}

		var limited = max < Math.max( 1, service.max_capacity );
		return '<p class="tc-eyebrow">' + stepLabel( 'party' ) + '</p><h2 class="tc-title">How many people are you bringing?</h2>' +
			'<p class="tc-sub">Includes you — up to ' + max + ' ' + ( 1 === max ? 'person' : 'people' ) + ' total for this ceremony' +
			( limited ? ' (limited availability on this date)' : '' ) + '. The base price is charged per person.</p>' +
			'<div class="tc-party-row"><div><div class="en">Total in your group</div>' +
			'<div class="ep">' + fmt( service.price ) + ' per person</div></div>' +
			'<div class="tc-qty"><button type="button" id="tc-party-minus"' + ( state.partySize <= 1 ? ' disabled' : '' ) + '>−</button>' +
			'<span class="val">' + state.partySize + '</span>' +
			'<button type="button" id="tc-party-plus"' + ( state.partySize >= max ? ' disabled' : '' ) + '>+</button></div></div>' +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">← Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}

	function guestField( idx, key, label, value, placeholder, type ) {
		var errKey = 'guest-' + idx + '-' + key;
		var err    = state.fieldErrors[ errKey ];
		return '<div class="tc-field' + ( err ? ' invalid' : '' ) + '"><label>' + label + '</label>' +
			'<input type="' + ( type || 'text' ) + '" data-guest-index="' + idx + '" data-guest-field="' + key + '" value="' + escapeAttr( value ) + '" placeholder="' + placeholder + '">' +
			( err ? '<div class="tc-field-error">' + escapeHtml( err ) + '</div>' : '' ) + '</div>';
	}

	function renderGuests() {
		var count  = Math.max( 0, state.partySize - 1 );
		var blocks = '';
		for ( var i = 0; i < count; i++ ) {
			var g = state.guests[ i ] || { name: '', email: '', phone: '' };
			blocks += '<div class="tc-guest-block">' +
				'<p class="tc-guest-heading">Guest ' + ( i + 1 ) + '</p>' +
				guestField( i, 'name', 'Full name', g.name, 'Guest name' ) +
				guestField( i, 'email', 'Email', g.email, 'guest@example.com', 'email' ) +
				guestField( i, 'phone', 'Phone (optional)', g.phone, '+31 6 12345678', 'tel' ) +
				'</div>';
		}

		return '<p class="tc-eyebrow">' + stepLabel( 'guests' ) + '</p><h2 class="tc-title">Your group’s details</h2>' +
			'<p class="tc-sub">We need contact details for everyone joining you, so we can reach them if needed.</p>' +
			blocks +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">← Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}

	// GitHub issue #48 - an extra with "limit by seats" ticked (admin: Service
	// edit screen, extras repeater) can't be bought in a quantity greater than
	// the number of people in the booking. partyMultiplier() already resolves
	// that to 1 for services that don't use "bring anyone with you", so a
	// solo (non-party) booking correctly caps a seat-limited extra at 1.
	// Extras without the checkbox still just use their own configured Max
	// qty, unchanged. Re-validated server-side in create_booking() too -
	// never trust this cap alone.
	function extraEffectiveMax( e ) {
		return e.limit_by_seats ? Math.min( e.max, partyMultiplier() ) : e.max;
	}

	// GitHub issue #53 - small circle-i icon next to the price/max line,
	// opens the extra's description in a popup (renderExtraInfoModal())
	// instead of always showing it inline.
	var INFO_ICON = '<svg width="16" height="16" viewBox="0 0 16 16" fill="none" aria-hidden="true">' +
		'<circle cx="8" cy="8" r="7" stroke="currentColor" stroke-width="1.3"></circle>' +
		'<rect x="7.3" y="6.8" width="1.4" height="4.6" rx="0.7" fill="currentColor"></rect>' +
		'<circle cx="8" cy="4.9" r="0.9" fill="currentColor"></circle></svg>';

	function renderExtras() {
		var service = getService( state.serviceId );
		if ( ! service ) return '<p>Please go back and pick a date.</p>';
		// GitHub issue #53 - full-width title on its own line, then a second
		// row with price/max (+ info icon for the description, moved out of
		// the inline text) on the left and the qty stepper on the right.
		var rows = service.extras.length === 0
			? '<p style="color:var(--ink-soft);font-size:14px">No extras for this ceremony.</p>'
			: service.extras.map( function ( e ) {
				var max = extraEffectiveMax( e );
				var qty = Math.min( state.extraQty[ e.key ] || 0, max );
				state.extraQty[ e.key ] = qty;
				return '<div class="tc-extra-row">' +
					'<div class="en">' + escapeHtml( e.label ) + '</div>' +
					'<div class="tc-extra-meta">' +
					'<div class="ep">' + fmt( e.price ) + ' each \u00b7 max ' + max +
					( e.description ? '<button type="button" class="tc-info-btn" data-info="' + e.key + '" aria-label="More info about ' + escapeAttr( e.label ) + '">' + INFO_ICON + '</button>' : '' ) +
					'</div>' +
					'<div class="tc-qty"><button data-extra="' + e.key + '" data-dir="-1"' + ( 0 === qty ? ' disabled' : '' ) + '>\u2212</button>' +
					'<span class="val">' + qty + '</span>' +
					'<button data-extra="' + e.key + '" data-dir="1"' + ( qty >= max ? ' disabled' : '' ) + '>+</button></div>' +
					'</div></div>';
			} ).join( '' );

		return '<p class="tc-eyebrow">' + stepLabel( 'extras' ) + '</p><h2 class="tc-title">Extras &amp; quantity</h2>' + selectionSummary() + rows +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">← Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}

	function renderInfo() {
		var i = state.info;
		return '<p class="tc-eyebrow">' + stepLabel( 'details' ) + '</p><h2 class="tc-title">Your details</h2>' +
			field( 'firstName', 'First name', i.firstName, 'Jane' ) +
			field( 'lastName', 'Last name', i.lastName, 'Doe' ) +
			field( 'email', 'Email', i.email, 'jane@example.com', 'email' ) +
			field( 'phone', 'Phone', i.phone, '+31 6 12345678', 'tel' ) +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">← Back</button><button class="tc-btn primary" id="tc-next">Continue</button></div>';
	}
	function field( id, label, value, placeholder, type ) {
		var err = state.fieldErrors[ id ];
		return '<div class="tc-field' + ( err ? ' invalid' : '' ) + '"><label>' + label + '</label>' +
			'<input type="' + ( type || 'text' ) + '" id="tc-' + id + '" value="' + escapeAttr( value ) + '" placeholder="' + placeholder + '">' +
			( err ? '<div class="tc-field-error">' + escapeHtml( err ) + '</div>' : '' ) + '</div>';
	}

	function renderReview() {
		var service = getService( state.serviceId );
		var loc     = getLocation( state.locationId );
		var dateStr = parseDateStr( state.date ).toLocaleDateString( 'en-US', { weekday: 'long', day: 'numeric', month: 'long', year: 'numeric' } );
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

		var bookerName  = ( state.info.firstName + ' ' + state.info.lastName ).trim();
		var bookerLines = '<div class="tc-rline"><span class="l">Booked by</span><span>' + escapeHtml( bookerName || '—' ) + '</span></div>' +
			'<div class="tc-rline"><span class="l">Email</span><span>' + escapeHtml( state.info.email ) + '</span></div>' +
			'<div class="tc-rline"><span class="l">Phone</span><span>' + escapeHtml( state.info.phone ) + '</span></div>';

		return '<p class="tc-eyebrow">' + stepLabel( 'review' ) + '</p><h2 class="tc-title">Review your booking</h2>' +
			'<div class="tc-rline"><span class="l">Location</span><span>' + escapeHtml( loc.name ) + '</span></div>' +
			( state.guide ? '<div class="tc-rline"><span class="l">Guide</span><span>' + escapeHtml( state.guide.name ) + '</span></div>' : '' ) +
			'<div class="tc-rline"><span class="l">Ceremony</span><span>' + escapeHtml( service.name ) + '</span></div>' +
			'<div class="tc-rline"><span class="l">Date</span><span>' + dateStr + '</span></div>' +
			bookerLines +
			( isParty ? '<div class="tc-rline"><span class="l">Group size</span><span>' + state.partySize + '</span></div>' : '' ) +
			guestLines +
			basePriceLine +
			extraLines +
			'<div class="tc-rline total"><span class="l">Total</span><span class="r">' + fmt( grandTotal() ) + '</span></div>' +
			'<div class="tc-nav"><button class="tc-btn ghost" id="tc-back">← Back</button>' +
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

		root.querySelectorAll( '[data-photo-zoom]' ).forEach( function ( el ) {
			el.onclick = function () {
				state.lightboxUrl = el.dataset.photoZoom;
				render();
			};
		} );
		var lightbox = document.getElementById( 'tc-lightbox-overlay' );
		if ( lightbox ) {
			lightbox.onclick = function ( e ) {
				if ( e.target === lightbox || e.target.classList.contains( 'tc-lightbox-close' ) ) {
					state.lightboxUrl = null;
					render();
				}
			};
		}

		// GitHub issue #36 - "Read more" opens the full guide bio as a popup.
		var guideReadMore = document.getElementById( 'tc-guide-readmore' );
		if ( guideReadMore ) guideReadMore.onclick = function () { state.guideInfoOpen = true; render(); };
		var guideModal = document.getElementById( 'tc-guide-modal' );
		if ( guideModal ) {
			guideModal.onclick = function ( e ) {
				if ( e.target === guideModal || e.target.id === 'tc-guide-modal-close' ) {
					state.guideInfoOpen = false;
					render();
				}
			};
		}

		// GitHub issue #53 - the (i) button on an extra opens its
		// description in a popup instead of showing it inline.
		root.querySelectorAll( '[data-info]' ).forEach( function ( el ) {
			el.onclick = function () { state.extraInfoKey = el.dataset.info; render(); };
		} );
		var extraInfoModal = document.getElementById( 'tc-extra-info-modal' );
		if ( extraInfoModal ) {
			extraInfoModal.onclick = function ( e ) {
				if ( e.target === extraInfoModal || e.target.id === 'tc-extra-info-modal-close' || e.target.id === 'tc-extra-info-modal-close-btn' ) {
					state.extraInfoKey = null;
					render();
				}
			};
		}

		var prevMonth = document.getElementById( 'tc-prev-month' );
		if ( prevMonth ) prevMonth.onclick = function () { state.monthOffset = Math.max( 0, state.monthOffset - 1 ); loadGrid(); };
		var nextMonth = document.getElementById( 'tc-next-month' );
		if ( nextMonth ) nextMonth.onclick = function () { state.monthOffset = Math.min( 6, state.monthOffset + 1 ); loadGrid(); };

		// GitHub issue #21 - picking a ceremony card no longer implies a
		// date; it just swaps which service's calendar is shown below, and
		// stays on this step (GitHub issue #42 - service cards and the
		// calendar are back on one view together).
		root.querySelectorAll( '[data-svc-select]' ).forEach( function ( el ) {
			el.onclick = function () {
				var id = parseInt( el.dataset.svcSelect, 10 );
				if ( id === state.serviceId ) return;
				state.serviceId  = id;
				state.date       = null;
				state.monthOffset = 0;
				state.extraQty   = {};
				state.partySize  = 1;
				state.guests     = [];
				loadGrid();
			};
		} );

		root.querySelectorAll( '.tc-avail-day[data-date]' ).forEach( function ( el ) {
			el.onclick = function () {
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
				v = Math.max( 0, Math.min( extraEffectiveMax( extra ), v ) );
				state.extraQty[ key ] = v;
				render();
			};
		} );

		root.querySelectorAll( '[data-guest-field]' ).forEach( function ( el ) {
			el.oninput = function () {
				var idx = parseInt( el.dataset.guestIndex, 10 );
				var key = el.dataset.guestField;
				if ( 'phone' === key ) {
					el.value = sanitizePhoneInput( el.value );
				}
				if ( ! state.guests[ idx ] ) state.guests[ idx ] = { name: '', email: '', phone: '' };
				state.guests[ idx ][ key ] = el.value;
				delete state.fieldErrors[ 'guest-' + idx + '-' + key ];
			};
		} );

		[ 'firstName', 'lastName', 'email', 'phone' ].forEach( function ( f ) {
			var el = document.getElementById( 'tc-' + f );
			if ( el ) el.oninput = function () {
				if ( 'phone' === f ) {
					el.value = sanitizePhoneInput( el.value );
				}
				state.info[ f ] = el.value;
				delete state.fieldErrors[ f ];
			};
		} );

		var checkout = document.getElementById( 'tc-checkout' );
		if ( checkout ) checkout.onclick = submitBooking;
	}

	function adjustPartySize( dir ) {
		var max = partySizeMax();
		var v    = Math.max( 1, Math.min( max, state.partySize + dir ) );
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
		state.guideInfoOpen = false;
		render();
	}

	function loadGrid() {
		if ( ! state.serviceId ) return;
		var requestedService = state.serviceId; // guard against a stale response landing after the customer picked a different card
		state.gridLoading = true;
		render();
		var bounds = monthBoundsFromOffset( state.monthOffset );

		apiGet( '/availability?service_id=' + requestedService + '&location_id=' + state.locationId +
			'&start=' + isoDate( bounds.first ) + '&end=' + isoDate( bounds.last ) )
			.then( function ( rows ) {
				if ( requestedService !== state.serviceId ) return;
				state.grid        = rows;
				state.gridLoading = false;
				render();
			} )
			.catch( function ( err ) {
				if ( requestedService !== state.serviceId ) return;
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
	// GitHub issue #7 - strips anything that isn't a digit, space, or one of
	// +()- as the customer types, so letters simply can't end up in a phone
	// field in the first place.
	function sanitizePhoneInput( v ) {
		return v.replace( /[^0-9+()\-\s]/g, '' );
	}

	document.addEventListener( 'keydown', function ( e ) {
		if ( 'Escape' !== e.key ) return;
		if ( state.lightboxUrl ) {
			state.lightboxUrl = null;
			render();
		} else if ( state.guideInfoOpen ) {
			state.guideInfoOpen = false;
			render();
		} else if ( state.extraInfoKey ) {
			state.extraInfoKey = null;
			render();
		}
	} );

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
