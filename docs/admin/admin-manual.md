# MUST Hotel Booking — Admin Operations Manual

**Plugin:** MUST Hotel Booking  
**Scope:** WordPress admin panel operations for site owners and hotel managers  
**Based on:** Source code review of plugin version found in repository (v0.3.45 series)

---

## Table of Contents

1. [Dashboard](#1-dashboard)
2. [Reservations](#2-reservations)
3. [Calendar](#3-calendar)
4. [Accommodations](#4-accommodations)
5. [Rates & Pricing](#5-rates--pricing)
6. [Rate Plans](#6-rate-plans)
7. [Availability Rules](#7-availability-rules)
8. [Payments](#8-payments)
9. [Taxes & Fees](#9-taxes--fees)
10. [Coupons](#10-coupons)
11. [Emails](#11-emails)
12. [Guests](#12-guests)
13. [Reports](#13-reports)
14. [Settings](#14-settings)

---

## Admin Menu Structure

The plugin registers one top-level menu item **MUST Hotel Booking** (dashicons-calendar, position 26) with the following visible subpages:

| Menu Label | Slug | Capability Required |
|---|---|---|
| Dashboard | `must-hotel-booking` | `manage_options` |
| Reservations | `must-hotel-booking-reservations` | `manage_options` |
| Calendar | `must-hotel-booking-calendar` | `manage_options` |
| Accommodations | `must-hotel-booking-rooms` | `manage_options` |
| Rates & Pricing | `must-hotel-booking-pricing` | `manage_options` |
| Availability Rules | `must-hotel-booking-availability-rules` | `manage_options` |
| Payments | `must-hotel-booking-payments` | `manage_options` |
| Emails | `must-hotel-booking-emails` | `manage_options` |
| Guests | `must-hotel-booking-guests` | `manage_options` |
| Coupons | `must-hotel-booking-coupons` | `manage_options` |
| Reports | `must-hotel-booking-reports` | `manage_options` |
| Settings | `must-hotel-booking-settings` | `manage_options` or staff capability |

The following pages exist but are not shown in the sidebar (hidden subpages, accessed by link only):

| Page | Slug |
|---|---|
| Reservation detail | `must-hotel-booking-reservation` |
| Add Reservation | `must-hotel-booking-reservation-create` |
| Rate Plans | `must-hotel-booking-rate-plans` |
| Taxes & Fees | `must-hotel-booking-taxes` |

---

## 1. Dashboard

**URL:** `wp-admin/admin.php?page=must-hotel-booking`

The Dashboard is the primary operations board. It shows live KPI cards, items needing attention, a system health panel, recent reservations, and a recent activity trail. A Quick Booking workspace is available at the bottom of the page.

### 1.1 KPI Cards

Eight KPI cards are rendered at the top of the page. Each card links to the relevant operational page filtered to that metric:

| Card | What it shows | Links to |
|---|---|---|
| Arrivals Today | Number of reservations with check-in date = today | Reservations (preset: arrivals_today) |
| Departures Today | Number of reservations with check-out date = today | Reservations (preset: departures_today) |
| In-House Guests | Total guests currently checked in | Reservations (preset: in_house_today) |
| Pending Reservations | Reservations awaiting confirmation or payment | Reservations (preset: pending) |
| Unpaid Reservations | Reservations not fully settled | Payments (payment_group: due) |
| Occupancy Today | Percentage of active units occupied | Calendar |
| Revenue Today | Payments received today from the payment ledger | Payments |
| Blocked / Unavailable Units | Manual blocks + maintenance blocks + unavailable inventory units | Availability Rules |

### 1.2 Attention Panel

The Attention panel surfaces operational issues that are likely to create guest or payment problems. Each item shows a severity badge (Warning / Error), a reference (booking ID or room name), a short description, and an "Open" button to the relevant record.

Issues surfaced automatically:

- Pending reservations not yet confirmed or paid (up to 3 shown)
- Reservations with a missing payment ledger row despite having a total price
- Failed or stale Stripe payments (older than 30 minutes)
- Possible booking conflicts — overlapping confirmed reservations for the same room
- Arrivals today with incomplete guest details (no name or email)
- Reservations still in an active status after their checkout date has passed
- Accommodations with a base price of 0.00
- Room types with no active rate plan or fallback pricing
- Room types with no physical inventory units assigned
- Recent failed email sends
- Availability rules not yet configured (if the table is empty)
- Stripe enabled but API keys incomplete for the active environment
- Stripe webhook secret not set

### 1.3 System Health Panel

Located in the right sidebar. Shows configuration and infrastructure checks. Items with a status that is not `ok` or `healthy` contribute to the Health Review count shown in the page header. A link to Settings is provided.

### 1.4 Recent Reservations Panel

Shows the latest bookings created or updated. Each card shows booking ID, guest name, total, accommodation, dates, reservation status badge, and payment status badge. View and Edit buttons are included.

### 1.5 Recent Activity Panel

Shows the most recent entries from the activity log (`must_activity_log` table). Entries cover reservation events, payment events, and email operations. Each entry shows a severity badge, timestamp, message, reference, and an "Open record" link when an associated reservation exists.

### 1.6 Quick Actions Panel

Located in the right sidebar. Shows shortcut buttons to the most-used operational screens (Calendar, Reservations, Payments, Emails, Settings). The icon for each action is resolved from the label text.

### 1.7 Quick Booking Workspace

A form at the bottom of the Dashboard page for creating a direct reservation without navigating to the full reservation workflow. Fields:

- Room (dropdown of all sellable rooms)
- Check-in date (default: today)
- Check-out date (default: tomorrow)
- Guests (default: 1)
- Guest name
- Phone
- Email
- Booking source (`website`, `phone`, `walk_in`, `booking_com`, `airbnb`, `agency`)
- Notes

Submitting the form creates a reservation and redirects to the full reservation detail page.

---

## 2. Reservations

**List URL:** `wp-admin/admin.php?page=must-hotel-booking-reservations`  
**Detail URL:** `wp-admin/admin.php?page=must-hotel-booking-reservation&reservation_id=<id>`  
**Create URL:** `wp-admin/admin.php?page=must-hotel-booking-reservation-create`

### 2.1 Reservation List View

The list view shows all reservations in a tabular format. Available filters and search options (passed as query arguments):

- `quick_filter` — preset filters such as `arrivals_today`, `departures_today`, `in_house_today`, `pending`
- `search` — searches by booking ID, guest name, or email
- `status` — filter by reservation status
- `payment_status` — filter by payment status
- Additional date and room filters may be supported by the query layer

Bulk actions available: **Confirm**, **Mark Paid**, **Cancel**.

### 2.2 Reservation Statuses

The `status` column in `must_reservations` accepts the following values:

| Status | Blocks Inventory | Notes |
|---|---|---|
| `pending` | Yes | Awaiting confirmation or payment |
| `pending_payment` | Yes | Guest has initiated payment but not completed it |
| `confirmed` | Yes | Active confirmed reservation |
| `completed` | Yes | Stay has ended, reservation closed normally |
| `blocked` | Yes | Manual block (not a guest reservation) |
| `cancelled` | No | Cancelled by guest or staff |
| `expired` | No | Reservation expired before payment or confirmation |
| `payment_failed` | No | Payment attempt failed, reservation not active |

Statuses `confirmed` and `completed` are the "confirmed statuses" that count as confirmed bookings in reporting.

### 2.3 Payment Statuses

| Status | Meaning |
|---|---|
| `unpaid` | No payment recorded |
| `pending` | Payment initiated but not confirmed |
| `paid` | Fully paid |
| `partially_paid` | Partial payment received |
| `pay_at_hotel` | Guest will pay at check-in |
| `failed` | Payment attempt failed |
| `cancelled` | Payment cancelled |
| `refunded` | Payment has been refunded |
| `blocked` | Used for manual block reservations |

### 2.4 Reservation Detail Page

Accessed by clicking a reservation row or the View button. Displays all fields of a reservation with inline action buttons.

**Actions available from the detail page (POST):**

| Action key | Effect |
|---|---|
| `save_guest` | Update guest contact details on the reservation |
| `save_admin_details` | Update admin-facing fields (status, notes, etc.) |
| `confirm` | Move reservation to `confirmed` status |
| `mark_pending` | Move reservation to `pending` status |
| `mark_paid` | Mark the reservation's payment as paid |
| `mark_unpaid` | Mark the reservation's payment as unpaid |
| `cancel` | Cancel the reservation |
| `resend_guest_email` | Re-send the guest confirmation email |
| `resend_admin_email` | Re-send the admin notification email |

**Actions available via GET link (with nonce):**

| Action | Effect |
|---|---|
| `confirm` | Confirm the reservation |
| `mark_paid` | Mark as paid |
| `resend_guest_email` | Resend guest email |
| `cancel` | Cancel the reservation |

### 2.5 Creating a Reservation

Navigate to **MUST Hotel Booking → Add Reservation** or use the "Add Reservation" button on the Dashboard. The form collects the same fields as the Quick Booking workspace. Upon submission, the reservation is created and the admin is redirected to the reservation detail page.

### 2.6 Status Lifecycle

The reservation status lifecycle as enforced by the engine is:

```
[new booking] → pending | pending_payment | confirmed
pending → confirmed | cancelled | expired
pending_payment → confirmed | cancelled | payment_failed
confirmed → completed | cancelled
completed → (terminal)
cancelled → (terminal)
expired → (terminal)
payment_failed → (terminal)
blocked → (can be deleted)
```

The `default_reservation_status` setting (Booking Rules tab in Settings) controls what status new reservations receive on creation. Options are: `pending`, `pending_payment`, `confirmed`, `completed`, `cancelled`.

### 2.7 Cancelling a Reservation

> **Warning:** Cancellation is irreversible through the normal admin UI. The reservation status moves to `cancelled` which does not block inventory. If a payment was already recorded, the payment status must be manually handled (mark refunded, etc.) from the Payments page.

---

## 3. Calendar

**URL:** `wp-admin/admin.php?page=must-hotel-booking-calendar`

The Calendar page provides a visual timeline view of reservations across all rooms. It accepts `start_date` and `weeks` as query parameters (e.g., `?start_date=2026-04-06&weeks=2`).

### 3.1 Calendar View

The calendar renders each room as a row and each day as a column. Reservations appear as colored blocks spanning their check-in to check-out dates. The view supports filtering by room.

### 3.2 Creating Blocks from the Calendar

From the Calendar page, staff can create manual reservation blocks directly on the timeline. Blocks are stored as reservation rows with `status = 'blocked'` in `must_reservations`. They consume inventory for the blocked dates.

Required fields for a manual block: room, check-in date, check-out date.

Optional: notes.

> **Warning:** Manual blocks created from the Calendar are stored as `blocked` reservation rows. They count in KPI counts for blocked units. They must be deleted (not cancelled) to release inventory — cancellation is only for guest reservations.

### 3.3 Moving Reservations

The Calendar supports moving reservations between rooms and/or date ranges. This requires the `mhb_calendar_move_reservation` capability. The availability engine is consulted before allowing a move to prevent conflicts.

### 3.4 Quick Booking from Calendar

The Calendar page also supports booking source options beyond what the Dashboard Quick Booking provides: `website`, `phone`, `walk_in`, `booking_com`, `airbnb`, `agency`.

---

## 4. Accommodations

**URL:** `wp-admin/admin.php?page=must-hotel-booking-rooms`

The Accommodations page manages the room catalog. The plugin has two parallel data models:

- **Legacy model (authoritative):** `must_rooms` table — the primary sellable accommodation record. The admin UI manages this table.
- **Inventory model (derived):** `mhb_room_types` + `mhb_rooms` — derived from `must_rooms` by the inventory mirror sync. These tables are used by the rate plan and inventory engine.

> **Warning:** `must_rooms` is the authoritative source. Do not manually edit `mhb_room_types` or `mhb_rooms` rows — they are managed by the inventory sync. Run the **Repair Inventory Mirror** action in Settings > Diagnostics & Maintenance to re-sync if needed.

### 4.1 Room Listing Fields

Each room (`must_rooms`) has the following fields:

| Field | Type | Notes |
|---|---|---|
| `name` | VARCHAR(191) | Display name |
| `slug` | VARCHAR(191) | URL-safe identifier, must be unique |
| `category` | VARCHAR(100) | Category slug, default `standard-rooms` |
| `description` | LONGTEXT | Full description |
| `internal_code` | VARCHAR(100) | Internal reference code |
| `is_active` | TINYINT | 0 = inactive |
| `is_bookable` | TINYINT | 0 = not bookable |
| `is_online_bookable` | TINYINT | 0 = not available on guest-facing forms |
| `is_calendar_visible` | TINYINT | 0 = hidden from Calendar view |
| `sort_order` | INT | Display order |
| `max_adults` | SMALLINT | Maximum adults |
| `max_children` | SMALLINT | Maximum children |
| `max_guests` | SMALLINT | Maximum total guests |
| `default_occupancy` | SMALLINT | Default guest count shown in booking form |
| `base_price` | DECIMAL(12,2) | Base nightly price |
| `extra_guest_price` | DECIMAL(12,2) | Per-night charge for guests above default occupancy |
| `room_size` | VARCHAR(100) | Size description (e.g., "45 sqm") |
| `beds` | VARCHAR(100) | Bed configuration description |
| `admin_notes` | LONGTEXT | Internal notes, not shown to guests |

### 4.2 Room Categories

Categories are managed from the room edit form. The category field stores a slug value. Categories are resolved via `RoomCatalog::getCategories()`. The system also has a `must_room_categories` table for named categories with `name`, `slug`, `description`, and `sort_order`.

### 4.3 Room Meta (Amenities, Images, Rules)

Additional room attributes are stored as key-value rows in `must_room_meta` (columns: `room_id`, `meta_key`, `meta_value`). This includes:

- **Amenities:** Stored as serialized list. The available amenity options are defined in `RoomCatalog::getAvailableAmenities()`. Values are stored as amenity keys and must be normalized via `normalize_room_amenity_key()`.
- **Gallery images:** Stored as a comma-separated list of WordPress attachment IDs in the `gallery_ids` meta key.
- **Main image:** Stored as a single attachment ID in `main_image_id` meta key.
- **Room rules / amenities intro:** Additional text meta stored under their respective meta keys.

### 4.4 Inventory Units (Physical Rooms)

Each room type (`must_rooms`) can have one or more physical inventory units in `mhb_rooms`. Inventory units represent a physical room at the property (e.g., Room 101, Room 102).

Inventory unit fields:

| Field | Notes |
|---|---|
| `room_type_id` | References `must_rooms.id` |
| `title` | Unit label (e.g., "Room 101") |
| `room_number` | Must be unique across all units |
| `floor` | Floor number |
| `status` | `available`, or other status values |
| `is_active` | 1 = active |
| `is_bookable` | 1 = sellable |
| `is_calendar_visible` | 1 = shown on calendar |
| `sort_order` | Display sort |
| `capacity_override` | Overrides room type capacity; 0 = use type default |
| `building` | Building identifier |
| `section` | Section identifier |
| `admin_notes` | Internal notes |

If a room type has no inventory units, the Needs Attention panel on the Dashboard will flag it.

### 4.5 Import / Export XLSX

The Accommodations page supports bulk management via Excel workbooks.

**Export:** Downloads a `.xlsx` file named `must-hotel-booking-accommodations-<timestamp>.xlsx` with one sheet (`accommodations`) containing all room records.

**Download Template:** Downloads a blank `.xlsx` template with the correct column headers.

**Import:** Upload a `.xlsx` file matching the template schema. The importer processes the `accommodations` sheet.

XLSX column schema (sheet name: `accommodations`):

| Column | Notes |
|---|---|
| `id` | Leave blank for new rooms; provide the existing ID to update |
| `title` | Room name |
| `accommodation_category` | Category slug (alias: `accommodation_type`) |
| `description` | Full description |
| `internal_code` | Internal code |
| `max_adults` | |
| `max_children` | |
| `max_guests` | |
| `default_occupancy` | |
| `base_price` | |
| `extra_guest_price` | |
| `size` | |
| `bed_type` | |
| `amenities` | Comma-separated amenity keys |
| `amenities_intro` | |
| `room_rules` | |
| `sort_order` | |
| `active` | 1 or 0 |
| `bookable` | 1 or 0 |
| `online_bookable` | 1 or 0 |
| `calendar_visible` | 1 or 0 |
| `admin_notes` | |

> **Warning:** Importing a row with an existing `id` will overwrite that room's data. There is no undo. Export first to create a backup before bulk imports.

---

## 5. Rates & Pricing

**URL:** `wp-admin/admin.php?page=must-hotel-booking-pricing`

The Rates & Pricing page manages the **legacy pricing system** using the `must_pricing` table. This is a separate system from Rate Plans.

### 5.1 Pricing Systems Overview

The plugin contains two pricing systems. Understanding which one applies to a given reservation is important:

| System | Tables | Description |
|---|---|---|
| **Legacy Pricing** (older) | `must_pricing` | Date-range rules with price overrides per room. Managed on the Rates & Pricing page. |
| **Modern Rate Plans** (newer) | `mhb_rate_plans`, `mhb_room_type_rate_plans`, `mhb_rate_plan_prices`, `mhb_seasons`, `mhb_seasonal_prices` | Named plans linked to room types with per-date and seasonal price overrides. Managed on the Rate Plans page. |

The `PricingEngine` resolves which price applies. When a reservation is created, a `rate_plan_id` is stored on the reservation row (0 if no rate plan was used).

### 5.2 Base Price

Each room has a `base_price` in `must_rooms`. This is the fallback price per night when no pricing rule or rate plan overrides it.

To update the base price for a room:

1. Go to **Rates & Pricing**.
2. Select the room from the dropdown.
3. Enter the new base price.
4. Click **Save Base Price**.

### 5.3 Legacy Pricing Rules (`must_pricing`)

A pricing rule defines a price override for a room over a date range.

Pricing rule fields:

| Field | Description | Notes |
|---|---|---|
| `room_id` | Room this rule applies to (0 = all rooms) | Unverified / needs confirmation for 0 behaviour |
| `name` | Internal rule label | |
| `start_date` | Rule start date | Inclusive |
| `end_date` | Rule end date | Inclusive |
| `price_override` | Per-night price override | Replaces base price |
| `weekend_price` | Per-night price for weekends | Applied on Sat/Sun within the range |
| `minimum_nights` | Minimum stay required for this price to apply | Default 1 |
| `priority` | Higher number = higher priority when multiple rules overlap | Default 10 |
| `is_active` | 1 = active | |

**Actions on rules:**

- Create / Edit a rule using the form on the Rates & Pricing page.
- **Duplicate:** Creates a copy of the rule.
- **Toggle active/inactive:** Enables or disables without deleting.
- **Delete:** Permanently removes the rule.

> **Warning:** Deleting a pricing rule is permanent. There is no recycle bin. Duplicate before deleting if you may need the rule again.

### 5.4 Relationship to Rate Plans

The legacy pricing table (`must_pricing`) and rate plans are separate systems. If a reservation is linked to a rate plan (`rate_plan_id > 0`), the rate plan pricing takes precedence. The `must_pricing` rules act as the fallback when no rate plan is active for a room type.

---

## 6. Rate Plans

**URL:** `wp-admin/admin.php?page=must-hotel-booking-rate-plans`

Rate Plans are the modern pricing system. A rate plan is a named pricing template that can be assigned to one or more room types with a specific base price and occupancy setting.

### 6.1 What a Rate Plan Is

A rate plan (`mhb_rate_plans`) has:

| Field | Description |
|---|---|
| `name` | Plan name (e.g., "Bed & Breakfast", "Non-refundable") |
| `description` | Description shown to guests |
| `cancellation_policy_id` | References a cancellation policy |
| `is_active` | 1 = active and selectable during booking |

### 6.2 Creating a Rate Plan

1. Navigate to **MUST Hotel Booking → Rate Plans**.
2. Click **Add Rate Plan** (or leave the list to open the create form).
3. Enter a name (required), description (optional), select a cancellation policy (optional), and set active status.
4. Click **Save Rate Plan**.

### 6.3 Assigning a Rate Plan to Room Types

After creating a rate plan, assign it to room types via the assignment section on the rate plan edit page.

Assignment fields:

| Field | Description |
|---|---|
| `room_type_id` | The room type (`must_rooms.id`) to assign |
| `base_price` | Base price per night for this plan + room type combination |
| `max_occupancy` | Maximum occupancy for this assignment |

The assignment is stored in `mhb_room_type_rate_plans`. A unique constraint ensures each room type can only be assigned to a given rate plan once.

To remove an assignment: use the Delete link next to the assignment row.

### 6.4 Per-Date Price Overrides

For any active rate plan, you can set a specific price for a specific date. These are stored in `mhb_rate_plan_prices`:

| Field | Description |
|---|---|
| `rate_plan_id` | The plan this price belongs to |
| `date` | The specific date (YYYY-MM-DD) |
| `price` | Per-night price for that date |

To add a per-date override on the rate plan edit page: enter the date and price, then click Save. Each `(rate_plan_id, date)` pair is unique — entering a date that already has a price replaces it.

To delete a per-date price: use the Delete link next to the price row.

### 6.5 Seasons and Seasonal Pricing

The database contains two supporting tables:

- `mhb_seasons` — Named seasons with a date range and priority.
- `mhb_seasonal_prices` — A modifier (fixed or percentage) applied to a rate plan during a season.

> **Unverified / needs confirmation:** A dedicated UI for managing seasons is not confirmed in the code reviewed. The tables exist in the schema and the `RatePlanRepository` exposes `seasonsTableExists()` and `seasonalPricesTableExists()`, but no admin page or form for season management was found in the files reviewed.

### 6.6 Deleting a Rate Plan

> **Warning:** Deleting a rate plan removes the plan, all its room type assignments, and all its per-date price overrides. Existing reservations that reference the deleted `rate_plan_id` retain the stored ID but the plan will no longer be resolvable. The `must_reservations.rate_plan_id` column is not automatically cleared.

---

## 7. Availability Rules

**URL:** `wp-admin/admin.php?page=must-hotel-booking-availability-rules`

The Availability Rules page manages two related but distinct features: **Availability Rules** and **Manual Blocks**.

### 7.1 Availability Rule Types

Rules are stored in `must_availability`. Each rule has a `rule_type`:

| Rule Type | Description | `rule_value` |
|---|---|---|
| `maintenance_block` | Marks a date range as unavailable for maintenance | Not used (set to 0) |
| `minimum_stay` | Enforces a minimum number of nights for arrivals in the date range | Number of nights |
| `maximum_stay` | Enforces a maximum number of nights for arrivals in the date range | Number of nights |
| `closed_arrival` | Arrivals not permitted on dates in the range | Not used |
| `closed_departure` | Departures not permitted on dates in the range | Not used |

### 7.2 Availability Rule Fields

| Field | Description |
|---|---|
| `room_id` | The room this rule applies to (0 = all rooms) |
| `name` | Internal rule label (required) |
| `availability_date` | Start date of the rule (YYYY-MM-DD) |
| `end_date` | End date of the rule (YYYY-MM-DD) |
| `rule_type` | One of the types above |
| `rule_value` | Number of nights (for min/max stay), 0 otherwise |
| `is_active` | 1 = active |
| `reason` | Public-facing reason text (defaults to rule name) |

### 7.3 Creating an Availability Rule

1. Go to **Availability Rules**.
2. Fill in the rule form: name, room, date range, rule type, rule value (for stay rules), and active status.
3. Click **Save Rule**.

If the rule date range overlaps existing confirmed reservations, the rule is still saved but a warning notice (`rule_saved_with_conflict`) is shown.

### 7.4 Actions on Rules

| Action | Description |
|---|---|
| Edit | Open rule in the form for editing |
| Duplicate | Create a copy of the rule |
| Toggle active/inactive | Enable or disable without deleting |
| Delete | Permanently remove the rule |

### 7.5 Manual Blocks

Manual blocks are date range blocks for a specific room that are stored as `status = 'blocked'` reservation rows in `must_reservations` (not in `must_availability`). They are managed from the same Availability Rules page via the Block form.

Manual block fields: room (required), check-in date, check-out date (must be after check-in), notes.

If the block dates conflict with existing confirmed reservations, the block is saved but a warning notice is shown.

> **Warning:** Manual blocks consume inventory in the same way a real reservation does. A manual block prevents bookings for the blocked room and date range. Deleting a block releases the inventory immediately.

**Block actions:** Edit, Duplicate, Delete.

---

## 8. Payments

**URL:** `wp-admin/admin.php?page=must-hotel-booking-payments`

The Payments page shows all payment ledger rows from `must_payments`, linked to reservations.

### 8.1 Payment List

The list shows reservation reference, amount, currency, method, status, transaction ID, and dates. Filters available:

- `payment_group` — `due` (unpaid/outstanding)
- `reservation_id` — view payments for a specific reservation
- Additional filters supported by the query layer

### 8.2 Payment Methods

Two payment methods are supported in the current codebase:

| Method Key | Description |
|---|---|
| `pay_at_hotel` | Guest pays at check-in, no online transaction required |
| `stripe` | Online payment via Stripe Checkout |

Additional methods may be registered via `PaymentMethodRegistry` (extensible, Unverified / needs confirmation).

### 8.3 Payment Status Transitions (Admin Actions)

From the Payments page, staff can trigger status transitions for a reservation's payment:

| Action | Result |
|---|---|
| `mark_paid` | Marks payment as fully paid; records a payment row if needed |
| `mark_unpaid` | Reverts payment to unpaid |
| `mark_pending` | Marks payment as pending |
| `mark_pay_at_hotel` | Sets payment status to `pay_at_hotel` |
| `mark_failed` | Marks payment as failed |
| `resend_guest_email` | Re-sends the guest confirmation email |
| `resend_admin_email` | Re-sends the admin notification email |

The `PaymentStatusService::canTransition()` method enforces which transitions are allowed from the current state. Resend email actions bypass the transition check.

### 8.4 Posting a Manual Payment

Staff can post a manual payment against a reservation. This creates a row in `must_payments` and updates the reservation's `payment_status`.

Fields for a manual payment posting:

- Amount
- Method (default `pay_at_hotel`)
- Transaction ID (optional reference)

> **Warning:** Posting a manual payment that exceeds the amount due will still be recorded. The system does not automatically prevent over-payment via manual posting. Check the reservation total and outstanding amount before posting.

### 8.5 Payment Table Schema (`must_payments`)

| Column | Type | Notes |
|---|---|---|
| `reservation_id` | BIGINT | References `must_reservations.id` |
| `amount` | DECIMAL(12,2) | |
| `currency` | VARCHAR(10) | |
| `method` | VARCHAR(50) | `pay_at_hotel`, `stripe`, etc. |
| `status` | VARCHAR(50) | `pending`, `paid`, `failed`, etc. |
| `transaction_id` | VARCHAR(191) | Stripe PaymentIntent ID or manual reference |
| `paid_at` | DATETIME | NULL until confirmed paid |
| `created_at` | DATETIME | |

---

## 9. Taxes & Fees

**URL:** `wp-admin/admin.php?page=must-hotel-booking-taxes`

> **Note:** This page is accessible via direct URL only. It is not shown in the sidebar menu — it is a hidden submenu page accessible by link from the Rates & Pricing page or via direct URL.

Taxes and fees are stored in `must_taxes`. Each rule defines a named tax or fee that is applied to bookings.

### 9.1 Tax Rule Fields

| Field | Options | Description |
|---|---|---|
| `name` | — | Label for the tax or fee (e.g., "VAT", "City Tax", "Resort Fee") |
| `rule_type` | `percentage` / `fixed` | Whether the value is a percentage or a flat amount |
| `rule_value` | DECIMAL(12,2) | The percentage (e.g., 10 for 10%) or flat amount |
| `apply_mode` | `stay` / `night` | Whether the fee is applied once per stay or per night |

### 9.2 Creating a Tax Rule

1. Navigate to **Taxes & Fees** (via direct URL `wp-admin/admin.php?page=must-hotel-booking-taxes`).
2. Fill in the rule form: name, type, value, and apply mode.
3. Submit the form.

### 9.3 Examples

| Name | Type | Value | Mode | Effect |
|---|---|---|---|---|
| VAT | percentage | 11 | stay | 11% added to the total booking amount once |
| City Tax | fixed | 2.50 | night | $2.50 per night added to each night's cost |
| Resort Fee | fixed | 20.00 | stay | $20.00 flat fee added once per stay |

> **Warning:** Changing or deleting a tax rule does not retroactively update existing reservations. Reservation totals stored in `must_reservations.total_price` reflect the taxes applied at the time the booking was created.

---

## 10. Coupons

**URL:** `wp-admin/admin.php?page=must-hotel-booking-coupons`

Coupons are stored in `must_coupons`. They allow guests to apply discount codes during the booking process.

### 10.1 Coupon Fields

| Field | Description | Validation |
|---|---|---|
| `code` | The discount code guests enter | Required, must be unique |
| `name` | Internal label for the coupon | Optional |
| `is_active` | 1 = active and usable by guests | |
| `discount_type` | `percentage` or `fixed` | Required |
| `discount_value` | Amount of the discount | Must be > 0; percentage cannot exceed 100 |
| `minimum_booking_amount` | Minimum booking total for coupon to apply | 0 = no minimum |
| `valid_from` | First date the coupon is valid | Required |
| `valid_until` | Last date the coupon is valid | Required; must be >= valid_from |
| `usage_limit` | Maximum number of uses (0 = unlimited) | Cannot be negative |
| `usage_count` | Number of times the coupon has been used | Read-only, auto-incremented |

### 10.2 Creating a Coupon

1. Go to **MUST Hotel Booking → Coupons**.
2. Click **Add Coupon** or navigate to the coupon form.
3. Enter the code, name, discount type and value, validity dates, and optional minimum booking amount and usage limit.
4. Click **Save Coupon**.

### 10.3 Managing Existing Coupons

**Toggle active/inactive:** Use the Toggle action to enable or disable a coupon without deleting it. Disabled coupons cannot be applied at checkout.

**Edit:** Click the coupon row to open the edit form.

**Delete:** Permanently removes the coupon.

> **Warning:** Deleting a coupon does not remove it from existing reservations. The `must_reservations` table stores `coupon_code` and `coupon_discount_total` directly on the reservation row, so historical data is preserved. However, the coupon record itself will no longer be resolvable.

### 10.4 Coupon Application Logic

At checkout, the guest enters a code. The engine checks:
1. Code exists and `is_active = 1`
2. Current date is within `valid_from` to `valid_until`
3. Booking total meets `minimum_booking_amount` (if set)
4. `usage_count < usage_limit` (if `usage_limit > 0`)

If all checks pass, the discount is applied:
- `percentage`: discount = booking_total * (discount_value / 100)
- `fixed`: discount = discount_value

The discount is stored in `must_reservations.coupon_discount_total`.

---

## 11. Emails

**URL:** `wp-admin/admin.php?page=must-hotel-booking-emails`

The Emails page manages email sender settings and individual email templates.

### 11.1 Email Settings

These fields are saved to the `notifications_summary` settings group and can also be edited here (in addition to Settings > Notifications & Emails):

| Field | Description |
|---|---|
| Booking notification email | Email address that receives admin notifications for new bookings |
| Sender name | The "From" name on outgoing emails |
| Sender email | The "From" email address |
| Reply-to email | Optional reply-to address |
| Hotel phone | Phone shown in email templates |
| Email logo URL | URL of logo image shown in email header |
| Email button color | Hex color for CTA buttons in emails |
| Email footer text | Text shown at the bottom of all emails |
| Email layout type | `classic`, `luxury`, `compact`, or `custom` |
| Custom layout HTML | Only used when layout type = `custom` |

### 11.2 Email Templates

The plugin maintains a set of email templates stored in the `notifications_summary` settings group under `email_templates`. Each template has:

| Field | Notes |
|---|---|
| Template key | Internal identifier (e.g., `admin_new_booking_pay_at_hotel`) |
| Subject | Email subject line |
| Body | Email body text (may use placeholders) |
| Enabled | 1 = template is active and will be sent |

**Template actions:**

| Action | Description |
|---|---|
| Edit template | Open template form and edit subject and body |
| Save template | Submit the edited template |
| Toggle enable/disable | Activate or deactivate without editing content |
| Reset to default | Restores the template's subject and body to the plugin defaults |

> **Warning:** Resetting a template is irreversible. Any customizations to the subject or body are lost. The plugin's default text is restored.

### 11.3 Sending a Test Email

A test email action is available on the Emails page. It sends a sample email using the `admin_new_booking_pay_at_hotel` template to the booking notification email address configured in Settings. This also available from Settings > Diagnostics & Maintenance.

### 11.4 Email Placeholders

Email templates use placeholder variables that the engine replaces at send time. Specific placeholder keys are rendered by `EmailLayoutEngine` / `EmailEngine`. The exact list of available placeholders is defined in those engine files and is **Unverified / needs confirmation** from code not reviewed here.

---

## 12. Guests

**URL:** `wp-admin/admin.php?page=must-hotel-booking-guests`

The Guests page lists all guest profiles stored in `must_guests`.

### 12.1 Guest List

The list shows guest name, email, phone, country, VIP flag, problem flag, and a link to view their reservation history. Filters:

- Search by name, email
- Filter by VIP flag or problem flag

### 12.2 Guest Profile Fields

| Field | Description |
|---|---|
| `first_name` | |
| `last_name` | |
| `email` | Optional, validated as email format |
| `phone` | |
| `country` | Country code or name |
| `admin_notes` | Internal notes, not shown to guests |
| `vip_flag` | 1 = guest flagged as VIP |
| `problem_flag` | 1 = guest flagged as problem |

### 12.3 Editing a Guest Profile

1. Click the guest row in the list to open the profile.
2. Edit any of the above fields.
3. At least a guest name (first or last) or email is required.
4. Click **Save Guest**.

Validation rules:
- If `email` is provided, it must be a valid email address.
- At least `first_name`, `last_name`, or `email` must be non-empty.

### 12.4 Guest Flags

**VIP Flag:** Marks a guest as VIP. The flag is stored as `vip_flag = 1`. Currently used for filtering and display; no automated behaviour is applied to VIP guests by the plugin engine (no automatic upgrades, pricing changes, etc.).

**Problem Flag:** Marks a guest as a problem guest. Stored as `problem_flag = 1`. Used for filtering and staff awareness; no automated restrictions are applied.

Both flags can be toggled by any user with the `mhb_guest_edit_flags` capability.

### 12.5 Guest Notes

The `admin_notes` field stores free-text internal notes on the guest profile. These notes are not shown to guests. Multiple lines of text are supported (textarea field). Notes are set per-update — there is no append history for notes; the field is overwritten each save.

---

## 13. Reports

**URL:** `wp-admin/admin.php?page=must-hotel-booking-reports`

The Reports page provides financial and operational reporting across date ranges.

### 13.1 Report Filters

| Filter | Options |
|---|---|
| Preset | Today, Last 7 Days, This Month, Last Month, This Year, Custom |
| Date From | YYYY-MM-DD, custom start date |
| Date To | YYYY-MM-DD, custom end date |
| Accommodation | All accommodations, or a specific room |
| Reservation Status | All, or a specific status |
| Payment Method | All, or a specific payment method |

Filters are applied via a GET form and reflect in the URL query string. A **Reset** button returns all filters to defaults.

### 13.2 KPI Cards in Reports

The reports page renders the following KPI summary cards:

| KPI | Description |
|---|---|
| Total Reservations | Count of reservations created in the date range |
| Confirmed Reservations | Count of `confirmed` or `completed` reservations in range |
| Cancelled Reservations | Count of `cancelled` reservations in range |
| Booked Revenue | Sum of `total_price` excluding blocked and cancelled rows |
| Amount Paid | Sum of confirmed paid amounts from the payment ledger |
| Amount Due | Outstanding balance on non-cancelled reservations |
| Occupancy | Occupied nights as a percentage of available nights |
| Average Booking Value | Booked revenue divided by confirmed reservation count |

**Notes on occupancy calculation (from source):**
- Reservation section uses reservations *created* in the range.
- Stay/occupancy section uses reservations whose *stay overlaps* the range.
- Available nights exclude inactive or non-bookable inventory, units marked unavailable, and maintenance-blocked dates.
- Closed-arrival and closed-departure rules are not removed from available capacity in the occupancy calculation.
- Coupon discounts are applied after nightly pricing and fees, before taxes.

### 13.3 Trend Data

The reports page also builds trend rows from the created reservations query. This is rendered as a breakdown by date showing reservations and revenue over time within the selected range.

### 13.4 Occupancy Requirements

Occupancy metrics require sellable inventory units in the inventory model (`mhb_rooms` table). If no inventory units exist, the Occupancy KPI will show "N/A" with the message "Occupancy requires sellable inventory units in the current inventory model."

### 13.5 Export

> **Unverified / needs confirmation:** A data export (CSV/XLSX) from the Reports page was not confirmed in the files reviewed. The filter form and KPI rendering were found, but no export download action was identified in `reports.php` or `ReportAdminDataProvider`.

---

## 14. Settings

**URL:** `wp-admin/admin.php?page=must-hotel-booking-settings`

Settings are stored as a single serialized option in `wp_options` under the key `must_hotel_booking_settings` (constant: `MustBookingConfig::OPTION_NAME`). The settings are organized into groups. The page is tabbed.

**Capability:** The Settings page uses a distinct capability check. Users with `manage_options` always have access. Other users require the capability set in `StaffAccess::getSettingsCapability()`.

---

### 14.1 General Tab

Slug: `general`

| Setting Key | Description | Default | Notes |
|---|---|---|---|
| `hotel_name` | Display name of the hotel | WordPress site name | Used in emails and guest-facing pages |
| `hotel_legal_name` | Legal entity name | `` | Used on invoices/receipts |
| `hotel_address` | Physical address | `` | Textarea |
| `hotel_phone` | Contact phone | `` | |
| `hotel_email` | Hotel contact email | WordPress admin email | |
| `default_country` | Default country for guest forms | Derived from WP locale | 2-letter ISO code |
| `timezone` | Hotel timezone | WordPress timezone | Must be a valid PHP timezone identifier |
| `currency` | Currency code | `USD` | ISO 4217 code, uppercase only |
| `currency_display` | How currency is shown | `symbol_code` | Options: `symbol`, `symbol_code`, `code` |
| `date_format` | Date display format | WordPress date format | PHP date format string |
| `time_format` | Time display format | WordPress time format | PHP time format string |
| `hotel_logo_url` | URL of hotel logo | `` | Used in admin and frontend pages |
| `portal_logo_url` | URL of logo for staff portal | `` | Separate from hotel logo |
| `site_environment` | Active site environment | `` | Affects Stripe key selection: `local`, `staging`, `production` |

---

### 14.2 Booking Rules Tab

Slug: `booking_rules`

| Setting Key | Description | Default | Range |
|---|---|---|---|
| `booking_window` | Days in advance guests can book | 365 | 1–3650 |
| `same_day_booking_allowed` | Allow bookings for today | `true` | Boolean |
| `same_day_booking_cutoff_time` | Cutoff time for same-day bookings | `18:00` | HH:MM format |
| `minimum_nights` | Minimum stay length | 1 | 1–365 |
| `maximum_nights` | Maximum stay length | 30 | 1–365 |
| `max_booking_guests` | Maximum guests per booking | 12 | 1–100 |
| `max_booking_rooms` | Maximum rooms per booking | 3 | 1–25 |
| `allow_multi_room_booking` | Allow booking multiple rooms at once | `true` | Boolean |
| `default_reservation_source` | Default booking source tag | `website` | `website`, `phone`, `walk_in`, `email`, `portal`, `admin` |
| `pending_reservation_expiration_minutes` | Minutes before a pending reservation expires | 35 | 5–1440 |
| `require_phone` | Make phone required in booking form | `true` | Boolean |
| `require_country` | Make country required in booking form | `true` | Boolean |
| `enable_special_requests` | Show special requests field | `true` | Boolean |
| `require_terms_acceptance` | Require terms checkbox | `true` | Boolean |
| `default_reservation_status` | Status assigned to new reservations | `confirmed` | `pending`, `pending_payment`, `confirmed`, `completed`, `cancelled` |
| `default_payment_mode` | Payment mode offered at checkout | `guest_choice` | `guest_choice`, `pay_now`, `pay_at_hotel` |
| `cancellation_allowed` | Allow guest-initiated cancellation | `true` | Boolean |
| `cancellation_notice_hours` | Hours before check-in that cancellation is allowed | 48 | 0–720 |

---

### 14.3 Check-in / Check-out Tab

Slug: `checkin_checkout`

| Setting Key | Description | Default |
|---|---|---|
| `checkin_time` | Standard check-in time | `14:00` |
| `checkout_time` | Standard check-out time | `11:00` |
| `allow_early_checkin_request` | Show early check-in request option to guests | `true` |
| `allow_late_checkout_request` | Show late check-out request option to guests | `true` |
| `arrival_instructions` | Free-text instructions shown to guests at booking confirmation | `` |
| `departure_instructions` | Free-text departure instructions | `` |
| `guest_checkin_label` | Label text for check-in field in booking forms | `Check-in` |
| `guest_checkout_label` | Label text for check-out field in booking forms | `Check-out` |

---

### 14.4 Payments Summary Tab

Slug: `payments_summary`

| Setting Key | Description | Default | Notes |
|---|---|---|---|
| `payment_methods.pay_at_hotel` | Enable Pay at Hotel | `true` | Boolean |
| `payment_methods.stripe` | Enable Stripe | `false` | Boolean |
| `deposit_required` | Require a deposit at booking | `false` | Boolean |
| `deposit_type` | Deposit calculation type | `percentage` | `percentage` or `fixed` |
| `deposit_value` | Deposit amount or percentage | `0.00` | |
| `tax_rate` | Global fallback tax rate | `0.00` | Percentage, 0–100 |
| `stripe_publishable_key` | Active Stripe publishable key (resolved from environment) | `` | Set by environment key fields below |
| `stripe_secret_key` | Active Stripe secret key | `` | |
| `stripe_webhook_secret` | Active Stripe webhook signing secret | `` | |
| `stripe_local_publishable_key` | Stripe publishable key for `local` environment | `` | |
| `stripe_local_secret_key` | Stripe secret key for `local` environment | `` | |
| `stripe_local_webhook_secret` | Stripe webhook secret for `local` environment | `` | |
| `stripe_staging_publishable_key` | Stripe publishable key for `staging` environment | `` | |
| `stripe_staging_secret_key` | Stripe secret key for `staging` environment | `` | |
| `stripe_staging_webhook_secret` | Stripe webhook secret for `staging` environment | `` | |
| `stripe_production_publishable_key` | Stripe publishable key for `production` environment | `` | |
| `stripe_production_secret_key` | Stripe secret key for `production` environment | `` | |
| `stripe_production_webhook_secret` | Stripe webhook secret for `production` environment | `` | |

**Stripe key resolution:** The active keys (`stripe_publishable_key`, `stripe_secret_key`, `stripe_webhook_secret`) are resolved from the environment-specific key sets based on `site_environment` (set in General tab). The Payments Summary tab in the Settings page appears to show summary and diagnostic information about the payment configuration, while the full key entry is in this group.

> **Warning:** Never commit Stripe secret keys to version control. The keys stored in the database are site-scoped settings. For `local` and `staging` environments, use Stripe test keys only.

---

### 14.5 Staff & Access Tab

Slug: `staff_access`

| Setting Key | Description | Default |
|---|---|---|
| `enable_staff_portal` | Enable the staff portal frontend | `false` |
| `redirect_worker_after_login` | Where staff users land after login | `dashboard` |
| `hide_wp_admin_for_workers` | Redirect staff away from WP admin | `true` |
| `portal_access_roles` | Which WP roles can access the staff portal | Front Desk, Supervisor, Housekeeping, Finance, Ops Manager |
| `capability_matrix` | Per-role capability toggles | See defaults |
| `portal_module_visibility` | Which portal modules are visible | All enabled |

**Built-in staff roles:**

| Role Slug | Label |
|---|---|
| `mhb_front_desk` | Front Desk |
| `mhb_supervisor` | Supervisor |
| `mhb_housekeeping` | Housekeeping |
| `mhb_finance` | Finance |
| `mhb_ops_manager` | Operations Manager |

Legacy role slugs `mhb_worker` and `mhb_manager` are deprecated aliases for `mhb_front_desk` and `mhb_supervisor` respectively.

**Portal module visibility toggles:**
`dashboard`, `reservations`, `calendar`, `front_desk`, `guests`, `payments`, `housekeeping`, `reports`, `rooms_availability`

---

### 14.6 Staff Users Tab

Slug: `staff_users`

This tab provides a management interface for creating, activating, deactivating, and deleting plugin staff users. Only users with `manage_options` can access this tab.

**Creating a staff user:**

1. Enter username (must be unique in WordPress)
2. Enter email (must be unique in WordPress)
3. Enter password (minimum 8 characters)
4. Select a staff role from the available portal roles

On creation, the WordPress user is created and assigned the selected role. Credentials are stored briefly in a short-lived transient for one-time display to the admin — they are not stored in the redirect URL.

**Activating / Deactivating a staff user:**

Deactivation sets `mhb_staff_disabled = 1` user meta. The user account is not deleted from WordPress — it is only blocked from the staff portal. Activation removes the user meta key.

> **Warning:** Deactivating your own staff account is not permitted. You cannot delete yourself either.

**Deleting a staff user:**

Permanently deletes the WordPress user account. Only users with plugin staff roles can be deleted through this tool. Administrator accounts (`manage_options`) cannot be deleted here.

> **Warning:** Deleting a staff user is a WordPress user deletion and is irreversible. Any content authored by the user in WordPress may be affected.

---

### 14.7 Frontend & Branding Tab

Slug: `branding`

| Setting Key | Description | Default |
|---|---|---|
| `primary_color` | Primary brand color | `#0f766e` |
| `secondary_color` | Secondary brand color | `#155e75` |
| `accent_color` | Accent / highlight color | `#f59e0b` |
| `text_color` | Body text color | `#16212b` |
| `border_radius` | Border radius in pixels for UI elements | 18 (range: 0–40) |
| `font_family` | Font family name | `Instrument Sans` |
| `inherit_elementor_colors` | Use Elementor Global Color tokens instead of above | `false` |
| `inherit_elementor_typography` | Use Elementor Global Typography instead of font settings | `false` |
| `portal_welcome_title` | Title shown on the staff portal dashboard | `Welcome back` |
| `portal_welcome_text` | Subtitle/description on staff portal dashboard | Default text |
| `booking_form_style_preset` | Visual style for the guest booking form | `balanced` (`balanced`, `editorial`, `minimal`) |

---

### 14.8 Managed Pages Tab

Slug: `managed_pages`

The plugin automatically creates and manages specific WordPress pages. This tab shows the assignment status of each managed page and allows manual repair.

| Page Key | Purpose |
|---|---|
| `page_rooms_id` | Room listing page |
| `page_booking_id` | Booking entry page |
| `page_booking_accommodation_id` | Accommodation detail page used during booking |
| `page_checkout_id` | Checkout / payment page |
| `page_booking_confirmation_id` | Post-booking confirmation page |
| `portal_page_id` | Staff portal main page |
| `portal_login_page_id` | Staff portal login page |

**Actions:**

- **Create:** Creates the page if it does not exist.
- **Recreate:** Deletes and re-creates the page (resets any manual edits to the page content).
- **Reinstall All Pages (in Diagnostics tab):** Runs the full page installer for all managed pages.

> **Warning:** The "Recreate" action deletes the existing WordPress page and creates a new one. Any custom content or metadata added to the page will be lost.

---

### 14.9 Notifications & Emails Summary Tab

Slug: `notifications_summary`

This tab shows a read-only summary of the email configuration (sender, templates, recent failures) and provides a shortcut to the Emails management page. The actual email settings form is on the Emails admin page.

Settings managed here (duplicated from the Emails page):

| Setting Key | Description | Default |
|---|---|---|
| `booking_notification_email` | Admin receives new booking notifications here | WordPress admin email |
| `email_from_name` | Sender name | Hotel name |
| `email_from_email` | Sender email address | Booking notification email |
| `email_reply_to` | Reply-to email | `` |
| `email_logo_url` | Logo URL for emails | `` |
| `email_button_color` | CTA button hex color | `#141414` |
| `email_footer_text` | Footer text in all emails | `We look forward to welcoming you.` |
| `email_layout_type` | Email layout style | `classic` (`classic`, `luxury`, `compact`, `custom`) |
| `custom_email_layout_html` | Custom HTML layout template | `` |

---

### 14.10 Diagnostics & Maintenance Tab

Slug: `maintenance`

This tab is the operational health and maintenance centre. It shows a detailed table of all plugin database tables (whether they exist or are missing), managed page health, cron job status, payment configuration, email configuration, updater status, and environment information.

**Database Tables Checked:**

| Table Label | Table Name |
|---|---|
| Rooms | `{prefix}must_rooms` |
| Room Types | `{prefix}mhb_room_types` |
| Inventory Rooms | `{prefix}mhb_rooms` |
| Room Meta | `{prefix}must_room_meta` |
| Guests | `{prefix}must_guests` |
| Reservations | `{prefix}must_reservations` |
| Pricing | `{prefix}must_pricing` |
| Availability | `{prefix}must_availability` |
| Locks | `{prefix}mhb_inventory_locks` |
| Payments | `{prefix}must_payments` |
| Activity Log | `{prefix}must_activity_log` |
| Taxes | `{prefix}must_taxes` |
| Coupons | `{prefix}must_coupons` |
| Cancellation Policies | `{prefix}mhb_cancellation_policies` |
| Rate Plans | `{prefix}mhb_rate_plans` |
| Room Type Rate Plans | `{prefix}mhb_room_type_rate_plans` |
| Rate Plan Prices | `{prefix}mhb_rate_plan_prices` |
| Seasons | `{prefix}mhb_seasons` |
| Seasonal Prices | `{prefix}mhb_seasonal_prices` |

**Maintenance Actions:**

| Action Key | Description |
|---|---|
| `reinstall_pages` | Re-runs the managed page installer and flushes rewrite rules |
| `reschedule_cron` | Unschedules and reschedules the lock cleanup cron job |
| `cleanup_expired_locks` | Immediately runs the expired booking lock cleanup |
| `send_test_email` | Sends a test email to the booking notification address |
| `flush_portal_routes` | Flushes WordPress rewrite rules for portal routes |
| `repair_inventory_mirror` | Syncs `mhb_room_types` and `mhb_rooms` from `must_rooms` |

The Repair Inventory Mirror action reports how many room types were inserted/updated and how many inventory units were created.

**Dangerous Reset Actions:**

Two destructive reset operations are available from this tab. They require administrator (`manage_options`) access and a typed confirmation phrase.

> **Warning — Reset Hotel Operational Data:** Deletes room listings, room inventory, room meta, guests, reservations, payments, pricing rules, availability rules, rate plans, seasons, seasonal prices, taxes, coupons, locks, cancellation policies, and activity history. Plugin settings, branding, payment configuration, and email templates are preserved. **Confirmation phrase required: `RESET HOTEL DATA`**

> **Warning — Full Plugin Factory Reset:** Runs the operational reset AND additionally clears the plugin settings bundle, managed page assignments, portal configuration, and branding. Returns the plugin to near first-install state without dropping tables or deleting WordPress pages. **Confirmation phrase required: `FACTORY RESET MUST HOTEL BOOKING`**

Both resets are irreversible. Take a full database backup before proceeding.

---

## Appendix A: Database Table Reference

| Table | Purpose |
|---|---|
| `must_room_categories` | Room category definitions |
| `must_rooms` | Authoritative room type / accommodation listings |
| `must_room_meta` | Key-value metadata for rooms (amenities, images, rules) |
| `mhb_room_types` | Inventory mirror of room types (derived from `must_rooms`) |
| `mhb_rooms` | Physical inventory units (individual bookable rooms) |
| `must_room_housekeeping_statuses` | Per-unit housekeeping status |
| `must_room_housekeeping_issues` | Housekeeping issue reports per unit |
| `must_housekeeping_handoffs` | Shift handoff records |
| `must_guests` | Guest profiles |
| `must_reservations` | All reservations, blocks, and booking records |
| `must_pricing` | Legacy pricing rules by room and date range |
| `must_availability` | Availability rules (min/max stay, closed dates, maintenance) |
| `mhb_inventory_locks` | Session-scoped booking locks to prevent double-booking |
| `must_payments` | Payment ledger rows |
| `must_taxes` | Tax and fee rules |
| `must_coupons` | Discount coupon codes |
| `must_activity_log` | Audit/activity trail for reservation, payment, and email events |
| `mhb_cancellation_policies` | Cancellation policy definitions |
| `mhb_rate_plans` | Rate plan definitions |
| `mhb_room_type_rate_plans` | Assignments of rate plans to room types with base prices |
| `mhb_rate_plan_prices` | Per-date price overrides for rate plans |
| `mhb_seasons` | Named seasons with date ranges and priority |
| `mhb_seasonal_prices` | Rate plan price modifiers for seasons |

---

## Appendix B: Staff Role Capabilities Reference

Capabilities are checked using WordPress capability functions. The capability matrix is configurable per-role in Settings > Staff & Access.

| Capability Constant | Slug | Description |
|---|---|---|
| `CAP_DASHBOARD_VIEW` | `mhb_dashboard_view` | View the staff portal dashboard |
| `CAP_RESERVATION_VIEW` | `mhb_reservation_view` | View reservations |
| `CAP_RESERVATION_CREATE` | `mhb_reservation_create` | Create new reservations |
| `CAP_RESERVATION_EDIT_BASIC` | `mhb_reservation_edit_basic` | Edit guest and notes fields |
| `CAP_RESERVATION_EDIT_STAY` | `mhb_reservation_edit_stay` | Change dates and room on a reservation |
| `CAP_RESERVATION_ASSIGN_ROOM` | `mhb_reservation_assign_room` | Assign a physical inventory unit |
| `CAP_RESERVATION_MOVE_ROOM` | `mhb_reservation_move_room` | Move reservation to another room |
| `CAP_RESERVATION_CHECKIN` | `mhb_reservation_checkin` | Perform check-in on a reservation |
| `CAP_RESERVATION_CHECKOUT` | `mhb_reservation_checkout` | Perform check-out on a reservation |
| `CAP_RESERVATION_CANCEL` | `mhb_reservation_cancel` | Cancel a reservation |
| `CAP_RESERVATION_NO_SHOW` | `mhb_reservation_mark_no_show` | Mark a reservation as no-show |
| `CAP_RESERVATION_BULK` | `mhb_reservation_bulk_actions` | Run bulk actions on reservations |
| `CAP_GUEST_VIEW` | `mhb_guest_view` | View guest profiles |
| `CAP_GUEST_EDIT_CONTACT` | `mhb_guest_edit_contact` | Edit guest contact details |
| `CAP_GUEST_EDIT_FLAGS` | `mhb_guest_edit_flags` | Set VIP or problem flags |
| `CAP_GUEST_ADD_NOTE` | `mhb_guest_add_note` | Add admin notes to guests |
| `CAP_PAYMENT_VIEW` | `mhb_payment_view` | View payment records |
| `CAP_PAYMENT_POST` | `mhb_payment_post` | Post a full payment |
| `CAP_PAYMENT_POST_PARTIAL` | `mhb_payment_post_partial` | Post a partial payment |
| `CAP_PAYMENT_MARK_PAID` | `mhb_payment_mark_paid` | Mark reservation as paid |
| `CAP_PAYMENT_REFUND` | `mhb_payment_refund` | Process a refund |
| `CAP_PAYMENT_RECEIPT` | `mhb_payment_receipt_issue` | Issue a payment receipt |
| `CAP_PAYMENT_INVOICE` | `mhb_payment_invoice_issue` | Issue an invoice |
| `CAP_PAYMENT_RECONCILE` | `mhb_payment_reconcile` | Reconcile payment records |
| `CAP_CALENDAR_VIEW` | `mhb_calendar_view` | View the calendar |
| `CAP_CALENDAR_MOVE` | `mhb_calendar_move_reservation` | Move reservations on the calendar |
| `CAP_CALENDAR_CREATE_BLOCK` | `mhb_calendar_create_block` | Create manual blocks on the calendar |
| `CAP_CALENDAR_EDIT_BLOCK` | `mhb_calendar_edit_block` | Edit existing blocks |
| `CAP_HOUSEKEEPING_VIEW` | `mhb_housekeeping_view` | View housekeeping statuses |
| `CAP_HOUSEKEEPING_UPDATE_STATUS` | `mhb_housekeeping_update_status` | Update room housekeeping status |
| `CAP_HOUSEKEEPING_ASSIGN_STAFF` | `mhb_housekeeping_assign_staff` | Assign housekeeping to staff |
| `CAP_HOUSEKEEPING_INSPECT` | `mhb_housekeeping_inspect_room` | Mark room as inspected |
| `CAP_HOUSEKEEPING_CREATE_ISSUE` | `mhb_housekeeping_create_issue` | Log a housekeeping issue |

---

*This document is based on direct source code review. Features flagged "Unverified / needs confirmation" could not be fully confirmed from the files examined and should be verified against a running installation or additional source files before being presented to end users.*
