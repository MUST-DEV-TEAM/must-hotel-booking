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
- PokPay uses `src/Engine/Payment/PokPayPayment.php` and SDK order/finalization behavior in `src/Engine/PaymentEngine.php`.
- Online gateway reservations use `PaymentEngine::getInitialReservationStateForMethod()` behavior: Stripe/PokPay initialize pending-style payment state; pay-at-hotel initializes unpaid behavior.

## Payment Confirmation
- Stripe success is verified by the REST webhook `must-hotel-booking/v1/stripe/webhook`.
- Stripe webhook verifies the `stripe-signature` header with the configured webhook secret before processing.
- `checkout.session.completed` updates reservations to `confirmed` and `paid`, creates payment rows, and triggers Clock payment reconciliation when available.
- `checkout.session.expired` fails pending Stripe reservations as expired.
- PokPay finalization route is `must-hotel-booking/v1/pokpay/finalize`.
- PokPay finalization requires `x-wp-nonce`, validates order/reservation relationship against payment rows, then finalizes through PokPay API/order checks.
- PokPay error route is `must-hotel-booking/v1/pokpay/error` and logs sanitized checkout error context.

## Refund Service
- Main refund service: `src/Engine/PaymentRefundService.php`.
- Refund repository: `src/Database/RefundRepository.php`.
- Refund table: `must_refunds`.
- Stripe refunds create a local refund row, use a Stripe idempotency key, call Stripe `refunds`, update provider IDs/status, record local refund ledger on success, and attempt Clock refund sync.
- PokPay refunds check for blocking duplicate refund records, create local refund rows, call PokPay refund API when possible, otherwise set manual pending behavior, record local ledger on success/manual completion, and attempt Clock refund sync.
- Cash/pay-at-hotel refunds are manual outside the plugin.

## Clock Reconciliation
- `src/Provider/Clock/ClockPaymentReconciliationService.php` runs after Stripe/PokPay payment success if the class exists.
- `src/Provider/Clock/ClockFolioPaymentSyncService.php` handles Clock folio payment sync.
- `src/Provider/Clock/ClockFolioRefundSyncService.php` handles refund credit item sync.
- Some Clock folio operations may require manual staff accounting if Clock permissions or folio IDs are unavailable.

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
