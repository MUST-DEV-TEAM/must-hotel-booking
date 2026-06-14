# Clock Provider Setup

**Plugin:** MUST Hotel Booking  
**Introduced:** 0.4.0

---

## Overview

The plugin supports two booking modes selected at runtime by `ProviderManager`:

| Mode | Behavior |
|---|---|
| **Local** | Default. All availability, pricing, reservations, and payments are managed entirely inside WordPress. No external PMS required. |
| **Clock** | Delegates availability checks, pricing quotes, and reservation creation to a Clock PMS REST API. Reservations are stored locally as mirror records. Payment reconciliation and operational mutations (cancel, room move, stay edit, check-in/out) are sent to Clock. |

Local mode is active when Clock is disabled or misconfigured. Switching modes does not affect existing Local reservations.

---

## Clock Configuration

All settings are stored in **MUST Hotel Booking > Settings > Provider**.

### Required for any Clock operation

| Setting | Key | Notes |
|---|---|---|
| Enable Clock | `clock_enabled` | Must be checked. |
| API Base URL | `clock_api_base_url` | Root URL of the Clock REST API, no trailing slash. |
| API User | `clock_api_user` | HTTP Basic Auth username. |
| API Key | `clock_api_key` | HTTP Basic Auth password / token. |

### Required for public booking (availability + quote + reservation create)

| Setting | Key | Example value |
|---|---|---|
| Property ID | `clock_property_id` | `hotel-123` |
| Availability path | `clock_availability_path` | `/v1/availability` |
| Quote path | `clock_quote_path` | `/v1/quote` |
| Reservation create path | `clock_reservation_create_path` | `/v1/reservations` |

### Required for operational mutations (cancel, room move, stay edit, guest edit, check-in/out)

| Setting | Key | Supported tokens |
|---|---|---|
| Status update path | `clock_reservation_status_update_path` | `{reservation_id}`, `{booking_id}` |
| Cancel path | `clock_reservation_cancel_path` | `{reservation_id}`, `{booking_id}` |
| Room update path | `clock_reservation_room_update_path` | `{reservation_id}`, `{booking_id}`, `{provider_room_id}` |
| Stay update path | `clock_reservation_stay_update_path` | `{reservation_id}`, `{booking_id}`, `{checkin}`, `{checkout}` |
| Guest update path | `clock_reservation_guest_update_path` | `{reservation_id}`, `{booking_id}` |

Path tokens are replaced at call time with URL-encoded values from the mirror reservation. If a required token cannot be resolved (empty provider ID), the request is rejected with a clear error rather than sending a malformed URL.

### Optional

| Setting | Key | Notes |
|---|---|---|
| Reservation fetch path | `clock_reservation_fetch_path` | Used by inbound sync refresh jobs. |
| Room types path | `clock_room_types_path` | Used by catalog sync to fetch remote accommodations. |
| Rooms path | `clock_rooms_path` | Used by catalog sync to fetch remote physical rooms. |
| Rate plans path | `clock_rate_plans_path` | Used by catalog sync to fetch remote rate plans. |
| Clock PUSH Basic username | `clock_webhook_basic_username` | Optional HTTP Basic username when the Clock PUSH endpoint URL embeds credentials. |
| Clock PUSH Basic password | `clock_webhook_basic_password` | Optional HTTP Basic password when the Clock PUSH endpoint URL embeds credentials. |
| Legacy webhook token / HMAC secret | `clock_webhook_secret` | Backward-compatible auth for custom Bearer/HMAC webhook senders. Official Clock PUSH uses Amazon SNS signatures, not this value. |
| Timeout | `clock_timeout_seconds` | Default: 15. Max: 60. |

---

## Required Mappings

Before Clock public booking can work, local entities must be mapped to their Clock external identifiers. Manage mappings in **Settings > Provider > Mappings**.

| Local entity | Mapping type | Required for |
|---|---|---|
| Accommodation (`must_rooms`) | `accommodation` | Availability, quote, reservation create |
| Rate plan (`mhb_rate_plans`) | `rate_plan` | Quote and reservation create when a rate plan is selected |
| Physical room (`mhb_rooms`) | `physical_room` | Room assignment mutations |

Each mapping stores: `external_id` (Clock's identifier), `external_code` (optional short code), and `display_name` (optional label). Use the inline add form in the Mappings panel to save them, or use `ProviderMappingRepository::save()` programmatically.

Missing accommodation mappings block Clock public booking for those rooms. The readiness checklist in the Diagnostics panel will show a warning.

---

## Webhook / Inbound Sync

Clock can push reservation status updates to the plugin via PUSH webhooks.

**Webhook URL:** `{site}/wp-json/must-hotel-booking/v1/clock/webhook`

The endpoint is registered by `ClockInboundSyncController`. It:
1. Parses official Amazon SNS envelopes.
2. Verifies SNS signatures after validating that `SigningCertURL` is a safe Amazon SNS certificate URL.
3. Confirms SNS `SubscriptionConfirmation` messages only through safe Amazon SNS `SubscribeURL` hosts.
4. Supports optional HTTP Basic authentication if Clock embeds credentials in the endpoint URL.
5. Extracts the Clock event type from SNS `Subject` and event data from the JSON string in `Message`.
6. Preserves legacy Bearer token and HMAC validation through `clock_webhook_secret` for custom senders.
7. Finds matching local mirror reservations, maps Clock status strings, and updates provider metadata.

If no webhook is configured, inbound sync can be triggered manually or via `ProviderSyncJobRunner` refresh jobs (operation `reservation_refresh`).

---

## Sync Job Runner

The plugin schedules a WP-Cron job (`must_hotel_booking_provider_sync`) to process the `mhb_provider_sync_jobs` queue. Jobs are retried up to their `max_attempts` limit with exponential-like delay. Failed/exhausted jobs are visible in **Settings > Provider > Sync Health**.

For Clock to work reliably in production, WP-Cron must be running. Use a real cron or a cron plugin rather than relying on traffic-triggered pseudo-cron on low-traffic sites.

---

## Database Tables Added in 0.4.0

All tables are created automatically on upgrade via `dbDelta`. No manual SQL is required.

| Table | Purpose |
|---|---|
| `mhb_provider_mappings` | Local entity → Clock external ID/code mappings per entity type |
| `mhb_provider_request_logs` | Outbound Clock API call log (operation, status, duration, error) |
| `mhb_provider_sync_jobs` | Retryable outbound Clock operation queue |

---

## Known Limitations

- **Mirror reservations are not locally authoritative.** Guest, stay, and status data on a Clock mirror reservation should be treated as a local copy. The source of truth is Clock. Inbound sync keeps the mirror up to date but may lag if webhooks are not configured.
- **Availability is Clock-delegated.** The local availability engine is bypassed for Clock-backed rooms. Overbooking prevention relies on Clock returning accurate availability.
- **Pricing is Clock-delegated.** Local seasonal rules and coupons are not applied to Clock quotes. Discounts shown to guests come from Clock's quote response.
- **No partial-Clock setup.** The plugin is either fully in Local mode or fully in Clock mode. You cannot route some rooms through Clock and others through Local in the same install.
- **Rate plan mappings are optional but required for rate-plan-specific quotes.** If a rate plan has no mapping, it is excluded from Clock quote results.
- **Webhook secret is strongly recommended in production.** Without it, any POST to the webhook URL can update mirror reservation statuses.
