=== TC Booking ===
Contributors: idealwebdesign
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.4.0
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
7. Create a page with the shortcode [tc_guide_dashboard] - give guides this URL plus their login, so they can manage their own availability. Admins can also view/edit any guide's calendar directly from Bookings -> Guides -> (edit a guide) -> Availability Calendar, without needing to log in as them.

== Changelog ==

= 0.4.0 =
* New "Bring anyone with you" feature: a checkbox on a Service enables a
  group-size step in the booking widget (capped at that service's Max
  capacity, including the customer themself), collects each additional
  guest's name/email/phone, multiplies the base price per person, and
  surfaces the guest list in the WooCommerce order. Resolves GitHub
  issue #6.
* Widened the booking widget so the availability calendar is more
  visible. Resolves GitHub issue #3.
* Guide preview now loads instantly when picking a location, instead of
  visibly lagging - all locations' guide previews are fetched once up
  front instead of one request per click. Resolves GitHub issue #4.
* Fixed the +/- buttons on extra quantities rendering off-center inside
  their circles. Resolves GitHub issue #5.

= 0.3.0 =
* Extras on a Service now have an optional description field (admin: edit
  screen extras repeater; front end: shown under the extra's label/price
  in the booking widget's Extras step). Resolves GitHub issue #1.
* Admins can now view and edit a guide's own-availability calendar
  directly from the Guide edit screen in wp-admin, instead of only via
  the guide's own [tc_guide_dashboard] login. Resolves GitHub issue #2.

= 0.2.1 =
* Fixed a bug where the guide edit screen's "None" dropdown option and
  the booking widget / guide dashboard "Loading..." placeholders showed
  raw backslash-u escape codes instead of an em dash / ellipsis -
  those PHP strings used JS-style unicode escapes that PHP doesn't
  interpret.

= 0.2.0 =
* Added GitHub-based update checker so the plugin can be updated from
  wp-admin -> Updates like a wordpress.org plugin, pulling releases from
  the IdealWebDesignLk/truffle GitHub repo. See PROJECT_NOTES.md for the
  release process.

= 0.1.0 =
* Initial build: CPTs, admin panel, availability engine, REST API, WooCommerce integration, guide self-service, admin cancel/reschedule, email notifications.
