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

		// GitHub follow-up - regular (non-special) availability is now
		// scoped per location too, same as special-service dates already
		// were, so a guide covering two locations can be blocked at one
		// without it affecting the other. location_id 0 means "applies to
		// every location" - both the default for a fresh install's schema
		// here, and what migrate_to_location_scoped_availability() leaves
		// any row that existed before this column did, so a guide's
		// already-set days off keep blocking everywhere they used to
		// rather than silently stop applying anywhere.
		$sql = "CREATE TABLE {$table} (
			id BIGINT UNSIGNED NOT NULL AUTO_INCREMENT,
			guide_id BIGINT UNSIGNED NOT NULL,
			location_id BIGINT UNSIGNED NOT NULL DEFAULT 0,
			availability_date DATE NOT NULL,
			status VARCHAR(20) NOT NULL DEFAULT 'blocked',
			note VARCHAR(255) DEFAULT NULL,
			created_at DATETIME NOT NULL,
			updated_at DATETIME NOT NULL,
			PRIMARY KEY  (id),
			UNIQUE KEY guide_location_date (guide_id, location_id, availability_date),
			KEY guide_id (guide_id),
			KEY availability_date (availability_date)
		) {$charset_collate};";

		dbDelta( $sql );
		self::migrate_to_location_scoped_availability();
	}

	/**
	 * dbDelta() (called just above) can ADD the new location_id column and
	 * the new guide_location_date unique key just fine, but it never DROPS
	 * an index that's no longer in the CREATE TABLE SQL - a well-known
	 * dbDelta limitation. Left alone, the old guide_date UNIQUE KEY
	 * (guide_id, availability_date) would still be enforced and silently
	 * block ever inserting a second location's row for a guide+date that
	 * already has one for a different location. Safe to call on every
	 * request that reaches maybe_upgrade() below - each check is a no-op
	 * once already applied.
	 */
	private static function migrate_to_location_scoped_availability() {
		global $wpdb;
		$table = $wpdb->prefix . 'tc_guide_availability';

		$old_index_exists = $wpdb->get_var(
			$wpdb->prepare(
				"SELECT COUNT(*) FROM INFORMATION_SCHEMA.STATISTICS WHERE TABLE_SCHEMA = %s AND TABLE_NAME = %s AND INDEX_NAME = 'guide_date'",
				DB_NAME,
				$table
			)
		);
		if ( $old_index_exists ) {
			$wpdb->query( "ALTER TABLE {$table} DROP INDEX guide_date" );
		}
	}

	/**
	 * Runs create_tables() again (dbDelta() is safe/idempotent to re-run)
	 * whenever the stored schema version is behind TC_BOOKING_VERSION's
	 * bundled one - the only way an already-active site actually picks up
	 * a schema change: this plugin self-updates from GitHub (see the
	 * PucFactory setup in tc-booking.php), which just replaces files and
	 * never fires register_activation_hook again the way a manual
	 * deactivate/reactivate would.
	 */
	public static function maybe_upgrade() {
		if ( get_option( 'tc_booking_db_version' ) === TC_BOOKING_DB_VERSION ) {
			return;
		}
		self::create_tables();
		update_option( 'tc_booking_db_version', TC_BOOKING_DB_VERSION );
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
