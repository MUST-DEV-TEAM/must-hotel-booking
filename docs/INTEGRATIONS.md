# Integrations

## Stripe
- Main code: `src/Engine/PaymentEngine.php`, `src/Engine/Payment/StripePayment.php`, `src/Engine/PaymentRefundService.php`.
- Settings defaults/sanitization: `src/Core/MustBookingConfig.php`.
- Webhook URL helper returns `must-hotel-booking/v1/stripe/webhook`.
- REST route verifies Stripe signature using the configured webhook secret.
- Successful `checkout.session.completed` confirms reservations, marks payment paid, creates payment rows, and runs Clock payment reconciliation if available.
- On successful Stripe finalization, `PaymentProviderFeeService` fetches the Stripe payment intent/charge balance transaction and stores the exact provider fee snapshot on `must_payments` when Stripe exposes it. If the fee cannot be fetched, the payment is marked `provider_fee_status = unknown` for admin review; no percentage is hardcoded.
- `checkout.session.expired` expires pending Stripe reservations.
- Refund webhooks handled by `PaymentRefundService` for `refund.created`, `refund.updated`, and `charge.refunded`.
- A Stripe Dashboard endpoint may listen to all events, but the plugin only handles `checkout.session.completed`, `checkout.session.expired`, `refund.created`, `refund.updated`, and `charge.refunded`; selected events should include at least those five.
- Default refund math is `paid amount - stored provider fee - cancellation fee`, never below zero. If the provider fee is unknown, guest cancellation/default refund requires manual review instead of silently refunding the full paid amount.

## PokPay
- Main code: `src/Engine/PaymentEngine.php`, `src/Engine/Payment/PokPayPayment.php`, `src/Engine/PaymentRefundService.php`.
- Settings include environment-specific merchant/key fields in `MustBookingConfig`.
- API base can be production or staging; JS SDK URL is `https://static.pokpay.io/public/dist/pokpayments/pok-payment.js`.
- SDK order creation posts to `merchants/{merchantId}/sdk-orders` with `amount`, `currencyCode`, `autoCapture`, and `description`; do not add unsupported top-level `currency` or `email` fields.
- Finalization endpoint: `must-hotel-booking/v1/pokpay/finalize`.
- Error endpoint: `must-hotel-booking/v1/pokpay/error`.
- Finalization validates the `x-wp-nonce` REST nonce, order ID, reservation IDs, active flow data, and payment row/order relationship, then polls `GET sdk-orders/{orderId}`. `CAPTURED` marks the reservation paid and triggers Clock payment reconciliation.
- PokPay paid finalization stores a provider fee snapshot from the PokPay order response when an exact fee is present, including common nested response wrappers such as `sdkOrder`. If PokPay does not return a fee, the plugin can snapshot the configured PokPay fee estimate from payment settings at payment time.
- There is no Stripe-style PokPay webhook secret in current code. The browser/SDK callback calls the finalize route, and the success return URL is the booking confirmation page with `payment_method=pokpay`, `pokpay_return=success`, and `order_id`.
- Refunds call `POST merchants/{merchantId}/sdk-orders/{orderId}/refund`; if automatic PokPay API refund fails or is unavailable, the refund is recorded as manual pending and can be completed manually.
- PokPay is code-ready for payment, duplicate finalize idempotency, refund, and Clock folio accounting. On 2026-06-12, active staging credentials passed auth preflight and one staging SDK order smoke test; full manual payment/finalize/refund testing still requires a real user payment flow.

## Clock PMS
- Main provider directory: `src/Provider/Clock`.
- Provider registration happens through `src/Provider/ProviderManager.php`.
- Modes found: local and Clock.
- Clock support includes availability, quote, booking/reservation provider behavior, catalog service, inbound sync, auto sync scheduler, payment reconciliation, folio payment sync, folio refund sync, mappings, and diagnostics.
- Clock webhook URL is exposed in Clock config as `must-hotel-booking/v1/clock/webhook`.
- Booking cancellation uses the documented `PUT /bookings/{booking_id}` endpoint with body `booking.status = canceled`; do not close folios automatically.
- Stripe/PokPay paid rows trigger `ClockPaymentAccountingService` through `must_hotel_booking/payment_recorded`; the service creates/reuses `must_clock_folio_accounting`, posts one positive Clock `credit_item`, records folio/item IDs, and guards duplicate posts by idempotency key and posting claim.
- Refunds use the actual refunded amount from the refund row, post one negative Clock `credit_item` to the original posted payment folio when available, and guard duplicate negative posts through `must_clock_folio_accounting`.
- Customer cancellation sync changes the Clock booking status only. Room charge reversal, cancellation-fee accounting, or folio closure remain separate hotel accounting policy tasks unless a safe Clock charge-reversal flow is implemented.
- Booking folio list responses can be scalar IDs such as `[71355568]`, string IDs, full folio objects, or wrappers like `data`, `folios`, `booking_folios`, `items`, and `records`.
- Folio verification reads `GET /folios/{folio_id}` and Clock may return money values as nested objects like `balance.cents`.
- Some Clock payment/refund accounting may require manual review if API rights or folio IDs are missing.

## Elementor Widgets
- Booking search widget: `src/Elementor/Booking_Search_Widget.php` and `src/Elementor/booking-search-widget.php`.
- Rooms list widget: `src/Elementor/Rooms_List_Widget.php` and `src/Elementor/rooms-list-widget.php`.
- Rooms text grid widget: `src/Elementor/Rooms_Text_Grid_Widget.php` and `src/Elementor/rooms-text-grid-widget.php`.
- Assets live in `assets/css/*widget.css` and `assets/js/*widget.js`.
- Widgets register through Elementor hooks and check Elementor classes before registration.

## GitHub Updater/Plugin-Update-Checker
- Main code: `src/Core/Updater.php`.
- Library: `lib/plugin-update-checker/plugin-update-checker.php`.
- Constants in `must-hotel-booking.php` configure repository URL, branch, release asset pattern, token, slug, and updater enablement.
- Updater expects release ZIP names matching the configured pattern.

## Settings/Options
- Payment settings include `payment_methods`, Stripe/PokPay environment credentials, and PokPay fee estimate fields in `MustBookingConfig`.
- Clock settings include PMS/Base API URL details, credentials, webhook shared secret, provider mode, WBE inline mode settings, and mapping settings.
- Staff portal and managed page settings are stored through `MustBookingConfig`.

## Known Limitations
- Exact provider API behavior must be verified from provider docs and current code; do not guess.
- Clock permissions can block automatic folio payment/refund posting.
- Production credentials and provider account configuration are unknown from current code inspection.

## Rules
- Keep integration logic isolated from core booking logic.
- Do not log secrets.
- Preserve idempotency keys and provider request logs.
- Do not change provider endpoints, payloads, or status mapping without verifying current code and provider docs.
