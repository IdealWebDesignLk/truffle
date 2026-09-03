<?php
/**
 * Admin meta boxes for Location, Service, Guide, and Booking (read-only).
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
	/* Booking (read-only)                                               */
	/* ---------------------------------------------------------------- */

	public static function render_booking( $post ) {
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
}
