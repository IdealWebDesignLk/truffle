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
	 * @return array[] [ ['date' => 'Y-m-d', 'status' => 'available'|'limited'|'off', 'price' => float], ... ]
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
			$date_str = $cursor->format( 'Y-m-d' );
			$grid[]   = array(
				'date'   => $date_str,
				'status' => self::get_date_status( $service, $guide_ids, $date_str ),
				'price'  => $service['price'],
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
		return 'off' !== self::get_date_status( $service, $guide_ids, $date_str );
	}

	/**
	 * Picks a specific guide to assign to a new booking on a given date -
	 * the first covering guide who is actually free, so multi-guide
	 * locations distribute bookings rather than always hitting the same one.
	 *
	 * @return int 0 if none available.
	 */
	public static function pick_guide( $service_id, $location_id, $date_str ) {
		$service   = self::get_service_data( $service_id );
		$guide_ids = self::get_guides_for( $location_id, $service_id );
		foreach ( $guide_ids as $guide_id ) {
			if ( self::guide_is_free( $guide_id, $service, $date_str ) ) {
				return $guide_id;
			}
		}
		return 0;
	}

	private static function get_date_status( $service, $guide_ids, $date_str ) {
		if ( empty( $guide_ids ) ) {
			return 'off';
		}

		$max_capacity = max( 1, (int) $service['max_capacity'] );

		if ( 1 === $max_capacity ) {
			foreach ( $guide_ids as $guide_id ) {
				if ( self::guide_is_free( $guide_id, $service, $date_str ) ) {
					return 'available';
				}
			}
			return 'off';
		}

		// Group service: sum party size already booked across all covering
		// guides for this date (a group session is one guide, one date - but
		// we don't yet know which guide will end up assigned, so we check
		// whether ANY covering guide has room).
		foreach ( $guide_ids as $guide_id ) {
			if ( ! self::guide_available_on( $guide_id, $service, $date_str ) ) {
				continue;
			}
			$used      = self::get_party_size_booked( $guide_id, $service['id'], $date_str );
			$remaining = $max_capacity - $used;
			if ( $remaining >= $max_capacity ) {
				return 'available';
			}
			if ( $remaining > 0 ) {
				return 'limited';
			}
		}
		return 'off';
	}

	private static function guide_is_free( $guide_id, $service, $date_str ) {
		if ( ! self::guide_available_on( $guide_id, $service, $date_str ) ) {
			return false;
		}
		return 0 === self::get_party_size_booked( $guide_id, $service['id'], $date_str );
	}

	/**
	 * True if the guide has no explicit 'blocked' override, and no existing
	 * booking (of any service) whose span overlaps this service's span
	 * starting on $date_str. Multi-day services (duration_days > 1) block
	 * every day in their span, not just the start date.
	 */
	private static function guide_available_on( $guide_id, $service, $date_str ) {
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

		// 2. Existing bookings for this guide that overlap the span, across
		// ANY service (a guide can only be in one place at a time).
		$booking_rows = $wpdb->get_results(
			$wpdb->prepare(
				"SELECT p.ID, pm_date.meta_value AS booking_date, pm_service.meta_value AS booking_service_id
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_guide ON pm_guide.post_id = p.ID AND pm_guide.meta_key = '_tc_guide_id'
				 INNER JOIN {$wpdb->postmeta} pm_date ON pm_date.post_id = p.ID AND pm_date.meta_key = '_tc_date'
				 INNER JOIN {$wpdb->postmeta} pm_service ON pm_service.post_id = p.ID AND pm_service.meta_key = '_tc_service_id'
				 INNER JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_tc_status'
				 WHERE p.post_type = %s AND pm_guide.meta_value = %d AND pm_status.meta_value NOT IN ('cancelled')",
				TC_CPT::BOOKING,
				$guide_id
			)
		);

		foreach ( $booking_rows as $row ) {
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

	private static function get_party_size_booked( $guide_id, $service_id, $date_str ) {
		global $wpdb;
		$total = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT SUM(CAST(pm_party.meta_value AS UNSIGNED))
				 FROM {$wpdb->posts} p
				 INNER JOIN {$wpdb->postmeta} pm_guide ON pm_guide.post_id = p.ID AND pm_guide.meta_key = '_tc_guide_id'
				 INNER JOIN {$wpdb->postmeta} pm_service ON pm_service.post_id = p.ID AND pm_service.meta_key = '_tc_service_id'
				 INNER JOIN {$wpdb->postmeta} pm_date ON pm_date.post_id = p.ID AND pm_date.meta_key = '_tc_date'
				 INNER JOIN {$wpdb->postmeta} pm_status ON pm_status.post_id = p.ID AND pm_status.meta_key = '_tc_status'
				 LEFT JOIN {$wpdb->postmeta} pm_party ON pm_party.post_id = p.ID AND pm_party.meta_key = '_tc_party_size'
				 WHERE p.post_type = %s AND pm_guide.meta_value = %d AND pm_service.meta_value = %d
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
	 * @return array{id:int,price:float,duration_days:int,min_capacity:int,max_capacity:int,extras:array}|null
	 */
	public static function get_service_data( $service_id ) {
		$post = get_post( $service_id );
		if ( ! $post || TC_CPT::SERVICE !== $post->post_type ) {
			return null;
		}
		$extras = get_post_meta( $service_id, '_tc_extras', true );
		return array(
			'id'             => $post->ID,
			'name'           => $post->post_title,
			'price'          => (float) get_post_meta( $service_id, '_tc_price', true ),
			'duration_days'  => (int) get_post_meta( $service_id, '_tc_duration_days', true ) ?: 1,
			'start_time'     => get_post_meta( $service_id, '_tc_start_time', true ),
			'min_capacity'   => (int) get_post_meta( $service_id, '_tc_min_capacity', true ) ?: 1,
			'max_capacity'   => (int) get_post_meta( $service_id, '_tc_max_capacity', true ) ?: 1,
			'extras'         => is_array( $extras ) ? $extras : array(),
		);
	}
}
