<?php
/**
 * Computes availability for a service+location across a date range.
 *
 * This is the piece flagged throughout scoping as the hardest, most
 * bug-prone part of the build - conflict-free scheduling. Kept in one place
 * so both the customer-facing grid and the booking-creation endpoint use the
 * exact same logic; the grid must never promise something booking can't
 * deliver.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Availability {

	public static function init() {
		// No hooks of its own - called directly by TC_Rest_Api.
	}

	/**
	 * @param int    $service_id
	 * @param int    $location_id
	 * @param string $start_date  Y-m-d
	 * @param string $end_date    Y-m-d
	 * @return array[] [ ['date' => 'Y-m-d', 'status' => 'available'|'limited'|'off', 'price' => float, 'remaining' => int|null], ... ]
	 */
	public static function get_grid( $service_id, $location_id, $start_date, $end_date ) {
		$service = self::get_service_data( $service_id );
		if ( ! $service ) {
			return array();
		}

		$guide_ids = self::get_guides_for( $location_id, $service_id );
		$grid      = array();

		$cursor = new DateTime( $start_date );
		$end    = new DateTime( $end_date );

		while ( $cursor <= $end ) {
			$date_str      = $cursor->format( 'Y-m-d' );
			$status_remain = self::get_date_status_and_remaining( $service, $guide_ids, $date_str, $location_id );
			$grid[]        = array(
				'date'      => $date_str,
				'status'    => $status_remain['status'],
				'price'     => $service['price'],
				'remaining' => $status_remain['remaining'],
			);
			$cursor->modify( '+1 day' );
		}

		return $grid;
	}

	/**
	 * Single source of truth for "can this actually be booked" - used again,
	 * server-side, at the moment a booking is submitted. The grid can go
	 * briefly stale between page load and checkout; this re-check cannot.
	 */
	public static function is_bookable( $service_id, $location_id, $date_str ) {
		$service   = self::get_service_data( $service_id );
		$guide_ids = self::get_guides_for( $location_id, $service_id );
		if ( ! $service || empty( $guide_ids ) ) {
			return false;
		}
		return 'off' !== self::get_date_status( $service, $guide_ids, $date_str, $location_id );
	}

	/**
	 * Picks a specific guide to assign to a new booking on a given date -
	 * the first covering guide with enough room for this booking, so
	 * multi-guide locations distribute bookings rather than always hitting
	 * the same one.
	 *
	 * Exclusive services (see is_exclusive()) keep the original
	 * all-or-nothing rule: a guide is only eligible if nothing at all is
	 * booked with them that day - this is also what a private booking with
	 * "Bring anyone with you" needs, e.g. a party of 3 out of a Max
	 * capacity of 4 that must NOT leave the 4th seat open to a stranger.
	 * Shared services instead check whether a guide has enough REMAINING
	 * seats for this specific party - without this branch (GitHub issue
	 * #18), a shared service behaved like an exclusive one: the first
	 * booking of any size closed the whole date instead of just using up
	 * its own seats and leaving the rest open, even though
	 * get_date_status() (the grid) already promised "limited" availability
	 * remained. Mirrors get_date_status()'s own branching so the grid and
	 * the actual assignment agree.
	 *
	 * @return int 0 if none available.
	 */
	public static function pick_guide( $service_id, $location_id, $date_str, $party_size = 1 ) {
		$service      = self::get_service_data( $service_id );
		$guide_ids    = self::get_guides_for( $location_id, $service_id );
		$exclusive    = self::is_exclusive( $service );
		$max_capacity = max( 1, (int) $service['max_capacity'] );
		$party_size   = max( 1, (int) $party_size );

		foreach ( $guide_ids as $guide_id ) {
			if ( ! self::guide_available_on( $guide_id, $service, $date_str, $location_id ) ) {
				continue;
			}
			if ( $exclusive ) {
				if ( 0 === self::get_party_size_booked( $guide_id, $service['id'], $date_str ) ) {
					return $guide_id;
				}
				continue;
			}
			$remaining = $max_capacity - self::get_party_size_booked( $guide_id, $service['id'], $date_str );
			if ( $remaining >= $party_size ) {
				return $guide_id;
			}
		}
		return 0;
	}

	/**
	 * A service is "exclusive" - one booking closes the whole date to
	 * everyone else, regardless of how many of its seats that booking
	 * actually used - when either it only has one seat total, or the admin
	 * hasn't explicitly turned on "Allow sharing remaining seats". Sharing
	 * is opt-in: a private booking for up to N people (via "Bring anyone
	 * with you") should not, by default, leave its unused seats open to a
	 * stranger just because Max capacity was set higher than the actual
	 * party size.
	 */
	private static function is_exclusive( $service ) {
		$max_capacity = max( 1, (int) $service['max_capacity'] );
		return 1 === $max_capacity || empty( $service['allow_shared_seats'] );
	}

	private static function get_date_status( $service, $guide_ids, $date_str, $location_id = 0 ) {
		return self::get_date_status_and_remaining( $service, $guide_ids, $date_str, $location_id )['status'];
	}

	/**
	 * Same result as get_date_status(), plus how many seats are actually
	 * left for a shared service on this date - null for exclusive services,
	 * where a seat count doesn't mean anything (GitHub issue #20: the
	 * booking widget capped "how many people are you bringing" at the
	 * service's static Max capacity instead of what's actually left for the
	 * specific date, and there was no way to show remaining seats on the
	 * calendar at all).
	 *
	 * Mirrors pick_guide()'s own guide-selection order (first covering
	 * guide with room wins) so the number shown here always matches
	 * whichever guide would actually be assigned if the customer books.
	 *
	 * @return array{status:string,remaining:?int}
	 */
	private static function get_date_status_and_remaining( $service, $guide_ids, $date_str, $location_id = 0 ) {
		if ( empty( $guide_ids ) ) {
			return array( 'status' => 'off', 'remaining' => null );
		}

		if ( self::is_exclusive( $service ) ) {
			foreach ( $guide_ids as $guide_id ) {
				if ( self::guide_is_free( $guide_id, $service, $date_str, $location_id ) ) {
					return array( 'status' => 'available', 'remaining' => null );
				}
			}
			return array( 'status' => 'off', 'remaining' => null );
		}

		// Shared service: sum party size already booked across all covering
		// guides for this date (a group session is one guide, one date - but
		// we don't yet know which guide will end up assigned, so we check
		// whether ANY covering guide has room).
		$max_capacity = max( 1, (int) $service['max_capacity'] );
		foreach ( $guide_ids as $guide_id ) {
			if ( ! self::guide_available_on( $guide_id, $service, $date_str, $location_id ) ) {
				continue;
			}
			$used      = self::get_party_size_booked( $guide_id, $service['id'], $date_str );
			$remaining = max( 0, $max_capacity - $used );
			if ( $remaining >= $max_capacity ) {
				return array( 'status' => 'available', 'remaining' => $remaining );
			}
			if ( $remaining > 0 ) {
				return array( 'status' => 'limited', 'remaining' => $remaining );
			}
		}
		return array( 'status' => 'off', 'remaining' => 0 );
	}

	private static function guide_is_free( $guide_id, $service, $date_str, $location_id = 0 ) {
		if ( ! self::guide_available_on( $guide_id, $service, $date_str, $location_id ) ) {
			return false;
		}
		return 0 === self::get_party_size_booked( $guide_id, $service['id'], $date_str );
	}

	/**
	 * True if the guide has no explicit 'blocked' override, and no existing
	 * booking (of any service) whose span overlaps this service's span
	 * starting on $date_str. Multi-day services (duration_days > 1) block
	 * every day in their span, not just the start date.
	 *
	 * $location_id only matters for special services (GitHub issue #71) -
	 * see the special-dates check below. Regular services don't need it
	 * here at all (a regular booking already blocks a guide everywhere,
	 * not just at one location - see the file-level comment on
	 * get_guides_for() about this deliberately not being location-scoped).
	 */
	private static function guide_available_on( $guide_id, $service, $date_str, $location_id = 0 ) {
		global $wpdb;

		$duration = max( 1, (int) $service['duration_days'] );
		$start    = new DateTime( $date_str );
		$span_end = ( clone $start )->modify( '+' . ( $duration - 1 ) . ' days' );

		// 1. Explicit guide-set blocks. A row for any day within the span with
		// status 'blocked' and no overriding 'available' row on that same day
		// makes the whole span unavailable for this guide.
		$table = $wpdb->prefix . 'tc_guide_availability';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT availability_date, status FROM {$table}
				 WHERE guide_id = %d AND availability_date BETWEEN %s AND %s",
				$guide_id,
				$start->format( 'Y-m-d' ),
				$span_end->format( 'Y-m-d' )
			)
		);
		foreach ( $rows as $row ) {
			if ( 'blocked' === $row->status ) {
				return false;
			}
		}

		// 1b. Special services (GitHub issue #71) - a guide is only
		// "available" for a special service on dates they've explicitly
		// opted into offering it, at this exact location (see
		// guide_offers_special_on() - special dates are recorded per
		// service+location, since the same rare service can happen at
		// different locations on different dates). The reverse also
		// applies: opting into a special date at all (any location) takes
		// the guide off the board for every OTHER, regular service that
		// same day, even before anyone's actually booked it - "once he
		// selected those dates ... other normal services wont be
		// available on that day," per the same issue.
		if ( ! empty( $service['is_special'] ) ) {
			if ( ! self::guide_offers_special_on( $guide_id, $service['id'], $location_id, $date_str ) ) {
				return false;
			}
		} elseif ( self::guide_has_special_date_on( $guide_id, $date_str ) ) {
			return false;
		}

		// 2. Existing bookings for this guide that overlap the span, across
		// ANY service (a guide can only be in one place at a time).
		//
		// GitHub issue #70 - p.post_status = 'publish' matters here: deleting
		// a booking from wp-admin moves it to Trash first (post_status
		// 'trash'), it isn't gone from wp_posts until the trash is emptied.
		// Without this filter a trashed booking kept blocking its date right
		// up until permanent deletion, even though _tc_status itself was
		// never touched by trashing (that's a separate, deliberate "soft
		// cancelled" state tracked via meta - see the NOT IN ('cancelled')
		// clause below, which is unaffected by this fix). Both a trashed
		// post and a 'cancelled' status must be excluded independently.
		$booking_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm_date.meta_value AS booking_date, pm_service.meta_value AS booking_service_id
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_guide ON pm_guide.post_id = p.ID AND pm_guide.meta_key = '_tc_guide_id'
				 INNER JOIN {$wpdb->postmeta} pm_date ON pm_date.post_id = p.ID AND pm_date.meta_key = '_tc_date'
				 INNER JOIN {$wpdb->postmeta} pm_service ON pm_service.post_id = p.ID AND pm_service.meta_key = '_tc_service_id'
				 INNER JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_tc_status'
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm_guide.meta_value = %d AND pm_status.meta_value NOT IN ('cancelled')",
				TC_CPT::BOOKING,
				$guide_id
			)
		);

		foreach ( $booking_rows as $row ) {
			// A shared service's own existing bookings on this exact same
			// date aren't a real scheduling conflict - that's what "seats"
			// means, and it's governed separately by get_party_size_booked()
			// / max_capacity (see is_exclusive()). Without this exemption,
			// the very first booking of a shared service made this function
			// return false for every subsequent one regardless of remaining
			// capacity, since their date ranges trivially "overlap" by being
			// the same date - this was the actual bug behind GitHub issue
			// #19 ("booked 2 of 4 seats, but the date shows fully booked").
			// A genuinely different service, or a different start date of
			// this same service (e.g. two overlapping multi-day sessions),
			// still counts as the guide being busy and must still block.
			if ( (int) $row->booking_service_id === (int) $service['id']
				&& $row->booking_date === $date_str
				&& ! self::is_exclusive( $service )
			) {
				continue;
			}

			$other_service  = self::get_service_data( (int) $row->booking_service_id );
			$other_duration = $other_service ? max( 1, (int) $other_service['duration_days'] ) : 1;
			$other_start    = new DateTime( $row->booking_date );
			$other_end      = ( clone $other_start )->modify( '+' . ( $other_duration - 1 ) . ' days' );

			// Overlap test between [start, span_end] and [other_start, other_end].
			if ( $start <= $other_end && $other_start <= $span_end ) {
				return false;
			}
		}

		return true;
	}

	/**
	 * A guide's full special-dates roster - each entry
	 * {service_id:int, location_id:int, date:'Y-m-d'} records one date
	 * they've opted into offering one special service at one location.
	 * One flat post meta value (matches how _tc_selected_extras etc. store
	 * an array, not one meta row per entry) rather than a new DB table -
	 * this is small, admin-managed data, not something queried across all
	 * guides the way bookings/regular availability rows are.
	 *
	 * Public since TC_Meta_Boxes reads/writes this too (rendering and
	 * saving the Guide edit screen's special-dates calendars).
	 */
	public static function guide_special_dates( $guide_id ) {
		$rows = get_post_meta( $guide_id, '_tc_special_dates', true );
		return is_array( $rows ) ? $rows : array();
	}

	private static function guide_offers_special_on( $guide_id, $service_id, $location_id, $date_str ) {
		foreach ( self::guide_special_dates( $guide_id ) as $row ) {
			if ( (int) $row['service_id'] === (int) $service_id
				&& (int) $row['location_id'] === (int) $location_id
				&& $row['date'] === $date_str
			) {
				return true;
			}
		}
		return false;
	}

	/**
	 * Any special date at all, regardless of which service/location -
	 * used to close a guide's REGULAR availability on a day they've
	 * committed to a special service somewhere. See the matching comment
	 * in guide_available_on().
	 */
	private static function guide_has_special_date_on( $guide_id, $date_str ) {
		foreach ( self::guide_special_dates( $guide_id ) as $row ) {
			if ( $row['date'] === $date_str ) {
				return true;
			}
		}
		return false;
	}

	private static function get_party_size_booked( $guide_id, $service_id, $date_str ) {
		global $wpdb;
		// GitHub issue #70 - see the matching comment in guide_available_on()
		// above: p.post_status = 'publish' excludes trashed bookings, which
		// otherwise kept counting toward booked capacity until permanently
		// deleted from Trash.
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(CAST(pm_party.meta_value AS UNSIGNED))
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_guide ON pm_guide.post_id = p.ID AND pm_guide.meta_key = '_tc_guide_id'
				 INNER JOIN {$wpdb->postmeta} pm_service ON pm_service.post_id = p.ID AND pm_service.meta_key = '_tc_service_id'
				 INNER JOIN {$wpdb->postmeta} pm_date ON pm_date.post_id = p.ID AND pm_date.meta_key = '_tc_date'
				 INNER JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_tc_status'
				 LEFT JOIN {$wpdb->postmeta} pm_party ON pm_party.post_id = p.ID AND pm_party.meta_key = '_tc_party_size'
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm_guide.meta_value = %d AND pm_service.meta_value = %d
				   AND pm_date.meta_value = %s AND pm_status.meta_value NOT IN ('cancelled')",
				TC_CPT::BOOKING,
				$guide_id,
				$service_id,
				$date_str
			)
		);
		return (int) $total;
	}

	/**
	 * @return int[] Guide post IDs covering this location AND this service.
	 */
	private static function get_guides_for( $location_id, $service_id ) {
		// WPML support: kept as a defensive layer, though it's a no-op in
		// practice now - Location, Service, and Guide are all
		// non-translatable (wpml-config.xml), so none of them duplicate
		// posts per language, meaning $location_id/$service_id are already
		// the single canonical ID regardless of which language the
		// request is in. See PROJECT_NOTES.md's "WPML support" section for
		// why full "Translatable" mode was wrong for Guide specifically
		// (it broke guide-dashboard calendar syncing across languages).
		$location_id = TC_WPML::to_default_language_id( $location_id, TC_CPT::LOCATION );
		$service_id  = TC_WPML::to_default_language_id( $service_id, TC_CPT::SERVICE );
		$guides = get_posts(
			array(
				'post_type'   => TC_CPT::GUIDE,
				'numberposts' => -1,
				'meta_query'  => array(
					array(
						'key'   => '_tc_location_ids',
						'value' => (int) $location_id,
					),
					array(
						'key'   => '_tc_service_ids',
						'value' => (int) $service_id,
					),
				),
			)
		);
		return wp_list_pluck( $guides, 'ID' );
	}

	/**
	 * GitHub issue #66 - the Location step's service picker was showing
	 * every service regardless of whether any guide actually covers it at
	 * the chosen location; used by TC_Rest_Api::get_services() to filter
	 * the catalog down to what's actually offered there. Thin public
	 * wrapper so get_guides_for() (the guide-matching logic every
	 * availability/booking check already shares) stays the single source
	 * of truth rather than being duplicated.
	 *
	 * @return bool
	 */
	public static function service_available_at_location( $location_id, $service_id ) {
		return (bool) self::get_guides_for( $location_id, $service_id );
	}

	/**
	 * @return array{id:int,price:float,duration_days:int,min_capacity:int,max_capacity:int,extras:array}|null
	 */
	public static function get_service_data( $service_id ) {
		$post = get_post( $service_id );
		if ( ! $post || TC_CPT::SERVICE !== $post->post_type ) {
			return null;
		}
		$extras = get_post_meta( $service_id, '_tc_extras', true );
		return array(
			'id'                 => $post->ID,
			'name'               => $post->post_title,
			'price'              => (float) get_post_meta( $service_id, '_tc_price', true ),
			'duration_days'      => (int) get_post_meta( $service_id, '_tc_duration_days', true ) ?: 1,
			'start_time'         => get_post_meta( $service_id, '_tc_start_time', true ),
			'min_capacity'       => (int) get_post_meta( $service_id, '_tc_min_capacity', true ) ?: 1,
			'max_capacity'       => (int) get_post_meta( $service_id, '_tc_max_capacity', true ) ?: 1,
			'allow_party'        => (bool) get_post_meta( $service_id, '_tc_allow_party', true ),
			'allow_shared_seats' => (bool) get_post_meta( $service_id, '_tc_allow_shared_seats', true ),
			'extras'             => is_array( $extras ) ? $extras : array(),
			// GitHub issues #71/#72 - a "special service" (happens once or
			// twice a month, e.g.) only opens on dates a guide has
			// explicitly opted into offering it, rather than the normal
			// blocked/booked-conflict calendar - see guide_available_on().
			// special_color is purely cosmetic (the booking widget's
			// calendar), defaulting to '' so the front-end falls back to
			// its normal --available color rather than an empty swatch.
			'is_special'         => (bool) get_post_meta( $service_id, '_tc_is_special', true ),
			'special_color'      => (string) get_post_meta( $service_id, '_tc_special_color', true ),
		);
	}

	/* ------------------------------------------------------------------ */
	/* A guide's own wp_tc_guide_availability exceptions + real bookings.   */
	/* Moved here from TC_Rest_Api (were private helpers on that class) so */
	/* TC_Meta_Boxes could reuse them too, once GitHub feedback moved the   */
	/* admin-side save off AJAX and onto the Guide post's own save_post -   */
	/* meta-box code depending on REST-class internals would have been a   */
	/* backwards, confusing dependency. This class is already this         */
	/* plugin's single choke-point for availability/booking logic          */
	/* (see this file's own top-of-file warning), so these belong here.    */
	/* ------------------------------------------------------------------ */

	public static function fetch_guide_availability( $guide_id, $start, $end ) {
		global $wpdb;
		$table = $wpdb->prefix . 'tc_guide_availability';
		$rows  = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT availability_date, status, note FROM {$table} WHERE guide_id = %d AND availability_date BETWEEN %s AND %s",
				$guide_id,
				$start,
				$end
			)
		);

		$data = array();
		foreach ( $rows as $row ) {
			$data[] = array( 'date' => $row->availability_date, 'status' => $row->status, 'note' => $row->note );
		}
		return $data;
	}

	/**
	 * This guide's actual bookings within the range, grouped by date, so
	 * the calendar (public/js/guide-dashboard.js and its admin-editing
	 * counterpart, admin/js/guide-availability.js) can show what's already
	 * booked instead of just the manually-set blocked/available exceptions
	 * from fetch_guide_availability() above - and so upsert is refused for
	 * an already-booked date (guide_has_booking_on() below), which would
	 * otherwise leave a real booking sitting on a date the calendar claims
	 * is free.
	 *
	 * p.post_status = 'publish' and excluding _tc_status = 'cancelled'
	 * mirrors guide_available_on()'s own booking query above exactly (see
	 * GitHub issue #70's fix there) - a trashed or cancelled booking must
	 * not show as "booked" here either.
	 */
	public static function fetch_guide_bookings( $guide_id, $start, $end ) {
		global $wpdb;
		$rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT pm_date.meta_value AS booking_date, pm_service.meta_value AS service_id,
				        pm_fname.meta_value AS first_name, pm_lname.meta_value AS last_name
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_guide ON pm_guide.post_id = p.ID AND pm_guide.meta_key = '_tc_guide_id'
				 INNER JOIN {$wpdb->postmeta} pm_date ON pm_date.post_id = p.ID AND pm_date.meta_key = '_tc_date'
				 INNER JOIN {$wpdb->postmeta} pm_service ON pm_service.post_id = p.ID AND pm_service.meta_key = '_tc_service_id'
				 INNER JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_tc_status'
				 LEFT JOIN {$wpdb->postmeta} pm_fname ON pm_fname.post_id = p.ID AND pm_fname.meta_key = '_tc_customer_first_name'
				 LEFT JOIN {$wpdb->postmeta} pm_lname ON pm_lname.post_id = p.ID AND pm_lname.meta_key = '_tc_customer_last_name'
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm_guide.meta_value = %d
				   AND pm_date.meta_value BETWEEN %s AND %s AND pm_status.meta_value != 'cancelled'",
				TC_CPT::BOOKING,
				$guide_id,
				$start,
				$end
			)
		);

		$by_date = array();
		foreach ( $rows as $row ) {
			if ( ! isset( $by_date[ $row->booking_date ] ) ) {
				$by_date[ $row->booking_date ] = array();
			}
			$by_date[ $row->booking_date ][] = trim(
				get_the_title( (int) $row->service_id ) . ' — ' . trim( $row->first_name . ' ' . $row->last_name )
			);
		}

		$data = array();
		foreach ( $by_date as $date => $summaries ) {
			$data[] = array( 'date' => $date, 'summary' => implode( '; ', $summaries ) );
		}
		return $data;
	}

	/** Refuses to let a date already carrying an active booking for this guide be marked as a day off. */
	public static function guide_has_booking_on( $guide_id, $date ) {
		global $wpdb;
		$count = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_guide ON pm_guide.post_id = p.ID AND pm_guide.meta_key = '_tc_guide_id'
				 INNER JOIN {$wpdb->postmeta} pm_date ON pm_date.post_id = p.ID AND pm_date.meta_key = '_tc_date'
				 INNER JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_tc_status'
				 WHERE p.post_type = %s AND p.post_status = 'publish' AND pm_guide.meta_value = %d
				   AND pm_date.meta_value = %s AND pm_status.meta_value != 'cancelled'",
				TC_CPT::BOOKING,
				$guide_id,
				$date
			)
		);
		return $count > 0;
	}

	public static function upsert_guide_availability( $guide_id, $date, $status, $note = null ) {
		global $wpdb;
		$table = $wpdb->prefix . 'tc_guide_availability';
		$now   = current_time( 'mysql' );

		$wpdb->query(
			$wpdb->prepare(
				"INSERT INTO {$table} (guide_id, availability_date, status, note, created_at, updated_at)
				 VALUES (%d, %s, %s, %s, %s, %s)
				 ON DUPLICATE KEY UPDATE status = %s, note = %s, updated_at = %s",
				$guide_id,
				$date,
				$status,
				$note,
				$now,
				$now,
				$status,
				$note,
				$now
			)
		);
	}
}
