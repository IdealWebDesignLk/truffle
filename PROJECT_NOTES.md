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
  **Update:** that "explicit field" arrived as GitHub issue #6 ("bring
  anyone with you") - a per-service `_tc_allow_party` checkbox that adds a
  real group-size step to the booking flow, capped at `max_capacity`,
  multiplying the base price per person, and collecting each extra
  guest's name/email/phone (stored as `_tc_guests` on the booking, and
  surfaced on the WooCommerce order as an order note). It's additive: the
  older extra-N-person convention above still works unchanged for
  whichever services don't opt into the checkbox. See `create_booking()`
  in `class-tc-rest-api.php` and `create_order_for_booking()` in
  `class-tc-woocommerce.php`.
  **Update - fixed in 0.6.2 (GitHub issue #18):** `TC_Availability::pick_guide()`
  used to only assign a guide when `get_party_size_booked()` was exactly
  zero, so a second, different customer could never join a guide/date
  slot that already had one group booked on it - even though
  `get_date_status()` correctly reported that slot as "limited" (room
  remains). `pick_guide()` now takes the new booking's `$party_size` and
  mirrors `get_date_status()`'s own `max_capacity` branching: individual
  services (`max_capacity` 1) keep the exact old all-or-nothing rule, but
  shared/group services now check REMAINING capacity against the
  specific party size being requested, so a shared service correctly
  stays open for the remaining seats. Both reschedule paths
  (`class-tc-admin-bookings.php`'s `handle_reschedule()` and
  `class-tc-rest-api.php`'s `admin_reschedule_booking()`) were updated to
  pass the booking's own `_tc_party_size` through, so rescheduling a
  group booking requires room for the whole group, not just one seat.
  Also fixed `handle_reschedule()` silently succeeding with
  `_tc_guide_id` set to 0 if `pick_guide()` returned nothing.
  **Follow-up in 0.7.0:** the client pointed out 0.6.2's fix was too
  broad - `max_capacity > 1` alone isn't the same thing as "strangers
  can share this date." A private "Bring anyone with you" booking for
  3 of a Max capacity of 4 should NOT leave the 4th seat open to a
  stranger, even though `max_capacity` is 4. Added a separate
  `_tc_allow_shared_seats` checkbox on the Service (off by default) -
  `TC_Availability::is_exclusive()` is now the single place that
  decides exclusive-vs-shared, checked by both `pick_guide()` and
  `get_date_status()`: a service is exclusive if `max_capacity` is 1
  OR the checkbox is off, and only genuinely shared (checkbox on,
  capacity > 1) uses the remaining-capacity math from 0.6.2. Default
  is off, so any existing service relying on the just-shipped 0.6.2
  sharing behavior needs this checkbox turned on explicitly to keep
  working that way.
  **Second follow-up in 0.7.1 (GitHub issue #19):** turning the
  checkbox on still didn't work - client tested booking 2 of 4 seats
  and the date showed fully booked anyway. Root cause was one level
  deeper than 0.6.2/0.7.0 touched: `guide_available_on()` (the
  guide-conflict check both `pick_guide()` and `get_date_status()` call
  *before* ever reaching the remaining-capacity math) queries ALL of a
  guide's non-cancelled bookings across every service and blocks on any
  date-range overlap - written under the assumption "a guide can only
  be in one place at a time," which is true, but it didn't know a
  shared service's own seat-holders on the exact same date aren't a
  real conflict. So the very first booking of a shared service made
  every subsequent booking attempt see itself as "the guide is already
  booked" and bail before capacity was ever checked. Fixed by skipping
  that block specifically when the existing booking is for the SAME
  service, on the SAME exact date, AND the service isn't exclusive - a
  different service, or a different start date of the same service
  (e.g. two overlapping multi-day sessions), still correctly blocks.
  Lesson for next time: when a fix touches guide_available_on(),
  pick_guide(), or get_date_status(), trace through an actual *second*
  booking into an already-partially-filled shared slot, not just the
  first booking into an empty one - that's exactly the path this bug
  hid in twice in a row.
  **Third follow-up in 0.8.0 (GitHub issue #20):** the party-size step
  ("how many people are you bringing") was still capped at the
  service's *static* `max_capacity`, not the *actual remaining* seats
  for the specific date - so a shared service with 2 of 4 seats
  already taken would still offer up to 4, only to get rejected by
  `pick_guide()`'s already-correct remaining-capacity check at
  submission time. `TC_Availability::get_date_status()` was split into
  `get_date_status()` (thin wrapper, unchanged callers) and
  `get_date_status_and_remaining()`, which also returns the actual
  seat count for shared services (null for exclusive ones) - the same
  guide-selection order as `pick_guide()`, so the number shown always
  matches whichever guide would really be assigned. `get_grid()` now
  returns a `remaining` field per cell; the booking widget reads it
  both to show a "N left" label on the calendar and to cap the
  party-size stepper for whichever cell is currently selected.
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
projection constants (`MERC_SCALE`, `MERC_TRANSLATE`) come from real
Natural Earth boundary data (`world-atlas`'s `countries-10m.json`, public
domain), not hand-drawn - an earlier hand-drawn version looked wrong and
was replaced for exactly that reason. The Netherlands entry in that
dataset includes Aruba/Curacao/Sint Maarten (Kingdom of the Netherlands),
which are filtered out by longitude before generating the outline.

**Regenerated in 0.9.0 (GitHub issue #23)** - the version shipped before
that point was ALSO nominally from this same Natural Earth source, but had
been run through `topojson-simplify` at a tolerance aggressive enough to
both lose most of the mainland's coastline detail AND drop every island
polygon entirely (a known simplify gotcha: over-aggressive tolerance can
delete small landmasses outright, not just smooth them). The client
correctly called this out as "not real looking" and missing islands. The
current outline skips simplification and decodes the full-detail TopoJSON
directly (no Node/d3-geo available in this environment, so this was done
with a small Python script implementing TopoJSON's arc delta-decoding by
hand, then a manual `fitExtent`-equivalent: project every kept point with
the unscaled Mercator formula, take the bounding box, and solve
scale/translate to center it in the 320x400 viewBox with padding). Kept 9
of the Netherlands' 12 polygons after the longitude filter: the mainland
(592 points, vs. ~90 before) plus Texel, Vlieland, Terschelling, Ameland,
Schiermonnikoog, two small Zeeland delta landmasses, and one tiny
uninhabited islet near Rottumeroog.

Pin positions are computed live in the browser from each Location's
lat/lng (set in the admin meta box) using the same Mercator formula the
outline was generated with, so **any** location works automatically - not
just the ones used during prototyping. **MERC_SCALE/MERC_TRANSLATE and
NL_OUTLINE are coupled** - they were fit together to this specific shape's
bounding box, so if the outline is ever regenerated again, refit and
replace both together, not just the path. If it ever needs regenerating:
fetch `https://cdn.jsdelivr.net/npm/world-atlas@2/countries-10m.json`,
find the Netherlands feature (topojson `id` "528") in
`objects.countries.geometries`, decode its arcs (delta-decode using
`transform.scale`/`transform.translate`, then resolve each polygon's ring
arc-indices - a negative index `i` means arc `~i` reversed), drop any
polygon whose points have longitude < 0 (Caribbean territories), then fit
to the viewBox as described above. Don't hand-draw a country outline
again, and don't run it through aggressive simplification without
checking that every intended island survived.

## WPML support

**Revised after a real bug report from the live site** - the original
design (0.17.0) registered Services and Guides as fully "Translatable" in
WPML, which creates a *separate WordPress post - a separate ID - per
language*. That's the right model for ordinary content, but wrong here,
and it broke in exactly the way you'd expect once the site actually had
guides in three languages:

- A Guide post isn't just content - it's tied to a stateful availability
  calendar (`wp_tc_guide_availability`, keyed by that post's ID) and to
  one specific WordPress login account (`_tc_user_id`). With three
  duplicate posts per guide, the calendar a guide manages through their
  own dashboard lived under *one* language's post ID, while a customer
  booking in a different language resolved a *different* post ID for
  "the same" guide - so a guide blocking a date only actually blocked it
  on one language version of the site. This is what got reported as
  "guide calendar not properly syncing."
- Worse, `TC_Rest_Api::get_guide_post_for_current_user()` (which resolves
  "which Guide post is the logged-in user") queried by `_tc_user_id` with
  no explicit order and `numberposts => 1` - with three posts sharing the
  same `_tc_user_id` (copied across on duplication), which one "won" was
  effectively undefined, compounding the desync.
- Guide<->Location/Service assignments (`_tc_location_ids` /
  `_tc_service_ids`, one meta row per ID) are post-ID references, and a
  translated post's ID differs from the original's - so matching them
  needed active normalization (`TC_WPML::to_default_language_id()`) just
  to work around IDs that should never have differed in the first place.

**Fixed by switching Services and Guides to WPML's "Display as
Translated" mode** (`translate="2"` in `wpml-config.xml`, not `"1"`)
instead. This keeps a single canonical post - one ID, one calendar, one
set of location/service assignments, no ambiguity in
`get_guide_post_for_current_user()` - while still letting a translator
provide a per-language title/content through WPML's own Translation
Editor, without ever creating a second `wp_posts` row. Locations and
Bookings stay non-translatable (`translate="0"`) as before: Locations are
addresses/coordinates, not content that reads differently per language
(GitHub issue #65); Bookings are transactional records, not content at
all.

**Known limitation**: the extras repeater (`_tc_extras` on Service, a
serialized array of label/price/max/description per row) has no
per-language text of its own under this setup - it shows the same values
in every language. WPML's field-translation tools are built for plain
string values, not structured array data, so there's no clean way to
route this through WPML as-is; per-language extras text would be a
plugin feature to build (e.g. storing each extra's label/description as
an array keyed by language code), not a WPML configuration change.

**Upgrading a site that already has WPML-duplicated Guide/Service posts
from before this fix**: switching the config does *not* retroactively
merge or delete the duplicates WPML already created per language - those
are now just extra, disconnected posts sitting in wp-admin. They need to
be manually cleaned up (keep the original/canonical post, delete the
per-language copies) after updating, or they'll keep showing up as
duplicate entries in admin lists even though the booking flow itself no
longer suffers the ID-desync bug.

Since Location, Booking, Service, and Guide are now *all* either
non-translatable or "Display as Translated," none of them duplicate
posts per language anymore - meaning `TC_WPML::to_default_language_id()`
(the ID-normalization helper, called from `save_guide()` in
`class-tc-meta-boxes.php` and `get_guides_for()` in
`class-tc-availability.php`, the one function every availability/booking
code path funnels through) is now a no-op everywhere in this plugin's
data model. Left in place rather than ripped out - it's harmless, and a
defensive layer if this assumption ever needs revisiting - but it's not
doing meaningful work anymore.

Separately, from the *language selection* (not ID) side: a REST API
request doesn't necessarily inherit the same language context a normal
page load would (depends on WPML's URL format setting - directory,
subdomain, or query parameter). Rather than guessing, the customer's
current language is localized into `window.tcBooking.lang`
(`class-tc-booking-shortcode.php`) and the front-end passes it back
explicitly as `?lang=` on its catalog requests (`/locations`, `/services`,
`/guides` - see `withLang()` in `booking-app.js`); the matching REST
callbacks call `TC_WPML::maybe_switch_language()` on it before querying,
which is what actually selects which language's title/content WPML
serves for the "Display as Translated" Service/Guide posts. The
availability endpoint doesn't need this itself - it fetches a specific
`service_id` directly (language-agnostic once you have the ID) rather
than running a fresh catalog query.

Every `TC_WPML` method is a guarded no-op when WPML isn't active
(`defined( 'ICL_SITEPRESS_VERSION' )` gates all of it) - verified via a
standalone PHP script exercising the class directly (no WordPress
available in this environment) that every value passes through unchanged
and no WPML function is ever called in that case, since that's the
overwhelming majority of installs and must never be at risk. **Still not
verified against a real WPML install** (this dev environment has no way
to run one) - worth confirming end-to-end on a WPML-enabled staging site,
particularly that "Display as Translated" actually behaves as documented
above for a custom post type with custom fields, since that's the piece
most likely to have a surprise a static read-through can't catch.

**A separate, already-resolved gotcha**: when a post type that already
has content newly becomes translatable, WPML needs every existing post
explicitly assigned a language - this isn't automatic just because the
type is newly registered translatable. A post that's missed this shows up
inconsistently: excluded from wp-admin's list when filtered to a specific
language, but still shown on the front-end (WPML's default handling of
language-unassigned content). Not a plugin bug - fix is on the WPML side:
in wp-admin, switch the list to "All", find the post lacking a language
flag, and assign one via WPML's language column. Worth checking for again
if a post type is ever newly made translatable (`translate="1"`) in the
future - "Display as Translated" and non-translatable types don't have
this issue, since neither one requires a language assignment per post.

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

**A second real bug, found in production rather than by this testing**
(GitHub issue #11, fixed in 0.5.0): `class-tc-woocommerce.php` was calling
`$order->add_fee( $name, $amount )`, which is not a real WooCommerce
method - it was deprecated in WC 2.7 (2016) in favor of building a
`WC_Order_Item_Fee` and adding it via `add_item()`, and even that old
deprecated version took a single fee object, not (name, amount)
arguments. The dev-environment stub described above was written to
match this plugin's own (incorrect) call shape rather than real
WooCommerce's actual API, so it "passed" every fee-related test above
despite the method never having been valid against a real install. The
practical effect on the live site: `wc_create_order()` still creates and
saves a real order (it does that internally before this plugin's code
ever runs), but every fee line then failed to attach, leaving every
order at a genuine $0 total - which WooCommerce correctly refuses to
take payment for. This is exactly the class of bug the paragraph above
warns about: a stub that mirrors the code being tested, rather than the
real system, will pass tests while hiding a real breakage. Worth
treating "no real WooCommerce available to test against" as a standing
risk for any future WooCommerce-integration change here, not just a
one-time caveat.

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

- **GitHub issue #14 ("book a 1-day event, the next day also disappears
  from the calendar") is still open, pending the client re-testing after
  0.6.1.** Two timezone fixes have landed so far, neither confirmed yet
  as the actual fix for #14 itself:
  - 0.5.0: `isoDate()` used `toISOString()` (UTC), silently shifting the
    date string back a day for any browser ahead of UTC.
  - 0.6.1: the client explicitly confirmed this business only operates
    in the Netherlands, so "today"/calendar navigation must always mean
    the Netherlands' own calendar day - not the visitor's or admin's own
    device timezone. Every "today"/"this month" anchor (booking widget,
    guide dashboard, admin guide calendar, reschedule modal) now goes
    through an `nlToday()` helper built on `Intl.DateTimeFormat` with
    `timeZone: 'Europe/Amsterdam'` (handles CET/CEST DST transitions
    automatically), instead of a bare `new Date()`. Two server-side
    default date ranges in `class-tc-rest-api.php` that used `gmdate()`
    (always UTC) were switched to `current_time()` (site-timezone-aware)
    for the same reason - **this requires Settings -> General -> Timezone
    to be set to Amsterdam** for the server side to actually agree with
    the client side; verify that's set correctly on the live site.
  - Despite both fixes, tracing `TC_Availability::guide_available_on()`
    by hand for a plain 1-day service still didn't turn up a matching
    off-by-one in the day-span math itself (span correctly collapses to
    a single day when `duration_days` is 1). If the symptom still
    reproduces after 0.6.1, get exact repro details before changing this
    function further - which service/duration, which date was clicked,
    and exactly which date shows blocked afterward - since this is the
    piece of the build most explicitly flagged as bug-prone and "should
    never be forked" without being sure.
- Reschedule admin UI is a plain `prompt()` for the new date (see
  `class-tc-admin-bookings.php`) - functional, not polished. A real
  date-picker modal is a good first improvement.
- No automated PHP test suite - everything so far is `php -l` syntax
  checks plus manual logic review. Worth setting up PHPUnit + wp-env if
  this grows.
- No pagination on the admin bookings list beyond a 200-item cap.
- Guide photo upload has no custom UI - it's just the standard WordPress
  featured image on the Guide post type.

## Updating the plugin on the live site

The plugin self-updates from this GitHub repo (`IdealWebDesignLk/truffle`,
now public) using the [Plugin Update Checker](https://github.com/YahnisElsts/plugin-update-checker)
library, vendored at `includes/plugin-update-checker/`. It's wired up at the
top of `tc-booking.php`.

To ship an update to the live site:

1. Bump the `Version` header in `tc-booking.php` and the `Stable tag` in
   `readme.txt` (they should match).
2. Commit and push to `main`.
3. Tag the release, e.g. `git tag v0.2.0 && git push origin v0.2.0` -
   optionally turn that tag into a proper GitHub Release for a changelog
   entry site admins can see from the "View version details" link.

Without a tag, the update checker falls back to watching the latest commit
on `main` directly (that's what it's doing right now, since no tags exist
yet) - it'll switch to preferring tags/releases automatically the moment
one exists, per the library's documented behavior. Prefer always tagging
releases going forward so `wp-admin -> Updates` shows a real version number
and changelog instead of just "there's a newer commit."

No GitHub token is configured or needed - the repo is public, so the
update checker hits the GitHub API unauthenticated (rate-limited to 60
requests/hour, well above what a single site's periodic update check
needs). If the repo is ever made private again, `setAuthentication()` will
need to be added with a personal access token.

## Setup

See readme.txt - it has the step-by-step for locations/services/guides and
which shortcodes go where.
