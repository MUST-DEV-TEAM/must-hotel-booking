# Integrations

## Stripe
- Main code: `src/Engine/PaymentEngine.php`, `src/Engine/Payment/StripePayment.php`, `src/Engine/PaymentRefundService.php`.
- Settings defaults/sanitization: `src/Core/MustBookingConfig.php`.
- Webhook URL helper returns `must-hotel-booking/v1/stripe/webhook`.
- REST route verifies Stripe signature using the configured webhook secret.
- Successful `checkout.session.completed` confirms reservations, marks payment paid, creates payment rows, and runs Clock payment reconciliation if available.
- Duplicate `checkout.session.completed` events must no-op when the exact Stripe payment intent/session transaction already has a paid row; this prevents refunded Stripe ledger rows from being reused as paid rows during replay.
- On successful Stripe finalization, `PaymentProviderFeeService` fetches the Stripe payment intent/charge balance transaction and stores the exact provider fee snapshot on `must_payments` when Stripe exposes it. If the fee cannot be fetched, the payment is marked `provider_fee_status = unknown` for admin review; no percentage is hardcoded.
- `checkout.session.expired` expires pending Stripe reservations.
- Refund webhooks handled by `PaymentRefundService` for `refund.created`, `refund.updated`, and `charge.refunded`.
- A Stripe Dashboard endpoint may listen to all events, but the plugin only handles `checkout.session.completed`, `checkout.session.expired`, `refund.created`, `refund.updated`, and `charge.refunded`; selected events should include at least those five.
- Default refund math is `paid amount - stored provider fee - cancellation fee`, never below zero. If the provider fee is unknown, guest cancellation/default refund requires manual review instead of silently refunding the full paid amount.

## PokPay
- Main code: `src/Engine/PaymentEngine.php`, `src/Engine/Payment/PokPayPayment.php`, `src/Engine/PaymentRefundService.php`.
- Settings include environment-specific merchant/key fields in `MustBookingConfig`.
- Payments -> Payment Settings provides a safe PokPay credential test that authenticates only. It stores masked verification evidence and never creates an SDK order, charge, refund, or Clock booking.
- Credential state is fingerprinted to the saved merchant/key values. Editing credentials invalidates old verification, and known rejected/malformed credentials are gated before Clock reservation creation.
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
- PokPay staging write E2E passed on 2026-06-14 with a fresh SDK order, manual staging payment, local callback/webhook update, provider refund, duplicate payment/refund replay, and Clock positive/negative accounting on a deposit folio.

## Clock PMS
- Main provider directory: `src/Provider/Clock`.
- Provider registration happens through `src/Provider/ProviderManager.php`.
- Modes found: local and Clock.
- Clock support includes availability, quote, booking/reservation provider behavior, catalog service, inbound sync, auto sync scheduler, payment reconciliation, folio payment sync, folio refund sync, mappings, and diagnostics.
- Website-created Clock bookings send the local website booking reference (for example `MHB-YYYYMMDD-XXXXXXXX`) before the Clock write. The create payload uses Clock's existing writable `reference_number` field and also appends `Website booking reference: ...` to the booking note as a manual-search fallback.
- Local Clock mirror rows store `clock_booking_id`, `clock_booking_reference`, `website_booking_reference`, `website_reference_sent_to_clock`, and the Clock-side storage/fallback fields in `must_reservations.provider_metadata`. Existing `provider_booking_id` remains the raw Clock booking ID fallback for older rows.
- Admin reservation detail shows the website booking reference, Clock booking ID/reference, and whether the website reference was sent to Clock. If the sent marker is missing, the detail page exposes a safe retry action that rereads the Clock booking, appends the website-reference note only if missing, and updates Clock through `PUT /bookings/{booking_id}`.
- Clock webhook URL is exposed in Clock config as `must-hotel-booking/v1/clock/webhook`.
- Clock PMS PUSH webhooks use Amazon SNS envelope payloads. `ClockInboundSyncController` verifies SNS signatures from safe Amazon SNS signing certificate URLs, supports SNS `SubscriptionConfirmation` and `Notification`, confirms safe SNS subscribe URLs, and extracts the Clock event type from `Subject` plus event data from the JSON string in `Message`.
- Clock PUSH can optionally use HTTP Basic authentication when Clock embeds credentials in the endpoint URL. The legacy `clock_webhook_secret` still supports custom Bearer token and HMAC senders for backward compatibility, but it is not required for official Clock PUSH/SNS.
- Clock PUSH Basic authentication is valid only when both username and password are configured. Half-configured credentials block inbound processing and are reported in diagnostics.
- Clock inbound processing serializes each SNS event ID with a database advisory lock. Successful duplicates are no-ops; failed attempts remain retryable.
- Official status subjects such as `booking_canceled`, `booking_checked_out`, and `booking_no_show` can apply a safe local status fallback when the Clock booking detail fetch is temporarily unavailable. Detail-only events return retryable failure until booking details are fetched.
- A valid booking event that arrives before its room/provider mapping exists queues a retryable `booking_upsert` provider-sync job; it never invents a mapping or updates an unrelated reservation.
- Booking cancellation uses the documented `PUT /bookings/{booking_id}` endpoint with body `booking.status = canceled`; do not close folios automatically.
- Pre-arrival Clock room/type/date amendments use documented booking `GET` and `PUT` operations. The write body uses the `booking` wrapper with `arrival`, `departure`, `arrival_room_type_id`, optional `arrival_room_id`, optional `rate_id`, and `lock_version` when returned by the pre-write reread.
- Clock amendment retries always reread the booking before another write. WordPress is updated only after a matching provider reread; a confirmed provider write followed by local failure queues `reservation_amendment` reconciliation.
- Checked-in/current-room Clock moves are intentionally blocked. The documented arrival-room update is not treated as an API for creating `booking_room_changes`.
- Checked-in date-only corrections may update the stay window, but their payload omits `arrival_room_type_id`, `arrival_room_id`, and `rate_id`.
- Clock amendment price increases/decreases create manual financial-review metadata only. No Stripe/PokPay charge/refund or Clock folio credit item is created by the amendment service.
- Clock inbound webhooks, scheduled refresh jobs, Clock booking upserts, and successful outbound Clock cancellation reconciliation apply local status changes through `BookingLifecycleSyncService`, not direct repository status writes. This keeps Clock-originated cancellations separate from automatic refunds while still firing the standard cancellation domain event and email hooks once.
- For paid Stripe/PokPay website bookings cancelled from Clock/provider sync, `BookingLifecycleSyncService` creates a `refund_review_required` row for staff decision when no active/completed refund already exists. Do not replace this with automatic refund behavior unless the provider/refund policy is explicitly verified.
- Stripe/PokPay paid rows trigger `ClockPaymentAccountingService` through `must_hotel_booking/payment_recorded`; the service creates/reuses `must_clock_folio_accounting` and guards duplicate posts by idempotency key and posting claim.
- Clock deposit idempotency uses gateway + provider transaction ID + reservation ID + accounting operation, not the local payment-row ID.
- Provider sync jobs use `mhb_provider_sync_jobs` with atomic claim, stale-lock release, backoff, max attempts, and admin-triggered manual runs from Settings. A stale running job consumes an attempt before it is requeued or exhausted so repeated worker crashes cannot retry forever without reaching a terminal state.
- Clock payment posting mode is configured by `clock_payment_posting_mode`: `auto_detect`, `deposit_for_future_bookings`, `folio_payment_only`, or `manual_clock_accounting`. Default `auto_detect` posts future Stripe/PokPay website payments to a Clock deposit folio, not to the normal folio.
- A centralized endpoint registry lives in `src/Provider/Clock/ClockEndpointRegistry.php`. Existing folio endpoints now resolve through the registry and advanced provider settings can override endpoint templates.
- Clock endpoint overrides must be safe relative path templates only. Absolute URLs, protocol-relative URLs, credentials, traversal, control characters, and unknown placeholders are rejected before settings are saved.
- Clock accounting rows use structured `last_error_code` values such as `future_booking_requires_deposit_endpoint`, `deposit_endpoint_not_verified`, `same_day_folio_posting_disabled`, `clock_folio_not_found`, and `clock_api_right_missing`; the human-readable detail remains in `last_error`.
- Payment accounting rows in `manual_review` or `failed` can be marked `handled_manually` from the admin payment detail screen after staff records the website payment in Clock outside the plugin. This is terminal local accounting state and does not post to Clock or change the paid payment ledger.
- Official Clock docs verified on 2026-06-13 support `POST /bookings/{booking_id}/folios/` with `booking_folio.deposit=true`, followed by `POST /folios/{folio_id}/credit_items` to record the website payment on the open deposit folio. Clock support docs state payments in open deposit folios are treated as deposits; deposit refund is a negative payment in the deposit folio.
- Clock sandbox verified on 2026-06-13: deposit folio `71442250` was created for booking `36591448`, payment credit item `60052192` posted, duplicate sync returned `already_posted`, refund/reversal credit item `60052222` posted, and the deposit folio balance returned to zero.
- Clock sandbox write E2E on 2026-06-14 verified Stripe and PokPay future-stay deposit folios with positive provider payment credit items, negative refund credit items, duplicate replay idempotency, and final folio balances returning to zero.
- Normal Stripe/PokPay website payments always use deposit folios. A saved legacy `folio_payment_only` value is treated as deposit mode and cannot target the standard accommodation folio.
- Refunds use the actual refunded amount from the refund row, post one negative Clock `credit_item` to the original posted payment folio when available, and guard duplicate negative posts through `must_clock_folio_accounting`.
- Ambiguous Clock folio/payment targets, missing original refund folios, or unverifiable accounting targets must go to `manual_review` rather than auto-posting or silently repairing financial state.
- Customer cancellation sync changes the Clock booking status only. Room charge reversal, cancellation-fee accounting, or folio closure remain separate hotel accounting policy tasks unless a safe Clock charge-reversal flow is implemented.
- The full cached Clock Postman collection refreshed from the live public export on 2026-06-19 documents generic folio charge voiding through `DELETE /base_api/:subscription_id/:account_id/folios/:folio_id/charges/:charge_id` with `void_reason`, a compensating `POST /base_api/:subscription_id/:account_id/folios/:folio_id/charges/bulk` pattern that uses positive price with negative quantity, generic booking-associated single charge creation through `POST /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source`, booking-associated bulk charge creation through `POST /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source/bulk`, and discount mirroring through `POST /base_api/:subscription_id/:account_id/folios/:folio_id/charges/`.
- Those contracts are still not treated as approval for automatic room-move writes, automatic future room-change creation, coupon-native sync, or blanket accommodation/cancellation cleanup. Continue using `manual_clock_charge_cleanup_required` and `manual_clock_cancellation_fee_required` until business rules define which Clock charges/folios are eligible and how closed-folio cases should be handled.
- Outbound Clock cancellation performs a booking reread before a retry and after a successful cancellation request. Local cancellation is finalized only when reread confirms `canceled`/`cancelled`.
- Booking folio list responses can be scalar IDs such as `[71355568]`, string IDs, full folio objects, or wrappers like `data`, `folios`, `booking_folios`, `items`, and `records`.
- Folio verification reads `GET /folios/{folio_id}` and Clock may return money values as nested objects like `balance.cents`.
- Deposit posting verifies that the target remains `deposit=true`, Clock's raw signed deposit-folio balance matches the expected movement, the normalized deposit amount held matches the website payment, the credit-item reference is present when Clock exposes it, and standard non-deposit folio balances did not change before recording `verified_deposit_isolated`. Clock's booking header may still display aggregate `Balance 0`/deposit summary values even when the standard folio has not received a payment or transfer; do not create compensating entries to alter that native presentation.
- `ClockApiClient` deduplicates identical safe GETs within one request, caches catalogue/config reads briefly, and permits a 45-second cache for intermediate public availability/product quote display. Final reservation availability, product pricing, and guarantee-policy reads pass `bypass_cache`, do not read or write request/transient caches, and use dedicated final-revalidation operation names.
- Public Clock availability/product reads use an 8-second total timeout. Safe GETs may retry once for HTTP 429; writes are not automatically retried. The shared four-requests-per-second limiter covers Digest challenge and authenticated calls.
- The booking-selection transient contains the signed server-side quote draft, so checkout/confirmation rendering normally avoids repeated Clock quote work. Final submission compares fresh total/currency and guarantee policy to that signed draft before any Clock booking write.
- Clock auto-sync scheduling only enqueues refresh jobs. The provider worker uses an atomic expiring option lock, processes one due job per cron slice, and schedules another slice when work remains. New-install defaults are one reservation every 60 minutes; existing saved settings are preserved until an administrator changes them.
- Provider request summaries use recursive redaction for tokens, authorization headers, secrets, card data, self-service keys/PINs, door codes, and unnecessary contact data.
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
- Clock settings include PMS/Base API URL details, credentials, official PUSH/SNS inbound options, legacy custom webhook token/HMAC auth, provider mode, WBE inline mode settings, and mapping settings.
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
