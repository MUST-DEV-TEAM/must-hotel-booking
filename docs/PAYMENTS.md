# Payments

## Inspection Provenance
Verified from targeted current-code inspection on 2026-06-11. Use this document for navigation, then verify behavior in the referenced files before changing payment, webhook, refund, or reconciliation logic.

## Payment Methods Found
- `pay_at_hotel`
- `stripe`
- `pokpay`

Payment catalog/defaults are in `src/Core/PaymentMethodRegistry.php`. Checkout ordering in `PaymentEngine` prefers `pokpay`, then `stripe`, then `pay_at_hotel` when enabled and available.

## Payment Row Behavior
- Payment rows are stored in `must_payments`.
- Key columns: `reservation_id`, `amount`, `currency`, `method`, `status`, `transaction_id`, `paid_at`, `created_at`.
- Payment row creation/update helpers are in `src/Database/PaymentRepository.php` and `src/Engine/BookingStatusEngine.php`.

## Payment Statuses
- Status values observed around payment/reservation handling include `pending`, `paid`, `unpaid`, `refunded`, `expired`, `payment_failed`, and `blocked`.
- Exact full allowed status set is not centralized. Unknown from current code inspection.

## Payment Creation
- `pay_at_hotel` uses `src/Engine/Payment/CashPayment.php` and returns unpaid behavior.
- Stripe uses `src/Engine/Payment/StripePayment.php` and Stripe Checkout/session behavior in `src/Engine/PaymentEngine.php`.
- PokPay uses `src/Engine/Payment/PokPayPayment.php` and documented SDK order/finalization behavior in `src/Engine/PaymentEngine.php`. It supports two SDK-order modes controlled by `pokpay_checkout_mode`: embedded SDK and SDK confirmUrl redirect.
- Online gateway reservations use `PaymentEngine::getInitialReservationStateForMethod()` behavior: Stripe/PokPay initialize pending-style payment state; pay-at-hotel initializes unpaid behavior.

## Payment Confirmation
- Stripe success is verified by the REST webhook `must-hotel-booking/v1/stripe/webhook`.
- Stripe webhook verifies the `stripe-signature` header with the configured webhook secret before processing.
- Stripe Checkout success/cancel URLs and Stripe webhook URL diagnostics use the optional public callback base setting when configured.
- `checkout.session.completed` updates reservations to `confirmed` and `paid`, creates payment rows, and triggers Clock payment reconciliation when available.
- `checkout.session.expired` fails pending Stripe reservations as expired.
- PokPay finalization route is `must-hotel-booking/v1/pokpay/finalize`.
- PokPay finalization requires `x-wp-nonce`, validates order/reservation relationship against payment rows, then finalizes through PokPay API/order checks.
- PokPay error route is `must-hotel-booking/v1/pokpay/error` and logs sanitized checkout error context.
- PokPay webhook route is `must-hotel-booking/v1/pokpay/webhook`; it extracts the SDK order ID, resolves local payment rows, and still verifies the SDK order server-side before confirming paid.
- PokPay SDK order `webhookUrl`, `redirectUrl`, and `failRedirectUrl` use the optional public callback base setting when configured.
- `embedded_sdk` creates a pending SDK order/payment row, renders the PokPay SDK container on the confirmation page, and confirms/marks paid only after the finalize route verifies the fetched SDK order status.
- `sdk_confirm_url_redirect` creates a pending SDK order/payment row, extracts `sdkOrder._self.confirmUrl` or documented fallback provider URLs from the SDK order response, redirects the guest to PokPay/RPay, and verifies the SDK order server-side on return before marking paid.

## Refund Service
- Main refund service: `src/Engine/PaymentRefundService.php`.
- Refund repository: `src/Database/RefundRepository.php`.
- Refund table: `must_refunds`.
- Stripe refunds create a local refund row, use a Stripe idempotency key, call Stripe `refunds`, update provider IDs/status, record local refund ledger on success, and attempt Clock refund sync.
- PokPay refunds check for blocking duplicate refund records, create local refund rows, and call the documented PokPay merchant SDK-order refund endpoint. A confirmed provider refund records the local ledger and attempts Clock refund sync when applicable. If PokPay rejects the request or does not return a confirmed refunded status, the local refund remains `manual_pending` for staff dashboard verification/completion.
- Cash/pay-at-hotel refunds are manual outside the plugin.

## Clock Reconciliation
- `src/Provider/Clock/ClockPaymentReconciliationService.php` runs after Stripe/PokPay payment success if the class exists.
- `src/Provider/Clock/ClockFolioPaymentSyncService.php` handles Clock folio payment sync.
- `src/Provider/Clock/ClockFolioRefundSyncService.php` handles refund credit item sync.
- `src/Provider/Clock/ClockPaymentAccountingService.php` chooses Clock accounting by `clock_payment_posting_mode`. Default `auto_detect` posts future Stripe/PokPay website payments to Clock deposit folios using the verified booking-folio `deposit=true` plus folio `credit_items` workflow. It does not silently post future payments to the normal folio when deposit posting fails.
- `folio_payment_only` preserves legacy folio credit-item posting and is not recommended for future reservations because it can settle/affect folio balance before arrival.
- Same-day/current-stay payments can still use folio posting when `clock_same_day_folio_payment_enabled` is true.
- Clock payment/refund accounting stores stable reason codes in `must_clock_folio_accounting.last_error_code` and human-readable messages in `last_error`.
- Admin payment detail can mark failed/manual-review payment accounting rows as `handled_manually` after staff handles Clock outside the plugin. This does not post to Clock and does not change the local paid payment status.
- Some Clock folio/deposit operations may require manual staff accounting if Clock permissions, folio IDs, deposit folio creation, or deposit credit-item posting fail.

## Booking/Payment Relationship
- Reservations store `payment_status`.
- Payments store one or more ledger rows per reservation.
- Booking status updates happen through `BookingStatusEngine`; do not update only payment rows when reservation status/payment status also need to change.

## Important Files
- `src/Core/PaymentMethodRegistry.php`
- `src/Engine/PaymentEngine.php`
- `src/Engine/BookingStatusEngine.php`
- `src/Engine/PaymentStatusService.php`
- `src/Engine/PaymentRefundService.php`
- `src/Engine/Payment/CashPayment.php`
- `src/Engine/Payment/StripePayment.php`
- `src/Engine/Payment/PokPayPayment.php`
- `src/Database/PaymentRepository.php`
- `src/Database/RefundRepository.php`
- `src/Provider/Clock/ClockPaymentReconciliationService.php`
- `src/Provider/Clock/ClockFolioPaymentSyncService.php`
- `src/Provider/Clock/ClockFolioRefundSyncService.php`

## Rules To Preserve
- Never mark a booking paid without verified Stripe/PokPay/Clock/manual payment logic.
- Do not fake gateway success.
- Webhook/finalization/refund paths must remain idempotent.
- Keep provider-specific code isolated from core booking rules.
- Preserve local payment ledger and Clock reconciliation/manual-review behavior.

## Targeted Search Recipes
```bash
rg -n "PaymentMethodRegistry|getCheckoutPaymentMethods|getInitialReservationStateForMethod" src
rg -n "stripe/webhook|pokpay/finalize|registerPaymentRestRoutes" src/Engine/PaymentEngine.php
rg -n "createPaymentRows|updateReservationStatuses|failPending" src/Engine src/Database
rg -n "refund|idempotency|clock_sync_status|manual_pending" src/Engine src/Database src/Provider/Clock
```
