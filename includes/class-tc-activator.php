<?php
/**
 * Handles plugin activation: custom DB table + guide role.
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_Activator {

	/**
	 * Availability table stores explicit overrides only.
	 *
	 * Design choice: a guide is assumed AVAILABLE on any date with no row here.
	 * Guides block time off by inserting a 'blocked' row; they can also insert
	 * an explicit 'available' row to override a recurring closure (e.g. a
	 * public holiday the admin marked blocked for everyone). This keeps the
	 * common case (guide works most days) low-friction - they only touch the
	 * calendar to mark exceptions, not to re-confirm every working day forever.
	 */
	public static function activate() {
		self::create_tables();
		self::add_guide_role();
		update_option( 'tc_booking_db_version', TC_BOOKING_DB_VERSION );
		flush_rewrite_rules();
	}

	public static function deactivate() {
		flush_rewrite_rules();
		// Intentionally not dropping tables or the guide role on deactivation -
		// only on uninstall (see uninstall.php) so a deactivate/reactivate cycle
		// (plugin update, etc.) never loses guide availability data.
	}

	private static function create_tables() {
		global $wpdb;
		require_once ABSPATH . 'wp-admin/includes/upgrade.php';

		$charset_collate = $wpdb->get_charset_collate();
		$table           = $wpdb->prefix . 'tc_guide_availability';

		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			guide_id BIGINT UNSIGNED NOT NULL,
			availability_date DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'blocked',
			note VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY guide_date (guide_id, availability_date),
			KEY guide_id (guide_id),
			KEY availability_date (availability_date)
		) {$charset_collate};";

		dbDelta( $sql );
	}

	/**
	 * A lightweight role for guides: can log in and reach their own dashboard
	 * (a front-end shortcode page, not wp-admin) but nothing else. Capability
	 * checks in the REST layer additionally confirm a guide only ever touches
	 * their own availability rows, never another guide's or the admin screens.
	 */
	private static function add_guide_role() {
		if ( ! get_role( 'tc_guide' ) ) {
			add_role(
				'tc_guide',
				__( 'Ceremony Guide', 'tc-booking' ),
				array(
					'read'                       => true,
					'tc_manage_own_availability' => true,
				)
			);
		}
	}
}
