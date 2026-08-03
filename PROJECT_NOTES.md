# TC Booking - Project Notes

Read this before touching the code in VS Code. It explains *why* things are
built the way they are, not just what they do - the goal is that decisions
made during scoping don't have to be rediscovered from the code alone.

## What this is

A fully custom WordPress plugin replacing the Amelia booking widget on
truffelceremonie.com. This is **not** a hybrid - Amelia is not used as a
backend here. Early scoping considered keeping Amelia (Elite REST API) as
the scheduling engine and only replacing the front-end, which would have
been cheaper and lower-risk. The client confirmed budget for the full
custom build instead, so this plugin owns the entire stack: data model,
availability engine, admin panel, and WooCommerce integration.

The Amelia research done during scoping is still worth knowing, because it
shaped several design decisions below (see "Decisions carried over from the
Amelia audit").

## Architecture at a glance

```
tc-booking.php              Bootstrap, autoloader, activation hooks
includes/
  class-tc-activator.php    Creates wp_tc_guide_availability table + 'tc_guide' role
  class-tc-cpt.php          4 custom post types: tc_location, tc_service, tc_guide, tc_booking
  class-tc-meta-boxes.php   Admin fields for each CPT
  class-tc-availability.php Core conflict-checking engine (see below - read this one carefully)
  class-tc-rest-api.php     tc/v1 REST routes - the only way the front-end talks to WordPress
  class-tc-woocommerce.php  Creates WC orders from bookings, syncs status back
  class-tc-notifications.php Email only, no SMS (final scope dropped SMS)
  class-tc-guide-dashboard.php  [tc_guide_dashboard] shortcode
  class-tc-booking-shortcode.php [tc_booking_widget] shortcode
  class-tc-admin-bookings.php  Cancel/reschedule row actions on the Bookings list
admin/                      Extras-repeater JS/CSS for the Service edit screen
public/                     Customer-facing app + guide dashboard (vanilla JS, no build step)
uninstall.php               Drops the custom table on delete; leaves booking data alone
```

CPTs are **not** exposed via the default `wp/v2` REST namespace
(`show_in_rest => false` on all four) - everything goes through `tc/v1`,
which shapes data for the front-end and enforces booking-specific
validation that generic CPT endpoints don't know about.

## The availability engine (`class-tc-availability.php`)

This is the piece flagged throughout scoping as the hardest, most
bug-prone part of the whole build. A few things worth understanding before
changing it:

- **Guides are assumed available by default.** The `wp_tc_guide_availability`
  table only stores *exceptions* - a `blocked` row for a day off, or an
  `available` row to override a blanket closure. This was a deliberate
  low-friction choice (see readme setup) so guides aren't re-confirming
  every working day forever.
- **Multi-day services block every day in their span, not just the start
  date.** This exists specifically because of the overnight retreat - see
  "the duration conversation" below. `duration_days` on a Service drives
  this.
- **The grid and the booking-creation endpoint call the exact same
  function** (`is_bookable()`). This is intentional and should never be
  forked - if the grid and the actual booking check ever diverge, customers
  will see availability that isn't real.
- **Known gap: no DB transaction/locking around the check-then-insert in
  `create_booking()`.** Two customers submitting for the last spot at the
  exact same instant could theoretically both succeed. Low real-world risk
  for ceremony bookings (they're not high-frequency flash-sale purchases),
  but worth a wrapped transaction or a unique-constraint-with-retry if this
  ever needs hardening.

## The duration conversation

The client originally asked "does duration still matter, now that there's
no time picker?" The answer that shaped this build: mostly no, except the
overnight retreat spans two calendar days and needs to block the guide's
following morning too. That's exactly what `duration_days` is for - it's
not shown to customers (no time-of-day UI at all now), it's purely a
calendar-blocking mechanism. `start_time` is stored separately, purely for
display in emails/admin, not used in any availability logic.

## Decisions carried over from the Amelia audit

Even though Amelia itself isn't used, a few things learned from picking
apart its data model directly shaped this build:

- **Real capacity pricing instead of Amelia's fixed-price-extras
  workaround.** Amelia had no notion of per-seat pricing, so "+1/+2/+3
  extra person" were three separate fixed-price extras. This plugin keeps
  that same *pattern* in the extras repeater (still just label/price/max
  rows) rather than building true per-seat pricing, because rebuilding the
  extras UI as a capacity-aware pricing model was out of scope - but see
  `party_size` handling in `class-tc-rest-api.php`'s `create_booking()`: it
  detects extras matching the naming convention `extra-N-person` and grows
  the party size accordingly, so group-capacity math still works correctly
  even though the underlying extra is still "just a fixed-price line item."
  If a future extra needs to affect capacity and doesn't fit that naming
  convention, this detection will silently miss it - worth an explicit
  "counts as N people" field on the extra if this comes up again.
- **Guides are a first-class entity, not an employee-as-resource hack.**
  Amelia had no separate "resource" concept, so group ceremonies were
  represented as fake employee records per location. This plugin gives
  Guides real location + service assignments (checkboxes in the meta box),
  so there's no need for that workaround here.
- **WooCommerce via fees, not products.** Keeps the Service/Extra price
  snapshot on the booking as the single source of truth rather than
  needing to keep a shadow WC_Product in sync with every price edit.

## Front-end (`public/js/booking-app.js`, `guide-dashboard.js`)

Vanilla JS, no build step, on purpose - this is meant to be opened straight
in VS Code and iterated on without a toolchain first. Both files were
functionally tested (not just syntax-checked) against a mocked version of
the REST API before being handed off; see the flow below matches what was
already designed and approved with the client:

Location (+ map, guide preview) -> Availability grid -> Extras -> Details
-> Review -> redirect to WooCommerce checkout.

No in-app confirmation screen - WooCommerce's own checkout/thank-you page
is the confirmation, since payment happens there.

### The map

The Netherlands outline (`NL_OUTLINE` in `booking-app.js`) and the
projection constants (`MERC_SCALE`, `MERC_TRANSLATE`) came from real
Natural Earth boundary data (via the `world-atlas` / `d3-geo` npm packages,
public domain), not hand-drawn - an earlier hand-drawn version looked
wrong and was replaced for exactly that reason. The Netherlands entry in
that dataset includes Aruba/Curacao/Sint Maarten (Kingdom of the
Netherlands), which had to be filtered out by longitude before generating
the outline.

Pin positions are computed live in the browser from each Location's
lat/lng (set in the admin meta box) using the same Mercator formula the
outline was generated with, so **any** location works automatically - not
just the ones used during prototyping. If the map ever needs to be
regenerated (e.g. higher detail, or a different region), the generation
script approach is: `world-atlas` countries-10m.json -> filter to the
target country -> `topojson-simplify` -> `d3-geo` `geoMercator().fitExtent()`
-> `geoPath()`. Don't hand-draw a country outline again.

## Testing performed

This has been tested against a **real WordPress + MySQL install**, not just
`php -l` syntax checks - WordPress core (from the official GitHub mirror)
and MariaDB were installed, the plugin was activated, and real REST
requests were dispatched through `rest_do_request()` against real database
data. Real WooCommerce wasn't reachable (it's distributed via
wordpress.org, not downloadable from anywhere this environment can reach)
so a minimal stub covering only the functions/classes this plugin calls
stood in for it - enough to verify the integration points (order created,
fees added, status-change hook fires) without a real payment gateway.

What was verified end-to-end this way:
- Plugin activates cleanly, all 4 CPTs register, the custom
  `wp_tc_guide_availability` table is created with the correct schema, the
  `tc_guide` role exists, all `tc/v1` REST routes register
- Full booking lifecycle: create -> shows correctly in admin list -> grid
  reflects it as unavailable -> reschedule (old date frees, new date
  blocks) -> cancel (date frees again)
- Party-size-affecting extras correctly change the total and (for group
  services) the capacity math
- **The overnight-retreat scenario from the client conversation**: booking
  the 2-day service correctly blocks the guide across both days for *any*
  other service, and correctly releases on day 3 - this was the specific
  case the "does duration still matter" conversation was about, and it
  works.
- Guide self-service: a guide can block/unblock their own dates and it
  correctly affects the public grid; a non-guide user correctly gets a 403
  when trying to hit the guide-only endpoint (permission boundary holds)

**One real bug was found and fixed by this testing**, not caught by syntax
checking or code review: `_tc_location_ids` / `_tc_service_ids` were
originally stored as a single serialized array per guide, with lookups
using a `LIKE '"4"'` match against the serialized value. This silently
never matched, because PHP serializes integers unquoted (`i:4;`, not
`"4"`) - and even a corrected quoting wouldn't have been fully safe, since
an array's own index markers (`i:1;`) can collide with a genuine value
being searched for. Fixed by storing one meta row per ID (the standard
WordPress pattern for this kind of relationship) and querying with a plain
`meta_query` value match instead. This is exactly the class of bug that
only shows up when something real actually queries the database - worth
remembering as a reason to keep testing against a real WP install as this
evolves, rather than trusting syntax/logic review alone for anything
touching postmeta relationships.

## What's NOT done yet

- WPML wiring - the CPTs/fields exist but aren't yet registered with WPML's
  translation config on the live site.
- Reschedule admin UI is a plain `prompt()` for the new date (see
  `class-tc-admin-bookings.php`) - functional, not polished. A real
  date-picker modal is a good first improvement.
- No automated PHP test suite - everything so far is `php -l` syntax
  checks plus manual logic review. Worth setting up PHPUnit + wp-env if
  this grows.
- No pagination on the admin bookings list beyond a 200-item cap.
- Guide photo upload has no custom UI - it's just the standard WordPress
  featured image on the Guide post type.

## Setup

See readme.txt - it has the step-by-step for locations/services/guides and
which shortcodes go where.
