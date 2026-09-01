<?php
/**
 * Registers the custom post types backing the booking system.
 *
 * All four types are admin/REST data records, not public content - none of
 * them get a public single template. Bookings is the top-level admin menu;
 * Locations/Services/Guides nest under it as submenus (standard WP trick:
 * point their show_in_menu at the parent CPT's edit.php screen).
 *
 * @package TC_Booking
 */

if ( ! defined( 'ABSPATH' ) ) {
	exit;
}

class TC_CPT {

	const BOOKING  = 'tc_booking';
	const LOCATION = 'tc_location';
	const SERVICE  = 'tc_service';
	const GUIDE    = 'tc_guide';

	public static function init() {
		add_action( 'init', array( __CLASS__, 'register' ) );
	}

	public static function register() {
		self::register_booking();
		self::register_location();
		self::register_service();
		self::register_guide();
	}

	private static function register_booking() {
		register_post_type(
			self::BOOKING,
			array(
				'label'               => __( 'Bookings', 'tc-booking' ),
				'labels'               => array(
					'name'          => __( 'Bookings', 'tc-booking' ),
					'singular_name' => __( 'Booking', 'tc-booking' ),
					'add_new_item'  => __( 'Add Booking', 'tc-booking' ),
					'edit_item'     => __( 'Edit Booking', 'tc-booking' ),
				),
				'public'               => false,
				'show_ui'              => true,
				'show_in_menu'         => true,
				'menu_icon'            => 'dashicons-calendar-alt',
				'menu_position'        => 25,
				'supports'             => array( 'title' ),
				'capability_type'      => 'post',
				'map_meta_cap'         => true,
				'show_in_rest'         => false, // Exposed only via our own tc/v1 endpoints, not wp/v2.
				'exclude_from_search'  => true,
				'publicly_queryable'   => false,
			)
		);
	}

	private static function register_location() {
		register_post_type(
			self::LOCATION,
			array(
				'label'              => __( 'Locations', 'tc-booking' ),
				'labels'             => array(
					'name'          => __( 'Locations', 'tc-booking' ),
					'singular_name' => __( 'Location', 'tc-booking' ),
					'add_new_item'  => __( 'Add Location', 'tc-booking' ),
					'edit_item'     => __( 'Edit Location', 'tc-booking' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::BOOKING,
				'supports'           => array( 'title' ),
				'show_in_rest'       => false,
				'exclude_from_search' => true,
				'publicly_queryable' => false,
			)
		);
	}

	private static function register_service() {
		register_post_type(
			self::SERVICE,
			array(
				'label'              => __( 'Services', 'tc-booking' ),
				'labels'             => array(
					'name'          => __( 'Services', 'tc-booking' ),
					'singular_name' => __( 'Service', 'tc-booking' ),
					'add_new_item'  => __( 'Add Service', 'tc-booking' ),
					'edit_item'     => __( 'Edit Service', 'tc-booking' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::BOOKING,
				// 'page-attributes' adds the standard WordPress "Order" field
				// (menu_order) to the edit screen, so admins can control the
				// sequence services appear in on the front-end - see the
				// 'orderby' used in TC_Rest_Api::get_services(). GitHub issue
				// #64 asked for a specific display order; before this there
				// was no way to set one at all (front-end was always
				// alphabetical by title).
				'supports'           => array( 'title', 'editor', 'page-attributes' ), // editor = short customer-facing description
				'show_in_rest'       => false,
				'exclude_from_search' => true,
				'publicly_queryable' => false,
			)
		);
	}

	private static function register_guide() {
		register_post_type(
			self::GUIDE,
			array(
				'label'              => __( 'Guides', 'tc-booking' ),
				'labels'             => array(
					'name'          => __( 'Guides', 'tc-booking' ),
					'singular_name' => __( 'Guide', 'tc-booking' ),
					'add_new_item'  => __( 'Add Guide', 'tc-booking' ),
					'edit_item'     => __( 'Edit Guide', 'tc-booking' ),
				),
				'public'             => false,
				'show_ui'            => true,
				'show_in_menu'       => 'edit.php?post_type=' . self::BOOKING,
				'supports'           => array( 'title', 'editor', 'thumbnail' ), // editor = bio, thumbnail = photo
				'show_in_rest'       => false,
				'exclude_from_search' => true,
				'publicly_queryable' => false,
			)
		);
	}
}
