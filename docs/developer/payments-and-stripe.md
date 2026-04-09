# Payments and Stripe

Developer reference for the payment architecture, Stripe integration, payment status lifecycle, and deposit logic.

---

## Payment architecture overview

The payment layer is built around three collaborating components:

1. **`PaymentInterface`** — a contract that every gateway must implement.
2. **`PaymentFactory`** — creates the correct gateway instance from a method string.
3. **`PaymentEngine`** — a static facade that orchestrates all payment-related operations: gateway selection, Stripe API calls, webhook handling, and cleanup.

`BookingStatusEngine` is responsible for writing to the payment ledger (the `payment_rows` / payments table) and for firing status-transition hooks. It is called by the gateway implementations and by the webhook handler.

`PaymentStatusService` derives a canonical `derived_status` from the raw database state for display and transition-validation purposes.

---

## Payment methods

| Method slug | Gateway class | Internal gateway key | Description |
|---|---|---|---|
| `stripe` | `StripePayment` | `stripe` | Card payment via Stripe Checkout hosted page; redirects the guest off-site |
| `pay_at_hotel` | `CashPayment` | `cash` | Guest pays in cash or at the property; no redirection; reservation confirmed immediately |
| `cash` | `CashPayment` | `cash` | Alias for `pay_at_hotel`; normalised to the same gateway |

`PaymentFactory::normalizeMethod()` maps `pay_at_hotel` and `cash` to the string `'cash'`, and `stripe` to `'stripe'`. Any other value returns `''`.

The set of enabled methods is stored in the `payment_methods` key of the `payments_summary` settings group and managed by `PaymentMethodRegistry`. At checkout, `PaymentEngine::getCheckoutPaymentMethods()` filters that list by gateway availability (i.e. Stripe is excluded if keys are not configured) and returns display labels. If no method passes validation, `pay_at_hotel` is returned as a hard fallback.

---

## PaymentInterface

File: `src/Engine/Payment/PaymentInterface.php`

```php
namespace MustHotelBooking\Engine\Payment;

interface PaymentInterface
{
    /**
     * Initiate a payment. Returns an array describing the result.
     *
     * @param array<string, mixed> $reservation  Must contain 'reservation_ids' (array of ints).
     * @param float                $amount        Total to charge.
     * @param array<string, mixed> $context       Gateway-specific context (currency, guest_form, coupon_ids, …).
     * @return array<string, mixed>
     */
    public function processPayment( array $reservation, float $amount, array $context = [] ): array;

    /**
     * Issue a refund.
     *
     * @param array<string, mixed> $reservation
     * @param float                $amount       Amount to refund; 0 means full refund (gateway-dependent).
     * @param array<string, mixed> $context      Must contain 'transaction_id' for Stripe.
     * @return array<string, mixed>
     */
    public function refundPayment( array $reservation, float $amount, array $context = [] ): array;

    /**
     * Validate that the gateway is properly configured and the supplied data is valid.
     *
     * @param array<string, mixed> $paymentData  Optional data to validate (reservation_ids, amount, …).
     * @return array<string, mixed>              Must contain 'success' => bool.
     */
    public function validatePayment( array $paymentData = [] ): array;
}
```

### processPayment — return shape

For Stripe (redirect-based):

```php
[
    'success'          => true,
    'method'           => 'stripe',
    'reservation_ids'  => [1, 2],
    'status'           => 'pending_payment',
    'payment_status'   => 'pending',
    'requires_redirect' => true,
    'redirect_url'     => 'https://checkout.stripe.com/...',
    'checkout_url'     => 'https://checkout.stripe.com/...',
    'transaction_id'   => 'cs_test_...',
    'session_id'       => 'cs_test_...',
    'expires_at'       => '2026-04-06 10:30:00',
]
```

For cash / pay-at-hotel:

```php
[
    'success'          => true,
    'method'           => 'pay_at_hotel',
    'reservation_ids'  => [1],
    'status'           => 'confirmed',
    'payment_status'   => 'unpaid',
    'requires_redirect' => false,
    'redirect_url'     => '',
]
```

On failure, both gateways return `['success' => false, 'method' => '...', 'message' => '...']`.

---

## PaymentFactory

File: `src/Engine/Payment/PaymentFactory.php`

```php
PaymentFactory::create( string $method ): PaymentInterface
```

`$method` is sanitised with `sanitize_key()`. Unrecognised methods fall back to `CashPayment`.

```php
PaymentFactory::normalizeMethod( string $method ): string
// Returns 'stripe', 'cash', or '' (empty string for unrecognised input).
```

### Adding a new payment gateway

1. Create a class in `src/Engine/Payment/` that implements `PaymentInterface`.
2. Add a `case` for the new method slug in `PaymentFactory::create()` and `PaymentFactory::normalizeMethod()`.
3. Register the method in `PaymentMethodRegistry` (add its slug and label to the catalog).
4. Enable it in the admin settings under Payments Summary.

**Unverified / needs confirmation:** `PaymentMethodRegistry` was not fully read. Verify whether the catalog is code-defined or database-driven before adding new methods.

---

## StripePayment gateway

File: `src/Engine/Payment/StripePayment.php`

`StripePayment::processPayment()` does the following in order:

1. Extracts `reservation_ids` from `$reservation`.
2. Resolves currency from `$context['currency']` or falls back to `MustBookingConfig::get_currency()`.
3. Calls `validatePayment()` — verifies keys are configured and data is valid.
4. Calls `PaymentEngine::createStripeCheckoutSession()` with `$reservationIds`, guest form, amount, currency, and coupon IDs from context.
5. Calls `BookingStatusEngine::createPaymentRows()` to record a `pending` payment row with the session ID as the transaction ID.
6. Returns the redirect URL.

`StripePayment::refundPayment()` calls `POST /v1/refunds` on the Stripe API with the `payment_intent` from `$context['transaction_id']`. The amount in minor units is included if `$amount > 0`; omitting it issues a full refund.

---

## CashPayment gateway

File: `src/Engine/Payment/CashPayment.php`

`CashPayment::processPayment()` calls `BookingStatusEngine::createPaymentRows()` with status `'pending'` and returns `status = 'confirmed'`, `payment_status = 'unpaid'`, `requires_redirect = false`.

`CashPayment::refundPayment()` always returns `success = false` with the message "Cash payments must be refunded manually outside the plugin." Refunds are handled out-of-band.

---

## Stripe checkout session creation flow

Entry point: `PaymentEngine::createStripeCheckoutSession()` — `src/Engine/PaymentEngine.php`

1. **Validate** — `$reservationIds` must be non-empty; `$amountMinor` must be > 0.
2. **Resolve hotel name** — `MustBookingConfig::get_hotel_name()`.
3. **Convert amount** — `PaymentEngine::convertAmountToStripeMinorUnits( $totalAmount, $currency )`. Zero-decimal currencies (JPY, KRW, etc.) are rounded to an integer. All others are multiplied by 100.
4. **Build success/cancel URLs** — both point to the booking confirmation page (`ManagedPages::getBookingConfirmationPageUrl()`). The success URL includes `reservation_ids`, `payment_method=stripe`, `stripe_return=success`, and the Stripe `{CHECKOUT_SESSION_ID}` placeholder. The cancel URL includes `payment_method=stripe&stripe_return=cancel`.
5. **Set expiry** — 30 minutes from now (`PaymentEngine::getStripeCheckoutExpiryMinutes()` = 30).
6. **Build payload** — a single line item with `mode=payment`, `payment_method_types[0]=card`. Metadata contains `reservation_ids` (comma-separated), `coupon_ids` (comma-separated), and `source=must-hotel-booking`. The guest email is passed as `customer_email`.
7. **POST to Stripe API** — `POST https://api.stripe.com/v1/checkout/sessions` with `Bearer` authorization using the active secret key.
8. **Return** — session ID, checkout URL, expiry, payment status, and payment intent ID.

All Stripe API calls use `PaymentEngine::performStripeApiRequest()`, which is a thin wrapper around `wp_remote_request()` with a 20-second timeout.

---

## Stripe webhook

### Route

```
POST /wp-json/must-hotel-booking/v1/stripe/webhook
```

Registered in `PaymentEngine::registerPaymentRestRoutes()`, called via `rest_api_init`. `permission_callback` is `__return_true` — authentication is entirely signature-based.

### Signature verification

`PaymentEngine::verifyStripeWebhookSignature( string $payload, string $signatureHeader, string $webhookSecret, int $tolerance = 300 ): bool`

- Parses `Stripe-Signature` header: extracts timestamp (`t=`) and `v1` HMAC signatures.
- Rejects if timestamp is absent, no `v1` signatures exist, or `abs(time() - timestamp) > $tolerance` (default 300 s).
- Computes `hash_hmac('sha256', "$timestamp.$payload", $webhookSecret)`.
- Compares with `hash_equals()` against every `v1` signature in the header.

The webhook secret is environment-specific and is read by `PaymentEngine::getStripeWebhookSecret()` → `getStripeEnvironmentCredentials()`.

### Handled events

| Event | Handler logic |
|---|---|
| `checkout.session.completed` | `BookingStatusEngine::updateReservationStatuses( $ids, 'confirmed', 'paid' )` then `BookingStatusEngine::createPaymentRows( $ids, 'stripe', 'paid', $paymentIntent )`. Transaction ID is `payment_intent` from the session; falls back to `id` (session ID). |
| `checkout.session.expired` | `BookingStatusEngine::failPendingStripeReservations( $ids, 'expired' )` — sets status to `expired`, payment_status to `cancelled`, creates a `failed` payment row. |
| All other events | Acknowledged with `200 {"success":true}` and no action. |

Reservation IDs are read from `event.data.object.metadata.reservation_ids`. If that field is missing or empty, the handler returns `200` immediately.

### Configuring in the Stripe dashboard

Register the following URL as a webhook endpoint in the Stripe dashboard (Developers → Webhooks). Retrieve the URL from PHP with:

```php
$url = PaymentEngine::getStripeWebhookUrl();
```

Subscribe to at minimum:
- `checkout.session.completed`
- `checkout.session.expired`

Copy the Signing Secret from the Stripe dashboard and save it to the appropriate environment key in the plugin settings (e.g. `stripe_production_webhook_secret`).

---

## Payment status lifecycle

### Reservation statuses (set on the reservation row)

| Status | Meaning |
|---|---|
| `pending` | Created but not yet confirmed or paid |
| `pending_payment` | Stripe session created; awaiting guest completion |
| `confirmed` | Reservation accepted; payment may be outstanding |
| `completed` | Stay completed |
| `cancelled` | Cancelled by guest or admin |
| `expired` | Stripe session expired before payment |
| `payment_failed` | Stripe payment failed |
| `blocked` | Room blocked by admin |

`ReservationStatus::blocksInventory()` returns `true` for `pending`, `pending_payment`, `confirmed`, `completed`, `blocked`.

`ReservationStatus::isConfirmed()` returns `true` for `confirmed` and `completed`.

### Payment statuses (stored on the reservation row and on payment ledger rows)

| Status | Meaning |
|---|---|
| `unpaid` | Cash method; no Stripe session; payment expected at property |
| `pending` | Stripe session open; payment in progress |
| `paid` | Full payment received |
| `failed` | Gateway reported failure |
| `cancelled` | Payment was cancelled (also used for Stripe expired sessions) |
| `refunded` | Payment reversed |

### Derived status (computed by PaymentStatusService)

`PaymentStatusService::buildReservationPaymentState()` computes a `derived_status` from the combination of reservation status, stored payment status, payment ledger rows, and amounts:

| Derived status | Condition |
|---|---|
| `paid` | `amount_paid >= total` or `total <= 0` |
| `partially_paid` | `amount_paid > 0` and `amount_due > 0` |
| `refunded` | Refund recorded and `amount_paid <= 0` |
| `failed` | Status is `payment_failed`, or stored/latest payment status is `failed` or `cancelled` |
| `pay_at_hotel` | Method is `pay_at_hotel` and not otherwise resolved |
| `pending` | Status is `pending_payment`, or stored/latest payment status is `pending` or `processing` |
| `unpaid` | None of the above |

### Typical Stripe flow

```
Guest submits checkout form
  → createReservations() creates reservation with status='pending_payment', payment_status='pending'
  → StripePayment::processPayment() calls createStripeCheckoutSession()
  → createPaymentRows() records a ledger row with status='pending', transaction_id=session_id
  → Guest is redirected to checkout.stripe.com

Guest completes payment on Stripe
  → Stripe sends checkout.session.completed to the webhook
  → updateReservationStatuses([ids], 'confirmed', 'paid')
  → createPaymentRows([ids], 'stripe', 'paid', payment_intent_id)
  → do_action('must_hotel_booking/reservation_confirmed', $id)
  → do_action('must_hotel_booking/payment_recorded', [...])

Guest returns to the confirmation page
  → BookingStatusEngine::syncStripeReturnSession() is called (calls PaymentEngine::syncReturnSession())
  → If session status is 'complete' and payment_status is 'paid' → updates to confirmed/paid (idempotent)
  → If session status is 'expired' → calls failPendingStripeReservations()
  → If session is still open → returns state='pending' (page should poll or await webhook)
```

### Typical cash / pay-at-hotel flow

```
Guest submits checkout form
  → createReservations() creates reservation with status='confirmed', payment_status='unpaid'
  → CashPayment::processPayment() creates ledger row with status='pending'
  → do_action('must_hotel_booking/reservation_confirmed', $id) fires
  → Guest is sent to confirmation page (no redirect to payment gateway)
```

---

## Deposit logic

Deposit settings are stored in the `payments_summary` group:

| Setting | Type | Meaning |
|---|---|---|
| `deposit_required` | bool | Whether a deposit is requested |
| `deposit_type` | string | `'percentage'` or `'fixed'` |
| `deposit_value` | float | The amount (percentage: 0–100; fixed: currency units) |

Validation rules (enforced in `SettingsPage`):

- `deposit_value` cannot be negative.
- `deposit_type = 'percentage'` requires `deposit_value <= 100`.
- `deposit_type = 'fixed'` allows values up to `999999.0`.

**Unverified / needs confirmation:** The code path that actually applies the deposit amount when building the Stripe session total was not found in the searched files (`ReservationEngine`, `PaymentEngine`, `StripePayment`, `PricingEngine`). The settings are read in `SettingsPage` and `SettingsDiagnostics` for display purposes. It is likely computed in `PricingEngine::buildCheckoutRoomItems()` or a similar pricing function, but this was not confirmed by source inspection. Until this is traced, treat deposit application as unverified behaviour.

---

## Remainder payment (after deposit)

**Unverified / needs confirmation:** No code implementing a second Stripe Checkout session for collecting the balance after a partial deposit payment was located in the searched source files. This feature may not yet be implemented, or it may be handled outside the `src/` directory.

---

## Manual payment posting (admin)

File: `src/Admin/PaymentAdminActions.php`

Admin users can post manual payment status changes via GET action links in the Payments admin screen. Each action is nonce-protected (`must_payment_action_{action}_{reservationId}`).

Supported admin actions:

| Action | Effect |
|---|---|
| `mark_paid` | Updates reservation and ledger to paid |
| `mark_unpaid` | Resets to unpaid |
| `mark_pending` | Sets to pending |
| `mark_pay_at_hotel` | Switches method to pay_at_hotel |
| `mark_failed` | Marks as failed |
| `resend_guest_email` | Re-dispatches the guest confirmation email |
| `resend_admin_email` | Re-dispatches the admin notification email |

Transitions are validated by `PaymentStatusService::canTransition()` before being applied. Rules include:

- `mark_paid` is available from any status except `paid` or `refunded`.
- `mark_unpaid` is blocked if the method is Stripe and an amount has already been paid.
- `mark_failed` is blocked for `paid` or `refunded` states.

After a successful action, the admin is redirected with a `notice` query parameter indicating the result.

`dispatchPaymentRecordedEvent()` fires `must_hotel_booking/payment_recorded` after every manual payment write, using the same payload structure as the automated paths.

---

## Stripe environment detection

`PaymentEngine::getActiveSiteEnvironment()` determines which credential set to use:

1. Reads `site_environment` from settings.
2. If empty, calls `detectStripeEnvironmentFromSiteUrl()` which inspects `home_url('/')`:
   - `localhost`, `127.0.0.1`, `::1`, `*.localhost`, `*.local`, `*.test` → `local`
   - Any raw IP address → `staging`
   - Non-HTTPS scheme → `staging`
   - Everything else → `production`

The resolved environment is then used by `getStripeEnvironmentSettingKeys()` to produce the correct settings key names (e.g. `stripe_production_secret_key`).

### Reading credentials directly

```php
use MustHotelBooking\Engine\PaymentEngine;

// Uses the auto-detected or configured environment.
$creds = PaymentEngine::getStripeEnvironmentCredentials();

// Force a specific environment.
$creds = PaymentEngine::getStripeEnvironmentCredentials( 'staging' );

// Individual helpers.
$pub = PaymentEngine::getStripePublishableKey();
$sec = PaymentEngine::getStripeSecretKey();
$whs = PaymentEngine::getStripeWebhookSecret();

// Check if Stripe is ready to process payments.
$ready = PaymentEngine::isStripeCheckoutConfigured(); // bool: pub key + secret key both non-empty
```

---

## Pending session cleanup

A WordPress cron event (`must_hotel_booking_cleanup_expired_locks`) triggers `PaymentEngine::cleanupExpiredPendingPaymentReservations()`.

The method queries for reservations in `pending_payment` status whose `created_at` is older than `getPendingPaymentCleanupMinutes()` (35 minutes) and calls `BookingStatusEngine::failPendingStripeReservations()` on them. This is intentionally longer than the Stripe session expiry (30 minutes) to give the webhook time to arrive first.
