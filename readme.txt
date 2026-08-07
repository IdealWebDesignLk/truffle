=== TC Booking ===
Contributors: idealwebdesign
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.7.1
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

= 0.7.1 =
* Fixed shared services still showing "fully booked" after only part of
  the capacity was used (e.g. 2 of 4 seats taken). The guide-conflict
  check treated the service's own existing seat-holders on the same
  date as a scheduling conflict, blocking every further booking
  regardless of remaining capacity - it now correctly only treats a
  genuinely different service (or a different session of the same
  service) as a conflict. Resolves GitHub issue #19.

= 0.7.0 =
* Added a new "Allow sharing remaining seats" checkbox on Services,
  separate from Max capacity. A service with Max capacity 4 no longer
  automatically means strangers can fill each other's leftover seats -
  that's now opt-in via this checkbox. Off (the default) means the
  first booking closes the whole date to everyone else even if it
  doesn't use the full capacity - for a private booking of e.g. 3
  people that shouldn't leave the 4th seat open to a stranger. On
  keeps the v0.6.2 sharing behavior for genuinely public/shared
  services. Follow-up to GitHub issue #18.

= 0.6.2 =
* Fixed shared/group services (Max capacity > 1): booking part of the
  capacity (e.g. 2 of 4 seats) now correctly leaves the remaining seats
  open for other customers to book, instead of closing the whole date.
  The availability grid already showed this correctly ("limited"); guide
  assignment itself didn't respect it. Also fixed rescheduling a group
  booking to correctly require room for its whole party, and fixed
  admin reschedule silently succeeding with no guide assigned if none
  was actually available. Clarified the Max capacity field's
  description (individual vs shared). Resolves GitHub issue #18.
* Removed the redundant "Cancel" text button from the reschedule modal
  - the × icon is enough. Resolves GitHub issue #17.

= 0.6.1 =
* All "today"/calendar-navigation logic (booking widget, guide dashboard,
  admin guide calendar, reschedule modal) now anchors to the Netherlands'
  own calendar day (Europe/Amsterdam), not the visitor's or admin's own
  device timezone - this business only operates in the Netherlands, so a
  customer or admin browsing from anywhere else must still see and book
  against the same "today" everyone else does. Also fixed two server-side
  default date ranges that used gmdate() (always UTC) instead of the
  site's configured timezone. Requires Settings -> General -> Timezone
  to be set to Amsterdam - see PROJECT_NOTES.md.

= 0.6.0 =
* Booking Details in wp-admin now shows the full guest list (name, email,
  phone) for group bookings, and admins can add an internal note to any
  booking. Resolves GitHub issue #15.
* Replaced the reschedule prompt() with a real availability calendar
  modal - the same available/limited/unavailable view customers see,
  so you can pick a new date visually instead of typing one blind.
  Resolves GitHub issue #16.

= 0.5.0 =
* Fixed checkout being unable to take payment on every booking - the fee
  line items were being added via a WooCommerce method that doesn't
  actually exist (`WC_Order::add_fee()`), silently leaving every order
  at a real $0 total that WooCommerce correctly refused to charge for.
  Now builds real WC_Order_Item_Fee line items. Resolves GitHub issue
  #11.
* Fixed a date-shift bug where booking widget/guide calendar dates were
  computed via UTC conversion, silently shifting the stored date back a
  day for any browser in a timezone ahead of UTC (e.g. the Netherlands).
  Related to GitHub issue #14 - see PROJECT_NOTES.md for what's still
  unconfirmed here.
* The booking widget now requires and validates "Your details" and (if
  bringing a group) each guest's details before continuing - name/email
  required, phone restricted to phone-like characters as you type and
  validated, email format validated. Resolves GitHub issues #7, #8, #9,
  #10.
* The "missing booking fields" error now names the specific missing
  fields instead of a generic message. Resolves GitHub issue #12.
* Loading screens (booking widget, guide dashboard) now show a skeleton
  placeholder shaped like the real first screen instead of plain text,
  eliminating the layout shift when it loads. Resolves GitHub issue #13.

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
