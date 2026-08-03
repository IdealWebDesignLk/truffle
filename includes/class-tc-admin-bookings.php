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
		add_action( 'admin_footer-edit.php', array( __CLASS__, 'reschedule_prompt_script' ) );
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

		$actions['tc_reschedule'] = '<a href="#" class="tc-reschedule-link" data-booking-id="' . esc_attr( $post->ID ) . '" data-nonce="' .
			esc_attr( wp_create_nonce( 'tc_reschedule_booking_' . $post->ID ) ) . '">' . esc_html__( 'Reschedule', 'tc-booking' ) . '</a>';

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

		if ( ! TC_Availability::is_bookable( $service_id, $location_id, $new_date ) ) {
			wp_safe_redirect( add_query_arg( array( 'post_type' => TC_CPT::BOOKING, 'tc_notice' => 'unavailable' ), admin_url( 'edit.php' ) ) );
			exit;
		}

		$guide_id = TC_Availability::pick_guide( $service_id, $location_id, $new_date );
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

	/**
	 * Minimal reschedule UI: prompt() for the new date, then submit a plain
	 * POST form. Deliberately lightweight for v1 - a proper date-picker
	 * modal is a good candidate for polish once this is in VS Code.
	 */
	public static function reschedule_prompt_script() {
		global $post_type;
		if ( TC_CPT::BOOKING !== $post_type ) {
			return;
		}
		?>
		<script>
		document.addEventListener('click', function (e) {
			var link = e.target.closest('.tc-reschedule-link');
			if (!link) return;
			e.preventDefault();
			var newDate = prompt('<?php echo esc_js( __( 'New date (YYYY-MM-DD):', 'tc-booking' ) ); ?>');
			if (!newDate) return;
			var form = document.createElement('form');
			form.method = 'POST';
			form.action = '<?php echo esc_url( admin_url( 'admin-post.php' ) ); ?>';
			[
				['action', 'tc_reschedule_booking'],
				['booking_id', link.dataset.bookingId],
				['new_date', newDate],
				['_wpnonce', link.dataset.nonce]
			].forEach(function (pair) {
				var input = document.createElement('input');
				input.type = 'hidden';
				input.name = pair[0];
				input.value = pair[1];
				form.appendChild(input);
			});
			document.body.appendChild(form);
			form.submit();
		});
		</script>
		<?php
	}
}
