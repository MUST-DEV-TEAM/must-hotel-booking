# Hooks and Endpoints Reference

Developer reference for all action hooks, filter hooks, AJAX endpoints, and REST endpoints in the MUST Hotel Booking plugin.

---

## Action Hooks

All hooks use the `must_hotel_booking/` namespace prefix.

| Hook name | Where fired | Parameters | When it fires |
|---|---|---|---|
| `must_hotel_booking/init` | `src/Core/Plugin.php` — `Plugin::initPlugin()` | none | After all engine `registerHooks()` calls complete on each page load; analogous to `plugins_loaded` for plugin-internal bootstrapping |
| `must_hotel_booking/reservation_created` | `src/Engine/ReservationEngine.php` — `createReservation()` and `createReservationWithoutLock()` | `int $reservationId` | Immediately after a new reservation row is written to the database, before the HTTP response is sent |
| `must_hotel_booking/reservation_confirmed` | `src/Engine/BookingStatusEngine.php` — `updateReservationStatuses()` | `int $reservationId` | When a reservation's status transitions from any non-confirmed status to `confirmed` or `completed`; fired once per reservation in a batch update |
| `must_hotel_booking/reservation_cancelled` | `src/Engine/BookingStatusEngine.php` — `updateReservationStatuses()` | `int $reservationId` | When a reservation's status transitions from any non-`cancelled` status to `cancelled`; fired once per reservation in a batch update |
| `must_hotel_booking/payment_recorded` | `src/Engine/BookingStatusEngine.php` — `createPaymentRows()`; `src/Admin/PaymentAdminActions.php` — `dispatchPaymentRecordedEvent()` | `array $data` (see below) | After a payment ledger row is created or updated |
| `must_hotel_booking/email_dispatch_result` | `src/Engine/EmailEngine.php` — `sendTestEmail()` and the internal send path | `array $data` (see below) | After every email dispatch attempt, whether successful or not |

### `must_hotel_booking/payment_recorded` — payload shape

```php
[
    'payment_id'     => int,    // ID of the payment ledger row
    'reservation_id' => int,
    'method'         => string, // e.g. 'stripe', 'pay_at_hotel'
    'status'         => string, // e.g. 'paid', 'pending', 'failed', 'refunded'
    'amount'         => float,
    'transaction_id' => string, // Stripe payment_intent or session ID, or ''
    'paid_at'        => string, // MySQL datetime or ''
    'created_at'     => string, // MySQL datetime
    'is_update'      => bool,   // true when updating an existing row, false when inserting
]
```

### `must_hotel_booking/email_dispatch_result` — payload shape

```php
[
    'success'          => bool,
    'template_key'     => string, // e.g. 'guest_confirmation'
    'recipient_email'  => string,
    'reservation_id'   => int,
    'booking_id'       => string,
    'email_mode'       => string, // 'test' or the live send mode label
]
```

### Example: acting on reservation confirmation

```php
add_action( 'must_hotel_booking/reservation_confirmed', function ( int $reservation_id ): void {
    // Post-confirmation logic, e.g. notify a third-party PMS.
    my_pms_notify_confirmed( $reservation_id );
} );
```

### Example: acting on payment recorded

```php
add_action( 'must_hotel_booking/payment_recorded', function ( array $data ): void {
    if ( $data['status'] === 'paid' && $data['method'] === 'stripe' ) {
        my_accounting_push( $data['reservation_id'], $data['amount'], $data['transaction_id'] );
    }
} );
```

---

## Filter Hooks

No `apply_filters()` calls were found in the `src/` directory at the time of this writing.

**Unverified / needs confirmation:** third-party template or legacy code outside `src/` may use filters. Run a project-wide search for `apply_filters` if this is needed.

---

## AJAX Endpoints

All endpoints use the standard WordPress `admin-ajax.php` mechanism. Requests are `POST` to `wp-admin/admin-ajax.php` with the `action` parameter set to the action name.

### `must_check_availability`

| Property | Value |
|---|---|
| Action name | `must_check_availability` |
| Handler | `AvailabilityAjaxController::ajax_must_check_availability()` — `src/Engine/AvailabilityAjaxController.php` |
| Logged-in access | Yes (`wp_ajax_must_check_availability`) |
| Public access | Yes (`wp_ajax_nopriv_must_check_availability`) |
| Nonce | `must_availability_check` — verified in the handler; key `availabilityNonce` in `mustHotelBookingBookingPage` JS object |

**Request parameters (POST)**

| Parameter | Type | Description |
|---|---|---|
| `action` | string | `must_check_availability` |
| `nonce` | string | `wp_create_nonce('must_availability_check')` |
| `checkin` | string | ISO date `Y-m-d` |
| `checkout` | string | ISO date `Y-m-d` |
| `guests` | int | Number of guests |
| `room_count` | int | Number of rooms requested |
| `accommodation_type` | string | Room category slug |

**Response** — `wp_send_json_success()` with:

```json
{
  "checkin": "2026-04-10",
  "checkout": "2026-04-12",
  "guests": 2,
  "room_count": 1,
  "accommodation_type": "double",
  "message": "",
  "rooms": [ ... ]
}
```

---

### `must_get_disabled_dates`

| Property | Value |
|---|---|
| Action name | `must_get_disabled_dates` |
| Handler | `AvailabilityAjaxController::ajax_must_get_disabled_dates()` — `src/Engine/AvailabilityAjaxController.php` |
| Logged-in access | Yes |
| Public access | Yes |
| Nonce | Passed as `availabilityNonce` in `mustHotelBookingBookingPage` JS object |

Returns a list of dates that should be disabled in the date picker, based on availability and booking-window settings.

---

### `must_booking_accommodation_room_action`

| Property | Value |
|---|---|
| Action name | `must_booking_accommodation_room_action` |
| Handler | `handle_accommodation_room_selection_ajax()` in `src/Frontend/accommodation-page.php` |
| Logged-in access | Yes |
| Public access | Yes |
| Nonce | Not used at the AJAX handler level (session-based room selection) |
| JS object | `mustBookingAccommodationConfig` — key `ajaxAction` |

**Request parameters (POST)**

| Parameter | Type | Description |
|---|---|---|
| `action` | string | `must_booking_accommodation_room_action` |
| `must_accommodation_action` | string | `select_room` or `remove_selected_room` |
| `room_id` | int | Room post ID |
| `rate_plan_id` | int | Rate plan ID (0 = default) |
| `checkin` | string | ISO date |
| `checkout` | string | ISO date |
| `guests` | int | Guest count |

Delegates to `ReservationEngine::handleAccommodationRoomSelectionRequest()`.

---

### `must_admin_quick_booking_preview` (admin-only)

| Property | Value |
|---|---|
| Action name | `must_admin_quick_booking_preview` |
| Handler | `ajax_must_admin_quick_booking_preview()` in `src/Admin/quick-booking.php` |
| Logged-in access | Yes (admin context only) |
| Public access | No |
| Nonce | `must_admin_quick_booking_preview` — key `previewNonce` in `mustHotelBookingAdminQuickBooking` JS object |

Returns a pricing and availability preview for use in the admin quick-booking UI.

---

## REST Endpoints

The plugin registers REST routes under the `must-hotel-booking/v1` namespace.

### `POST /wp-json/must-hotel-booking/v1/stripe/webhook`

| Property | Value |
|---|---|
| Route | `/wp-json/must-hotel-booking/v1/stripe/webhook` |
| Method | `POST` |
| Handler class/method | `PaymentEngine::handleStripeWebhookRequest()` — `src/Engine/PaymentEngine.php` |
| Authentication | None — `permission_callback` is `__return_true`; authentication is performed via Stripe webhook signature verification |
| Registered in | `PaymentEngine::registerPaymentRestRoutes()` → hooked via `add_action('rest_api_init', ...)` |

#### Signature verification

The handler calls `PaymentEngine::verifyStripeWebhookSignature()` before processing any event. This method:

1. Parses the `Stripe-Signature` header (format: `t=<timestamp>,v1=<hmac>`).
2. Rejects if the timestamp is absent, no `v1` signatures are present, or the timestamp is older than 300 seconds (5-minute tolerance).
3. Recomputes `HMAC-SHA256` of `"<timestamp>.<raw_body>"` using the configured webhook secret.
4. Returns `false` (responds `400`) if no signature matches.

The active webhook secret is resolved by `PaymentEngine::getStripeWebhookSecret()`, which reads the environment-specific key (`stripe_local_webhook_secret`, `stripe_staging_webhook_secret`, or `stripe_production_webhook_secret`) from the plugin settings.

#### Handled Stripe events

| Event type | Action taken |
|---|---|
| `checkout.session.completed` | Calls `BookingStatusEngine::updateReservationStatuses($ids, 'confirmed', 'paid')` and `BookingStatusEngine::createPaymentRows($ids, 'stripe', 'paid', $paymentIntent)`. The `payment_intent` ID from the session object is stored as the transaction ID; falls back to the session ID if `payment_intent` is absent. |
| `checkout.session.expired` | Calls `BookingStatusEngine::failPendingStripeReservations($ids, 'expired')`, which sets status to `expired` and payment status to `cancelled` and records a `failed` payment row. |

Reservation IDs are extracted from `event.data.object.metadata.reservation_ids` (a comma-separated string written during session creation). If this metadata field is absent or empty, the handler responds `200` without performing any action.

All other Stripe event types are silently acknowledged with a `200` response.

#### Request

Raw JSON body from Stripe. No standard WordPress request parameters.

#### Response

| Scenario | HTTP status | Body |
|---|---|---|
| Invalid signature | 400 | `{"success":false,"message":"Invalid Stripe signature."}` |
| Invalid JSON body | 400 | `{"success":false,"message":"Invalid Stripe payload."}` |
| Success (any handled or unhandled event) | 200 | `{"success":true}` |

#### Webhook URL helper

```php
// Retrieve the webhook URL to paste into the Stripe dashboard.
$url = PaymentEngine::getStripeWebhookUrl();
// Equivalent to: rest_url('must-hotel-booking/v1/stripe/webhook')
```
