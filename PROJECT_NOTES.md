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
- **Fixed in 0.24.2 (GitHub issue #70): a trashed booking kept blocking
  its date.** `guide_available_on()` and `get_party_size_booked()` are
  raw SQL (not `get_posts()`/`WP_Query`, which default to `publish`-only
  automatically) - they filtered `p.post_type` but never `p.post_status`,
  so a booking moved to Trash from wp-admin (the normal first step of
  "delete," not immediate permanent removal) was still counted as an
  active reservation right up until the trash was emptied. Both queries
  now also require `p.post_status = 'publish'`, independent of the
  existing `_tc_status <> 'cancelled'` exclusion (a separate, deliberate
  soft-cancel state tracked via meta, not affected by trashing at all).
  Worth remembering if a similar raw-SQL query is ever added here:
  `get_posts()` gives you the publish-only default for free, raw SQL
  against `$wpdb->posts` does not.

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

**0.25.1 - label legibility over the coastline.** A pin's label can land
directly on top of the outline stroke (real coastal towns, not just an
edge case) - the purple line was cutting straight through the letters.
Fixed with a CSS-only halo: `.tc-pin-label` now paints a thick stroke in
the map's own background color (`var(--surface-dim)`) underneath its
fill (`paint-order: stroke`), giving each glyph a soft light background
regardless of what's behind it. Deliberately not a sized `<rect>` behind
the text - that would need measuring each label's rendered width,
awkward given labels are sometimes two `<tspan>` lines (see the
word-wrapping in `renderLocation()`) - the stroke halo handles that for
free since it's per-glyph, not per-label-box.

## WPML support

**Revised twice after real bug reports from the live site.** The
original design (0.17.0) registered Services and Guides as fully
"Translatable" in WPML, which creates a *separate WordPress post - a
separate ID - per language*. That's the right model for ordinary
content, but wrong here, and it broke in exactly the way you'd expect
once the site actually had guides in three languages:

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
  effectively undefined, compounding the desync. (Now given an explicit
  `orderby => ID, order => ASC` as a defensive tie-breaker, though this
  matters much less once nothing duplicates in the first place.)
- Guide<->Location/Service assignments (`_tc_location_ids` /
  `_tc_service_ids`, one meta row per ID) are post-ID references, and a
  translated post's ID differs from the original's - so matching them
  needed active normalization (`TC_WPML::to_default_language_id()`) just
  to work around IDs that should never have differed in the first place.

**First fix attempt - "Display as Translated"**: switched Services and
Guides to `translate="2"` in `wpml-config.xml`, WPML's documented mode
for "one canonical post, translator can still provide per-language
title/content." This turned out not to exist as an actual selectable
option on this site's WPML setup - its Post Types Translation screen for
a custom post type only offers two flavors of full duplication
("Translatable - only show translated items" / "...or fall back to
default language") or fully non-translatable, no middle ground.
Confirmed by directly testing: with `translate="2"` set, translating a
Guide through wp-admin still created a brand new duplicate post (an
11-guide list became 12 after translating one Guide into German).

**Final fix - non-translatable everywhere + WPML String Translation**:
Location, Service, Guide, and Booking are now *all* `translate="0"` in
`wpml-config.xml` - a single, permanent, canonical post per
location/service/guide/booking, guaranteed no duplication under any WPML
Post Types Translation setting on this site. Per-language TEXT (a
guide's bio, a service's name/description, each extra's
label/description) is instead handled through WPML's separate *String
Translation* module, which translates an arbitrary string independently
of any post - no post duplication is possible by construction, because
no post is ever involved.

`TC_WPML::translate_string( $context, $name, $value )` in
`class-tc-wpml.php` is the mechanism: it registers `$value` with WPML
(`wpml_register_single_string`) under a stable `$name` (includes the post ID,
so re-registering on every request updates the same string rather than
creating a new one) and a `$context`, then returns whatever translation
WPML currently has for it (`wpml_translate_single_string`), falling back
to `$value` itself if nothing's been translated yet. `TC_Rest_Api` calls
this from `get_services()` (service name/description, plus each extra's
label/description - contexts "TC Booking Services" / "TC Booking
Extras"), `get_guide_for_location()`, and `get_guides_by_location()`
(guide bio - context "TC Booking Guides"). A guide's name is a proper
name and is left untranslated (`$guide->post_title` returned as-is). A
translator fills these
in under WPML -> String Translation, per string, per language - this
replaces the old per-post Translation Editor workflow entirely for these
two post types.

This also resolves the extras-repeater limitation from the first
attempt's write-up (structured array data had no clean per-language
story under post-level translation) - since extras text is now
translated as plain strings one field at a time, there's no structured-
data problem to route around.

**Upgrading a site that already has WPML-duplicated Guide/Service posts
from an earlier version of this plugin** (either the original fully-
"Translatable" design, or a brief window on `translate="2"`): switching
the config to `translate="0"` does *not* retroactively merge or delete
posts WPML already duplicated - those are now just extra, disconnected
posts sitting in wp-admin, and need to be manually deleted (keep the
original/canonical post per guide/service).

**Also worth knowing**: WPML reads `wpml-config.xml` to *pre-fill* its
Post Types Translation settings, but doesn't necessarily re-read and
re-apply it once a site already has its own setting stored - if Service
or Guide were ever set to "Translatable" (or `translate="2"`) through
wp-admin at some point, updating this plugin's code alone may not be
enough to flip them back. Check WPML -> Settings -> Post Types
Translation directly after updating, and set Service and Guide to "Not
translatable" by hand if they aren't already.

Since Location, Booking, Service, and Guide are now *all*
non-translatable, none of them duplicate posts per language -
meaning `TC_WPML::to_default_language_id()` (the ID-normalization
helper, called from `save_guide()` in `class-tc-meta-boxes.php` and
`get_guides_for()` in `class-tc-availability.php`, the one function
every availability/booking code path funnels through) is now a no-op
everywhere in this plugin's data model. Left in place rather than
ripped out - it's harmless, and a defensive layer if this assumption
ever needs revisiting - but it's not doing meaningful work anymore.

Separately, from the *language selection* (not ID) side: a REST API
request doesn't necessarily inherit the same language context a normal
page load would (depends on WPML's URL format setting - directory,
subdomain, or query parameter). Rather than guessing, the customer's
current language is localized into `window.tcBooking.lang`
(`class-tc-booking-shortcode.php`) and the front-end passes it back
explicitly as `?lang=` on its catalog requests (`/locations`, `/services`,
`/guides` - see `withLang()` in `booking-app.js`); the matching REST
callbacks call `TC_WPML::maybe_switch_language()` on it before querying,
which is what actually selects which language's translated string
`translate_string()` returns for Service/Guide text.  The availability
endpoint doesn't need this itself - it fetches a specific `service_id`
directly (language-agnostic once you have the ID) rather than running a
fresh catalog query.

Every `TC_WPML` method is a guarded no-op when WPML isn't active
(`defined( 'ICL_SITEPRESS_VERSION' )` gates all of it) - verified via
standalone PHP scripts exercising the class directly (no WordPress
available in this environment): one confirming every value passes
through unchanged and no WPML function is ever called when WPML isn't
active (the overwhelming majority of installs, must never be at risk),
another simulating an active WPML install's `do_action`/`apply_filters`
to confirm `translate_string()` both registers a string and returns the
correct translated-or-fallback value. **Still not verified against a
real WPML install** that the `wpml_register_single_string` /
`wpml_translate_single_string` calls actually surface entries under
WPML -> String Translation and serve translated values through the REST
API end-to-end - that's the next thing to confirm on a WPML-enabled
staging site. The "translation creates a duplicate post" failure mode
from the first fix attempt *was* verified end-to-end on the live site
(that's what caught it) - only the String Translation piece is still
unverified live.

**A separate, already-resolved gotcha**: when a post type that already
has content newly becomes translatable, WPML needs every existing post
explicitly assigned a language - this isn't automatic just because the
type is newly registered translatable. A post that's missed this shows up
inconsistently: excluded from wp-admin's list when filtered to a specific
language, but still shown on the front-end (WPML's default handling of
language-unassigned content). Not a plugin bug - fix is on the WPML side:
in wp-admin, switch the list to "All", find the post lacking a language
flag, and assign one via WPML's language column. Only relevant if a post
type is ever made "Translatable" again in the future - non-translatable
types (all four, now) don't have this issue, since none of them require
a language assignment per post.

**0.22.1 - wrong WPML hook name broke the whole widget**: the first cut
of `translate_string()` called `do_action( 'wpml_register_string', $value,
$name, $context )` - not a real WPML hook, and the wrong argument order
for the real one (`wpml_register_single_string`, which takes `$context,
$name, $value` in that order). WPML ended up receiving a guide's full
bio text where it expected a short context slug, and rejected it - every
call to `/guides`, `/services`, and `/guide` returned an HTTP 500 with
"The string did not match the expected pattern.", breaking the booking
widget outright on the live site. Fixed by correcting the hook name and
argument order; verified with a standalone test simulating a WPML-style
handler that rejects an oversized "context" value.

**0.22.2 - a guide's name isn't translated**: only bio goes through
`translate_string()` now - a guide's name is a proper name, not content
that should read differently per language, so `get_guide_for_location()`
and `get_guides_by_location()` return `$guide->post_title` as-is.

**0.23.0 - the customer-facing widget's static UI text is now
translatable too**: everything above only ever covered *dynamic* content
pulled from the database (guide bio, service name/description/extras).
Every other piece of text the widget shows - "Pick a location", "Enter
a valid email address.", button labels, step headings, roughly 70
strings in total - was hardcoded directly in `public/js/booking-app.js`,
invisible to WPML entirely (WPML's automatic string scanner only reads
PHP source, never JS), so the widget rendered in English no matter what
language the customer had selected. Fixed the same way any other
plugin's admin-facing text is made translatable: every string moved into
a big `i18n` array in `class-tc-booking-shortcode.php`, each one wrapped
in `__( '...', 'tc-booking' )` and passed to the front-end via
`wp_localize_script( 'tc-booking-app', 'tcBooking', array( ..., 'i18n'
=> array( ... ) ) )`. `booking-app.js` reads everything from
`window.tcBooking.i18n` (aliased `I18N`) instead of hardcoding text, and
a small `i18nFmt()` helper fills `%s`/`%d` placeholders in templates like
`"Available dates for %s"` in the order they appear (not full `sprintf`
- no `%1$s` positional/reordering support, deliberately, since nothing
here needs it - watch for this if a translator's string ever needs
reordered placeholders, it can't be done with this helper as written).
Translators fill these in the same place as guide/service text: WPML ->
String Translation, "Strings in theme and plugins".

Month names and weekday abbreviations (the calendar heading, the "Mon
Tue Wed..." header row, full date strings on the review step) and price
number formatting (decimal/thousands separators) are handled separately
from that `i18n` list - via `jsLocale()` in `booking-app.js`, which maps
the WPML language code to a real locale tag (`nl` -> `nl-NL`, `de` ->
`de-DE`) and passes it to the browser's own `Intl`/`toLocaleString` APIs
instead of the previously hardcoded `'en-US'`. This is deliberately
*not* routed through WPML String Translation - `Intl` already knows how
to localize month/weekday names and number formatting correctly for
any locale, so there's no translation work needed for that piece and no
risk of an incomplete/inconsistent manual translation.

Verified end-to-end in a browser (no live WordPress available in this
dev environment, so a static HTML harness with `window.tcBooking`
mocked and `fetch` stubbed to return sample data stood in for it):
walked the full flow - location -> service/calendar -> party -> extras
-> details (including triggering every validation message) -> review -
confirmed every string renders from `I18N` rather than a hardcoded
literal, every template's placeholders substitute correctly, and dates/
prices render in Dutch locale format (`jsLocale()` returning `'nl-NL'`)
with no console errors at any step.

**0.24.0 - source strings are Dutch, not English**: after 0.23.0 shipped,
the live site's WPML default language turned out to be Dutch, and this
surfaced a mismatch that actually applied to *every* piece of text this
plugin has ever registered with WPML, not just the widget's `i18n` array
- WPML's String Translation always stores whatever value is passed to
`__()` / `wpml_register_single_string` as the **default language**'s
content; it has no notion of "this text happens to be written in
English." With the default language set to Dutch, every English string
this plugin registered was effectively being told "this Dutch text is in
English," so Dutch visitors - the site's main audience - saw raw
untranslated English everywhere, and would have needed a manually-added
"Dutch translation" of English source text to see it correctly, which is
backwards.

Fixed by writing every customer-facing string's *source* text in Dutch
instead of English, matching the site's actual WPML default language, so
Dutch visitors need zero String Translation entries and English/German
become the genuine translations added under WPML -> String Translation -
same principle as 0.23.0, just corrected to the right source language.
This touched:
- The `i18n` array in `class-tc-booking-shortcode.php` (0.23.0's ~70
  widget UI strings) and the loading-skeleton's `aria-label`.
- Customer-facing REST error messages in `class-tc-rest-api.php`:
  `get_availability()`'s date-range errors and every validation/failure
  message in `create_booking()` (missing fields, invalid email, unknown
  service, date no longer available, no guide available, booking
  creation failed).
- Booking confirmation/cancellation/reschedule email subject+body text in
  `class-tc-notifications.php` (customer copy only - the admin-notice
  copy in `send_confirmation()` deliberately stays in whatever language
  is already active, normally Dutch, since it always goes to the site's
  own `admin_email` and isn't meant to follow the customer).
- The WooCommerce order line-item label built in
  `class-tc-woocommerce.php`'s `create_order_for_booking()` (the
  no-placeholder-words variant, `'%1$s (%2$s)'`, needed no translation -
  it's already language-neutral). The admin-only order note listing
  additional guests, and the "Cancelled from TC Booking admin." order
  note, stay in English deliberately - both are private
  WooCommerce order notes never shown to the customer.

Admin-facing text (post type labels, meta box titles, admin list
columns, wp-admin confirm dialogs in `class-tc-cpt.php`,
`class-tc-meta-boxes.php`, `class-tc-admin-bookings.php`) was
deliberately left in English - wp-admin's language follows the logged-in
admin user's own WordPress profile locale setting, a completely
different mechanism from WPML's front-end default language, so it isn't
affected by this bug and doesn't need this fix.

Two REST endpoints didn't have a way to know the customer's language at
all and needed wiring, not just translated strings:
- `get_availability()` and `create_booking()` never called
  `TC_WPML::maybe_switch_language()`, unlike the four GET catalog
  routes - so their error messages were relying on whatever WPML
  resolves as "current language" for a bare REST request, which the
  existing code comments already flagged as unreliable. Fixed by adding
  the same `maybe_switch_language( $request->get_param( 'lang' ) )` call
  used everywhere else, and updating `booking-app.js` to send `?lang=`
  on `/availability` (`loadGrid()`) and `/bookings`
  (`submitBooking()`) via the existing `withLang()` helper - both
  previously omitted it.
- Confirmation/cancellation/reschedule emails are a harder case: they're
  sent from `TC_Woocommerce::sync_booking_from_order()`, hooked to
  `woocommerce_order_status_changed` - a *separate*, later HTTP request
  (the customer completing WooCommerce checkout, or an async payment
  gateway webhook) with no language context of its own at all. Switching
  language during the original `/bookings` request wouldn't help here.
  Fixed by capturing the customer's language at booking time
  (`_tc_customer_lang` post meta, set in `create_booking()` right after
  it calls `maybe_switch_language()`) and having each of
  `TC_Notifications`'s three `send_*()` methods explicitly switch to
  that stored language (via `booking_context()`, which now also returns
  `'lang'`) before building the email - regardless of what triggered the
  send (a webhook, a normal checkout page load, or staff clicking
  cancel/reschedule in wp-admin, which also fires these methods and
  previously would've built the email in the *admin's* current
  language, not the customer's).

Verified with the same browser-harness approach as 0.23.0 - re-ran the
full location -> service -> party -> extras -> details -> review ->
submit flow against Dutch source strings, confirmed the rendered text is
now correctly Dutch throughout, and confirmed via `window.__lastFetch`
that both `/availability` and `/bookings` now carry `?lang=nl`. Email
sending and the WooCommerce order-status-hook path could not be
exercised in this harness (no live WordPress/WooCommerce available) -
worth confirming on staging that a booking's confirmation email actually
arrives in the language it was booked in, especially for the async
payment-webhook path where the language-persistence fix matters most.

**Live-site follow-up to 0.24.0 - WPML's Default Language was still
English**: after 0.24.0 shipped, WPML's String Translation screen kept
showing the (now-Dutch) strings under an English flag. Turned out the
site's actual WPML -> Languages -> Default language setting had never
been changed to Dutch - it was still English, so WPML was (correctly,
given its own setting) treating the Dutch source text as "the English
original." 0.24.0's code change was the right complementary half of the
fix; the other half - actually changing that WPML setting to Dutch - is
a site-configuration change on the live install, not something in this
repo. Also noticed (same live-site session): the `tc-booking` domain
showed as "Unknown" in WPML's domain filter, because the plugin declared
`Text Domain: tc-booking` in its header but never actually called
`load_plugin_textdomain()` to register it with WordPress core - fixed in
0.24.1 (`tc_booking_load_textdomain()` in `tc-booking.php`, hooked to
`init` rather than `plugins_loaded` to avoid WP 6.7+'s
`_load_textdomain_just_in_time` doing-it-wrong notice for loading a
domain too early).

## Guide dashboard auth pages & checkout summary (GitHub issues #67/#68/#69)

**#67/#68 - login and access-denied pages** (`class-tc-guide-dashboard.php`)
were previously a single bare line of plain text each, with `wp_login_form()`
completely unstyled. Both are now a centered `.tc-auth-card` (reusing
`.tc-card`/`.tc-title`/`.tc-sub` from the rest of the widget, plus new
`.tc-auth-*` rules in `booking-app.css` - shadow, max-width 520px, restyled
form fields) instead of a new one-off design. `wp_login_form()`'s own markup
(`#loginform`, `#user_login`, `#user_pass`, `.login-remember`, `#wp-submit`)
is restyled via CSS rather than rebuilt from scratch, scoped under
`.tc-auth-card` so it can't leak onto the real wp-admin login screen
elsewhere on the site. The access-denied page's heading/description/button
labels use the exact Dutch copy suggested in issue #68. Its "Contact
opnemen" button has no obvious destination in this plugin (no dedicated
contact-page concept) - filterable via `tc_booking_contact_url`, defaulting
to a `mailto:` link to the site's admin email so it always works without
extra configuration.

**#69 - booking details on the WooCommerce checkout page**
(`class-tc-woocommerce.php`): the booking widget's review step already
showed Location/Guide/Ceremony/Date/Booked by/Email/Phone/Total, but none
of it carried over to the WooCommerce page the customer is redirected to
for payment - only the fee line's compressed name/price showed there.
`render_booking_summary()` reads the same booking meta via
`TC_Notifications::booking_context()` (made `public` for this reuse rather
than duplicating the meta-reading logic) and outputs the same
`.tc-card`/`.tc-rline` breakdown, hooked to `before_woocommerce_pay`.
**Not verified against a real WooCommerce install** - `before_woocommerce_pay`
and the `order-pay` query var are both genuine, long-standing WooCommerce
APIs (unlike the WPML specifics elsewhere in this file, these are core
WooCommerce, not something guessed from an unfamiliar admin screen), but
this dev environment has no live WooCommerce to confirm the hook actually
fires where expected on the pay-for-order page. Fails silently (nothing
rendered) rather than fatally if the hook name or query var turn out wrong,
so worth a quick visual check on staging rather than assuming it's correct.

All three new pieces of customer/guide-facing text are written in Dutch,
matching the source-language decision from the WPML sections above.

## Manually adding a booking from wp-admin (`class-tc-meta-boxes.php`)

Booking only ever `'supports' => array( 'title' )` (`class-tc-cpt.php`) -
every other field came from `render_booking()`'s meta box, which was
*read-only* (built assuming a booking only ever gets created through the
customer-facing widget / REST API). Bookings -> Add New therefore had
nowhere to actually enter details, just a title field - reported as "I
can only type the title, I need to be able to add bookings from the
backend."

`render_booking()` now dispatches on whether `_tc_service_id` is already
set: no service yet -> `render_new_booking_form()` (an editable form),
otherwise the existing read-only display. `save_new_booking()` (hooked
to `save_post_tc_booking` alongside the existing `save_booking_note()`)
handles the actual creation, reusing `TC_Availability::is_bookable()` /
`pick_guide()` - the exact same validation and guide-assignment the
customer-facing REST endpoint uses - rather than a second, possibly-
divergent set of rules for the admin form. On any validation failure the
booking meta is simply left unset, so `render_booking()`'s same
`_tc_service_id` check makes the form reappear (with the error shown
inline via a short-lived transient) for another attempt - no redirect
handling needed, unlike `TC_Admin_Bookings`'s cancel/reschedule actions
(those are separate `admin-post.php` actions, not a `save_post` hook, so
a redirect there is normal; doing that from inside `save_post` would
short-circuit WordPress's own post-save flow and any other plugin's
`save_post` hooks that haven't run yet).

Two deliberate choices from discussing this with the client, both
different from the online flow:

- **No WooCommerce order, no payment.** The booking is marked
  `_tc_status = 'confirmed'` directly. This form is for a booking already
  arranged (and typically already paid) outside the online flow - a
  phone booking, for instance - not a way to send a customer a payment
  link. (If a payment link ever *is* needed from wp-admin, that's a
  different, unbuilt feature - this form intentionally doesn't create an
  order at all.)
- **No confirmation email.** `TC_Notifications::send_confirmation()` is
  never called here - the admin has presumably already spoken to the
  customer directly.

The guide is never picked manually - always `pick_guide()`, matching
every other guide-assignment path in the plugin. "Total price" defaults
to the service's own price (× group size if the service allows a party,
plus any extras chosen) but can be typed over, for a phone-arranged
discount or similar.

**Extras and additional guests (added right after the first cut of this
feature shipped without them)**: `render_new_booking_form()` renders
empty containers (`#tc-new-extras-list`, `#tc-new-guests-list`) that
`admin/js/booking-form.js` fills in dynamically - extras depend on which
service is picked (each service has its own extras list), guest row
count on the chosen group size, neither known until then. Every
service's price/allow_party/max_capacity/extras is localized up front
(`TC_Meta_Boxes::enqueue_new_booking_form()` - this plugin's whole
service catalog is a small enough data set that sending it all beats a
fetch-per-selection round trip) so the JS can react instantly without a
REST call.

Field names double as the server-side contract: extras post as
`tc_new_extra_qty[key]` (an associative array, read directly by PHP - no
JSON encoding needed), guests as parallel indexed arrays
(`tc_new_guest_name[]`/`email[]`/`phone[]`). `save_new_booking()`
validates both **exactly the way `create_booking()` does** - same order
(`is_bookable()` -> party_size -> extras, which can still grow party_size
via the extra-N-person convention or get capped by `limit_by_seats` ->
guests, sliced to `party_size - 1` -> `pick_guide()` with the *final*
party_size) and the same rules, ported line-for-line rather than
re-derived, and verified against a standalone test asserting the ported
logic produces identical results to the documented `create_booking()`
behavior (a `limit_by_seats` extra capped at the current party size, an
`extra-N-person` extra growing party_size and that growth affecting a
*later* `limit_by_seats` extra in the same submission, guest rows sliced
to `party_size - 1`). Never trust the client's submitted quantities/rows
against what a service's extras actually allow, same as everywhere else
this plugin accepts extras input.

`wp_update_post()` is called at the end of `save_new_booking()` to
replace the placeholder title (whatever the admin typed to get past
WordPress's empty-title guard) with a real one, matching
`create_booking()`'s own title convention. This is safe from infinite
recursion despite firing `save_post_tc_booking` again immediately:
`_tc_service_id` is already written to meta by that point, so the
re-entrant call hits this method's own early-return guard right away.

## Email notifications (`class-tc-notifications.php`)

Three events - confirmation, cancellation, reschedule - each `wp_mail()`
the customer, and (confirmation/cancellation only) a copy to the site's
own `admin_email`. As of the guide-email addition below, the assigned
guide also gets a copy for all three events, at the email address of the
WP user account linked via `_tc_user_id` on their Guide post (same
account they log into the guide dashboard with - `guide_email()`, `''`
if no guide is assigned or that guide has no linked account, in which
case that `wp_mail()` call is simply skipped).

**Fixed a real bug while adding the guide email**: every `send_*()`
method calls `TC_WPML::maybe_switch_language( $b['lang'] )` to build the
*customer's* copy in the customer's own language (needed because these
often fire from a later request with no language context of its own -
see the WPML section above). The admin copy was previously built
*after* that switch, with a comment claiming it "stays in whatever
language was already active (normally Dutch)" - but the switch has no
"undo," so the admin copy was actually ALSO rendering in the customer's
language, contradicting that comment. Fixed by reordering: admin and
guide copies are now built and sent *first* (while whatever language was
already active for the request is still active), and the language switch
+ customer copy happen last. Worth remembering if a fourth "internal"
recipient is ever added here: build and send anything that should NOT
follow the customer *before* the `maybe_switch_language()` call, not
after.

The guide email is deliberately lighter than the admin copy - booking
logistics (ceremony, location, date/time, customer name + phone, group
size if more than 1) rather than price/payment details, which aren't the
guide's concern.

**Not sent from the manual admin "Add Booking" form**
(`TC_Meta_Boxes::save_new_booking()`) - consistent with that form
skipping the customer confirmation email too (see the section above);
the assumption there is the booking was arranged directly, guide
included.

## Guide calendar: booked dates shown, past dates already excluded

Two things about a guide's own availability calendar (`public/js/
guide-dashboard.js`, and its admin-editing-on-behalf counterpart
`admin/js/guide-availability.js`, which share the same UI/behavior and
were kept in lockstep for this too):

**Past dates were already fully handled** before this change - `isPast`
already withheld `data-date`/`data-blocked` (making a past cell
unclickable), and `.tc-cal-day.past` is `visibility: hidden` in
`booking-app.css` (so past days in the visible month aren't just
unclickable, they're not shown at all - same treatment as the leading
empty cells before day 1). A guide can only ever set availability for
today or later. Nothing needed changing here.

**Booked dates now actually show, and can't be marked as a day off** -
`guide-dashboard.js`'s own top-of-file comment had claimed "dates with
an existing booking are shown but not clickable" since it was first
written, but no booking data was ever actually fetched or rendered - the
calendar only ever showed the `wp_tc_guide_availability` exception table
(blocked/available), with real bookings invisible to it entirely. Fixed
by:

- `TC_Rest_Api::fetch_guide_bookings()` (new) - this guide's actual
  bookings in the visible date range, grouped by date, each with a
  `service — customer name` summary (joined with `; ` if a shared
  service has more than one booking on the same day). `p.post_status =
  'publish'` + excluding `_tc_status = 'cancelled'` mirrors
  `TC_Availability::guide_available_on()`'s own booking query exactly
  (GitHub issue #70's fix) - a trashed or cancelled booking must not
  show as "booked" here either, same reasoning.
- `guide_get_availability()`/`admin_get_guide_availability()`'s response
  shape changed from a flat array to `{ availability: [...], bookings:
  [...] }` - both endpoints only exist for this calendar (not consumed
  anywhere else), so this wasn't a concern for any other caller.
- The calendar's `render()`: a date with a booking always shows the
  existing `.booked` CSS class (amber - was already defined in
  `booking-app.css`, just never applied) with the summary as a `title`
  tooltip, and never gets `data-date` - so it's non-clickable exactly
  like a past date, taking priority over whatever the availability table
  separately says for that date.
- `guide_set_availability()`/`admin_set_guide_availability()` also
  reject the write server-side (`tc_has_booking`, 409) if `status =
  'blocked'` is requested for a date `TC_Rest_Api::guide_has_booking_on()`
  finds an active booking on - the client-side omission of `data-date`
  is only the UI half; a direct API call (or a stale page) must not be
  able to leave a real booking sitting on a date the calendar claims is
  free. The guide-facing error message is Dutch, the admin-facing one
  English, per this plugin's established split on that.

Verified in a browser against a mocked `/guide/availability` response
(both the old blocked/available and new booking data) - confirmed booked
cells render amber with the right tooltip text, have no `data-date`
(so no click handler attaches) and the default cursor, and that past
dates remain fully hidden.

## "Sometimes it saves, sometimes it doesn't" - guide calendar save fix

Reported directly against the guide's own availability calendar (and
its admin-editing-on-behalf counterpart - both share the exact same fix,
in lockstep as always). Root cause, once traced through `toggleDate()`
in both `public/js/guide-dashboard.js` and `admin/js/guide-availability.js`:
the click handler applied its change to `state.availability` optimistically
and re-rendered immediately, but **never undid that change if the save
actually failed** - the calendar just kept showing whatever the guide
clicked, with no visible sign the server had rejected it beyond a small
error banner easy to miss. A fast double-tap on the same date (easy to
do by accident on mobile) made this worse: two overlapping requests for
the same date, no protection against firing the second one before the
first resolved, so which one "won" depended on network timing - a
textbook race, matching "sometimes it saves, sometimes it doesn't"
exactly.

Fixed by:
- **Reverting on failure.** `toggleDate()` now remembers the date's
  previous value before applying the optimistic change, and puts it back
  if the request's `.catch()` fires - the calendar never shows a status
  the server didn't actually store.
- **A pending lock per date** (`state.pending[iso]`). A date with a save
  already in flight gets no `data-date` attribute at all (same mechanism
  already used for past/booked dates), so a second click/tap on it before
  the first request resolves is simply a no-op instead of firing a second,
  racing request.
- **A visible "Saving…" / "✓ Saved" status pill** (new `.tc-cal-status`
  rules in `booking-app.css`) near the calendar's title/description,
  `aria-live="polite"` so it's announced to a screen reader too - "Saved"
  auto-clears after 2 seconds. The pending cell itself also dims slightly
  (`.tc-cal-day.pending`) so the specific date being saved is visually
  obvious, not just a page-level message.

Also for the front-end guide dashboard specifically (not the admin
edit-a-guide's-calendar screen, which lives inside wp-admin's own page
chrome and wasn't part of this ask): `#tc-guide-dashboard-root` now has
`margin-top`/`margin-bottom` so the card has breathing room on its own
page instead of sitting flush against whatever's above/below it, and the
existing title ("Your availability") + description text were kept but
the description was expanded slightly for clarity.

Verified in a browser against a mocked, artificially-delayed REST
response: confirmed the "Saving…"/"Saved" pill transitions correctly,
that a cell whose save fails reverts to its prior color instead of
sticking on the optimistic guess, and that three rapid clicks on the
same date produce only two actual requests (the pending lock correctly
swallowing the click that landed while the first was still in flight).

**Live-site follow-up**: the fix above didn't fully resolve it - saves
were still unreliable specifically on the front-end guide dashboard, not
the admin equivalent, and the "Saving…" message wasn't showing at all.
Traced to a caching plugin caching the guide dashboard page itself - a
page carrying a personalized WordPress REST nonce (baked in at render
time via `wp_localize_script`) should never be page-cached, since (a) a
cached copy keeps serving an aging nonce past WordPress's ~24h rotation
window, causing saves to start failing with no warning once it expires,
and (b) the visitor was looking at whatever JS version was cached from
before this plugin was last updated - explaining why the admin screen
(never cached) worked fine while the front-end one didn't. Site-side fix
(excluding the page from caching) is outside this repo; two things did
still change here:
- `handleResponse()` in both calendar JS files now recognizes a 403
  specifically and shows "Your session has expired - please reload the
  page and try again" instead of the generic fallback - a stale nonce is
  by far the most likely cause of a 403 here, so this is worth having
  regardless of whether page caching turns out to be the whole story.
- `.tc-cal-status` (the "Saving…"/"Saved" message) is now `position:
  fixed` in the corner of the screen rather than sitting inline near the
  title - on a tall calendar, scrolled down to a later week, the inline
  version needed scrolling back up to see, which was raised separately
  once the message was actually visible again after a hard refresh.

**Actual root cause, found by live-site debugging** (the user provided
direct login credentials for this specifically, after the fixes above
still didn't resolve it): confirmed, with real requests against the
live site, that saving was never actually broken - the write itself
always succeeded (`POST /guide/availability/bulk` correctly returned
`{"date":"2026-09-10","success":true}`, and a request that bypassed
caching immediately afterward showed the change had genuinely persisted
in the database). What was broken is the **read**: the *exact same* GET
request (`/wp-json/tc/v1/guide/availability?start=...&end=...`),
requested normally right after a page reload, kept returning a **stale
cached response** missing the just-saved change - even though
WordPress's own response already carries `Cache-Control: no-cache,
must-revalidate, max-age=0, no-store, private`. Something in front of
WordPress (a caching plugin or CDN/host-level cache) is caching this GET
by URL regardless of that header. This is a more precise version of the
caching diagnosis two paragraphs up - not really about the *page* being
cached (though that was real too, and explained the earlier missing-JS/
stale-nonce symptoms), but specifically about this REST *endpoint's own
response* being cached independently of the page.

Fixed at the code level rather than relying on a server-side caching
exclusion, since that requires access to (and correct configuration of)
whatever caching layer this site is using, which varies by host and
isn't something this repo controls: `apiGet()` in both
`public/js/guide-dashboard.js` and `admin/js/guide-availability.js` now
appends a `_=<timestamp>` query parameter to every GET request, making
each request's URL unique - this defeats any cache keyed on the full
URL (which is how the overwhelming majority of caching plugins/CDNs
key their cache) regardless of why it was ignoring the `Cache-Control`
header, without needing to know or touch whatever is doing the caching.
`cache: 'no-store'` was also added to the `fetch()` call itself, as the
browser's own equivalent for anything a URL-based cache alone wouldn't
already catch. Applied to both files even though only the front-end
guide dashboard was the one reported broken - the same URL-keyed cache
could just as easily serve a stale response to the admin screen's
identical request, it just hadn't been noticed there yet.

Confirmed live: toggled a real date, saved, reloaded the page - the
change reverted, reproducing the report exactly. Then fetched the same
endpoint with a cache-busting parameter added by hand and confirmed the
save *had* actually gone through. The specific date toggled for this
test was reverted back to its original value afterward, so no real
data was left changed by the debugging session itself.

## Booking emails are now styled HTML, and appear on WooCommerce's own "New order" email

Every `wp_mail()` this plugin sends (`TC_Notifications`'s confirmation/
cancellation/reschedule emails - customer, admin, and guide copies) was
plain text, built with `sprintf()` and literal `\n`s. Reported as "very
basic," with a request to also surface booking details on WooCommerce's
own "New order" admin email (previously that email only showed whatever
the fee line item's name happened to say, e.g. "Zonsopgang ceremonie
(2026-09-03) × 3 personen" - no location/guide/extras/guest breakdown).

**HTML email shell** (`TC_Notifications::email_shell()`/`email_p()`/
`email_rows()`, all at the bottom of that file): a small, deliberately
plain, table-based layout with inline styles only - no `<style>` block,
no external stylesheet or image, since both are liable to be stripped or
blocked by a real-world mail client. Colors are the literal hex values
from this plugin's own brand palette (`public/css/booking-app.css`'s
`--brand-deep` `#4B2E7D` etc.) rather than shared at runtime - HTML email
can't reliably use CSS custom properties across clients, so there was
nothing to gain by trying to keep one source of truth for both. Every
`send_*()` method's plain-text `sprintf()` calls were replaced with calls
into these three helpers instead. `wp_mail()`'s content type is switched
to `text/html` via the `wp_mail_content_type` filter, added and removed
around each individual call (`send_html_mail()`) rather than switched
globally, so nothing else on the site calling `wp_mail()` for something
unrelated is affected.

**A real bug found via a rendered preview, not just reading the code**:
without an explicit `<meta charset="utf-8">` in the email's own `<head>`,
"€" (a multi-byte UTF-8 sequence) rendered as mangled bytes ("â‚¬") in a
browser preview of the generated HTML - `wp_mail()`'s own Content-Type
header already declares UTF-8 correctly, but some mail clients render
from the HTML's own declared charset regardless of the transport header
and get it wrong without one. Fixed by adding the meta tag; this is
exactly the kind of bug that a plain `php -l` check or a logic-only test
can't catch, only an actual rendered look at the output - which is why
one was worth doing here specifically.

**WooCommerce's "New order" email** (`TC_Woocommerce::
add_booking_details_to_order_email()`, hooked to
`woocommerce_email_order_details` at priority 5, so it renders BEFORE
WooCommerce's own order-items table at the default priority 10 - reads
top-to-bottom as "here's the booking, here's what's on it," not the
reverse): adds a "Boekingsgegevens" section with Location, Guide, Date,
Group size, additional guests (names only), and Extras (as a
"label ×qty" list) - everything the fee line items and default billing
fields don't already show. Reuses `TC_Notifications::email_rows()`
directly (made `public` for this) rather than a second, likely-to-drift
copy of that table markup in this file. Scoped to the "New order" email
specifically via `$email->id` (WooCommerce fires this same action for
cancelled/failed/refunded order emails too) and to only orders carrying
`_tc_booking_id` (so it can never appear on an unrelated WooCommerce
order, if this site ever has one) - extending to other order emails
later is a one-line change to that `$email->id` check. Handles both
WooCommerce's HTML and plain-text email formats, matching how WooCommerce
itself supports both.

Verified with standalone PHP tests: the extras-summary building (label
×qty, skipping a zero-quantity extra) and guests-summary building
(names only, blank entries filtered) both checked against realistic
data: end-to-end control flow (skip on a non-"new_order" email, skip on
an order with no `_tc_booking_id`, skip on a `null` `$email` without a
fatal, render the "Boekingsgegevens" heading otherwise) exercised
directly. The email HTML itself was rendered in a browser (not just
validated as well-formed) specifically to catch the charset bug above,
which no amount of code-reading would have surfaced.

**Follow-up - extras were missing from every one of `TC_Notifications`'s
own emails.** `booking_context()` had fetched `_tc_selected_extras` from
the start (added for the WooCommerce order-email work above), but
nothing in `send_confirmation()`/`send_cancellation()`/
`send_reschedule()` actually rendered it - a real gap, not a rendering
bug, reported as "customer, staff, or admin don't get info about
extras." `extras_summary()` (the "label ×qty, label ×qty" formatting
that lived only in `TC_Woocommerce::add_booking_details_to_order_email()`
before this) moved to `TC_Notifications` and was made `public` so both
classes share the one implementation, and an "Extras" row (using it) was
added to all three email types' customer/admin/guide copies -
`email_rows()` already skips a row with an empty value, so a booking
with no extras renders exactly as before, no blank "Extras" line. The
customer's confirmation email also gained a "Totaal" row it was
previously missing (noticed while adding extras there - showing what was
added without also showing the resulting total read as incomplete).

Also widened `email_shell()`'s content table from `560px` to `680px` (a
separate part of the same request) - re-verified in a rendered preview
that the wider layout, the new Extras row, and the € symbol (the
earlier charset fix) all still render correctly together.

**Follow-up - the ceremony date itself was still raw ISO** ("2026-10-31")
on the actual WooCommerce order line item (the fee's own name/label,
built in `TC_Woocommerce::create_order_for_booking()`) - a different,
earlier gap than the missing-extras one above, and one that shows up
everywhere an order's line items do: cart, checkout, every order email,
the admin order screen. Reported directly from a real order confirmation
email. Fixed by formatting a separate `$date_display` (`date_i18n( 'j F
Y', strtotime( $date ) )`) for the fee label only - `$date` itself stays
the raw `'Y-m-d'` from `_tc_date`, nothing else in that function needs a
display version. Hardcoded `'j F Y'` rather than `get_option(
'date_format' )` (used elsewhere in this same file for the checkout-page
summary and the "Boekingsgegevens" order-email addition) specifically to
guarantee the exact "31 oktober 2026" shape asked for, regardless of
whatever the site's own Settings -> General date format happens to be
set to.

**Follow-up - extras were all crammed onto one line.** `extras_summary()`
formatted every extra into a single comma-joined string ("Maaltijd ×3,
Fotopakket ×1") shown as one "Extras" row - fine for one extra, unreadable
for several, per "we need one extra per line." Couldn't just insert
`<br>` into that string, since `email_rows()` runs every value through
`esc_html()` (it would come out as literal `&lt;br&gt;` text, not a line
break). Replaced `extras_summary()` with `extras_rows()`, returning one
`[ 'Extra', 'Label ×qty' ]` pair per extra instead of one joined string -
each pair becomes its own table row once spliced (via `array_merge()`)
into the row list every email already builds, so several extras now stack
as repeated "Extra" rows rather than one wide blob. Applied to all three
`TC_Notifications` email types (customer/admin/guide copies alike) and to
`TC_Woocommerce::add_booking_details_to_order_email()`'s "Boekingsgegevens"
block on the WooCommerce order email - its plain-text branch already
looped over the same `$rows` array printing one line per row, so it picked
up "one extra per line" for free once extras became multiple rows there
too. A booking with zero extras still renders exactly as before -
`extras_rows()` returns `array()`, which `array_merge()`s in as a no-op.

**Follow-up - "Extra" was repeating on every line.** That first version
put the label `"Extra"` on every row, so three extras read as
"Extra / Extra / Extra" down the left column - not what was asked for
("no need to say extra multiple times, one title extra is enough, just
list extra data one by one"). Changed so only the *first* row carries the
label (now `"Extras"`, plural, since it's a heading for the group rather
than a per-item label) - every row after gets `''`. `email_rows()` only
skips a row on an empty *value*, so those follow-up rows still render,
just with a blank first cell, reading as one heading with each extra
listed underneath. The WooCommerce order-email plain-text branch needed a
matching tweak - it built each line as `"label: value"`, which would've
printed a bare `": Fotopakket ×1"` for a blank-label row, so a blank
label there now prints as an indented continuation line instead.

## Guide calendar: dropped AJAX auto-save entirely, added explicit save

Follow-up to the "sometimes it saves, sometimes it doesn't" section
above - that fix (revert-on-failure, a pending lock, a visible status
message) turned out not to be enough. Reported directly: on the
**admin** guide-calendar screen specifically, a toggle would show
"Saved" yet not actually be reflected until the admin also clicked the
Guide post's own Update button - i.e. the AJAX save and the post's own
save were somehow landing in a state where only the latter "really"
counted from the admin's perspective. Rather than chase that further,
the decision was to drop AJAX-per-tap saving entirely on both screens
and replace it with an explicit, staged-changes model - matching how
every *other* field on the Guide edit screen already behaves (Locations
covered, Services provided, the linked user account: all just sit there
until Update is clicked), and giving the front-end guide dashboard its
own equivalent explicit Save button since it has no surrounding "Update"
to piggyback on.

**Shared DB helpers moved from `TC_Rest_Api` to `TC_Availability`**
(`fetch_guide_availability()`, `fetch_guide_bookings()`,
`guide_has_booking_on()`, `upsert_guide_availability()`, all now
`public static`) - both `TC_Rest_Api` (the front-end's bulk-save REST
endpoint) and `TC_Meta_Boxes` (the admin's save-on-Update handler) need
them now, and `TC_Meta_Boxes` (meta-box/admin-UI code) calling into
`TC_Rest_Api` (a REST-routing class) for shared business logic would
have been a backwards, confusing dependency. `TC_Availability` is
already this plugin's stated single choke-point for availability/
booking logic (see this file's own warning about `get_guides_for()`
near the top), so this is where they belonged all along.

**Front-end** (`public/js/guide-dashboard.js`): `toggleDate()` no longer
calls the API at all - it only updates `state.dirty` (a `{date: status}`
map, separate from `state.availability`, the last-confirmed-saved
value). `state.dirty` deliberately persists across month navigation
(`loadMonth()` only ever merges fresh server data into
`state.availability`, never touches `state.dirty`), so a change staged
in one month survives browsing to another and back before Save is
clicked - `render()`'s per-cell status resolves as `dirty[iso] ||
availability[iso] || 'available'`. A new **Save** button (disabled when
nothing is staged) posts every staged change together to a new bulk
endpoint, `POST /guide/availability/bulk` (`TC_Rest_Api::
guide_save_availability_bulk()` - the old single-date `POST
/guide/availability` route is gone). Each date in the batch is validated
and applied *independently* server-side, so one date that became booked
between page load and clicking Save (someone else booked it in the
meantime) doesn't block the rest of the batch - only that date reverts,
with its specific reason shown, while everything else that succeeded
commits. The whole grid locks (no `data-date` on any cell) while a save
request is in flight, replacing the earlier per-date `pending` lock -
there's only ever one request in flight at a time now, covering
everything staged, so a global lock is simpler and equally correct. A
staged-but-unsaved cell shows a dashed outline (`.tc-cal-day.dirty` in
`booking-app.css` - the old, now-unused `.tc-cal-day.pending` rule was
removed) and a "Not saved yet" tooltip. A `beforeunload` handler warns
before leaving the page with anything still staged.

**Admin** (`admin/js/guide-availability.js` +
`TC_Meta_Boxes::save_guide()`): same `state.dirty` staging concept, but
with *no* REST write call anywhere - `admin_set_guide_availability()`
and its whole POST route are gone, `admin_get_guide_availability()`
(read-only) is all that's left. Every render, the staged changes are
written out as hidden `<input name="tc_availability[DATE]" value="...">`
fields directly inside `#tc-guide-availability-root` - which is itself
inside the Guide post edit screen's own `<form>` (guaranteed by how
WordPress renders `normal`/`side` context meta boxes), so these fields
submit automatically with the rest of the form when Update is clicked,
no JS submit-hook needed. `TC_Meta_Boxes::save_guide_availability_
changes()` (called from the existing `save_guide()`, already hooked to
`save_post_tc_guide`) reads `$_POST['tc_availability']` and applies each
entry with the *exact same* validation as the front-end's bulk endpoint
(`TC_Availability::guide_has_booking_on()`) - never a forked copy of
that rule. A `save_post` hook can't cleanly redirect with a query-arg
notice (see `save_new_booking()`'s docblock above for why), so a
booking-conflict failure here uses the same short-lived-transient +
inline-notice pattern that method already established:
`render_guide_availability()` now checks for and displays one at the
top of the meta box.

Verified end-to-end in a browser for both screens (mocked REST
responses, a real `<form>` for the admin case): confirmed toggling a
date fires **zero** network requests on either screen until told to
save; the front-end's Save button posts exactly one bulk request
containing every staged change; a per-date failure in that response
reverts only that one date (with its message shown) while a
simultaneously-succeeding date correctly commits; and - the part
specific to the admin fix - that the hidden `tc_availability[...]`
inputs actually appear in `FormData` built from the surrounding `<form>`
when its submit button is clicked, confirming they'd genuinely reach
`save_guide()` on a real Update click, not just visually resemble form
fields. Also verified the `TC_Meta_Boxes::save_guide_availability_
changes()` validation logic standalone: a booking-conflict date is
rejected and recorded as an error rather than silently dropped, an
invalid date/status pair is silently skipped, and a request with no
`tc_availability` field at all is a clean no-op.

## WooCommerce order line items are now real (hidden) products, not bare fees

Reported from a screenshot of a "Factuur" PDF: a third-party plugin
generated it (Booster for WooCommerce's PDF invoicing module - not part of
this codebase at all, confirmed by grepping the whole plugin for
"invoice"/"factuur" and finding nothing), and it showed a single generic
"Appointment" line with none of the booking's actual details. Root cause:
`create_order_for_booking()` added every line as a bare `WC_Order_Item_Fee`
(no linked WC_Product), a deliberate original design choice (see this
file's own header comment before this change) to avoid keeping a shadow
product in sync with every Service/Extra edited in the TC admin screens.
Booster's invoice module apparently can't render a real name/description
for a fee-only line item with nothing to look up, and falls back to that
generic placeholder.

Fixed by giving each line a real product to point to:
`get_or_create_placeholder_product()` looks up (by a deterministic SKU -
`tc-service-{id}` for the main service line, `tc-extra-{service_id}-{key}`
per extra) or lazily creates a `WC_Product_Simple` that's `publish` status
but `catalog_visibility` 'hidden' - fully valid as an order line item and
visible to any tool that reads WooCommerce products, but never shown in
the shop, search, or purchasable directly. Its regular price is left at 0
and is never read for what a booking actually charges - `add_product_line()`
still sets the line's display name and subtotal/total explicitly, exactly
as `add_fee_line()` did before, so the booking's own price snapshot stays
the single source of truth (the sync problem the original fee-only design
was avoiding never actually comes back - only the placeholder product's
own fallback title could go stale if a service is renamed later, and nothing
of ours ever reads that title once the line exists). Tax stays off via the
placeholder product's own `tax_status` set to 'none' at creation, matching
the fee items' `set_tax_status( 'none' )` before them - `WC_Order_Item_Product`
doesn't expose that setter directly since order items read tax status live
from whatever product they're linked to.

## Guides land on their dashboard after login, however they log in

Previously a guide only ended up on `/guide-dashboard/` after login if they
logged in through the form embedded on that page itself -
`wp_login_form( array( 'redirect' => get_permalink() ) )` in
`login_prompt()` already handled that case. Logging in any other way (a
bookmark to `wp-login.php`, a password-reset email link, "Remember me"
expiring and WordPress reprompting there instead) dropped them into
wp-admin by default, which the `tc_guide` role has no real use for - it
only grants `read` plus `tc_manage_own_availability`, so wp-admin's menu
renders essentially empty for them.

Fixed with a `login_redirect` filter (`redirect_guide_to_dashboard()`) -
runs on every login regardless of which form was used, checks
`tc_manage_own_availability` (true only for guides), and sends anyone who
has it to whichever page carries `[tc_guide_dashboard]` instead of
whatever WordPress/another plugin had already decided. That page isn't
pinned to a specific ID anywhere in this plugin's settings (matching how
this class already works - "place the shortcode on a page" per the file's
own header comment), so `dashboard_url()` finds it with a `post_content
LIKE '%[tc_guide_dashboard%'` lookup rather than requiring one more
setting to configure. Non-guides (customers, admins) are untouched -
the filter returns whatever `$redirect_to` it was given unchanged for
anyone without that capability.

## A prominent link back to the guide dashboard on WooCommerce My Account

A guide can still end up on the regular WooCommerce My Account page rather
than `/guide-dashboard/` directly - clicking "My account" in the site
header, or a "View order" link in an email, both go there. Added a
banner, guide-only, at the top of My Account's Dashboard tab (hooked on
`woocommerce_account_dashboard`) with one link back to their calendar -
deliberately its own filled-brand block (`.tc-account-guide-banner` in
booking-app.css) rather than another entry in the account nav, since the
ask was specifically a *prominent* link, not a permanent extra menu item
sitting next to Orders/Addresses/Account details (most of which don't
mean much for a guide's account anyway).

Reuses `TC_Guide_Dashboard::dashboard_url()` (made `public` for this -
previously only `login_redirect` used it) rather than a second lookup for
the same page. Needed its own root ID, `#tc-account-dashboard-root`,
added to booking-app.css's shared variable-scope selector (`--brand-deep`
etc. are scoped to a specific list of root IDs, not `:root` - see that
file's header) - without it the banner rendered with no background/button
styling at all, caught by an actual rendered preview, not code review
(the same category of bug the email charset issue was, earlier in this
file). Nothing renders for anyone without `tc_manage_own_availability`
(real customers).

## Customers no longer see "Bijna vol" or the exact seat count (GitHub issue #73)

The booking widget's availability calendar showed a distinct amber
"Bijna vol" (almost full) status plus the exact number of seats left
("Nog 2") for shared services close to capacity - explicitly not wanted:
"we dont want to show it to customers." A `limited` day now displays
identically to a fully `available` one in `renderAvailabilityCalendar()`
(`public/js/booking-app.js`) - same "Open" label, same green color, no
seat count - via a `displayStatus` that collapses `'limited'` into
`'available'` before it reaches the cell's class/label, leaving the raw
`status` value (and `cell.remaining`) untouched everywhere else. That
distinction matters because `cell.remaining` still does real work
elsewhere in the same file - `partySizeMax()` still caps the "how many
people are you bringing" stepper at the actual remaining seats, it's
just never displayed as a number on the calendar itself. The now-unused
`statusAlmostFull`/`leftSuffix` i18n strings and their `.tc-avail-day
.rem`/`.tc-avail-day.limited` CSS rules were removed rather than left as
dead code. This is customer-facing only - the guide/admin's own calendar
(`.tc-cal-day`, a different set of classes entirely) still shows real
booked/limited status, since staff still need to see it.

## Special services (GitHub issues #71/#72)

Some services ("only happened once or twice per month") don't fit the
regular blocked/available guide calendar at all - a guide should only
ever look "open" for one of these on a date they've specifically agreed
to do it, at one specific location, not by the usual
blocked-row/booking-conflict rules everything else uses.

**Data model.** A Service gets two new fields (Service edit screen,
`render_service()`/`save_service()` in `class-tc-meta-boxes.php`): "Special
service" (`_tc_is_special`, a checkbox) and a calendar color
(`_tc_special_color`, WordPress core's own `wp-color-picker`, defaulting
to booking-app.css's existing `--limited` amber rather than inventing a
new default color). `TC_Availability::get_service_data()` exposes both.

A Guide gets a new `_tc_special_dates` meta value - a flat array of
`{service_id, location_id, date}` rows, one per date they've opted into
offering one special service at one location (not a separate DB table -
this is small, admin-managed data, matching how `_tc_selected_extras`
etc. already store an array in one meta row rather than one row per
entry). New meta box "Special Service Dates" (`render_guide_special_
dates()`), between Guide Details and the regular Availability Calendar,
renders one calendar per (special service, location) pair - but only for
services/locations already checked in the SAME screen's existing
"Services provided"/"Locations covered" lists in Guide Details, which a
guide must set up first. `admin/js/guide-special-dates.js` builds these
live, reacting to those checkboxes changing without a page reload (a
`change` listener on `tc_service_ids[]`/`tc_location_ids[]`, both of
which live in a different meta box on the same page - meta boxes are
just sections of one `<form>`, so this works fine across box
boundaries). Same staged-changes model as `guide-availability.js`
(GitHub issue from earlier - dropped AJAX auto-save entirely): clicking a
date only updates local state and re-renders hidden `tc_special_dates
[SERVICE][LOCATION][]=DATE` inputs, submitted and applied by the guide's
own Update button, not any separate save action. Re-validated server-side
in `save_guide_special_dates_changes()` exactly like the regular
calendar's save path - a service/location must actually be one this
guide is linked to (from the very same request, not stale stored meta),
the service must currently be marked special, and a date already removed
from what's posted but with a real booking against it is kept rather
than silently dropped (the booking itself isn't touched, so losing the
date here would make it impossible to see/re-add while still owed to a
customer).

**Availability engine.** `TC_Availability::guide_available_on()` (private,
the single "is this guide free on this date" check every other method in
the file goes through) grew a `$location_id` parameter, threaded down
from `get_grid()`/`is_bookable()`/`pick_guide()` (all of which already
had it) via `get_date_status_and_remaining()`/`get_date_status()`/
`guide_is_free()`. Its logic branches on `$service['is_special']`:
- **Special service**: available only if `guide_offers_special_on()`
  finds a matching `{service_id, location_id, date}` row - the guide's
  regular blocked-calendar rows still apply (an explicit day off is a day
  off from everything), but the normal any-service booking-conflict check
  is still evaluated after this (a guide can't be double-booked into a
  special service either).
- **Regular service**: unavailable if `guide_has_special_date_on()` finds
  ANY special-date row for the guide that day, regardless of which
  service/location - opting into a special date closes that guide's
  regular calendar for the whole day, per the issue: "once he selected
  those dates ... other normal services wont be available on that day."
  Not location-scoped, matching how a guide's real bookings already block
  them everywhere, not just at one location (see the "one guide, two
  locations" question answered earlier in this file/session).

`get_guides_for()` itself is untouched - a guide must still be linked to
the service+location via the regular "Services provided"/"Locations
covered" checkboxes before special dates for that combination mean
anything, so the existing candidate-guide query is still the right first
filter either way.

**Booking flow and color.** No changes needed in `create_booking()`/
`save_new_booking()` (admin manual entry) - both already route through
`is_bookable()`/`pick_guide()`, so special-service correctness came for
free once the engine itself understood it. `TC_Rest_Api::get_services()`
now includes `is_special`/`special_color` in its response (that endpoint
builds its own response array rather than passing `get_service_data()`'s
array straight through, so this needed an explicit addition, not just
the engine change). `public/js/booking-app.js`'s
`renderAvailabilityCalendar()` uses `special_color` as an inline style
override (a ~10%-alpha tint for the cell background, the full color for
text/the legend swatch) on open days when the service is special, falling
back to the normal `--available` CSS variable otherwise - purely
cosmetic, no effect on what's actually bookable.

Verified with a standalone PHP harness (stubbed `$wpdb`/`get_post_meta`,
Reflection to call the private `guide_available_on()` directly) covering
both branches - a guide with no special dates is closed for the special
service and open normally; a guide who opted into one date at one
location is open for the special service there (and only there), and
closed for every regular service that whole day, everywhere, while an
unrelated date is unaffected. Also verified the admin calendar widget and
the customer-facing color rendering with actual browser previews (not
just code review) - the same category of bug the email charset issue
caught earlier in this file would otherwise slip through: the new
`#tc-special-dates-root` wrapper had to be added to booking-app.css's
CSS-variable-scope selector list (like `#tc-account-dashboard-root`
before it) or the calendar rendered with no color/styling at all.

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
