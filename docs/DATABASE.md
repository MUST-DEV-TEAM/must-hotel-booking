# Database

## Inspection Provenance
Verified from targeted current-code inspection on 2026-06-11. Use this document for navigation, then verify installer SQL and repositories before changing schema or queries.

## Installer/Upgrade
- Installer/upgrade path: `src/Database/install-tables.php`.
- Uses WordPress `dbDelta()`.
- DB version option: `must_hotel_booking_db_version`.
- Version value is updated to `MUST_HOTEL_BOOKING_VERSION`.
- Current local plugin version inspected in code: `0.4.72`; task prompt referenced `0.4.71`.

## Key Tables
| Table | Purpose |
| --- | --- |
| `must_room_categories` | Sellable accommodation categories. |
| `must_rooms` | Authoritative sellable accommodation/listing records. |
| `must_room_meta` | Room/listing metadata. |
| `mhb_room_types` | Internal mirrored room types derived from sellable rooms. |
| `mhb_rooms` | Physical/inventory room units. |
| `must_room_housekeeping_statuses` | Current housekeeping status by inventory room. |
| `must_room_housekeeping_issues` | Maintenance/housekeeping issues. |
| `must_housekeeping_handoffs` | Housekeeping shift handoff records. |
| `must_guests` | Guest contact/profile records. |
| `must_reservations` | Reservation/booking rows. |
| `must_pricing` | Legacy/room pricing rules. |
| `must_availability` | Availability rules and manual availability data. |
| `mhb_inventory_locks` | Temporary inventory/session locks. |
| `must_payments` | Payment ledger rows. |
| `must_refunds` | Refund records and provider/Clock sync state. |
| `must_taxes` | Tax/fee rules. |
| `must_coupons` | Coupon rules and usage counts. |
| `must_activity_log` | Audit/activity events. |
| `mhb_provider_mappings` | Local-to-provider entity mappings. |
| `mhb_provider_request_logs` | Provider request/response logs. |
| `mhb_provider_sync_jobs` | Provider sync job queue. |
| `mhb_cancellation_policies` | Cancellation policy records. |
| `mhb_rate_plans` | Rate plan definitions. |
| `mhb_room_type_rate_plans` | Room type/rate plan assignments. |
| `mhb_rate_plan_prices` | Per-day rate plan prices. |
| `mhb_seasons` | Season windows. |
| `mhb_seasonal_prices` | Seasonal rate plan modifiers. |

## Important Columns
| Table | Columns |
| --- | --- |
| `must_reservations` | `booking_id`, `room_id`, `room_type_id`, `assigned_room_id`, `rate_plan_id`, `guest_id`, `checkin`, `checkout`, `guests`, `status`, `booking_source`, `total_price`, `coupon_id`, `coupon_code`, `payment_status`, cancellation fields, provider fields, `created_at`. |
| `mhb_inventory_locks` | `room_id`, `checkin`, `checkout`, `session_id`, `expires_at`; unique key on room/stay/session. |
| `must_payments` | `reservation_id`, `amount`, `currency`, `method`, `status`, `transaction_id`, `paid_at`, `created_at`. |
| `must_refunds` | `reservation_id`, `booking_id`, `payment_id`, provider/Clock IDs, gateway/provider refs, Stripe IDs, `amount`, `currency`, `reason`, `refund_type`, `status`, `clock_sync_status`, idempotency keys, failure/manual fields, timestamps. |
| `mhb_provider_mappings` | `provider`, `entity_type`, `local_table`, `local_id`, `external_id`, `external_code`, `status`, `metadata`. |
| `mhb_provider_sync_jobs` | `provider`, `operation`, `target_type`, `target_local_id`, `target_external_id`, `status`, attempts, timing, payload. |

## Relationships
- `must_reservations.guest_id` points to `must_guests.id`.
- `must_reservations.room_id` points to sellable `must_rooms.id`.
- `must_reservations.assigned_room_id` points to physical `mhb_rooms.id` when assigned.
- `must_payments.reservation_id` points to `must_reservations.id`.
- `must_refunds.reservation_id` and `payment_id` link refunds to reservations/payments.
- `mhb_provider_mappings` maps local entities to external provider IDs, especially Clock.
- Explicit foreign keys are not visible in installer SQL; relationships are application-enforced.

## Migration/Idempotency Behavior
- `install_tables()` builds all CREATE TABLE statements and runs `dbDelta()`.
- `AccommodationCategoryUpgradeService` runs after table installation.
- `seed_inventory_model_from_legacy_rooms()` is documented as deliberate maintenance/save action only, not silent page-load or installer side effect.

## Schema-Change Rules
- Keep changes idempotent.
- Preserve existing data.
- Do not rename/drop tables or columns without an explicit migration plan.
- Update repositories and installer/upgrade logic together.
- Do not delete customer, guest, booking, payment, room, availability, refund, provider, or integration data unless explicitly requested.

## Unknowns
- Full historical migration sequence before the current installer. Unknown from current code inspection.
- Production DB state and any legacy data shape. Unknown from current code inspection.

## Targeted Search Recipes
```bash
rg -n "CREATE TABLE|dbDelta|must_hotel_booking_db_version" src/Database/install-tables.php
rg -n "table\\('reservations'\\)|must_reservations|payment_status" src/Database src/Engine
rg -n "mhb_inventory_locks|mhb_rooms|assigned_room_id" src/Database src/Engine
rg -n "must_refunds|clock_sync_status|idempotency_key" src/Database src/Engine
```
