<?php
/**
 * Admin meta boxes for Location, Service, Guide, and Booking. Booking is
 * mostly read-only (see the "Booking" section below) - the one exception
 * is a brand-new booking with no _tc_service_id yet (an admin manually
 * adding one via Bookings -> Add New), which gets an editable form
 * instead. See render_booking()/save_new_booking().
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Meta_Boxes {

	public static function init() {
		add_action( 'add_meta_boxes', array( __CLASS__, 'register' ) );
		add_action( 'save_post_' . TC_CPT::LOCATION, array( __CLASS__, 'save_location' ) );
		add_action( 'save_post_' . TC_CPT::SERVICE, array( __CLASS__, 'save_service' ) );
		add_action( 'save_post_' . TC_CPT::GUIDE, array( __CLASS__, 'save_guide' ) );
		add_action( 'save_post_' . TC_CPT::BOOKING, array( __CLASS__, 'save_booking_note' ) );
		add_action( 'save_post_' . TC_CPT::BOOKING, array( __CLASS__, 'save_new_booking' ) );
		add_action( 'admin_enqueue_scripts', array( __CLASS__, 'enqueue' ) );
	}

	public static function enqueue( $hook ) {
		global $post_type, $post;
		if ( ! in_array( $post_type, array( TC_CPT::SERVICE, TC_CPT::GUIDE ), true ) ) {
			return;
		}
		wp_enqueue_style( 'tc-admin', TC_BOOKING_URL . 'admin/css/admin.css', array(), TC_BOOKING_VERSION );
		wp_enqueue_script( 'tc-admin', TC_BOOKING_URL . 'admin/js/admin.js', array( 'jquery' ), TC_BOOKING_VERSION, true );

		// Availability calendar only makes sense once the guide has an ID to
		// attach it to - 'auto-draft' is the unsaved "Add New Guide" screen.
		if ( TC_CPT::GUIDE === $post_type && $post && 'auto-draft' !== $post->post_status ) {
			wp_enqueue_style( 'tc-guide-availability', TC_BOOKING_URL . 'public/css/booking-app.css', array(), TC_BOOKING_VERSION );
			wp_enqueue_script( 'tc-guide-availability', TC_BOOKING_URL . 'admin/js/guide-availability.js', array(), TC_BOOKING_VERSION, true );
			wp_localize_script(
				'tc-guide-availability',
				'tcGuideAvailabilityAdmin',
				array(
					'restRoot' => esc_url_raw( rest_url( 'tc/v1' ) ),
					'nonce'    => wp_create_nonce( 'wp_rest' ),
					'guideId'  => $post->ID,
				)
			);
		}
	}

	public static function register() {
		add_meta_box( 'tc_location_details', __( 'Location Details', 'tc-booking' ), array( __CLASS__, 'render_location' ), TC_CPT::LOCATION, 'normal', 'high' );
		add_meta_box( 'tc_service_details', __( 'Service Details', 'tc-booking' ), array( __CLASS__, 'render_service' ), TC_CPT::SERVICE, 'normal', 'high' );
		add_meta_box( 'tc_service_extras', __( 'Extras', 'tc-booking' ), array( __CLASS__, 'render_service_extras' ), TC_CPT::SERVICE, 'normal', 'default' );
		add_meta_box( 'tc_guide_details', __( 'Guide Details', 'tc-booking' ), array( __CLASS__, 'render_guide' ), TC_CPT::GUIDE, 'normal', 'high' );
		add_meta_box( 'tc_guide_availability', __( 'Availability Calendar', 'tc-booking' ), array( __CLASS__, 'render_guide_availability' ), TC_CPT::GUIDE, 'normal', 'default' );
		add_meta_box( 'tc_booking_details', __( 'Booking Details', 'tc-booking' ), array( __CLASS__, 'render_booking' ), TC_CPT::BOOKING, 'normal', 'high' );
	}

	/* ---------------------------------------------------------------- */
	/* Location                                                          */
	/* ---------------------------------------------------------------- */

	public static function render_location( $post ) {
		wp_nonce_field( 'tc_save_location', 'tc_location_nonce' );
		$address  = get_post_meta( $post->ID, '_tc_address', true );
		$province = get_post_meta( $post->ID, '_tc_province', true );
		$lat      = get_post_meta( $post->ID, '_tc_lat', true );
		$lng      = get_post_meta( $post->ID, '_tc_lng', true );
		?>
		<p>
			<label for="tc_address"><strong><?php esc_html_e( 'Address', 'tc-booking' ); ?></strong></label><br>
			<input type="text" id="tc_address" name="tc_address" class="large-text" value="<?php echo esc_attr( $address ); ?>">
		</p>
		<p>
			<label for="tc_province"><strong><?php esc_html_e( 'Province', 'tc-booking' ); ?></strong></label><br>
			<input type="text" id="tc_province" name="tc_province" class="regular-text" value="<?php echo esc_attr( $province ); ?>">
		</p>
		<p>
			<label for="tc_lat"><strong><?php esc_html_e( 'Latitude', 'tc-booking' ); ?></strong></label><br>
			<input type="text" id="tc_lat" name="tc_lat" value="<?php echo esc_attr( $lat ); ?>" placeholder="52.5711">
			<label for="tc_lng" style="margin-left:12px;"><strong><?php esc_html_e( 'Longitude', 'tc-booking' ); ?></strong></label><br>
			<input type="text" id="tc_lng" name="tc_lng" value="<?php echo esc_attr( $lng ); ?>" placeholder="4.6706">
			<p class="description"><?php esc_html_e( 'Used to place this location\'s pin on the map. Look up on Google Maps: right-click the spot -> the coordinates are the first option.', 'tc-booking' ); ?></p>
		</p>
		<?php
	}

	public static function save_location( $post_id ) {
		if ( ! isset( $_POST['tc_location_nonce'] ) || ! wp_verify_nonce( $_POST['tc_location_nonce'], 'tc_save_location' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['tc_address'] ) ) {
			update_post_meta( $post_id, '_tc_address', sanitize_text_field( wp_unslash( $_POST['tc_address'] ) ) );
		}
		if ( isset( $_POST['tc_province'] ) ) {
			update_post_meta( $post_id, '_tc_province', sanitize_text_field( wp_unslash( $_POST['tc_province'] ) ) );
		}
		if ( isset( $_POST['tc_lat'] ) ) {
			update_post_meta( $post_id, '_tc_lat', sanitize_text_field( wp_unslash( $_POST['tc_lat'] ) ) );
		}
		if ( isset( $_POST['tc_lng'] ) ) {
			update_post_meta( $post_id, '_tc_lng', sanitize_text_field( wp_unslash( $_POST['tc_lng'] ) ) );
		}
	}

	/* ---------------------------------------------------------------- */
	/* Service                                                           */
	/* ---------------------------------------------------------------- */

	public static function render_service( $post ) {
		wp_nonce_field( 'tc_save_service', 'tc_service_nonce' );
		$price         = get_post_meta( $post->ID, '_tc_price', true );
		$duration_days = get_post_meta( $post->ID, '_tc_duration_days', true );
		$start_time    = get_post_meta( $post->ID, '_tc_start_time', true );
		$min_capacity  = get_post_meta( $post->ID, '_tc_min_capacity', true );
		$max_capacity  = get_post_meta( $post->ID, '_tc_max_capacity', true );
		$allow_party   = get_post_meta( $post->ID, '_tc_allow_party', true );
		$shared_seats  = get_post_meta( $post->ID, '_tc_allow_shared_seats', true );

		if ( '' === $duration_days ) {
			$duration_days = 1;
		}
		if ( '' === $min_capacity ) {
			$min_capacity = 1;
		}
		if ( '' === $max_capacity ) {
			$max_capacity = 1;
		}
		?>
		<table class="form-table">
			<tr>
				<th><label for="tc_price"><?php esc_html_e( 'Price (EUR)', 'tc-booking' ); ?></label></th>
				<td><input type="number" step="0.01" min="0" id="tc_price" name="tc_price" value="<?php echo esc_attr( $price ); ?>" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="tc_duration_days"><?php esc_html_e( 'Duration (calendar days)', 'tc-booking' ); ?></label></th>
				<td>
					<input type="number" step="1" min="1" id="tc_duration_days" name="tc_duration_days" value="<?php echo esc_attr( $duration_days ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'Most ceremonies are 1. Use 2 for the overnight retreat so it blocks the guide\'s following morning too.', 'tc-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="tc_start_time"><?php esc_html_e( 'Display start time', 'tc-booking' ); ?></label></th>
				<td>
					<input type="time" id="tc_start_time" name="tc_start_time" value="<?php echo esc_attr( $start_time ); ?>">
					<p class="description"><?php esc_html_e( 'Shown to the customer and guide (confirmation email, admin panel). Customers do not choose a time - this is fixed per service.', 'tc-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="tc_min_capacity"><?php esc_html_e( 'Min capacity', 'tc-booking' ); ?></label></th>
				<td><input type="number" step="1" min="1" id="tc_min_capacity" name="tc_min_capacity" value="<?php echo esc_attr( $min_capacity ); ?>" class="small-text"></td>
			</tr>
			<tr>
				<th><label for="tc_max_capacity"><?php esc_html_e( 'Max capacity', 'tc-booking' ); ?></label></th>
				<td>
					<input type="number" step="1" min="1" id="tc_max_capacity" name="tc_max_capacity" value="<?php echo esc_attr( $max_capacity ); ?>" class="small-text">
					<p class="description"><?php esc_html_e( 'The most people that can be on this date at all (also caps the group size in "Bring anyone with you" below). Whether OTHER, unrelated customers can book the leftover seats is controlled separately by "Allow sharing remaining seats" below.', 'tc-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Allow sharing remaining seats', 'tc-booking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="tc_allow_shared_seats" name="tc_allow_shared_seats" value="1" <?php checked( '1', $shared_seats ); ?>>
						<?php esc_html_e( 'Let other, unrelated customers book the remaining seats once part of Max capacity is used', 'tc-booking' ); ?>
					</label>
					<p class="description">
						<?php esc_html_e( 'Off (default): the first booking on a date closes it entirely to everyone else, even if it doesn\'t use up the full Max capacity - use this for a private booking where a party of e.g. 3 shouldn\'t leave the 4th seat open to a stranger.', 'tc-booking' ); ?>
						<br>
						<?php esc_html_e( 'On: this is a genuinely public/shared service - e.g. Max capacity 4 lets one customer book 2 seats and leaves the other 2 open for someone else to book, until the date fills up.', 'tc-booking' ); ?>
					</p>
				</td>
			</tr>
			<tr>
				<th><?php esc_html_e( 'Bring anyone with you', 'tc-booking' ); ?></th>
				<td>
					<label>
						<input type="checkbox" id="tc_allow_party" name="tc_allow_party" value="1" <?php checked( '1', $allow_party ); ?>>
						<?php esc_html_e( 'Let the customer bring extra people to this ceremony', 'tc-booking' ); ?>
					</label>
					<p class="description"><?php esc_html_e( 'Adds a group-size step to the booking flow (capped at Max capacity above, including the customer themself) and collects each extra person\'s name, email, and phone. The base price is charged per person.', 'tc-booking' ); ?></p>
				</td>
			</tr>
		</table>
		<?php
	}

	public static function render_service_extras( $post ) {
		$extras = get_post_meta( $post->ID, '_tc_extras', true );
		if ( ! is_array( $extras ) ) {
			$extras = array();
		}
		?>
		<div id="tc-extras-rows" data-row-template="tc-extra-row-template">
			<?php foreach ( $extras as $i => $extra ) : ?>
				<?php self::render_extra_row( $i, $extra ); ?>
			<?php endforeach; ?>
		</div>
		<p><button type="button" class="button" id="tc-add-extra"><?php esc_html_e( '+ Add extra', 'tc-booking' ); ?></button></p>

		<script type="text/template" id="tc-extra-row-template">
			<?php self::render_extra_row( '__INDEX__', array() ); ?>
		</script>
		<?php
	}

	private static function render_extra_row( $index, $extra ) {
		$label          = isset( $extra['label'] ) ? $extra['label'] : '';
		$price          = isset( $extra['price'] ) ? $extra['price'] : '';
		$max            = isset( $extra['max'] ) ? $extra['max'] : 1;
		$description    = isset( $extra['description'] ) ? $extra['description'] : '';
		$limit_by_seats = ! empty( $extra['limit_by_seats'] );
		?>
		<div class="tc-extra-row">
			<div class="tc-extra-row-main">
				<input type="text" placeholder="<?php esc_attr_e( 'Label', 'tc-booking' ); ?>" name="tc_extras[<?php echo esc_attr( $index ); ?>][label]" value="<?php echo esc_attr( $label ); ?>" class="tc-extra-label">
				<input type="number" step="0.01" min="0" placeholder="<?php esc_attr_e( 'Price', 'tc-booking' ); ?>" name="tc_extras[<?php echo esc_attr( $index ); ?>][price]" value="<?php echo esc_attr( $price ); ?>" class="tc-extra-price">
				<input type="number" step="1" min="1" placeholder="<?php esc_attr_e( 'Max qty', 'tc-booking' ); ?>" name="tc_extras[<?php echo esc_attr( $index ); ?>][max]" value="<?php echo esc_attr( $max ); ?>" class="tc-extra-max">
				<button type="button" class="button-link-delete tc-remove-extra"><?php esc_html_e( 'Remove', 'tc-booking' ); ?></button>
			</div>
			<?php
			// GitHub issue #48 - some extras only make sense up to how many
			// seats/people are actually in the booking (e.g. one "meal" per
			// person). When ticked, this extra's runtime max is the smaller
			// of its own Max qty above and the party size chosen earlier in
			// the booking flow, enforced both client-side (booking-app.js)
			// and again server-side (create_booking()) - never trust the
			// client's number alone.
			?>
			<label class="tc-extra-limit-seats">
				<input type="checkbox" name="tc_extras[<?php echo esc_attr( $index ); ?>][limit_by_seats]" value="1"<?php checked( $limit_by_seats ); ?>>
				<?php esc_html_e( 'Limit by seats (cap quantity at the number of people in the booking)', 'tc-booking' ); ?>
			</label>
			<textarea placeholder="<?php esc_attr_e( 'Description shown to customers explaining what this extra is (optional)', 'tc-booking' ); ?>" name="tc_extras[<?php echo esc_attr( $index ); ?>][description]" class="tc-extra-description" rows="2"><?php echo esc_textarea( $description ); ?></textarea>
		</div>
		<?php
	}

	public static function save_service( $post_id ) {
		if ( ! isset( $_POST['tc_service_nonce'] ) || ! wp_verify_nonce( $_POST['tc_service_nonce'], 'tc_save_service' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$fields = array(
			'tc_price'         => '_tc_price',
			'tc_duration_days' => '_tc_duration_days',
			'tc_start_time'    => '_tc_start_time',
			'tc_min_capacity'  => '_tc_min_capacity',
			'tc_max_capacity'  => '_tc_max_capacity',
		);
		foreach ( $fields as $post_key => $meta_key ) {
			if ( isset( $_POST[ $post_key ] ) ) {
				update_post_meta( $post_id, $meta_key, sanitize_text_field( wp_unslash( $_POST[ $post_key ] ) ) );
			}
		}

		update_post_meta( $post_id, '_tc_allow_party', isset( $_POST['tc_allow_party'] ) ? '1' : '' );
		update_post_meta( $post_id, '_tc_allow_shared_seats', isset( $_POST['tc_allow_shared_seats'] ) ? '1' : '' );

		$extras = array();
		if ( isset( $_POST['tc_extras'] ) && is_array( $_POST['tc_extras'] ) ) {
			foreach ( $_POST['tc_extras'] as $row ) {
				$label = isset( $row['label'] ) ? sanitize_text_field( wp_unslash( $row['label'] ) ) : '';
				if ( '' === $label ) {
					continue; // Skip blank rows left over from removed inputs.
				}
				$extras[] = array(
					'key'            => sanitize_title( $label ),
					'label'          => $label,
					'price'          => isset( $row['price'] ) ? (float) $row['price'] : 0,
					'max'            => isset( $row['max'] ) ? max( 1, (int) $row['max'] ) : 1,
					'description'    => isset( $row['description'] ) ? sanitize_textarea_field( wp_unslash( $row['description'] ) ) : '',
					'limit_by_seats' => isset( $row['limit_by_seats'] ) && '1' === $row['limit_by_seats'],
				);
			}
		}
		update_post_meta( $post_id, '_tc_extras', $extras );
	}

	/* ---------------------------------------------------------------- */
	/* Guide                                                             */
	/* ---------------------------------------------------------------- */

	public static function render_guide( $post ) {
		wp_nonce_field( 'tc_save_guide', 'tc_guide_nonce' );
		$user_id      = get_post_meta( $post->ID, '_tc_user_id', true );
		$location_ids = array_map( 'intval', get_post_meta( $post->ID, '_tc_location_ids', false ) );
		$service_ids  = array_map( 'intval', get_post_meta( $post->ID, '_tc_service_ids', false ) );

		$users     = get_users( array( 'role' => 'tc_guide', 'orderby' => 'display_name' ) );
		$locations = get_posts( array( 'post_type' => TC_CPT::LOCATION, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$services  = get_posts( array( 'post_type' => TC_CPT::SERVICE, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		?>
		<p>
			<label for="tc_user_id"><strong><?php esc_html_e( 'Linked WordPress user', 'tc-booking' ); ?></strong></label><br>
			<select id="tc_user_id" name="tc_user_id">
				<option value=""><?php esc_html_e( '— None —', 'tc-booking' ); ?></option>
				<?php foreach ( $users as $user ) : ?>
					<option value="<?php echo esc_attr( $user->ID ); ?>" <?php selected( $user_id, $user->ID ); ?>>
						<?php echo esc_html( $user->display_name . ' (' . $user->user_email . ')' ); ?>
					</option>
				<?php endforeach; ?>
			</select>
			<p class="description">
				<?php
				printf(
					/* translators: link to add new user screen */
					esc_html__( 'This guide logs in with this account to manage their own calendar. Create the account first under Users, with the "Ceremony Guide" role, then link it here. %s', 'tc-booking' ),
					'<a href="' . esc_url( admin_url( 'user-new.php' ) ) . '">' . esc_html__( 'Add a new user', 'tc-booking' ) . '</a>'
				);
				?>
			</p>
		</p>

		<p><strong><?php esc_html_e( 'Locations covered', 'tc-booking' ); ?></strong></p>
		<?php foreach ( $locations as $location ) : ?>
			<label style="display:block;">
				<input type="checkbox" name="tc_location_ids[]" value="<?php echo esc_attr( $location->ID ); ?>" <?php checked( in_array( $location->ID, $location_ids, true ) ); ?>>
				<?php echo esc_html( $location->post_title ); ?>
			</label>
		<?php endforeach; ?>

		<p style="margin-top:16px;"><strong><?php esc_html_e( 'Services provided', 'tc-booking' ); ?></strong></p>
		<?php foreach ( $services as $service ) : ?>
			<label style="display:block;">
				<input type="checkbox" name="tc_service_ids[]" value="<?php echo esc_attr( $service->ID ); ?>" <?php checked( in_array( $service->ID, $service_ids, true ) ); ?>>
				<?php echo esc_html( $service->post_title ); ?>
			</label>
		<?php endforeach; ?>
		<?php
	}

	/**
	 * Lets an admin view/edit this guide's own-availability calendar
	 * (wp_tc_guide_availability) without needing to log in as the guide -
	 * same REST-backed calendar widget the guide dashboard shortcode uses,
	 * pointed at the admin-only /admin/guides/{id}/availability routes.
	 */
	public static function render_guide_availability( $post ) {
		if ( 'auto-draft' === $post->post_status ) {
			echo '<p>' . esc_html__( 'Save this guide first, then come back here to manage their availability calendar.', 'tc-booking' ) . '</p>';
			return;
		}
		echo '<div id="tc-guide-availability-root">' . esc_html__( 'Loading…', 'tc-booking' ) . '</div>';
	}

	public static function save_guide( $post_id ) {
		if ( ! isset( $_POST['tc_guide_nonce'] ) || ! wp_verify_nonce( $_POST['tc_guide_nonce'], 'tc_save_guide' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		update_post_meta( $post_id, '_tc_user_id', isset( $_POST['tc_user_id'] ) ? absint( $_POST['tc_user_id'] ) : 0 );

		// Stored as one meta row per ID (not a single serialized array) so
		// TC_Availability's meta_query lookups can match reliably. A LIKE
		// match against a serialized array is fragile in both directions:
		// PHP serializes integers unquoted (i:4;, not "4"), and even with
		// correct quoting, an array's own index markers (i:1;) can collide
		// with a genuine value being searched for. One row per ID sidesteps
		// the whole problem - see PROJECT_NOTES.md.
		//
		// WPML support: kept as a defensive layer, though it's a no-op in
		// practice now - Location and Service are both non-translatable
		// (wpml-config.xml), so the "Locations covered" / "Services
		// provided" checkbox values above are already each option's single
		// canonical ID. See PROJECT_NOTES.md's "WPML support" section for
		// why full "Translatable" mode (the original design) was wrong for
		// Guide specifically.
		delete_post_meta( $post_id, '_tc_location_ids' );
		$location_ids = isset( $_POST['tc_location_ids'] ) ? array_map( 'absint', (array) $_POST['tc_location_ids'] ) : array();
		foreach ( $location_ids as $location_id ) {
			add_post_meta( $post_id, '_tc_location_ids', TC_WPML::to_default_language_id( $location_id, TC_CPT::LOCATION ) );
		}

		delete_post_meta( $post_id, '_tc_service_ids' );
		$service_ids = isset( $_POST['tc_service_ids'] ) ? array_map( 'absint', (array) $_POST['tc_service_ids'] ) : array();
		foreach ( $service_ids as $service_id ) {
			add_post_meta( $post_id, '_tc_service_ids', TC_WPML::to_default_language_id( $service_id, TC_CPT::SERVICE ) );
		}
	}

	/* ---------------------------------------------------------------- */
	/* Booking (read-only, except a brand-new one - see render_booking()) */
	/* ---------------------------------------------------------------- */

	/**
	 * Form for an admin manually adding a booking (e.g. a phone booking) -
	 * previously the Booking post type only supported a title, so "Add
	 * New" gave an admin nowhere to actually enter booking details.
	 *
	 * Deliberately narrower than the customer-facing widget: no extras or
	 * additional guests (an admin can note either in the note field below,
	 * once the booking exists), and the guide is auto-assigned via
	 * TC_Availability::pick_guide() rather than picked here, exactly like
	 * the real booking flow - never fork that matching logic. Skips
	 * WooCommerce/payment entirely and marks the booking 'confirmed'
	 * directly (no confirmation email either) - the assumption is this
	 * form is for a booking already arranged/paid outside the online flow;
	 * see save_new_booking().
	 */
	private static function render_new_booking_form( $post ) {
		wp_nonce_field( 'tc_save_new_booking', 'tc_new_booking_nonce' );

		$error = get_transient( 'tc_new_booking_error_' . get_current_user_id() . '_' . $post->ID );
		if ( $error ) {
			delete_transient( 'tc_new_booking_error_' . get_current_user_id() . '_' . $post->ID );
			echo '<div class="notice notice-error inline"><p>' . esc_html( $error ) . '</p></div>';
		}

		$locations = get_posts( array( 'post_type' => TC_CPT::LOCATION, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		$services  = get_posts( array( 'post_type' => TC_CPT::SERVICE, 'numberposts' => -1, 'orderby' => 'title', 'order' => 'ASC' ) );
		?>
		<table class="form-table">
			<tr>
				<th><label for="tc_new_location_id"><?php esc_html_e( 'Location', 'tc-booking' ); ?></label></th>
				<td>
					<select id="tc_new_location_id" name="tc_new_location_id" required>
						<option value=""></option>
						<?php foreach ( $locations as $location ) : ?>
							<option value="<?php echo esc_attr( $location->ID ); ?>"><?php echo esc_html( $location->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
				</td>
			</tr>
			<tr>
				<th><label for="tc_new_service_id"><?php esc_html_e( 'Service', 'tc-booking' ); ?></label></th>
				<td>
					<select id="tc_new_service_id" name="tc_new_service_id" required>
						<option value=""></option>
						<?php foreach ( $services as $service ) : ?>
							<option value="<?php echo esc_attr( $service->ID ); ?>"><?php echo esc_html( $service->post_title ); ?></option>
						<?php endforeach; ?>
					</select>
					<p class="description"><?php esc_html_e( 'Every service is listed here regardless of location - if this service isn\'t actually offered at the chosen location, saving will show an error rather than silently guessing.', 'tc-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="tc_new_date"><?php esc_html_e( 'Date', 'tc-booking' ); ?></label></th>
				<td><input type="date" id="tc_new_date" name="tc_new_date" required></td>
			</tr>
			<tr>
				<th><label for="tc_new_party_size"><?php esc_html_e( 'Group size', 'tc-booking' ); ?></label></th>
				<td>
					<input type="number" id="tc_new_party_size" name="tc_new_party_size" value="1" min="1" step="1" style="width:80px;">
					<p class="description"><?php esc_html_e( 'Only relevant for a service with "bring anyone with you" enabled - ignored (treated as 1) otherwise.', 'tc-booking' ); ?></p>
				</td>
			</tr>
			<tr>
				<th><label for="tc_new_first_name"><?php esc_html_e( 'First name', 'tc-booking' ); ?></label></th>
				<td><input type="text" id="tc_new_first_name" name="tc_new_first_name" class="regular-text" required></td>
			</tr>
			<tr>
				<th><label for="tc_new_last_name"><?php esc_html_e( 'Last name', 'tc-booking' ); ?></label></th>
				<td><input type="text" id="tc_new_last_name" name="tc_new_last_name" class="regular-text" required></td>
			</tr>
			<tr>
				<th><label for="tc_new_email"><?php esc_html_e( 'Email', 'tc-booking' ); ?></label></th>
				<td><input type="email" id="tc_new_email" name="tc_new_email" class="regular-text" required></td>
			</tr>
			<tr>
				<th><label for="tc_new_phone"><?php esc_html_e( 'Phone', 'tc-booking' ); ?></label></th>
				<td><input type="text" id="tc_new_phone" name="tc_new_phone" class="regular-text"></td>
			</tr>
			<tr>
				<th><label for="tc_new_total"><?php esc_html_e( 'Total price', 'tc-booking' ); ?></label></th>
				<td>
					<input type="number" id="tc_new_total" name="tc_new_total" step="0.01" min="0" placeholder="<?php esc_attr_e( 'Auto (service price × group size)', 'tc-booking' ); ?>">
					<p class="description"><?php esc_html_e( 'Leave blank to use the service\'s own price. Set this to override it (e.g. a phone-arranged discount).', 'tc-booking' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description">
			<?php esc_html_e( 'This creates a confirmed booking directly - no WooCommerce order and no confirmation email are sent, since this form is for a booking already arranged (and typically already paid) outside the online flow. A guide is assigned automatically, the same way an online booking picks one.', 'tc-booking' ); ?>
		</p>
		<?php
	}

	public static function render_booking( $post ) {
		// A booking created through the customer-facing widget or the REST
		// API always has a service attached from the moment it exists - the
		// only way to see one without it is a fresh "Add New" booking an
		// admin is creating by hand (e.g. a phone booking), which is what
		// render_new_booking_form() is for. Once saved, this box always
		// shows the read-only view below - see the note about Cancel/
		// Reschedule at the bottom of this method for why editing an
		// established booking isn't done here.
		if ( ! (int) get_post_meta( $post->ID, '_tc_service_id', true ) ) {
			self::render_new_booking_form( $post );
			return;
		}

		$service_id  = (int) get_post_meta( $post->ID, '_tc_service_id', true );
		$location_id = (int) get_post_meta( $post->ID, '_tc_location_id', true );
		$guide_id    = (int) get_post_meta( $post->ID, '_tc_guide_id', true );
		$date        = get_post_meta( $post->ID, '_tc_date', true );
		$status      = get_post_meta( $post->ID, '_tc_status', true );
		$order_id    = (int) get_post_meta( $post->ID, '_tc_wc_order_id', true );
		$first_name  = get_post_meta( $post->ID, '_tc_customer_first_name', true );
		$last_name   = get_post_meta( $post->ID, '_tc_customer_last_name', true );
		$email       = get_post_meta( $post->ID, '_tc_customer_email', true );
		$phone       = get_post_meta( $post->ID, '_tc_customer_phone', true );
		$extras      = get_post_meta( $post->ID, '_tc_selected_extras', true );
		$party_size  = max( 1, (int) get_post_meta( $post->ID, '_tc_party_size', true ) );
		$guests      = get_post_meta( $post->ID, '_tc_guests', true );
		$admin_note  = get_post_meta( $post->ID, '_tc_admin_note', true );

		$service  = $service_id ? get_post( $service_id ) : null;
		$location = $location_id ? get_post( $location_id ) : null;
		$guide    = $guide_id ? get_post( $guide_id ) : null;
		?>
		<table class="form-table">
			<tr><th><?php esc_html_e( 'Status', 'tc-booking' ); ?></th><td><strong><?php echo esc_html( ucfirst( $status ) ); ?></strong></td></tr>
			<tr><th><?php esc_html_e( 'Customer', 'tc-booking' ); ?></th><td><?php echo esc_html( trim( $first_name . ' ' . $last_name ) ); ?> &mdash; <?php echo esc_html( $email ); ?> &mdash; <?php echo esc_html( $phone ); ?></td></tr>
			<tr><th><?php esc_html_e( 'Service', 'tc-booking' ); ?></th><td><?php echo $service ? esc_html( $service->post_title ) : '&mdash;'; ?></td></tr>
			<tr><th><?php esc_html_e( 'Location', 'tc-booking' ); ?></th><td><?php echo $location ? esc_html( $location->post_title ) : '&mdash;'; ?></td></tr>
			<tr><th><?php esc_html_e( 'Guide', 'tc-booking' ); ?></th><td><?php echo $guide ? esc_html( $guide->post_title ) : '&mdash;'; ?></td></tr>
			<tr><th><?php esc_html_e( 'Date', 'tc-booking' ); ?></th><td><?php echo esc_html( $date ); ?></td></tr>
			<?php if ( $party_size > 1 ) : ?>
				<tr>
					<th><?php esc_html_e( 'Group size', 'tc-booking' ); ?></th>
					<td>
						<?php
						/* translators: %d: total number of people including the customer */
						printf( esc_html__( '%d people (including the customer)', 'tc-booking' ), (int) $party_size );
						?>
					</td>
				</tr>
				<tr>
					<th><?php esc_html_e( 'Additional guests', 'tc-booking' ); ?></th>
					<td>
						<?php if ( is_array( $guests ) && $guests ) : ?>
							<table class="widefat striped" style="max-width:600px;">
								<thead>
									<tr>
										<th><?php esc_html_e( 'Name', 'tc-booking' ); ?></th>
										<th><?php esc_html_e( 'Email', 'tc-booking' ); ?></th>
										<th><?php esc_html_e( 'Phone', 'tc-booking' ); ?></th>
									</tr>
								</thead>
								<tbody>
									<?php foreach ( $guests as $guest ) : ?>
										<tr>
											<td><?php echo esc_html( $guest['name'] ?? '' ) ?: '&mdash;'; ?></td>
											<td><?php echo esc_html( $guest['email'] ?? '' ) ?: '&mdash;'; ?></td>
											<td><?php echo esc_html( $guest['phone'] ?? '' ) ?: '&mdash;'; ?></td>
										</tr>
									<?php endforeach; ?>
								</tbody>
							</table>
						<?php else : ?>
							&mdash;
						<?php endif; ?>
					</td>
				</tr>
			<?php endif; ?>
			<tr><th><?php esc_html_e( 'Extras', 'tc-booking' ); ?></th><td><?php echo is_array( $extras ) && $extras ? esc_html( wp_json_encode( $extras ) ) : '&mdash;'; ?></td></tr>
			<tr>
				<th><?php esc_html_e( 'WooCommerce order', 'tc-booking' ); ?></th>
				<td>
					<?php if ( $order_id && function_exists( 'wc_get_order' ) && wc_get_order( $order_id ) ) : ?>
						<a href="<?php echo esc_url( admin_url( 'post.php?post=' . $order_id . '&action=edit' ) ); ?>">
							<?php
							/* translators: %d order id */
							printf( esc_html__( 'View order #%d', 'tc-booking' ), $order_id );
							?>
						</a>
					<?php else : ?>
						&mdash;
					<?php endif; ?>
				</td>
			</tr>
			<tr>
				<th><label for="tc_admin_note"><?php esc_html_e( 'Admin note', 'tc-booking' ); ?></label></th>
				<td>
					<?php wp_nonce_field( 'tc_save_booking_note', 'tc_booking_note_nonce' ); ?>
					<textarea id="tc_admin_note" name="tc_admin_note" class="large-text" rows="4"><?php echo esc_textarea( $admin_note ); ?></textarea>
					<p class="description"><?php esc_html_e( 'Internal note, not shown to the customer. Saved when you update this booking.', 'tc-booking' ); ?></p>
				</td>
			</tr>
		</table>
		<p class="description"><?php esc_html_e( 'To cancel or reschedule, use the actions on the Bookings list screen - editing fields here (other than the note above) does not re-validate availability.', 'tc-booking' ); ?></p>
		<?php
	}

	public static function save_booking_note( $post_id ) {
		if ( ! isset( $_POST['tc_booking_note_nonce'] ) || ! wp_verify_nonce( $_POST['tc_booking_note_nonce'], 'tc_save_booking_note' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}
		if ( isset( $_POST['tc_admin_note'] ) ) {
			update_post_meta( $post_id, '_tc_admin_note', sanitize_textarea_field( wp_unslash( $_POST['tc_admin_note'] ) ) );
		}
	}

	/**
	 * Saves render_new_booking_form() above. Reuses
	 * TC_Availability::is_bookable()/pick_guide() - the exact same
	 * validation/guide-assignment the customer-facing REST endpoint uses
	 * (TC_Rest_Api::create_booking()) - rather than a second, possibly-
	 * divergent set of rules for the admin form. On any validation
	 * failure, the booking meta is simply left unset: render_booking()'s
	 * "does this booking have a service yet" check means the form just
	 * reappears (with the error above it) for another attempt, no special
	 * redirect handling needed.
	 */
	public static function save_new_booking( $post_id ) {
		// Once a booking has a service, this method's job is done - further
		// edits go through Cancel/Reschedule on the list screen instead
		// (see the note at the end of render_booking()).
		if ( (int) get_post_meta( $post_id, '_tc_service_id', true ) ) {
			return;
		}
		if ( ! isset( $_POST['tc_new_booking_nonce'] ) || ! wp_verify_nonce( $_POST['tc_new_booking_nonce'], 'tc_save_new_booking' ) ) {
			return;
		}
		if ( ! current_user_can( 'edit_post', $post_id ) ) {
			return;
		}

		$location_id = isset( $_POST['tc_new_location_id'] ) ? absint( $_POST['tc_new_location_id'] ) : 0;
		$service_id  = isset( $_POST['tc_new_service_id'] ) ? absint( $_POST['tc_new_service_id'] ) : 0;
		$date        = isset( $_POST['tc_new_date'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_new_date'] ) ) : '';
		$first_name  = isset( $_POST['tc_new_first_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_new_first_name'] ) ) : '';
		$last_name   = isset( $_POST['tc_new_last_name'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_new_last_name'] ) ) : '';
		$email       = isset( $_POST['tc_new_email'] ) ? sanitize_email( wp_unslash( $_POST['tc_new_email'] ) ) : '';
		$phone       = isset( $_POST['tc_new_phone'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_new_phone'] ) ) : '';
		$party_size  = isset( $_POST['tc_new_party_size'] ) ? max( 1, (int) $_POST['tc_new_party_size'] ) : 1;
		$total_input = isset( $_POST['tc_new_total'] ) ? sanitize_text_field( wp_unslash( $_POST['tc_new_total'] ) ) : '';

		// Nothing filled in at all (just a title typed and saved) - not an
		// error, just don't turn this into a half-built booking.
		if ( ! $location_id && ! $service_id && ! $date && ! $first_name && ! $email ) {
			return;
		}

		if ( ! $location_id || ! $service_id || ! preg_match( '/^\d{4}-\d{2}-\d{2}$/', $date ) || ! $first_name || ! is_email( $email ) ) {
			self::new_booking_error( $post_id, __( 'Please fill in location, service, a valid date, first name, and a valid email.', 'tc-booking' ) );
			return;
		}

		$service = TC_Availability::get_service_data( $service_id );
		if ( ! $service ) {
			self::new_booking_error( $post_id, __( 'Unknown service.', 'tc-booking' ) );
			return;
		}

		$party_size = $service['allow_party'] ? min( $party_size, max( 1, (int) $service['max_capacity'] ) ) : 1;

		if ( ! TC_Availability::is_bookable( $service_id, $location_id, $date ) ) {
			self::new_booking_error( $post_id, __( 'That service isn\'t available at that location on that date.', 'tc-booking' ) );
			return;
		}
		$guide_id = TC_Availability::pick_guide( $service_id, $location_id, $date, $party_size );
		if ( ! $guide_id ) {
			// pick_guide() can fail here even though is_bookable() passed -
			// is_bookable() only checks "is anything open," not "is there
			// room for this specific party size." Same caveat as
			// TC_Admin_Bookings::handle_reschedule().
			self::new_booking_error( $post_id, __( 'No guide has room for that group size on that date.', 'tc-booking' ) );
			return;
		}

		$total = is_numeric( $total_input ) ? (float) $total_input : $service['price'] * ( $service['allow_party'] ? $party_size : 1 );

		update_post_meta( $post_id, '_tc_service_id', $service_id );
		update_post_meta( $post_id, '_tc_location_id', $location_id );
		update_post_meta( $post_id, '_tc_guide_id', $guide_id );
		update_post_meta( $post_id, '_tc_date', $date );
		update_post_meta( $post_id, '_tc_status', 'confirmed' );
		update_post_meta( $post_id, '_tc_party_size', $party_size );
		update_post_meta( $post_id, '_tc_guests', array() );
		update_post_meta( $post_id, '_tc_selected_extras', array() );
		update_post_meta( $post_id, '_tc_customer_first_name', $first_name );
		update_post_meta( $post_id, '_tc_customer_last_name', $last_name );
		update_post_meta( $post_id, '_tc_customer_email', $email );
		update_post_meta( $post_id, '_tc_customer_phone', $phone );
		update_post_meta( $post_id, '_tc_total', $total );
		// WPML support - see the matching comment in
		// TC_Rest_Api::create_booking(); harmless/empty on a non-WPML site.
		update_post_meta( $post_id, '_tc_customer_lang', TC_WPML::current_language() );

		// The title typed on "Add New" was just a placeholder to get past
		// WordPress's empty-title guard - replace it with a real one now
		// that there's something to describe, matching create_booking()'s
		// own title convention. Safe from infinite recursion: by the time
		// this runs _tc_service_id is already set above, so the save_post
		// this triggers hits this method's own early-return immediately.
		wp_update_post(
			array(
				'ID'         => $post_id,
				'post_title' => sprintf( '%s - %s - %s', $service['name'], $date, trim( $first_name . ' ' . $last_name ) ),
			)
		);
	}

	private static function new_booking_error( $post_id, $message ) {
		set_transient( 'tc_new_booking_error_' . get_current_user_id() . '_' . $post_id, $message, 60 );
	}
}
