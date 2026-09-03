=== TC Booking ===
Contributors: idealwebdesign
Requires at least: 6.0
Tested up to: 6.6
Requires PHP: 8.0
Stable tag: 0.21.0
License: GPLv2 or later

Custom booking system for truffelceremonie.com. Replaces the Amelia-based booking widget with a purpose-built flow: location (with map) -> availability grid -> extras -> details -> review -> WooCommerce checkout. Guides manage their own calendar through a front-end self-service dashboard.

== Description ==

Built to the agreed scope: date-only booking (no time-of-day slot picker), guide self-service availability, WooCommerce checkout, admin cancel/reschedule, email notifications, no SMS.

See PROJECT_NOTES.md in the plugin root for architecture decisions and the Amelia research this replaced.

== Setup ==

1. Activate the plugin (requires WooCommerce active).
2. Under Bookings -> Locations, add each location: address, province, and latitude/longitude (for the map).
3. Under Bookings -> Services, add each ceremony: price, duration in days, display start time, capacity, and extras. To control the order they appear in on the booking widget, set the "Order" field in the Page Attributes box on each Service's edit screen (lower numbers show first; services left at the default all show in alphabetical order relative to each other).
4. Under Users, create an account per guide with the "Ceremony Guide" role.
5. Under Bookings -> Guides, add each guide: link their user account, tick which locations and services they cover.
6. Create a page with the shortcode [tc_booking_widget] - this is the customer-facing booking form.
7. Create a page with the shortcode [tc_guide_dashboard] - give guides this URL plus their login, so they can manage their own availability. Admins can also view/edit any guide's calendar directly from Bookings -> Guides -> (edit a guide) -> Availability Calendar, without needing to log in as them.

== Changelog ==

= 0.21.0 =
* Fixed the actual WPML structural bug behind guide calendars not
  syncing across languages: Services and Guides were registered fully
  "Translatable," which makes WPML create a separate post - a separate
  ID - per language. A Guide isn't just content though: its
  availability calendar and WordPress login are both tied to one
  specific post ID, so three duplicate posts per guide meant a guide's
  own dashboard changes lived under one language's ID while customer
  bookings in another language resolved a different ID for "the same"
  guide. Switched Services and Guides to WPML's "Display as Translated"
  mode instead - one canonical post, still with a per-language
  title/content - and hardened the guide-dashboard's own lookup query,
  which had no defined order and could resolve the wrong duplicate.
  **If your site already has WPML-duplicated Guide/Service posts from
  before this update, they need to be manually cleaned up** (delete the
  extra per-language copies, keep the original) - switching this
  setting doesn't retroactively merge them. See PROJECT_NOTES.md's
  "WPML support" section for the full explanation.

= 0.20.1 =
* Fixed the plugin header description (shown on the Plugins screen in
  wp-admin) - it claimed Amelia was still used as a scheduling backend
  "where configured," which was never true of this build and
  contradicted this file's own description above. Corrected to match:
  Amelia's booking widget was fully replaced, not kept as a backend.

= 0.20.0 =
* Step 2 (the ceremony picker) now only shows services actually offered
  at the chosen location - previously it showed every service
  regardless of location, even ones no guide there covers. The
  `/services` REST route accepts an optional `location_id` filter
  (services with at least one guide covering that location+service
  pair), and the booking widget now re-fetches scoped to the chosen
  location instead of loading the full catalog once at the start.
  Resolves GitHub issue #66.

= 0.19.1 =
* Locations are no longer registered translatable in WPML (Services and
  Guides still are) - they're addresses/coordinates, not content that
  reads differently per language. Resolves GitHub issue #65.

= 0.19.0 =
* Services can now be put in a specific display order. Each Service's
  edit screen has a new "Order" field (standard WordPress Page
  Attributes support); the booking widget now sorts services by that
  number first, falling back to alphabetical for any left unset.
  Previously there was no way to control this at all - the front-end
  was always alphabetical by title. Resolves GitHub issue #64. Enter
  the actual order values on the live site's Services screen (Order 1
  = shown first, and so on) - see the Setup section below.

= 0.18.1 =
* Fixed the map's own bottom margin doubling up with the grid gap
  already handling that space on mobile - was leaving 24px instead of
  10px between the map and whatever follows it (guide box or list).
  Resolves GitHub issue #62.
* Ceremony cards (Step 2) now show name, duration, and price on one line
  on mobile, with the name truncating with an ellipsis if it's too long
  to fit alongside the duration/price, instead of wrapping onto its own
  line above them. Resolves GitHub issue #63.

= 0.18.0 =
* Ceremony cards (Step 2) are back to one column on mobile only - desktop
  and tablet stay two columns. Resolves GitHub issue #57.
* Step 2's title now reads "Location: [name]" instead of "Availability at
  [name]", and its subtitle reads "Choose a service. Dates will appear
  below." Resolves GitHub issues #58 and #61.
* Removed the "STEP X OF Y" text above every step's title - the
  segmented progress bar is the only step indicator left. Resolves
  GitHub issue #59.
* Tighter spacing on mobile between the map and guide preview, and
  between the location list and the Continue button below it (desktop
  spacing unchanged). Resolves GitHub issue #60.

= 0.17.0 =
* Added WPML support. Locations, Services, and Guides are now registered
  translatable (Bookings stay untranslatable - they're transactional
  records, not content) via a new `wpml-config.xml`, which WPML reads
  automatically - no manual setup needed on the live site. Guide<->
  Location/Service assignments are stored by post ID, which WPML gives a
  different ID per language, so those lookups (the guide-matching logic
  every availability/booking check funnels through) now normalize IDs to
  the site's default language before comparing, both when a Guide is
  saved and when a booking is checked. The booking widget also passes its
  active language back on its catalog requests, since a REST call doesn't
  always carry the same language context a normal page load would. See
  PROJECT_NOTES.md's new "WPML support" section for the full design and
  what's not yet been verified against a real WPML install.

= 0.16.0 =
* The guide preview moved again: under the map on mobile, and under the
  location list on desktop, with the map now starting flush at the top
  of its column (no gap where the guide box used to sit in the heading
  row above it). The location step's layout is now a single CSS grid
  with named areas that differ per breakpoint, so the same guide-preview
  element can reposition without being rendered twice - this also
  removes the earlier flex-basis alignment workaround entirely, since
  grid placement doesn't have that quirk. Resolves GitHub issues #54
  and #55.
* Ceremony cards (Step 2) use smaller padding/font on mobile, so more of
  the calendar underneath is visible without scrolling. Resolves GitHub
  issue #56.

= 0.15.0 =
* Locations (Step 1) and ceremony cards (Step 2) now stay two columns on
  mobile instead of collapsing to one, so both need less scrolling on
  phones. Resolves GitHub issues #49 and #50.
* Calendar prev/next buttons are now equal-size squares, cleanly aligned
  with the month label between them. Resolves GitHub issue #51.
* "Back" is now a proper outlined button (with a `←` prefix) instead of
  unstyled plain text, consistently on every step that has one.
  Resolves GitHub issues #51 and #52.
* Extras & quantity (Step 4): each extra's title now spans the full row
  width, price/max sits below it with an (i) info icon, and the
  description opens in a popup instead of always rendering inline -
  less clutter, easier to scan. Resolves GitHub issue #53.

= 0.14.0 =
* New "Limit by seats" checkbox on each extra (Service edit screen,
  Extras repeater). When ticked, that extra's purchasable quantity is
  capped at however many people are in the booking (the party size
  chosen earlier in the flow, or 1 for services that don't use "bring
  anyone with you"), instead of always allowing up to its own
  configured Max qty. Enforced both in the booking widget and again
  server-side when the booking is created. Resolves GitHub issue #48.

= 0.13.0 =
* A multi-word city name on the map now breaks onto two lines under its
  pin instead of running on as one long line. Resolves GitHub issue #45.
* The location list drops the province from each city's label (city
  name only, matching the map pins) and is now two columns instead of
  one long scrolling list, so every city is visible without scrolling.
  Resolves GitHub issue #46.
* Extras step: each extra's name is now bigger, bolder, and purple so
  it's easier to scan down the list; row padding opened up a little
  too. Resolves GitHub issue #47.

= 0.12.2 =
* Rolled back 0.12.0 and 0.12.1 at the client's request: the
  availability step is back to ceremony cards on top with the selected
  ceremony's calendar below (not the full month table), and the widget
  is back to its 860px width (not 1140px). GitHub issue #44.

= 0.12.1 (reverted in 0.12.2) =
* Widened the booking widget from 860px to 1140px, matching the site's
  normal container width - mainly so the availability table shows more
  of the month at once without scrolling.

= 0.12.0 (reverted in 0.12.2) =
* Brought back the original availability table (every ceremony as a
  row, dates as columns, tap any open cell to pick both at once) that
  GitHub issue #21 had replaced with cards + a calendar - the client
  preferred it, and asked for a full month of columns instead of the
  original 7-day window (horizontally scrollable, same as before). Also
  fixed a latent bug this surfaced: the party-size cap was matching the
  selected date's grid cell by date only, which was only safe when the
  grid held one service's data at a time - now matches by service too,
  since the table loads every service's availability at once. Resolves
  GitHub issue #43.

= 0.11.0 =
* The ceremony picker and its calendar are back on one view (issue #38
  had split them into two separate steps) - picking a ceremony card
  shows that ceremony's calendar directly below it again, same screen.
  Resolves GitHub issue #42.

= 0.10.3 =
* Fixed the guide preview column still sitting a little left of the map
  column below it, even after 0.10.2's matching flex-basis/gap - some
  browsers were mis-measuring the guide card's flex-basis because of the
  clamped bio text inside it, giving it a few extra pixels over its 50%
  share. Switched that row to CSS Grid with minmax(0,1fr) tracks, which
  isn't subject to that flex quirk, so the two columns now line up
  exactly (verified pixel-for-pixel via getBoundingClientRect).

= 0.10.2 =
* The guide preview stays in the same row as the "Pick a location"
  heading, but its column now matches the map column's width/position
  below exactly (same two-column split and gap as the map/list row), so
  it lines up with the map instead of being a small floating box.

= 0.10.1 =
* The guide preview's name/bio column is now width-bounded and wraps
  instead of running past the card edge for a long guide name, and now
  shows a short two-line bio preview alongside the "Read more" button
  instead of name-only. Resolves GitHub issue #39.
* Map location dots and labels sized down a little. Resolves GitHub
  issue #40.
* Fixed the guide "Read more" link showing the wrong color and picking
  up extra padding on hover/focus - both were native browser <button>
  chrome bleeding through because only the base (non-hover) state had
  been styled. Resolves GitHub issue #41.

= 0.10.0 =
* The availability calendar is now its own step (was appearing directly
  below the ceremony cards on the same screen) and is back to filling
  the full card width like it did before the 0.9.1 compacting. Resolves
  GitHub issue #38.
* The map's viewBox now has some padding beyond the fitted map shape, and
  a pin label near the coast now flips to the left of its pin instead of
  running off the edge - both together fix place names getting clipped
  (e.g. a location cut down to a single letter) and make labels read a
  little smaller. Resolves GitHub issue #37.
* The guide preview on the location step no longer sits above the map as
  its own block (which pushed the map down whenever a guide loaded); it's
  now a compact circular photo + name next to the "Pick a location"
  heading, with a "Read more" button that opens the full bio in a popup.
  Resolves GitHub issue #36.

= 0.9.2 =
* Fixed the 0.9.1 release shipping with `TC_BOOKING_VERSION` still set to
  0.9.0 - that constant is what's appended as the `?ver=` cache-busting
  query string on every enqueued front-end CSS/JS file, so a site that
  had already cached the pre-0.9.1 assets kept serving them even after
  updating to 0.9.1, since the URL never actually changed. Now correctly
  matches the plugin header version, so updating always busts the cache.

= 0.9.1 =
* Guide details now show above the map on the location step instead of
  below it, and the map is sized down slightly. Resolves GitHub issue
  #31.
* The availability calendar's "N left" remaining-seat label is now
  larger and fully opaque. Resolves GitHub issue #33.
* Availability calendar date boxes are more compact (capped grid width,
  slightly shorter cells) instead of stretching to fill the full card
  width on wider screens. Resolves GitHub issue #34.
* A few spots left over from the green-to-purple rebrand (issue #22)
  were still using the calendar's green "available" tint purely as a
  generic selected/success color - the location-step selection summary,
  selected location row, and selected service card now use a purple
  tint instead. The calendar's own available/limited/unavailable status
  colors are unchanged. Resolves GitHub issue #32.

= 0.9.0 =
* Booking review now shows the main booker's own name/email/phone, not
  just guest names. Resolves GitHub issue #30.
* A "Ceremony · Date" summary now shows above the Extras step, so the
  selection made on the calendar doesn't disappear from view. Resolves
  GitHub issue #29.
* Bolded the "earlier"/"later" navigation buttons. Resolves GitHub
  issue #28.
* Location address is no longer shown to customers, just the location
  name. Resolves GitHub issue #27.
* Brand accent (buttons, progress bar, selection borders/focus, map
  pins) switched from green to purple to match Truffle Ceremonie's
  branding; the availability calendar's available/almost-full/not
  available colors deliberately stay green/amber/grey, since that's a
  separate, widely understood convention. Resolves GitHub issue #22.
* Redesigned the availability step: instead of one 7-day table listing
  every ceremony as a row, pick a ceremony from cards first, then see
  that ceremony's own month calendar (with remaining-seat counts on
  shared dates). Resolves GitHub issue #21.
* On desktop, the location map now sits to the right of the location
  list instead of above it. Resolves GitHub issue #24.
* The location map can now grow substantially larger within its
  container, so it (and its labels) are easier to read. Resolves
  GitHub issue #25.
* Guide photos are bigger, vertical instead of round, and open a
  full-size view when clicked. Resolves GitHub issue #26.
* Regenerated the Netherlands map outline from real, full-detail
  coastline data - the mainland is far more accurately shaped, and the
  Wadden Islands (Texel, Vlieland, Terschelling, Ameland,
  Schiermonnikoog) are now included. Resolves GitHub issue #23.

= 0.8.0 =
* Fixed "how many people are you bringing" letting a customer select
  more than what's actually left on a shared service's date - it was
  capped at the service's static Max capacity instead of the real
  remaining seats for that specific date (e.g. offering up to 4 when
  only 2 were actually left). Resolves GitHub issue #20.
* The availability calendar now shows a small "N left" label on shared
  service dates, so customers can see remaining seats before picking a
  date. Resolves GitHub issue #20.

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
