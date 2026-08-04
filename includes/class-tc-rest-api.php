<?php
/**
 * REST API for the booking system, namespace tc/v1.
 *
 * Deliberately NOT using the default wp/v2 CPT REST support (disabled in
 * class-tc-cpt.php) - these routes shape data specifically for the front-end
 * and enforce booking-specific validation/capability rules that generic CPT
 * REST endpoints don't know about.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Rest_Api {

	const NAMESPACE_ = 'tc/v1';

	public static function init() {
		add_action( 'rest_api_init', array( __CLASS__, 'register_routes' ) );
	}

	public static function register_routes() {
		register_rest_route(
			self::NAMESPACE_,
			'/locations',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_locations' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/services',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_services' ),
				'permission_callback' => '__return_true',
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/guide',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_guide_for_location' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'location_id' => array( 'required' => true ),
					'service_id'  => array( 'required' => false ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/availability',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'get_availability' ),
				'permission_callback' => '__return_true',
				'args'                => array(
					'service_id'  => array( 'required' => true ),
					'location_id' => array( 'required' => true ),
					'start'       => array( 'required' => true ),
					'end'         => array( 'required' => true ),
				),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/bookings',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'create_booking' ),
				'permission_callback' => '__return_true',
			)
		);

		// --- Admin ---------------------------------------------------

		register_rest_route(
			self::NAMESPACE_,
			'/admin/bookings',
			array(
				'methods'             => 'GET',
				'callback'            => array( __CLASS__, 'admin_get_bookings' ),
				'permission_callback' => array( __CLASS__, 'require_manage_bookings' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/bookings/(?P<id>\d+)/cancel',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_cancel_booking' ),
				'permission_callback' => array( __CLASS__, 'require_manage_bookings' ),
			)
		);

		register_rest_route(
			self::NAMESPACE_,
			'/admin/bookings/(?P<id>\d+)/reschedule',
			array(
				'methods'             => 'POST',
				'callback'            => array( __CLASS__, 'admin_reschedule_booking' ),
				'permission_callback' => array( __CLASS__, 'require_manage_bookings' ),
			)
		);

		// --- Guide self-service ---------------------------------------

		register_rest_route(
			self::NAMESPACE_,
			'/guide/availability',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'guide_get_availability' ),
					'permission_callback' => array( __CLASS__, 'require_guide' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'guide_set_availability' ),
					'permission_callback' => array( __CLASS__, 'require_guide' ),
				),
			)
		);

		// --- Admin: edit a guide's calendar on their behalf ------------

		register_rest_route(
			self::NAMESPACE_,
			'/admin/guides/(?P<id>\d+)/availability',
			array(
				array(
					'methods'             => 'GET',
					'callback'            => array( __CLASS__, 'admin_get_guide_availability' ),
					'permission_callback' => array( __CLASS__, 'require_manage_bookings' ),
				),
				array(
					'methods'             => 'POST',
					'callback'            => array( __CLASS__, 'admin_set_guide_availability' ),
					'permission_callback' => array( __CLASS__, 'require_manage_bookings' ),
				),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Permission callbacks                                                */
	/* ------------------------------------------------------------------ */

	public static function require_manage_bookings() {
		return current_user_can( 'edit_others_posts' );
	}

	public static function require_guide() {
		return current_user_can( 'tc_manage_own_availability' ) && self::get_guide_post_for_current_user();
	}

	private static function get_guide_post_for_current_user() {
		$user_id = get_current_user_id();
		if ( ! $user_id ) {
			return null;
		}
		$guides = get_posts(
			array(
				'post_type'   => TC_CPT::GUIDE,
				'numberposts' => 1,
				'meta_key'    => '_tc_user_id',
				'meta_value'  => $user_id,
			)
		);
		return $guides ? $guides[0] : null;
	}

	/* ------------------------------------------------------------------ */
	/* Public: catalog                                                     */
	/* ------------------------------------------------------------------ */

	public static function get_locations() {
		$posts = get_posts( array( 'post_type' => TC_CPT::LOCATION, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$data  = array();
		foreach ( $posts as $post ) {
			$data[] = array(
				'id'       => $post->ID,
				'name'     => $post->post_title,
				'address'  => get_post_meta( $post->ID, '_tc_address', true ),
				'province' => get_post_meta( $post->ID, '_tc_province', true ),
				'lat'      => (float) get_post_meta( $post->ID, '_tc_lat', true ),
				'lng'      => (float) get_post_meta( $post->ID, '_tc_lng', true ),
			);
		}
		return rest_ensure_response( $data );
	}

	public static function get_services() {
		$posts = get_posts( array( 'post_type' => TC_CPT::SERVICE, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$data  = array();
		foreach ( $posts as $post ) {
			$service  = TC_Availability::get_service_data( $post->ID );
			$data[]   = array(
				'id'            => $post->ID,
				'name'          => $post->post_title,
				'description'   => $post->post_content,
				'price'         => $service['price'],
				'duration_days' => $service['duration_days'],
				'start_time'    => $service['start_time'],
				'min_capacity'  => $service['min_capacity'],
				'max_capacity'  => $service['max_capacity'],
				'extras'        => $service['extras'],
			);
		}
		return rest_ensure_response( $data );
	}

	public static function get_guide_for_location( WP_REST_Request $request ) {
		$location_id = absint( $request->get_param( 'location_id' ) );
		$service_id  = absint( $request->get_param( 'service_id' ) );

		$meta_query = array(
			array( 'key' => '_tc_location_ids', 'value' => $location_id ),
		);
		if ( $service_id ) {
			$meta_query[] = array( 'key' => '_tc_service_ids', 'value' => $service_id );
		}

		// Note: without a service_id (used for the Location step's preview
		// card, before any service is chosen) this returns the first guide
		// covering the location at all. If a location ever has different
		// specialist guides per service, this preview may show a guide who
		// isn't the one ultimately assigned - the real assignment at booking
		// time always re-checks location+service together (see
		// TC_Availability::get_guides_for), so booking correctness isn't
		// affected, only which face the customer sees before picking a service.
		$guides = get_posts(
			array(
				'post_type'   => TC_CPT::GUIDE,
				'numberposts' => 1,
				'meta_query'  => $meta_query,
			)
		);

		if ( ! $guides ) {
			return rest_ensure_response( null );
		}

		$guide = $guides[0];
		return rest_ensure_response(
			array(
				'id'    => $guide->ID,
				'name'  => $guide->post_title,
				'bio'   => $guide->post_content,
				'photo' => get_the_post_thumbnail_url( $guide->ID, 'medium' ) ?: null,
			)
		);
	}

	public static function get_availability( WP_REST_Request $request ) {
		$service_id  = absint( $request->get_param( 'service_id' ) );
		$location_id = absint( $request->get_param( 'location_id' ) );
		$start       = self::sanitize_date( $request->get_param( 'start' ) );
		$end         = self::sanitize_date( $request->get_param( 'end' ) );

		if ( ! $start || ! $end ) {
			return new WP_Error( 'tc_invalid_date', __( 'Invalid start/end date.', 'tc-booking' ), array( 'status' => 400 ) );
		}

		// Cap the range so a malicious/buggy client can't force a huge scan.
		$span = ( new DateTime( $start ) )->diff( new DateTime( $end ) )->days;
		if ( $span > 120 ) {
			return new WP_Error( 'tc_range_too_large', __( 'Date range too large.', 'tc-booking' ), array( 'status' => 400 ) );
		}

		return rest_ensure_response( TC_Availability::get_grid( $service_id, $location_id, $start, $end ) );
	}

	/* ------------------------------------------------------------------ */
	/* Public: create booking                                              */
	/* ------------------------------------------------------------------ */

	public static function create_booking( WP_REST_Request $request ) {
		$params      = $request->get_json_params();
		$service_id  = isset( $params['service_id'] ) ? absint( $params['service_id'] ) : 0;
		$location_id = isset( $params['location_id'] ) ? absint( $params['location_id'] ) : 0;
		$date        = self::sanitize_date( $params['date'] ?? '' );
		$first_name  = isset( $params['first_name'] ) ? sanitize_text_field( $params['first_name'] ) : '';
		$last_name   = isset( $params['last_name'] ) ? sanitize_text_field( $params['last_name'] ) : '';
		$email       = isset( $params['email'] ) ? sanitize_email( $params['email'] ) : '';
		$phone       = isset( $params['phone'] ) ? sanitize_text_field( $params['phone'] ) : '';
		$extras_in   = isset( $params['extras'] ) && is_array( $params['extras'] ) ? $params['extras'] : array();

		if ( ! $service_id || ! $location_id || ! $date || ! $email || ! $first_name ) {
			return new WP_Error( 'tc_missing_fields', __( 'Missing required booking fields.', 'tc-booking' ), array( 'status' => 400 ) );
		}
		if ( ! is_email( $email ) ) {
			return new WP_Error( 'tc_invalid_email', __( 'Invalid email address.', 'tc-booking' ), array( 'status' => 400 ) );
		}

		$service = TC_Availability::get_service_data( $service_id );
		if ( ! $service ) {
			return new WP_Error( 'tc_invalid_service', __( 'Unknown service.', 'tc-booking' ), array( 'status' => 400 ) );
		}

		// Re-validate availability server-side - the front-end grid can be
		// stale by the time checkout happens, this check cannot be skipped.
		if ( ! TC_Availability::is_bookable( $service_id, $location_id, $date ) ) {
			return new WP_Error( 'tc_not_available', __( 'That date is no longer available. Please pick another date.', 'tc-booking' ), array( 'status' => 409 ) );
		}

		$guide_id = TC_Availability::pick_guide( $service_id, $location_id, $date );
		if ( ! $guide_id ) {
			return new WP_Error( 'tc_no_guide', __( 'No guide available for that date.', 'tc-booking' ), array( 'status' => 409 ) );
		}

		// Validate + price the selected extras against the service's own
		// extras list (never trust price/label from the client).
		$valid_extras = array();
		$extras_total = 0;
		$party_size   = 1;
		foreach ( $extras_in as $chosen ) {
			$key = isset( $chosen['key'] ) ? sanitize_title( $chosen['key'] ) : '';
			$qty = isset( $chosen['qty'] ) ? max( 0, (int) $chosen['qty'] ) : 0;
			if ( ! $key || ! $qty ) {
				continue;
			}
			foreach ( $service['extras'] as $extra ) {
				if ( $extra['key'] === $key ) {
					$qty           = min( $qty, (int) $extra['max'] );
					$valid_extras[] = array(
						'key'   => $key,
						'label' => $extra['label'],
						'price' => (float) $extra['price'],
						'qty'   => $qty,
					);
					$extras_total += $qty * (float) $extra['price'];
					// Extras named like "+N extra person(s)" grow the party size
					// for capacity purposes. Matched by convention on the key.
					if ( preg_match( '/extra-(\d+)-person/', $key, $m ) ) {
						$party_size += (int) $m[1] * $qty;
					}
					break;
				}
			}
		}

		$total = $service['price'] + $extras_total;

		$booking_id = wp_insert_post(
			array(
				'post_type'   => TC_CPT::BOOKING,
				'post_status' => 'publish',
				'post_title'  => sprintf( '%s - %s - %s', $service['name'], $date, $first_name . ' ' . $last_name ),
			)
		);
		if ( is_wp_error( $booking_id ) ) {
			return new WP_Error( 'tc_booking_failed', __( 'Could not create booking.', 'tc-booking' ), array( 'status' => 500 ) );
		}

		update_post_meta( $booking_id, '_tc_service_id', $service_id );
		update_post_meta( $booking_id, '_tc_location_id', $location_id );
		update_post_meta( $booking_id, '_tc_guide_id', $guide_id );
		update_post_meta( $booking_id, '_tc_date', $date );
		update_post_meta( $booking_id, '_tc_status', 'pending_payment' );
		update_post_meta( $booking_id, '_tc_party_size', $party_size );
		update_post_meta( $booking_id, '_tc_selected_extras', $valid_extras );
		update_post_meta( $booking_id, '_tc_customer_first_name', $first_name );
		update_post_meta( $booking_id, '_tc_customer_last_name', $last_name );
		update_post_meta( $booking_id, '_tc_customer_email', $email );
		update_post_meta( $booking_id, '_tc_customer_phone', $phone );
		update_post_meta( $booking_id, '_tc_total', $total );

		$order = TC_Woocommerce::create_order_for_booking( $booking_id );
		if ( is_wp_error( $order ) ) {
			wp_delete_post( $booking_id, true );
			return $order;
		}

		return rest_ensure_response(
			array(
				'booking_id' => $booking_id,
				'total'      => $total,
				'checkout_url' => $order->get_checkout_payment_url(),
			)
		);
	}

	/* ------------------------------------------------------------------ */
	/* Admin: manage bookings                                              */
	/* ------------------------------------------------------------------ */

	public static function admin_get_bookings( WP_REST_Request $request ) {
		$args = array(
			'post_type'   => TC_CPT::BOOKING,
			'numberposts' => 200,
			'orderby'     => 'meta_value',
			'meta_key'    => '_tc_date',
			'order'       => 'DESC',
		);

		$meta_query = array();
		if ( $request->get_param( 'location_id' ) ) {
			$meta_query[] = array( 'key' => '_tc_location_id', 'value' => absint( $request->get_param( 'location_id' ) ) );
		}
		if ( $request->get_param( 'guide_id' ) ) {
			$meta_query[] = array( 'key' => '_tc_guide_id', 'value' => absint( $request->get_param( 'guide_id' ) ) );
		}
		if ( $meta_query ) {
			$args['meta_query'] = $meta_query;
		}

		$posts = get_posts( $args );
		$data  = array();
		foreach ( $posts as $post ) {
			$data[] = self::format_booking( $post );
		}
		return rest_ensure_response( $data );
	}

	private static function format_booking( $post ) {
		$service_id  = (int) get_post_meta( $post->ID, '_tc_service_id', true );
		$location_id = (int) get_post_meta( $post->ID, '_tc_location_id', true );
		$guide_id    = (int) get_post_meta( $post->ID, '_tc_guide_id', true );
		return array(
			'id'         => $post->ID,
			'status'     => get_post_meta( $post->ID, '_tc_status', true ),
			'date'       => get_post_meta( $post->ID, '_tc_date', true ),
			'service'    => array( 'id' => $service_id, 'name' => get_the_title( $service_id ) ),
			'location'   => array( 'id' => $location_id, 'name' => get_the_title( $location_id ) ),
			'guide'      => array( 'id' => $guide_id, 'name' => get_the_title( $guide_id ) ),
			'customer'   => array(
				'first_name' => get_post_meta( $post->ID, '_tc_customer_first_name', true ),
				'last_name'  => get_post_meta( $post->ID, '_tc_customer_last_name', true ),
				'email'      => get_post_meta( $post->ID, '_tc_customer_email', true ),
				'phone'      => get_post_meta( $post->ID, '_tc_customer_phone', true ),
			),
			'total'      => (float) get_post_meta( $post->ID, '_tc_total', true ),
			'order_id'   => (int) get_post_meta( $post->ID, '_tc_wc_order_id', true ),
		);
	}

	public static function admin_cancel_booking( WP_REST_Request $request ) {
		$booking_id = absint( $request->get_param( 'id' ) );
		$post       = get_post( $booking_id );
		if ( ! $post || TC_CPT::BOOKING !== $post->post_type ) {
			return new WP_Error( 'tc_not_found', __( 'Booking not found.', 'tc-booking' ), array( 'status' => 404 ) );
		}

		update_post_meta( $booking_id, '_tc_status', 'cancelled' );

		$order_id = (int) get_post_meta( $booking_id, '_tc_wc_order_id', true );
		if ( $order_id ) {
			TC_Woocommerce::cancel_order( $order_id );
		}

		TC_Notifications::send_cancellation( $booking_id );

		return rest_ensure_response( array( 'success' => true ) );
	}

	public static function admin_reschedule_booking( WP_REST_Request $request ) {
		$booking_id = absint( $request->get_param( 'id' ) );
		$params     = $request->get_json_params();
		$new_date   = self::sanitize_date( $params['date'] ?? '' );

		$post = get_post( $booking_id );
		if ( ! $post || TC_CPT::BOOKING !== $post->post_type ) {
			return new WP_Error( 'tc_not_found', __( 'Booking not found.', 'tc-booking' ), array( 'status' => 404 ) );
		}
		if ( ! $new_date ) {
			return new WP_Error( 'tc_invalid_date', __( 'Invalid date.', 'tc-booking' ), array( 'status' => 400 ) );
		}

		$service_id  = (int) get_post_meta( $booking_id, '_tc_service_id', true );
		$location_id = (int) get_post_meta( $booking_id, '_tc_location_id', true );

		// Re-validate against the NEW date, excluding this booking's own
		// existing hold on its old date (the pick_guide/is_bookable calls
		// naturally exclude it since we haven't updated the meta yet).
		if ( ! TC_Availability::is_bookable( $service_id, $location_id, $new_date ) ) {
			return new WP_Error( 'tc_not_available', __( 'That new date is not available.', 'tc-booking' ), array( 'status' => 409 ) );
		}

		$guide_id = TC_Availability::pick_guide( $service_id, $location_id, $new_date );
		if ( ! $guide_id ) {
			return new WP_Error( 'tc_no_guide', __( 'No guide available on that date.', 'tc-booking' ), array( 'status' => 409 ) );
		}

		update_post_meta( $booking_id, '_tc_date', $new_date );
		update_post_meta( $booking_id, '_tc_guide_id', $guide_id );

		TC_Notifications::send_reschedule( $booking_id );

		return rest_ensure_response( array( 'success' => true, 'date' => $new_date, 'guide_id' => $guide_id ) );
	}

	/* ------------------------------------------------------------------ */
	/* Guide self-service                                                   */
	/* ------------------------------------------------------------------ */

	public static function guide_get_availability( WP_REST_Request $request ) {
		$guide = self::get_guide_post_for_current_user();
		$start = self::sanitize_date( $request->get_param( 'start' ) ) ?: gmdate( 'Y-m-d' );
		$end   = self::sanitize_date( $request->get_param( 'end' ) ) ?: gmdate( 'Y-m-d', strtotime( '+90 days' ) );

		return rest_ensure_response( self::fetch_guide_availability( $guide->ID, $start, $end ) );
	}

	public static function guide_set_availability( WP_REST_Request $request ) {
		$guide  = self::get_guide_post_for_current_user();
		$params = $request->get_json_params();

		$date   = self::sanitize_date( $params['date'] ?? '' );
		$status = isset( $params['status'] ) && in_array( $params['status'], array( 'blocked', 'available' ), true ) ? $params['status'] : '';
		$note   = isset( $params['note'] ) ? sanitize_text_field( $params['note'] ) : null;

		if ( ! $date || ! $status ) {
			return new WP_Error( 'tc_invalid_input', __( 'Invalid date or status.', 'tc-booking' ), array( 'status' => 400 ) );
		}

		self::upsert_guide_availability( $guide->ID, $date, $status, $note );

		return rest_ensure_response( array( 'success' => true ) );
	}

	/* ------------------------------------------------------------------ */
	/* Admin: edit a guide's calendar on their behalf                       */
	/* ------------------------------------------------------------------ */

	public static function admin_get_guide_availability( WP_REST_Request $request ) {
		$guide = self::get_guide_post_for_admin_request( $request );
		if ( is_wp_error( $guide ) ) {
			return $guide;
		}

		$start = self::sanitize_date( $request->get_param( 'start' ) ) ?: gmdate( 'Y-m-d' );
		$end   = self::sanitize_date( $request->get_param( 'end' ) ) ?: gmdate( 'Y-m-d', strtotime( '+90 days' ) );

		return rest_ensure_response( self::fetch_guide_availability( $guide->ID, $start, $end ) );
	}

	public static function admin_set_guide_availability( WP_REST_Request $request ) {
		$guide = self::get_guide_post_for_admin_request( $request );
		if ( is_wp_error( $guide ) ) {
			return $guide;
		}

		$params = $request->get_json_params();
		$date   = self::sanitize_date( $params['date'] ?? '' );
		$status = isset( $params['status'] ) && in_array( $params['status'], array( 'blocked', 'available' ), true ) ? $params['status'] : '';
		$note   = isset( $params['note'] ) ? sanitize_text_field( $params['note'] ) : null;

		if ( ! $date || ! $status ) {
			return new WP_Error( 'tc_invalid_input', __( 'Invalid date or status.', 'tc-booking' ), array( 'status' => 400 ) );
		}

		self::upsert_guide_availability( $guide->ID, $date, $status, $note );

		return rest_ensure_response( array( 'success' => true ) );
	}

	private static function get_guide_post_for_admin_request( WP_REST_Request $request ) {
		$guide_id = absint( $request->get_param( 'id' ) );
		$guide    = get_post( $guide_id );
		if ( ! $guide || TC_CPT::GUIDE !== $guide->post_type ) {
			return new WP_Error( 'tc_not_found', __( 'Guide not found.', 'tc-booking' ), array( 'status' => 404 ) );
		}
		return $guide;
	}

	/**
	 * Shared read/write helpers for the wp_tc_guide_availability table, used
	 * by both the guide's own self-service endpoints and the admin endpoints
	 * that edit a guide's calendar on their behalf - same data, same rules,
	 * just a different permission check and a guide_id supplied explicitly
	 * instead of resolved from the current user.
	 */
	private static function fetch_guide_availability( $guide_id, $start, $end ) {
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

	private static function upsert_guide_availability( $guide_id, $date, $status, $note ) {
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

	/* ------------------------------------------------------------------ */
	/* Helpers                                                              */
	/* ------------------------------------------------------------------ */

	private static function sanitize_date( $value ) {
		if ( ! $value || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $value ) ) {
			return false;
		}
		$d = DateTime::createFromFormat( 'Y-m-d', $value );
		return ( $d && $d->format( 'Y-m-d' ) === $value ) ? $value : false;
	}
}
