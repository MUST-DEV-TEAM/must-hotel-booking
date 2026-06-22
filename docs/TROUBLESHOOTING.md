# Troubleshooting

## Local WordPress Notes
- This is a WordPress plugin; checks should usually run from the plugin root.
- WP-CLI availability was not confirmed during this documentation pass.
- Use WP-CLI only when the local environment supports it and the command is safe for the current database.

## Common Booking Issue Areas
- Managed pages: `src/Core/ManagedPages.php`.
- Public flow: `src/Frontend/booking-page.php`, `accommodation-page.php`, `checkout-page.php`, `confirmation-page.php`.
- Reservation creation: `src/Engine/ReservationEngine.php`.
- Validation: `src/Engine/BookingValidationEngine.php`.
- Provider mode/fallback: `src/Provider/ProviderManager.php`, `src/Provider/Local/*`, `src/Provider/Clock/*`.

## Availability/Inventory Lock Issue Areas
- `src/Engine/AvailabilityEngine.php`
- `src/Engine/InventoryEngine.php`
- `src/Engine/LockEngine.php`
- `src/Database/InventoryRepository.php`
- Table: `mhb_inventory_locks`
- Cron hook: `must_hotel_booking_cleanup_expired_locks`

### Reservation room move or upgrade fails
- Check the destination physical room is active, bookable, `available`, belongs to the selected accommodation, and has no blocking reservation or live checkout lock for the effective stay.
- Local amendments serialize by reservation and destination room, then recheck availability inside the lock. A conflict reported at save time means another operation claimed the destination first; the original assignment remains intact.
- Combined portal amendments require both stay-edit and room assignment/move capabilities. Invalid nonces and missing capabilities redirect without applying the request.
- Same-type physical moves preserve total and payment status. Room-type, rate, or date changes recalculate pricing and may set manual financial-review metadata.

## Payment/Webhook/Finalization Issue Areas
- `src/Engine/PaymentEngine.php`
- `src/Engine/PaymentStatusService.php`
- `src/Engine/BookingStatusEngine.php`
- `src/Database/PaymentRepository.php`
- Stripe route: `must-hotel-booking/v1/stripe/webhook`
- PokPay routes: `must-hotel-booking/v1/pokpay/finalize`, `must-hotel-booking/v1/pokpay/error`
- Check webhook secrets, REST nonce behavior, payment row transaction IDs, and reservation IDs.

### Public callback/tunnel testing
- Use Settings -> General -> Public callback base URL, or `tools/clock-e2e-settings.php --public-callback-base-url=https://example.ngrok-free.dev`, when provider callbacks/returns must use a public HTTPS host instead of `localhost`.
- The setting affects provider callback URL helpers and public-host request URL rewriting only; leave it blank after E2E testing to restore normal local URL generation.
- Before mutating options or creating provider test bookings, run `tools/clock-e2e-backup.php`. It creates a sanitized manifest plus ignored local JSON table/option exports under `tools/backups/`.
- If an online provider test creates a pending payment reservation that cannot be completed, use `tools/clock-e2e-cleanup.php --reservation-id=<id> --method=stripe --status=cancelled` or the matching provider method. This marks the local reservation/payment failed through `BookingStatusEngine`; Clock cancellation may queue in `mhb_provider_sync_jobs` if the Clock API is unreachable.
- Public-host HTML can be checked locally with a `Host` header against `127.0.0.1:10016` to confirm `localhost` URLs are not emitted before testing through ngrok.
- Run `tools/provider-preflight-report.php` for a read-only Clock/Stripe/PokPay provider reachability report before destructive E2E. On 2026-06-13, read-only probes reached Clock sandbox booking fetch/folio endpoints, Stripe balance auth, and PokPay staging SDK-order fetch, but live callback E2E was still blocked by missing Clock webhook secret, missing Stripe webhook secret, and localhost webhook URLs.
- Run `php tests/E2E/production-lifecycle-harness.php` for the read-only production-readiness E2E gate. It verifies current backups, non-production Stripe/PokPay/Clock configuration, public callback/webhook readiness, and reports blocked lifecycle groups without creating external records. Use `--allow-external-writes` only after the report shows no `FAIL` or `BLOCKED` prerequisites.
- Missing Clock webhook secret blocks only inbound Clock webhook replay. It should not block unrelated outbound Clock sandbox tests such as booking fetch, folio listing, positive payment accounting, negative refund accounting, or folio rereads.

## Refund Issue Areas
- `src/Engine/PaymentRefundService.php`
- `src/Database/RefundRepository.php`
- `src/Admin/payments.php`
- Staff portal payment/refund actions in `src/Portal/PortalController.php`
- Clock refund sync: `src/Provider/Clock/ClockFolioRefundSyncService.php`

### PokPay credentials are present but checkout is unavailable
- Open Payments -> Payment Settings and run `Test PokPay credentials` for the active site environment.
- `verified` means the token endpoint authenticated without creating an order or charge. `rejected`/`malformed` disables PokPay before Clock booking creation; `provider_unavailable` remains a retryable warning.
- Local and staging site profiles both use the PokPay staging API URL but retain separate credential slots. Confirm the active General -> Site environment matches the slot where credentials were saved.
- Secret inputs are intentionally blank on reload. Leaving them blank preserves the saved secret.

## Clock Reconciliation Issue Areas
- `src/Provider/Clock/ClockPaymentReconciliationService.php`
- `src/Provider/Clock/ClockFolioPaymentSyncService.php`
- `src/Provider/Clock/ClockFolioRefundSyncService.php`
- `src/Provider/Clock/ClockPaymentAccountingService.php`
- `src/Provider/Clock/ClockEndpointRegistry.php`
- `src/Provider/Clock/ClockInboundSyncController.php`
- `src/Engine/BookingLifecycleSyncService.php`
- `src/Provider/Sync/ProviderSyncJobRunner.php`
- Tables: `mhb_provider_mappings`, `mhb_provider_request_logs`, `mhb_provider_sync_jobs`

### Clock reservation amendment is queued or blocked
- Confirm room-type, physical-room, and selected rate-plan mappings exist.
- A safe amendment sequence is booking reread, documented booking update, and booking reread. Do not manually retry a write before checking whether Clock already applied it.
- `reservation_amendment` jobs are reread-first reconciliation jobs. If Clock already matches, the job updates/reconciles WordPress without another provider mutation.
- Checked-in room/type changes are blocked because the documented booking update does not create an in-house room-change entry. Perform the move in Clock and refresh the mirror.
- Price increases set `additional_payment_review_required`; decreases set `refund_or_credit_review_required`. These flags do not execute Stripe/PokPay or Clock accounting actions.

### Clock cancellation reached the website but no cancellation email sent
- Check whether the status change passed through `BookingLifecycleSyncService` and `BookingStatusEngine::updateReservationStatuses()`. Clock inbound webhooks, scheduled refresh jobs, booking upserts, and successful outbound Clock cancellation reconciliation should use that path.
- Duplicate Clock webhooks or refreshes should leave already-cancelled reservations unchanged; `BookingStatusEngine` only fires `must_hotel_booking/reservation_cancelled` when the previous status was not already `cancelled`.
- If the reservation is cancelled locally but no email was sent, inspect the `must_hotel_booking/email_dispatch_result` hook activity and email template enabled state before retrying financial or provider operations.
- If the cancelled booking was paid through Stripe/PokPay, check the Payments detail warnings and `must_refunds` for `status=refund_review_required` / `refund_type=clock_cancellation_review`. That row means the cancellation synced, but staff still needs to decide and execute any refund.

### Clock booking is cancelled but financial cleanup is incomplete
- Check reservation provider metadata `cancellation_financial_cleanup` and the admin/staff warnings.
- `manual_clock_charge_cleanup_required` means the booking status is cancelled but accommodation/service charges still need staff review in Clock.
- `manual_clock_cancellation_fee_required` means the retained fee is calculated locally but no documented automatic Clock fee-posting contract is available.
- Do not retry the gateway refund to fix a Clock accounting error. Retry the specific Clock accounting row instead.
- Compare `balance_before`, `expected_balance`, and `actual_balance` on the Payments detail screen. Reconciliation is verified only when actual equals expected.

### Clock future payment appears as manual review
- Default `clock_payment_posting_mode=auto_detect` posts future Stripe/PokPay website payments to a Clock deposit folio. It creates or reuses a booking folio with `deposit=true`, then posts the external provider payment as a folio `credit_item` on that deposit folio.
- Check Settings -> Provider -> Advanced Clock endpoint paths for `Clock payment posting mode`, `booking_deposit_folio_create`, and `booking_deposit_payment_create`.
- A saved legacy `folio_payment_only` value no longer posts online payments to the standard folio; runtime accounting still requires the deposit-folio endpoints.
- If deposit posting enters manual review, check the Clock API user has rights to create booking folios and folio credit items, and confirm the booking ID has not been cancelled/voided in Clock.
- Clock endpoint overrides in Settings -> Provider must be relative paths beginning with `/` and can only use placeholders allowed by the endpoint registry. Unsafe values are rejected instead of saved.
- Clock accounting reason codes appear in the Payments detail screen from `must_clock_folio_accounting.last_error_code`. `handled_manually` means staff recorded or reviewed the accounting in Clock outside the plugin; it is not a successful API post.
- `verified_deposit_isolated` means the payment was posted to a `deposit=true` folio and the standard folio balances were unchanged.
- A booking header that still shows `Balance 0` can be Clock's aggregate booking presentation. Check the Payment Detail folio ID, deposit flag, credit-item ID, and isolation status before assuming the standard folio was modified.

### Clock reports HTTP 429
- The shared limiter allows at most four physical Clock HTTP requests per second, including Digest authentication calls.
- Identical availability/quote/config GETs are deduplicated during one PHP request; safe catalogue/config reads use short transient caching.
- 429 responses honor `Retry-After` and retry with exponential backoff and jitter. A later successful request clears the aggregate active-error state, so a historical 429 is not a permanent production blocker.

## Admin/Staff Portal Issue Areas
- Admin menu and dashboard: `src/Admin/dashboard.php`.
- Admin data providers/actions: `src/Admin/*DataProvider.php`, `src/Admin/*Actions.php`.
- Portal routing: `src/Portal/PortalRouter.php`.
- Portal controller/actions: `src/Portal/PortalController.php`.
- Portal auth: `src/Portal/PortalAuthController.php`.
- Staff capabilities: `src/Core/StaffAccess.php`.

## CSS/JS Issue Areas
- Public booking CSS/JS: `assets/css/booking-page.css`, `assets/js/booking-page.js`, `assets/js/booking-accommodation.js`, `assets/js/booking-confirmation.js`.
- Staff portal: `assets/css/portal.css`, `assets/js/portal-quick-booking.js`.
- Admin page CSS/JS: `assets/css/admin-*.css`, `assets/js/admin-*.js`.
- Elementor widgets: `assets/css/*widget.css`, `assets/js/*widget.js`.

## Useful Commands
```bash
git diff --stat
php -l path/to/file.php
php tools/lifecycle-sync-smoke-test.php
php tools/provider-preflight-report.php
rg -n "class PaymentEngine" src
rg -n "must_hotel_booking_db_version|CREATE TABLE" src/Database/install-tables.php
rg -n "register_rest_route|stripe/webhook|pokpay/finalize" src/Engine/PaymentEngine.php
rg -n "mhb_inventory_locks|cleanupExpiredLocks|lockRoomType" src
rg -n "add_menu_page|add_submenu_page" src/Admin/dashboard.php
rg -n "PortalRegistry|getDefinitions|front-desk" src/Portal
```

## Safe WP-CLI Examples
Use only if WP-CLI is configured for the local site:
```bash
wp option get must_hotel_booking_db_version
wp cron event list --fields=hook,next_run_relative | rg "must_hotel_booking|mhb|clock"
wp rewrite flush
```

Do not run destructive DB, user, option, or post deletion commands unless explicitly requested.
