=== MUST Hotel Booking ===
Contributors: must
Tags: hotel, booking, reservation, accommodation
Requires at least: 5.0
Tested up to: 6.0
Requires PHP: 7.4
Stable tag: 0.3.37
License: GPLv2 or later
License URI: https://www.gnu.org/licenses/gpl-2.0.html

MUST Hotel Booking manages accommodations, availability, reservations, pricing, checkout, and hotel operations directly inside WordPress.

== Description ==

MUST Hotel Booking helps hotel teams manage accommodation sales and daily operations from WordPress without splitting booking data across separate systems.

Main features include:

* Accommodation and room management for hotel inventory
* Availability, reservations, guest records, and payment tracking
* Pricing controls for rate plans, seasons, taxes, coupons, and checkout flows
* Staff-facing hotel operations tools inside WordPress admin and the staff portal
* Managed booking pages, notifications, diagnostics, and maintenance utilities

== Installation ==

1. Upload the `must-hotel-booking` folder to `/wp-content/plugins/`, or install the release ZIP from **Plugins > Add New > Upload Plugin**.
2. Activate **MUST Hotel Booking** from the WordPress Plugins screen.
3. Open **MUST Hotel Booking > Settings** and review hotel identity, booking rules, managed pages, payment methods, and email settings.
4. Add accommodations, configure availability and pricing, and test the booking flow before going live.
5. When updating, upload the new release ZIP or use the configured updater, then review **Diagnostics & Maintenance** after the update completes.

== Changelog ==

= 0.3.37 =
* Added an Elementor booking search widget option to send guests directly to the Select Accommodation page instead of the main booking page when that flow is preferred.
* Fixed booking-calendar navigation so the shared previous and next month controls keep both check-in and checkout calendars moving together and keep the visible month labels in sync.
* Refined booking and accommodation frontend presentation with cleaner step header spacing, a desktop-only width fix for the Select Accommodation page title to prevent overlap with the booking stepper, responsive room thumbnail layouts, stacked mobile room actions, and cleaner room/widget media edge treatment.

= 0.3.36 =
* Added an Elementor booking search widget option to send guests directly to the Select Accommodation page instead of the main booking page when that flow is preferred.
* Fixed booking-calendar navigation so the shared previous and next month controls keep both check-in and checkout calendars moving together and keep the visible month labels in sync.
* Refined booking and accommodation frontend presentation with cleaner step header spacing, calendar shell spacing, responsive room thumbnail layouts, stacked mobile room actions, and cleaner room/widget media edge treatment.

= 0.3.35 =
* Reworked the wp-admin operations experience so Calendar, Accommodations, Rates & Pricing, Availability Rules, Payments, and Emails now share the same polished dashboard-style shell, cards, filters, badges, and action hierarchy.
* Improved long-form admin workspaces with clearer KPI strips, cleaner tools and filter sections, stronger grouping, and easier-to-scan tables and cards for day-to-day hotel operations.
* Refined supporting admin flows and shared styling so quick booking and related operational screens feel more consistent, intentional, and product-level across the plugin.

= 0.3.34 =
* Added automatic default inventory unit backfill and sync so existing accommodations and newly created room/listing records always get a sellable unit when none exists yet.
* Kept default units aligned with the accommodation's active, bookable, and calendar visibility flags, including rooms created manually in admin or imported from Excel.
* Added a booking fallback that uses the accommodation's own base price when no rate plan is assigned, so public booking does not fail just because pricing plans have not been configured yet.
* Fixed coupon data loading so newly created coupons appear correctly in admin and can be found during checkout validation.
* Added clearer checkout coupon feedback for guests, including friendly success and failure messages such as coupon not found, not active yet, expired, unavailable, minimum amount not met, or no discount applied.
* Fixed the accommodation editor toggle cards so booking behavior options in the modal can be clicked reliably again.

= 0.3.33 =
* Fixed a fatal admin/runtime load error caused by the new accommodation category repository declaring an incompatible `tableExists()` method signature.
* Corrected the category table availability checks so the accommodation category/listing split loads cleanly in WordPress.

= 0.3.32 =
* Split accommodations into admin-managed top-level categories and separate sellable room/listing records.
* Added a dedicated Categories view for creating, editing, deleting, and ordering accommodation categories in admin.
* Kept pricing, availability, reservations, and booking logic attached to room/listing records while separating physical units into their own admin view.
* Updated the Excel workflow so imports and exports manage room/listing records only and reference existing accommodation categories created in admin.
* Removed the old cleanup demo accommodation panel from the production admin UI and replaced the mixed-model cleanup with safer category-aware upgrade handling.

= 0.3.31 =
* Reworked the accommodation Excel workflow into a single manager-friendly sheet focused on accommodation details only.
* Removed Excel handling for accommodation units, operational inventory state, and live availability so those stay managed in WordPress admin.
* Added separate template and current-data workbook downloads for bulk accommodation creation and editing.
* Import now updates existing accommodations by id, creates new accommodations when id is blank, validates accommodation types row by row, auto-generates slugs for new records, and leaves images to the admin UI.

= 0.3.30 =
* The Danger Zone reset tools in Diagnostics & Maintenance are now visible by default for full administrators in wp-admin.
* Removed the wp-config feature-flag requirement for dangerous resets, while keeping the reset UI limited to `manage_options` users only.
* Existing destructive-action protections remain in place: server-side capability checks, nonces, explicit target selection, exact confirmation phrases, current WordPress password verification, and the final irreversible-action acknowledgment.
* Strengthened the Factory Reset card styling so the nuclear option reads as more dangerous than the operational reset.

= 0.3.29 =
* Added an admin-only Danger Zone in Diagnostics & Maintenance for destructive reset actions.
* Added separate reset flows for hotel operational data and full plugin factory reset, with clearer preserved-versus-deleted scope.
* Hardened destructive reset confirmation with a feature flag, full admin capability checks, target selection, exact confirmation phrases, current WordPress password verification, and a final irreversible-action acknowledgment.
* Factory reset now suspends managed page auto-create and routing until an administrator explicitly reassigns or recreates managed pages again.
* Improved the WordPress plugin card and details modal with a stronger plugin description, linked author metadata, and cleaner Description, Installation, and Changelog content.

= 0.3.28 =
* Hardened accommodation cleanup safety with guarded type deletion and explicit inventory-mirror repair tooling.
* Tightened GitHub release update validation, version alignment, and release asset matching.

= 0.3.18 =
* Maintenance release and plugin structure cleanup for WordPress conventions.
