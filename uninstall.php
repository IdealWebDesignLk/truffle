<?php
/**
 * Runs only when the plugin is deleted from wp-admin (not on deactivation).
 *
 * Deliberately conservative: drops the guide-availability table and the
 * custom role, since those are purely internal plumbing, but leaves all
 * Location/Service/Guide/Booking post data in place. A merchant deleting
 * the plugin by accident, or to reinstall a newer copy, should not lose
 * their booking history as a side effect.
 *
 * @package TC_Booking
 */

if ( ! defined( 'WP_UNINSTALL_PLUGIN' ) ) {
	exit;
}

global $wpdb;
$wpdb->query( "DROP TABLE IF EXISTS {$wpdb->prefix}tc_guide_availability" );

remove_role( 'tc_guide' );

delete_option( 'tc_booking_db_version' );
