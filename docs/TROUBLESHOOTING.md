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

## Payment/Webhook/Finalization Issue Areas
- `src/Engine/PaymentEngine.php`
- `src/Engine/PaymentStatusService.php`
- `src/Engine/BookingStatusEngine.php`
- `src/Database/PaymentRepository.php`
- Stripe route: `must-hotel-booking/v1/stripe/webhook`
- PokPay routes: `must-hotel-booking/v1/pokpay/finalize`, `must-hotel-booking/v1/pokpay/error`
- Check webhook secrets, REST nonce behavior, payment row transaction IDs, and reservation IDs.

## Refund Issue Areas
- `src/Engine/PaymentRefundService.php`
- `src/Database/RefundRepository.php`
- `src/Admin/payments.php`
- Staff portal payment/refund actions in `src/Portal/PortalController.php`
- Clock refund sync: `src/Provider/Clock/ClockFolioRefundSyncService.php`

## Clock Reconciliation Issue Areas
- `src/Provider/Clock/ClockPaymentReconciliationService.php`
- `src/Provider/Clock/ClockFolioPaymentSyncService.php`
- `src/Provider/Clock/ClockFolioRefundSyncService.php`
- `src/Provider/Clock/ClockInboundSyncController.php`
- `src/Provider/Sync/ProviderSyncJobRunner.php`
- Tables: `mhb_provider_mappings`, `mhb_provider_request_logs`, `mhb_provider_sync_jobs`

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
