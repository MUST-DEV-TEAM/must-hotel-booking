=== MUST Hotel Booking ===
Contributors: must
Tags: hotel, booking, reservation, accommodation
Requires at least: 5.0
Tested up to: 6.0
Requires PHP: 7.4
Stable tag: 0.3.30
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
