# Installation & Setup Guide

**Plugin:** MUST Hotel Booking  
**Version:** 0.3.45  
**Audience:** Site owner / WordPress administrator performing a first-time installation

---

## Table of contents

1. [Requirements](#1-requirements)
2. [Installation steps](#2-installation-steps)
3. [What happens on activation](#3-what-happens-on-activation)
4. [First-time configuration order](#4-first-time-configuration-order)
5. [Minimum viable setup to accept the first booking](#5-minimum-viable-setup-to-accept-the-first-booking)
6. [Pre-launch checklist](#6-pre-launch-checklist)

---

## 1. Requirements

| Requirement | Minimum | Notes |
|------------|---------|-------|
| WordPress | 5.0 | Declared in plugin header (`Requires at least: 5.0`) |
| PHP | 7.4 | Declared in plugin header (`Requires PHP: 7.4`) |
| MySQL / MariaDB | Any version supported by your WordPress install | The plugin uses `dbDelta()` for table creation |
| Stripe account | Required only if you enable Stripe payments | You need a Publishable Key, Secret Key, and Webhook Secret per environment (local / staging / production) |
| WordPress cron | Must be functional | The plugin schedules a recurring cron job for inventory lock cleanup |

**Stripe keys needed (if using Stripe):**

The plugin stores separate key sets for three environments. Collect the keys that apply to your site:

- `stripe_local_publishable_key` / `stripe_local_secret_key` / `stripe_local_webhook_secret` — localhost / `.local` / `.test` domains
- `stripe_staging_publishable_key` / `stripe_staging_secret_key` / `stripe_staging_webhook_secret` — staging / IP-based sites
- `stripe_production_publishable_key` / `stripe_production_secret_key` / `stripe_production_webhook_secret` — live HTTPS site

The plugin detects which key set to use based on the site URL automatically.

---

## 2. Installation steps

1. Obtain the plugin ZIP file. The plugin updates itself from the GitHub repository `https://github.com/MUST-DEV-TEAM/must-hotel-booking`. For a first install, obtain the ZIP from the repository releases page or from your project administrator.

2. In WordPress admin, go to **Plugins > Add New > Upload Plugin**.

3. Upload the ZIP and click **Install Now**.

4. Click **Activate Plugin**.

   Activation runs automatically — see the next section for what it creates.

5. After activation, the plugin top-level menu **MUST Hotel Booking** appears in the WordPress sidebar.

---

## 3. What happens on activation

When the plugin is activated, `Plugin::activate()` runs the following in order:

### 3a. Database tables created

`install_tables()` uses `dbDelta()` to create or update the following tables (using your WordPress table prefix, e.g. `wp_`):

| Table | Purpose |
|-------|---------|
| `{prefix}must_room_categories` | Accommodation category definitions |
| `{prefix}must_rooms` | Sellable room / accommodation listings (authoritative) |
| `{prefix}must_room_meta` | Key-value metadata for rooms |
| `{prefix}mhb_room_types` | Inventory room type mirrors (derived from `must_rooms`) |
| `{prefix}mhb_rooms` | Physical inventory units linked to room types |
| `{prefix}must_room_housekeeping_statuses` | Per-unit housekeeping status records |
| `{prefix}must_room_housekeeping_issues` | Maintenance issues reported per unit |
| `{prefix}must_housekeeping_handoffs` | Shift handoff summaries |
| `{prefix}must_guests` | Guest contact records |
| `{prefix}must_reservations` | Reservation records |
| `{prefix}must_pricing` | Date-range pricing rules |
| `{prefix}must_availability` | Availability / blocking rules |
| `{prefix}mhb_inventory_locks` | Short-lived booking session locks |
| `{prefix}must_payments` | Payment records |
| `{prefix}must_taxes` | Tax / fee definitions |
| `{prefix}must_coupons` | Discount coupon codes |
| `{prefix}must_activity_log` | Activity / audit log |
| `{prefix}mhb_cancellation_policies` | Cancellation policy definitions |
| `{prefix}mhb_rate_plans` | Rate plan definitions |
| `{prefix}mhb_room_type_rate_plans` | Room type to rate plan assignments |
| `{prefix}mhb_rate_plan_prices` | Per-date price overrides for rate plans |
| `{prefix}mhb_seasons` | Season date windows |
| `{prefix}mhb_seasonal_prices` | Season-level pricing modifiers |

The option `must_hotel_booking_db_version` is updated to the current plugin version after tables are created.

### 3b. Inventory sync

`DefaultInventoryUnitSyncService::maybeRunBackfill()` runs to ensure any existing rooms in `must_rooms` are mirrored into `mhb_room_types` and `mhb_rooms`.

### 3c. Managed pages auto-created

`ManagedPages::install()` creates WordPress pages for pages marked `auto_create: true`. These pages are created with `post_status = publish` and registered in the plugin settings:

| Setting key | Page title | Slug | Required |
|------------|-----------|------|---------|
| `page_booking_id` | Booking | `/booking` | Yes |
| `page_booking_accommodation_id` | Select Accommodation | `/booking-accommodation` | Yes |
| `page_checkout_id` | Checkout | `/checkout` | Yes |
| `page_booking_confirmation_id` | Booking Confirmation | `/booking-confirmation` | Yes |
| `portal_page_id` | Staff Portal | `/staff` | Only if staff portal is enabled |
| `portal_login_page_id` | Staff Portal Login | `/staff-login` | Only if staff portal is enabled |

The "Rooms" page (`page_rooms_id`, slug `/rooms`) is **not** auto-created — it must be created and assigned manually if needed.

Page IDs are stored in the `must_hotel_booking_settings` WordPress option under the `managed_pages` group.

### 3d. Staff roles and capabilities registered

`StaffAccess::syncRoleCapabilities()` registers five custom WordPress roles:

| Role slug | Display name |
|----------|-------------|
| `mhb_front_desk` | Front Desk Agent |
| `mhb_supervisor` | Front Office Supervisor |
| `mhb_housekeeping` | Housekeeping |
| `mhb_finance` | Finance / Cashier |
| `mhb_ops_manager` | Operations Manager |

Each role is granted a specific set of `mhb_*` capabilities. The capability matrix is configurable later under **Settings > Staff & Access**.

### 3e. Cron job scheduled

`LockEngine::scheduleCleanupCron()` schedules a recurring WordPress cron event to clean up expired inventory locks from `mhb_inventory_locks`.

### 3f. Rewrite rules flushed

Portal rewrite rules are registered and `flush_rewrite_rules()` is called so that the booking and portal pages are reachable immediately.

### 3g. On subsequent page loads (plugins_loaded)

- `ManagedPages::sync()` runs on every page load to detect and repair any managed pages that were trashed or deleted.
- `maybeUpgradeDatabase()` checks whether the stored `must_hotel_booking_db_version` is older than the current plugin version and re-runs `install_tables()` if needed.

---

## 4. First-time configuration order

All settings are at **MUST Hotel Booking > Settings**. The settings page has the following tabs:

| Tab | Slug | What you configure |
|-----|------|--------------------|
| General | `general` | Hotel name, address, phone, email, currency, timezone, date/time format, logos |
| Booking Rules | `booking_rules` | Booking window, min/max nights, guest counts, same-day cutoff, cancellation policy |
| Check-in / Check-out | `checkin_checkout` | Check-in time (default 14:00), check-out time (default 11:00), arrival/departure instructions |
| Payments Summary | `payments_summary` | Enable/disable payment methods (Pay at Hotel, Stripe), Stripe keys per environment, deposit settings, tax rate |
| Staff & Access | `staff_access` | Enable/disable staff portal, portal access roles, capability matrix, portal module visibility |
| Staff Users | `staff_users` | Assign existing WordPress users to staff roles |
| Frontend & Branding | `branding` | Brand colors, border radius, font family, booking form style preset, portal welcome text |
| Managed Pages | `managed_pages` | View and reassign auto-created pages; recreate a page if it was deleted |
| Notifications & Emails Summary | `notifications_summary` | Sender name/email, reply-to, notification recipient, email logo, footer text, layout type |
| Diagnostics & Maintenance | `maintenance` | System health check, table status, cron status, payment config check, reset tools |

**Recommended order for a first-time setup:**

1. **General** — Set hotel name, currency, and timezone first. These values are used as defaults throughout the plugin.
2. **Check-in / Check-out** — Set your check-in and check-out times.
3. **Booking Rules** — Adjust the booking window (default 365 days), minimum nights (default 1), and whether same-day bookings are allowed.
4. **Payments Summary** — Enable at least one payment method. If using Stripe, enter the keys for your current environment.
5. **Notifications & Emails Summary** — Set a sender email and the notification recipient email. Without a valid sender email the plugin falls back to the admin email, but it is best to set this explicitly.
6. **Managed Pages** — Verify that all required pages show as healthy. If any show "missing", use the Recreate button.
7. **Staff & Access** — Enable the staff portal and configure role access only if you have hotel staff who need portal login. This is optional for a minimum viable setup.
8. **Accommodations** (under the main menu, not Settings) — Add at least one room/accommodation.

---

## 5. Minimum viable setup to accept the first booking

The following is the smallest set of steps needed for a guest to complete a booking on the front end:

- [ ] Plugin activated (tables created, pages exist)
- [ ] At least one accommodation added under **MUST Hotel Booking > Accommodations** with `is_active = 1` and `is_bookable = 1` and `is_online_bookable = 1`
- [ ] Currency set under **Settings > General**
- [ ] At least one payment method enabled under **Settings > Payments Summary** (enabling "Pay at Hotel" requires no external account)
- [ ] The four required managed pages exist and are published: Booking, Select Accommodation, Checkout, Booking Confirmation (check under **Settings > Managed Pages**)
- [ ] Permalinks flushed: go to **Settings > Permalinks** and click Save (if booking pages return 404 immediately after activation)
- [ ] Sender email set under **Settings > Notifications & Emails Summary** (otherwise confirmation emails will attempt to send from the WordPress admin email)

**If using Stripe:**

- [ ] Stripe enabled under **Settings > Payments Summary**
- [ ] Publishable key and secret key entered for the matching environment (local / staging / production)
- [ ] Webhook secret entered — obtain this from your Stripe Dashboard after adding the webhook endpoint
- [ ] Webhook URL registered in Stripe Dashboard (the exact URL is shown in **Settings > Diagnostics & Maintenance** under the payments section)

---

## 6. Pre-launch checklist

Work through this checklist before opening bookings to real guests.

### System health

- [ ] Go to **Settings > Diagnostics & Maintenance**. Overall Status shows "healthy" (or at most "warning" with 0 critical issues).
- [ ] All database tables show status "healthy" in the table check list.
- [ ] All required managed pages show status "ok".
- [ ] Cron shows "Recurring lock cleanup is scheduled."

### Booking flow

- [ ] Visit the Booking page on the front end (`/booking`). The date selection form loads.
- [ ] Select dates and number of guests. Available rooms appear on the Select Accommodation page (`/booking-accommodation`).
- [ ] Proceed to Checkout (`/checkout`). The guest information form and payment method selection load.
- [ ] Complete a test booking with "Pay at Hotel" selected. The Booking Confirmation page (`/booking-confirmation`) loads with a booking reference.
- [ ] Verify the reservation appears in **MUST Hotel Booking > Reservations**.

### Email

- [ ] Verify that a booking confirmation email arrives at the guest email address used in the test booking.
- [ ] Verify that a notification email arrives at the address set in **Settings > Notifications & Emails Summary > Notification recipient**.

### Stripe (if enabled)

- [ ] Complete a test booking using a Stripe test card number.
- [ ] Confirm the payment status updates to "paid" in **MUST Hotel Booking > Reservations**.
- [ ] In the Stripe Dashboard, confirm the webhook event was received and processed (status 200).

### Staff portal (if enabled)

- [ ] Visit `/staff-login`. The login form loads.
- [ ] Log in with a user assigned a staff role. The portal dashboard loads.
- [ ] Confirm the modules visible in the portal match the capability matrix configured in **Settings > Staff & Access**.

### Settings

- [ ] Hotel name, currency, check-in/out times are correct.
- [ ] Booking window is set to the number of days in advance guests should be allowed to book.
