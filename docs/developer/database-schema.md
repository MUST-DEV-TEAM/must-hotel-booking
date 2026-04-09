# Database Schema

**Plugin:** MUST Hotel Booking
**Version documented:** 0.3.45
**Source:** `src/Database/install-tables.php`

All tables are created or updated via WordPress `dbDelta()` each time `install_tables()` runs (activation, manual upgrade, or when the stored `must_hotel_booking_db_version` option is behind the current version constant).

The plugin writes its schema version to the WordPress option `must_hotel_booking_db_version`.

> **Important — dual room model and dual pricing model:** This plugin contains two parallel but partially overlapping data models. Both are actively maintained. See the flagged sections below.

---

## Dual Room Model Warning

The plugin maintains **two separate room representations**:

| Model | Tables | Role |
|---|---|---|
| Legacy / listing model | `must_rooms`, `must_room_meta`, `must_room_categories` | Sellable accommodation listings. This is the **authoritative sellable table** per the source code comment in `install-tables.php`. Used directly by the booking flow, pricing rules, and availability rules. |
| Inventory / physical unit model | `mhb_room_types`, `mhb_rooms` | Physical room units. `mhb_room_types` mirrors `must_rooms` (same IDs). `mhb_rooms` represents individual bookable physical units within a type. Used by `InventoryEngine` for granular assignment. |

**Sync behaviour:** `DefaultInventoryUnitSyncService` performs a one-time backfill to ensure every `must_rooms` row has at least one `mhb_rooms` unit. The `seed_inventory_model_from_legacy_rooms()` function (also in `install-tables.php`) handles bulk mirror updates but is described as a deliberate maintenance action, not a silent boot side effect.

**Risk:** The two models can diverge if rooms are added without triggering the sync. Code referencing `must_rooms.id` and `mhb_room_types.id` assumes they are equal for a given accommodation type.

---

## Dual Pricing Model Warning

| Model | Tables | Role |
|---|---|---|
| Legacy pricing rules | `must_pricing` | Date-range override rules with priority, weekend price, and minimum nights. Applied per `room_id` (references `must_rooms.id`). |
| Rate plan model | `mhb_rate_plans`, `mhb_room_type_rate_plans`, `mhb_rate_plan_prices`, `mhb_seasons`, `mhb_seasonal_prices` | Named rate plans with per-day price overrides, seasonal modifiers, and cancellation policies. Applied per `room_type_id` (references `mhb_room_types.id`). |

`PricingEngine` supports both models. When a `rate_plan_id > 0` is present on a reservation, the rate plan model is used; otherwise the legacy `must_pricing` rules and `must_rooms.base_price` are used as fallback.

---

## Reservations Domain

### `{prefix}must_reservations`

Central reservation record.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key, auto-increment |
| `booking_id` | `VARCHAR(50)` | Unique human-readable booking reference; nullable |
| `room_id` | `BIGINT UNSIGNED` | References `must_rooms.id` (listing-model room) |
| `room_type_id` | `BIGINT UNSIGNED` | References `mhb_room_types.id`; default `0` |
| `assigned_room_id` | `BIGINT UNSIGNED` | References `mhb_rooms.id` (physical unit); default `0` |
| `rate_plan_id` | `BIGINT UNSIGNED` | References `mhb_rate_plans.id`; `0` = no rate plan |
| `guest_id` | `BIGINT UNSIGNED` | References `must_guests.id` |
| `checkin` | `DATE` | Check-in date |
| `checkout` | `DATE` | Check-out date |
| `guests` | `SMALLINT UNSIGNED` | Number of guests; default `1` |
| `status` | `VARCHAR(50)` | Reservation lifecycle status; default `'pending'` |
| `booking_source` | `VARCHAR(50)` | `'website'`, `'phone'`, `'walk_in'`, `'email'`, `'portal'`, `'admin'`; default `'website'` |
| `notes` | `LONGTEXT` | Free-text notes (includes special requests and per-room guest info) |
| `total_price` | `DECIMAL(12,2)` | Booking total |
| `coupon_id` | `BIGINT UNSIGNED` | References `must_coupons.id`; `0` = none |
| `coupon_code` | `VARCHAR(100)` | Denormalised coupon code at time of booking |
| `coupon_discount_total` | `DECIMAL(12,2)` | Discount amount applied |
| `payment_status` | `VARCHAR(50)` | `'unpaid'`, `'partial'`, `'paid'`, etc.; default `'unpaid'` |
| `checked_in_at` | `DATETIME` | Physical check-in timestamp; nullable |
| `checked_out_at` | `DATETIME` | Physical check-out timestamp; nullable |
| `cancellation_requested` | `TINYINT(1)` | Guest-initiated cancellation request flag |
| `cancellation_requested_at` | `DATETIME` | Nullable |
| `cancellation_requested_by` | `BIGINT UNSIGNED` | WP user ID; `0` = guest self-service |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

**Key indexes:** `booking_id` (UNIQUE), `room_stay (room_id, checkin, checkout)`, `stay_dates (checkin, checkout)`, `status`, `payment_status`, `guest_id`, `rate_plan_id`, `coupon_id`.

---

## Room / Accommodation Domain

### `{prefix}must_room_categories`

Category taxonomy for accommodation listings.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `name` | `VARCHAR(191)` | Display name |
| `slug` | `VARCHAR(191)` | UNIQUE; used as `category` value in `must_rooms` |
| `description` | `LONGTEXT` | Nullable |
| `sort_order` | `INT(11)` | Default `0` |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

### `{prefix}must_rooms`

**Authoritative sellable accommodation listing table.**

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `name` | `VARCHAR(191)` | |
| `slug` | `VARCHAR(191)` | |
| `category` | `VARCHAR(100)` | Matches `must_room_categories.slug`; default `'standard-rooms'` |
| `description` | `LONGTEXT` | Nullable |
| `internal_code` | `VARCHAR(100)` | Internal reference code |
| `is_active` | `TINYINT(1)` | Default `1` |
| `is_bookable` | `TINYINT(1)` | Default `1` |
| `is_online_bookable` | `TINYINT(1)` | Controls frontend booking; default `1` |
| `is_calendar_visible` | `TINYINT(1)` | Default `1` |
| `sort_order` | `INT(11)` | Default `0` |
| `max_adults` | `SMALLINT UNSIGNED` | Default `1` |
| `max_children` | `SMALLINT UNSIGNED` | Default `0` |
| `max_guests` | `SMALLINT UNSIGNED` | Default `1` |
| `default_occupancy` | `SMALLINT UNSIGNED` | Default `1` |
| `base_price` | `DECIMAL(12,2)` | Fallback nightly rate |
| `extra_guest_price` | `DECIMAL(12,2)` | Per additional guest charge |
| `room_size` | `VARCHAR(100)` | |
| `beds` | `VARCHAR(100)` | |
| `admin_notes` | `LONGTEXT` | Nullable |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

### `{prefix}must_room_meta`

Arbitrary key-value metadata for `must_rooms` records.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `room_id` | `BIGINT UNSIGNED` | References `must_rooms.id` |
| `meta_key` | `VARCHAR(191)` | |
| `meta_value` | `LONGTEXT` | Nullable |

### `{prefix}mhb_room_types`

**Inventory model — room type.** Mirrors `must_rooms` with the same `id` values.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key; intentionally kept equal to `must_rooms.id` |
| `name` | `VARCHAR(191)` | |
| `description` | `LONGTEXT` | Nullable |
| `capacity` | `SMALLINT UNSIGNED` | Default `1` |
| `base_price` | `DECIMAL(12,2)` | Mirrors `must_rooms.base_price` |

### `{prefix}mhb_rooms`

**Inventory model — physical room unit.** Each row is one bookable physical unit.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `room_type_id` | `BIGINT UNSIGNED` | References `mhb_room_types.id` |
| `title` | `VARCHAR(191)` | Display name of this unit |
| `room_number` | `VARCHAR(100)` | UNIQUE; auto-generated as `RT-{type_id}-{n}` during backfill |
| `floor` | `INT(11)` | Default `0` |
| `status` | `VARCHAR(30)` | `'available'`, `'out_of_order'`, etc.; default `'available'` |
| `is_active` | `TINYINT(1)` | Default `1` |
| `is_bookable` | `TINYINT(1)` | Default `1` |
| `is_calendar_visible` | `TINYINT(1)` | Default `1` |
| `sort_order` | `INT(11)` | Default `0` |
| `capacity_override` | `SMALLINT UNSIGNED` | `0` = inherit from type |
| `building` | `VARCHAR(100)` | |
| `section` | `VARCHAR(100)` | |
| `admin_notes` | `LONGTEXT` | Nullable |

### `{prefix}must_availability`

Date-range availability rules applied to rooms (listing model).

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `room_id` | `BIGINT UNSIGNED` | References `must_rooms.id` |
| `name` | `VARCHAR(191)` | Rule label |
| `availability_date` | `DATE` | Start date of rule; nullable |
| `end_date` | `DATE` | End date of rule; nullable |
| `is_active` | `TINYINT(1)` | Default `1` |
| `is_available` | `TINYINT(1)` | `1` = available, `0` = blocked; default `1` |
| `reason` | `VARCHAR(191)` | Human-readable reason |
| `rule_type` | `VARCHAR(50)` | e.g. `'min_stay'`, `'max_stay'`, `'closed_arrival'`, `'closed_departure'`, `'maintenance'` |
| `rule_value` | `VARCHAR(191)` | Interpreted per `rule_type` |
| `updated_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

**Key indexes:** `room_rule (room_id, rule_type)`, `room_day (room_id, availability_date)`, `room_status_range`.

---

## Housekeeping Domain

### `{prefix}must_room_housekeeping_statuses`

Current housekeeping status per physical inventory room. One row per `mhb_rooms` unit (UNIQUE on `inventory_room_id`).

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `inventory_room_id` | `BIGINT UNSIGNED` | References `mhb_rooms.id`; UNIQUE |
| `status` | `VARCHAR(30)` | `'dirty'`, `'clean'`, `'inspected'`, `'out_of_order'`; default `'dirty'` |
| `assigned_to_user_id` | `BIGINT UNSIGNED` | WP user assigned to clean; `0` = unassigned |
| `assigned_by_user_id` | `BIGINT UNSIGNED` | WP user who assigned; `0` = unassigned |
| `assigned_at` | `DATETIME` | Nullable |
| `updated_by` | `BIGINT UNSIGNED` | WP user who last updated |
| `updated_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

### `{prefix}must_room_housekeeping_issues`

Maintenance/issue reports filed against a physical room.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `inventory_room_id` | `BIGINT UNSIGNED` | References `mhb_rooms.id` |
| `issue_title` | `VARCHAR(191)` | |
| `issue_details` | `LONGTEXT` | Nullable |
| `status` | `VARCHAR(30)` | `'open'`, `'resolved'`, etc.; default `'open'` |
| `created_by` | `BIGINT UNSIGNED` | WP user ID |
| `updated_by` | `BIGINT UNSIGNED` | WP user ID |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |
| `updated_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

### `{prefix}must_housekeeping_handoffs`

Snapshot records of shift handoff state (dirty/clean/inspected room counts).

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `shift_label` | `VARCHAR(191)` | e.g. morning, evening |
| `notes` | `LONGTEXT` | Nullable |
| `dirty_count` | `INT(11)` | Default `0` |
| `clean_count` | `INT(11)` | Default `0` |
| `inspected_count` | `INT(11)` | Default `0` |
| `out_of_order_count` | `INT(11)` | Default `0` |
| `assigned_count` | `INT(11)` | Default `0` |
| `open_issue_count` | `INT(11)` | Default `0` |
| `created_by` | `BIGINT UNSIGNED` | WP user ID |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

---

## Pricing Domain

> See Dual Pricing Model Warning above.

### `{prefix}must_pricing`

**Legacy pricing model.** Date-range price override rules per room (listing model).

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `room_id` | `BIGINT UNSIGNED` | References `must_rooms.id` |
| `name` | `VARCHAR(191)` | Rule label |
| `start_date` | `DATE` | |
| `end_date` | `DATE` | |
| `price_override` | `DECIMAL(12,2)` | Nightly rate for this period |
| `weekend_price` | `DECIMAL(12,2)` | Weekend rate override; `0` = not set |
| `minimum_nights` | `SMALLINT UNSIGNED` | Default `1` |
| `priority` | `INT(11)` | Higher priority wins; default `10` |
| `is_active` | `TINYINT(1)` | Default `1` |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

**Key index:** `room_status_dates (room_id, is_active, start_date, end_date, priority)`.

### `{prefix}mhb_rate_plans`

**New pricing model.** Named rate plans.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `name` | `VARCHAR(191)` | |
| `description` | `LONGTEXT` | Nullable |
| `cancellation_policy_id` | `BIGINT UNSIGNED` | References `mhb_cancellation_policies.id`; `0` = none |
| `is_active` | `TINYINT(1)` | Default `1` |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

### `{prefix}mhb_room_type_rate_plans`

Junction: which rate plans apply to which room types, and at what base price.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `room_type_id` | `BIGINT UNSIGNED` | References `mhb_room_types.id` |
| `rate_plan_id` | `BIGINT UNSIGNED` | References `mhb_rate_plans.id` |
| `base_price` | `DECIMAL(12,2)` | Base nightly price for this combination |
| `max_occupancy` | `SMALLINT UNSIGNED` | Default `1` |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

**Constraint:** UNIQUE on `(room_type_id, rate_plan_id)`.

### `{prefix}mhb_rate_plan_prices`

Per-day price overrides for a rate plan.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `rate_plan_id` | `BIGINT UNSIGNED` | References `mhb_rate_plans.id` |
| `date` | `DATE` | |
| `price` | `DECIMAL(12,2)` | Override price for this date |

**Constraint:** UNIQUE on `(rate_plan_id, date)`.

### `{prefix}mhb_seasons`

Named seasons used to apply pricing modifiers.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `name` | `VARCHAR(191)` | |
| `start_date` | `DATE` | |
| `end_date` | `DATE` | |
| `priority` | `INT(11)` | Default `0`; higher wins if overlapping |

### `{prefix}mhb_seasonal_prices`

Seasonal price modifiers applied to a rate plan within a season.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `season_id` | `BIGINT UNSIGNED` | References `mhb_seasons.id` |
| `rate_plan_id` | `BIGINT UNSIGNED` | References `mhb_rate_plans.id` |
| `modifier_type` | `VARCHAR(20)` | `'fixed'` or `'percentage'` |
| `modifier_value` | `DECIMAL(12,2)` | Amount or percentage to apply |

**Constraint:** UNIQUE on `(season_id, rate_plan_id)`.

### `{prefix}must_taxes`

Tax rules applied to bookings.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `name` | `VARCHAR(191)` | |
| `rule_type` | `VARCHAR(20)` | `'percentage'` or `'fixed'`; default `'percentage'` |
| `rule_value` | `DECIMAL(12,2)` | Tax rate or fixed amount |
| `apply_mode` | `VARCHAR(20)` | `'stay'` (per stay) or other values; default `'stay'` |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

---

## Payments Domain

### `{prefix}must_payments`

Individual payment transactions linked to a reservation.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `reservation_id` | `BIGINT UNSIGNED` | References `must_reservations.id` |
| `amount` | `DECIMAL(12,2)` | |
| `currency` | `VARCHAR(10)` | ISO currency code; default `'USD'` |
| `method` | `VARCHAR(50)` | `'stripe'`, `'pay_at_hotel'`, etc. |
| `status` | `VARCHAR(50)` | `'pending'`, `'paid'`, `'refunded'`, etc. |
| `transaction_id` | `VARCHAR(191)` | External gateway transaction/session ID |
| `paid_at` | `DATETIME` | Nullable |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

### `{prefix}mhb_inventory_locks`

Short-lived (10-minute TTL) locks placed on a room for a stay period during the checkout flow.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `room_id` | `BIGINT UNSIGNED` | References `must_rooms.id` (listing model) |
| `checkin` | `DATE` | |
| `checkout` | `DATE` | |
| `session_id` | `VARCHAR(191)` | Cookie or WP session token |
| `expires_at` | `DATETIME` | Lock expiry in UTC |

**Constraint:** UNIQUE on `(room_id, checkin, checkout, session_id)`.

---

## Guests Domain

### `{prefix}must_guests`

Guest profile records.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `first_name` | `VARCHAR(100)` | |
| `last_name` | `VARCHAR(100)` | |
| `email` | `VARCHAR(190)` | Indexed; not enforced UNIQUE at DB level |
| `phone` | `VARCHAR(50)` | |
| `country` | `VARCHAR(100)` | |
| `admin_notes` | `LONGTEXT` | Nullable |
| `vip_flag` | `TINYINT(1)` | Default `0` |
| `problem_flag` | `TINYINT(1)` | Default `0` |

---

## Discounts Domain

### `{prefix}must_coupons`

Discount coupon definitions.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `code` | `VARCHAR(100)` | UNIQUE |
| `name` | `VARCHAR(191)` | Display label |
| `is_active` | `TINYINT(1)` | Default `1` |
| `discount_type` | `VARCHAR(20)` | `'percentage'` or `'fixed'` |
| `discount_value` | `DECIMAL(12,2)` | |
| `minimum_booking_amount` | `DECIMAL(12,2)` | Minimum booking total for eligibility |
| `valid_from` | `DATE` | |
| `valid_until` | `DATE` | |
| `usage_limit` | `INT UNSIGNED` | `0` = unlimited |
| `usage_count` | `INT UNSIGNED` | Incremented on use |
| `updated_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

---

## Cancellation Policy Domain

### `{prefix}mhb_cancellation_policies`

Named cancellation policies that can be attached to rate plans.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `name` | `VARCHAR(191)` | |
| `hours_before_checkin` | `INT(11)` | Hours before check-in when the penalty activates |
| `penalty_percent` | `DECIMAL(5,2)` | Penalty as a percentage of total; `0.00` = free cancellation |
| `description` | `LONGTEXT` | Nullable |

Referenced from `mhb_rate_plans.cancellation_policy_id`.

---

## Audit / Logging Domain

### `{prefix}must_activity_log`

Append-only audit log. Written by `ActivityLogger` hooks.

| Column | Type | Notes |
|---|---|---|
| `id` | `BIGINT UNSIGNED` | Primary key |
| `event_type` | `VARCHAR(60)` | e.g. `'reservation_created'`, `'payment_recorded'` |
| `severity` | `VARCHAR(20)` | `'info'`, `'warning'`, `'error'` |
| `entity_type` | `VARCHAR(60)` | e.g. `'reservation'`, `'payment'` |
| `entity_id` | `BIGINT UNSIGNED` | ID of the referenced entity |
| `reference` | `VARCHAR(191)` | Human-readable reference (booking ID, etc.) |
| `message` | `VARCHAR(255)` | Short human-readable message |
| `context_json` | `LONGTEXT` | JSON blob of additional context; nullable |
| `actor_user_id` | `BIGINT UNSIGNED` | WP user who triggered the event; `0` = system/guest |
| `actor_role` | `VARCHAR(60)` | Role slug at time of action |
| `actor_ip` | `VARCHAR(45)` | IP address (supports IPv6) |
| `created_at` | `DATETIME` | Default `CURRENT_TIMESTAMP` |

---

## WordPress Options Used by the Plugin

Beyond database tables, the plugin stores state in the following WP options:

| Option key | Purpose |
|---|---|
| `must_hotel_booking_db_version` | Installed schema version; compared to `MUST_HOTEL_BOOKING_VERSION` to trigger upgrades |
| `must_hotel_booking_settings` | All plugin settings (type: serialised array). See `MustBookingConfig::OPTION_NAME`. |
| `must_hotel_booking_managed_pages_suspended` | Boolean flag to disable auto-management of managed pages |
| `must_hotel_booking_default_inventory_unit_sync_marker` | Marker preventing repeated inventory backfills |
| `must_hotel_booking_category_cleanup_summary` | Summary of the last `AccommodationCategoryUpgradeService` run |
| `must_hotel_booking_portal_rewrite_signature` | Version integer (current: `2`) used to detect when portal rewrite rules need flushing |

---

## Entity Relationship Summary

```
must_room_categories ──< must_rooms (via category slug)
must_rooms ──────────< must_reservations (room_id)
must_rooms ──────────< must_pricing (room_id)
must_rooms ──────────< must_availability (room_id)
must_rooms ──────────< mhb_inventory_locks (room_id)

mhb_room_types (id mirrors must_rooms.id)
mhb_room_types ──────< mhb_rooms (room_type_id)
mhb_room_types ──────< mhb_room_type_rate_plans (room_type_id)
mhb_rooms ───────────< must_room_housekeeping_statuses (inventory_room_id, 1:1)
mhb_rooms ───────────< must_room_housekeeping_issues (inventory_room_id)

mhb_rate_plans ──────< mhb_room_type_rate_plans (rate_plan_id)
mhb_rate_plans ──────< mhb_rate_plan_prices (rate_plan_id)
mhb_rate_plans ──────< mhb_seasonal_prices (rate_plan_id)
mhb_rate_plans.cancellation_policy_id > mhb_cancellation_policies.id
mhb_seasons ─────────< mhb_seasonal_prices (season_id)

must_guests ─────────< must_reservations (guest_id)
must_reservations ───< must_payments (reservation_id)
must_coupons ────────< must_reservations (coupon_id)
mhb_rate_plans ──────< must_reservations (rate_plan_id)
mhb_rooms ───────────< must_reservations (assigned_room_id)
```
