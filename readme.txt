=== TC Booking ===
Contributors: idealwebdesign
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.1.0
License: GPLv2 or later

Custom booking system for truffelceremonie.com. Replaces the Amelia-based booking widget with a purpose-built flow: location (with map) -> availability grid -> extras -> details -> review -> WooCommerce checkout. Guides manage their own calendar through a front-end self-service dashboard.

== Description ==

Built to the agreed scope: date-only booking (no time-of-day slot picker), guide self-service availability, WooCommerce checkout, admin cancel/reschedule, email notifications, no SMS.

See PROJECT_NOTES.md in the plugin root for architecture decisions and the Amelia research this replaced.

== Setup ==

1. Activate the plugin (requires WooCommerce active).
2. Under Bookings -> Locations, add each location: address, province, and latitude/longitude (for the map).
3. Under Bookings -> Services, add each ceremony: price, duration in days, display start time, capacity, and extras.
4. Under Users, create an account per guide with the "Ceremony Guide" role.
5. Under Bookings -> Guides, add each guide: link their user account, tick which locations and services they cover.
6. Create a page with the shortcode [tc_booking_widget] - this is the customer-facing booking form.
7. Create a page with the shortcode [tc_guide_dashboard] - give guides this URL plus their login, so they can manage their own availability.

== Changelog ==

= 0.1.0 =
* Initial build: CPTs, admin panel, availability engine, REST API, WooCommerce integration, guide self-service, admin cancel/reschedule, email notifications.
