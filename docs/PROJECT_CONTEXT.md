# Project Context

## Summary
- Plugin name: MUST Hotel Booking.
- Purpose: manage hotel accommodations, rooms, availability, reservations, pricing, checkout, payments, staff operations, and related WordPress admin workflows.
- Requested baseline version: `0.4.71`.
- Current local code inspected: `0.4.72` in `must-hotel-booking.php` header and `MUST_HOTEL_BOOKING_VERSION`.
- Inspection provenance: targeted current-code inspection on 2026-06-11; verify against code before changing behavior.

## Main User Types
- Guests using public booking, checkout, and booking confirmation pages.
- Staff using `/staff` and `/staff-login`.
- WordPress administrators using plugin admin pages.
- External systems/providers: Stripe, PokPay, Clock PMS, Elementor, and GitHub updater/plugin-update-checker.

## Main Flows
- Guest/frontend: `/booking`, `/booking-accommodation`, `/checkout`, `/booking-confirmation`.
- Staff portal: dashboard, reservations, calendar, front desk, guests, payments, housekeeping, rooms & availability, reports.
- Admin: dashboard, reservations, provider logs, calendar, accommodations, pricing, availability rules, payments, emails, guests, coupons, reports, settings.

## Must Not Be Broken
- Managed pages, staff portal routes, rewrite rules, inventory locks, reservation status lifecycle, payment verification, refund behavior, Clock reconciliation, database upgrades, Elementor/theme compatibility, and existing hooks.

## Current Assumptions
- Main plugin file is `must-hotel-booking.php`.
- Main bootstrap is `src/Core/Plugin.php`.
- Additional bootstrap/config is `includes/config.php`.
- Database installer/upgrade path is `src/Database/install-tables.php`.
- DB version option is `must_hotel_booking_db_version`.
- No `add_shortcode` registration was found in targeted inspection.
- No separate logged-in customer account dashboard was found.

## Unknowns Needing Manual Confirmation
- Production payment/provider credentials and Clock API permissions.
- Whether the intended release version should remain `0.4.72` or be treated as `0.4.71` from the task prompt.
- Any site-specific theme/Elementor overrides outside this plugin.
