<?php
/**
 * Customizes the Bookings admin list screen: useful columns, and
 * Cancel / Reschedule row actions that reuse the same availability
 * validation as the REST API (see TC_Availability) rather than writing
 * a second, possibly-divergent set of rules for the admin UI.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Admin_Bookings {

	public static function init() {
		add_filter( 'manage_' . TC_CPT::BOOKING . '_posts_columns', array( __CLASS__, 'columns' ) );
		add_action( 'manage_' . TC_CPT::BOOKING . '_posts_custom_column', array( __CLASS__, 'render_column' ), 10, 2 );
		add_filter( 'post_row_actions', array( __CLASS__, 'row_actions' ), 10, 2 );
		add_action( 'admin_post_tc_cancel_booking', array( __CLASS__, 'handle_cancel' ) );
		add_action( 'admin_post_tc_reschedule_booking', array( __CLASS__, 'handle_reschedule' ) );
		add_action( 'admin_notices', array( __CLASS__, 'notices' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	/**
	 * The reschedule UI is the same availability calendar the customer-facing
	 * booking widget uses (GitHub issue #16) - reuses public/css/booking-app.css
	 * for the calendar visuals and the existing public /availability REST
	 * route (already permission_callback => __return_true) for the data, so
	 * there's no new backend surface just for this. handle_reschedule() below
	 * is unchanged from the old prompt()-based flow; only the trigger UI
	 * changed.
	 */
	public static function enqueue( $hook ) {
		global $post_type;
		if ( 'edit.php' !== $hook || TC_CPT::BOOKING !== $post_type ) {
			return;
		}
		wp_enqueue_style( 'tc-booking-app', TC_BOOKING_URL . 'public/css/booking-app.css', array(), TC_BOOKING_VERSION );
		wp_enqueue_style( 'tc-admin', TC_BOOKING_URL . 'admin/css/admin.css', array(), TC_BOOKING_VERSION );
		wp_enqueue_script( 'tc-reschedule-modal', TC_BOOKING_URL . 'admin/js/reschedule-modal.js', array(), TC_BOOKING_VERSION, true );
		wp_localize_script(
			'tc-reschedule-modal',
			'tcRescheduleModal',
			array(
				'restRoot' => esc_url_raw( rest_url( 'tc/v1' ) ),
				'postUrl'  => esc_url( admin_url( 'admin-post.php' ) ),
			)
		);
	}

	public static function columns( $columns ) {
		$new = array();
		foreach ( $columns as $key => $label ) {
			$new[ $key ] = $label;
			if ( 'title' === $key ) {
				$new['tc_date']     = __( 'Date', 'tc-booking' );
				$new['tc_location'] = __( 'Location', 'tc-booking' );
				$new['tc_guide']    = __( 'Guide', 'tc-booking' );
				$new['tc_status']   = __( 'Status', 'tc-booking' );
				$new['tc_total']    = __( 'Total', 'tc-booking' );
			}
		}
		return $new;
	}

	public static function render_column( $column, $post_id ) {
		switch ( $column ) {
			case 'tc_date':
				echo esc_html( get_post_meta( $post_id, '_tc_date', true ) );
				break;
			case 'tc_location':
				echo esc_html( get_the_title( (int) get_post_meta( $post_id, '_tc_location_id', true ) ) );
				break;
			case 'tc_guide':
				echo esc_html( get_the_title( (int) get_post_meta( $post_id, '_tc_guide_id', true ) ) );
				break;
			case 'tc_status':
				echo esc_html( get_post_meta( $post_id, '_tc_status', true ) );
				break;
			case 'tc_total':
				echo '&euro;' . esc_html( number_format( (float) get_post_meta( $post_id, '_tc_total', true ), 2 ) );
				break;
		}
	}

	public static function row_actions( $actions, $post ) {
		if ( TC_CPT::BOOKING !== $post->post_type ) {
			return $actions;
		}
		$status = get_post_meta( $post->ID, '_tc_status', true );
		if ( 'cancelled' === $status ) {
			return $actions;
		}

		$cancel_url = wp_nonce_url(
			admin_url( 'admin-post.php?action=tc_cancel_booking&booking_id=' . $post->ID ),
			'tc_cancel_booking_' . $post->ID
		);
		$actions['tc_cancel'] = '<a href="' . esc_url( $cancel_url ) . '" onclick="return confirm(\'' .
			esc_js( __( 'Cancel this booking? This also cancels the linked WooCommerce order.', 'tc-booking' ) ) . '\');" style="color:#a3402e;">' .
			esc_html__( 'Cancel', 'tc-booking' ) . '</a>';

		$service_id  = (int) get_post_meta( $post->ID, '_tc_service_id', true );
		$location_id = (int) get_post_meta( $post->ID, '_tc_location_id', true );
		$current_date = get_post_meta( $post->ID, '_tc_date', true );

		$actions['tc_reschedule'] = '<a href="#" class="tc-reschedule-link"' .
			' data-booking-id="' . esc_attr( $post->ID ) . '"' .
			' data-nonce="' . esc_attr( wp_create_nonce( 'tc_reschedule_booking_' . $post->ID ) ) . '"' .
			' data-service-id="' . esc_attr( $service_id ) . '"' .
			' data-location-id="' . esc_attr( $location_id ) . '"' .
			' data-current-date="' . esc_attr( $current_date ) . '"' .
			' data-service-name="' . esc_attr( get_the_title( $service_id ) ) . '">' .
			esc_html__( 'Reschedule', 'tc-booking' ) . '</a>';

		return $actions;
	}

	public static function handle_cancel() {
		$booking_id = isset( $_GET['booking_id'] ) ? absint( $_GET['booking_id'] ) : 0;
		if ( ! $booking_id || ! check_admin_referer( 'tc_cancel_booking_' . $booking_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'tc-booking' ) );
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tc-booking' ) );
		}

		update_post_meta( $booking_id, '_tc_status', 'cancelled' );
		$order_id = (int) get_post_meta( $booking_id, '_tc_wc_order_id', true );
		if ( $order_id ) {
			TC_Woocommerce::cancel_order( $order_id );
		}
		TC_Notifications::send_cancellation( $booking_id );

		wp_safe_redirect( add_query_arg( array( 'post_type' => TC_CPT::BOOKING, 'tc_notice' => 'cancelled' ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function handle_reschedule() {
		$booking_id = isset( $_POST['booking_id'] ) ? absint( $_POST['booking_id'] ) : 0;
		$new_date   = isset( $_POST['new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['new_date'] ) ) : '';

		if ( ! $booking_id || ! check_admin_referer( 'tc_reschedule_booking_' . $booking_id ) ) {
			wp_die( esc_html__( 'Invalid request.', 'tc-booking' ) );
		}
		if ( ! current_user_can( 'edit_others_posts' ) ) {
			wp_die( esc_html__( 'Not allowed.', 'tc-booking' ) );
		}
		if ( ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $new_date ) ) {
			wp_safe_redirect( add_query_arg( array( 'post_type' => TC_CPT::BOOKING, 'tc_notice' => 'bad_date' ), admin_url( 'edit.php' ) ) );
			exit;
		}

		$service_id  = (int) get_post_meta( $booking_id, '_tc_service_id', true );
		$location_id = (int) get_post_meta( $booking_id, '_tc_location_id', true );
		$party_size  = max( 1, (int) get_post_meta( $booking_id, '_tc_party_size', true ) );

		if ( ! TC_Availability::is_bookable( $service_id, $location_id, $new_date ) ) {
			wp_safe_redirect( add_query_arg( array( 'post_type' => TC_CPT::BOOKING, 'tc_notice' => 'unavailable' ), admin_url( 'edit.php' ) ) );
			exit;
		}

		// Needs the booking's own party size so a shared/group booking only
		// moves to a date with enough REMAINING room for its whole group -
		// see TC_Availability::pick_guide().
		$guide_id = TC_Availability::pick_guide( $service_id, $location_id, $new_date, $party_size );
		if ( ! $guide_id ) {
			// pick_guide() can fail here even though is_bookable() passed -
			// is_bookable() only checks "is anything open," not "is there
			// room for this specific party." Don't silently corrupt the
			// booking to guide_id 0.
			wp_safe_redirect( add_query_arg( array( 'post_type' => TC_CPT::BOOKING, 'tc_notice' => 'unavailable' ), admin_url( 'edit.php' ) ) );
			exit;
		}

		update_post_meta( $booking_id, '_tc_date', $new_date );
		update_post_meta( $booking_id, '_tc_guide_id', $guide_id );
		TC_Notifications::send_reschedule( $booking_id );

		wp_safe_redirect( add_query_arg( array( 'post_type' => TC_CPT::BOOKING, 'tc_notice' => 'rescheduled' ), admin_url( 'edit.php' ) ) );
		exit;
	}

	public static function notices() {
		if ( empty( $_GET['tc_notice'] ) ) {
			return;
		}
		$messages = array(
			'cancelled'    => array( 'success', __( 'Booking cancelled.', 'tc-booking' ) ),
			'rescheduled'  => array( 'success', __( 'Booking rescheduled.', 'tc-booking' ) ),
			'unavailable'  => array( 'error', __( 'That date is not available for this service/location - booking was not changed.', 'tc-booking' ) ),
			'bad_date'     => array( 'error', __( 'Please enter a valid date (YYYY-MM-DD).', 'tc-booking' ) ),
		);
		$key = sanitize_key( $_GET['tc_notice'] );
		if ( isset( $messages[ $key ] ) ) {
			list( $type, $text ) = $messages[ $key ];
			printf( '<div class="notice notice-%s is-dismissible"><p>%s</p></div>', esc_attr( $type ), esc_html( $text ) );
		}
	}
}
