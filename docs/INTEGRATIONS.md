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
- PokPay checkout mode is configured in the admin Payments page under Payment Settings -> PokPay Checkout -> PokPay checkout mode. Allowed values are `embedded_sdk` and `sdk_confirm_url_redirect`; both use SDK orders.
- SDK order creation posts to `merchants/{merchantId}/sdk-orders` with `amount`, `currencyCode`, `autoCapture`, `description`, `webhookUrl`, `redirectUrl`, `failRedirectUrl`, and `products`; do not add unsupported top-level `currency` or `email` fields.
- In redirect mode, the plugin extracts the provider checkout URL from `sdkOrder._self.confirmUrl` first, then related `_self`/SDK order fallback fields. `redirectUrl` and `failRedirectUrl` are treated as return URLs, not provider checkout URLs.
- Finalization endpoint: `must-hotel-booking/v1/pokpay/finalize`.
- Error endpoint: `must-hotel-booking/v1/pokpay/error`.
- Finalization validates the `x-wp-nonce` REST nonce, order ID, reservation IDs, active flow data, and payment row/order relationship, then polls `GET sdk-orders/{orderId}`. Captured/paid/completed SDK order states mark the reservation paid and trigger Clock payment reconciliation.
- PokPay paid finalization stores a provider fee snapshot from the PokPay order response when an exact fee is present, including common nested response wrappers such as `sdkOrder`. If PokPay does not return a fee, the plugin can snapshot the configured PokPay fee estimate from payment settings at payment time.
- There is no Stripe-style PokPay webhook secret in current code. The browser/SDK callback and PokPay webhook route both confirm/mark paid only after server-side `GET sdk-orders/{orderId}` verification.
- Official PokPay Postman/API docs verified on 2026-06-13 list `POST /merchants/{merchantId}/sdk-orders/{sdkOrderId}/refund` with `refundReason` and optional `refundAmount`. The plugin attempts this endpoint first, then fetches the order and requires a completed/refunded status before marking the local refund succeeded.
- If PokPay rejects the refund, returns an incomplete status, or the order is already refunded, the refund request falls back to `manual_pending`; staff must verify/refund from the POK dashboard and then mark the refund completed in the plugin. Completing the manual refund records the local refund ledger, can continue the selected cancel-after-refund flow, and attempts Clock refund accounting if applicable.
- PokPay is code-ready for payment and duplicate finalize idempotency. On 2026-06-12, active staging credentials passed auth preflight and one staging SDK order smoke test; full manual payment/finalize/manual-refund testing still requires a real user payment flow.

## Clock PMS
- Main provider directory: `src/Provider/Clock`.
- Provider registration happens through `src/Provider/ProviderManager.php`.
- Modes found: local and Clock.
- Clock support includes availability, quote, booking/reservation provider behavior, catalog service, inbound sync, auto sync scheduler, payment reconciliation, folio payment sync, folio refund sync, mappings, and diagnostics.
- Clock webhook URL is exposed in Clock config as `must-hotel-booking/v1/clock/webhook`.
- Booking cancellation uses the documented `PUT /bookings/{booking_id}` endpoint with body `booking.status = canceled`; do not close folios automatically.
- Clock inbound webhooks, scheduled refresh jobs, Clock booking upserts, and successful outbound Clock cancellation reconciliation apply local status changes through `BookingLifecycleSyncService`, not direct repository status writes. This keeps Clock-originated cancellations separate from automatic refunds while still firing the standard cancellation domain event and email hooks once.
- For paid Stripe/PokPay website bookings cancelled from Clock/provider sync, `BookingLifecycleSyncService` creates a `refund_review_required` row for staff decision when no active/completed refund already exists. Do not replace this with automatic refund behavior unless the provider/refund policy is explicitly verified.
- Stripe/PokPay paid rows trigger `ClockPaymentAccountingService` through `must_hotel_booking/payment_recorded`; the service creates/reuses `must_clock_folio_accounting` and guards duplicate posts by idempotency key and posting claim.
- Clock payment posting mode is configured by `clock_payment_posting_mode`: `auto_detect`, `deposit_for_future_bookings`, `folio_payment_only`, or `manual_clock_accounting`. Default `auto_detect` posts future Stripe/PokPay website payments to a Clock deposit folio, not to the normal folio.
- A centralized endpoint registry lives in `src/Provider/Clock/ClockEndpointRegistry.php`. Existing folio endpoints now resolve through the registry and advanced provider settings can override endpoint templates.
- Clock endpoint overrides must be safe relative path templates only. Absolute URLs, protocol-relative URLs, credentials, traversal, control characters, and unknown placeholders are rejected before settings are saved.
- Clock accounting rows use structured `last_error_code` values such as `future_booking_requires_deposit_endpoint`, `deposit_endpoint_not_verified`, `same_day_folio_posting_disabled`, `clock_folio_not_found`, and `clock_api_right_missing`; the human-readable detail remains in `last_error`.
- Payment accounting rows in `manual_review` or `failed` can be marked `handled_manually` from the admin payment detail screen after staff records the website payment in Clock outside the plugin. This is terminal local accounting state and does not post to Clock or change the paid payment ledger.
- Official Clock docs verified on 2026-06-13 support `POST /bookings/{booking_id}/folios/` with `booking_folio.deposit=true`, followed by `POST /folios/{folio_id}/credit_items` to record the website payment on the open deposit folio. Clock support docs state payments in open deposit folios are treated as deposits; deposit refund is a negative payment in the deposit folio.
- Clock sandbox verified on 2026-06-13: deposit folio `71442250` was created for booking `36591448`, payment credit item `60052192` posted, duplicate sync returned `already_posted`, refund/reversal credit item `60052222` posted, and the deposit folio balance returned to zero.
- Same-day/current-stay website payments may still post to folio credit items when `clock_same_day_folio_payment_enabled` is enabled or when `clock_payment_posting_mode` is `folio_payment_only`.
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
- General settings include optional `public_callback_base_url`. Leave it blank for normal local/site URL generation; set it to an HTTPS tunnel or staging origin when Stripe, PokPay, or Clock callbacks/returns must use a public host instead of `localhost`.
- Public callback URL generation is centralized in `MustBookingConfig::build_public_callback_url()` and `build_public_rest_url()`. Provider success/cancel URLs and Stripe/PokPay/Clock webhook diagnostics should use those helpers instead of raw `home_url()`, `site_url()`, or `rest_url()`.
- Staff portal and managed page settings are stored through `MustBookingConfig`.

## Known Limitations
- Exact provider API behavior must be verified from provider docs and current code; do not guess.
- Clock permissions can block automatic folio payment/refund posting.
- Production credentials and provider account configuration are unknown from current code inspection.
- Local callback URLs are not provider-reachable unless `public_callback_base_url` is set to a public HTTPS origin/tunnel and webhook secrets are configured.

## Rules
- Keep integration logic isolated from core booking logic.
- Do not log secrets.
- Preserve idempotency keys and provider request logs.
- Do not change provider endpoints, payloads, or status mapping without verifying current code and provider docs.
