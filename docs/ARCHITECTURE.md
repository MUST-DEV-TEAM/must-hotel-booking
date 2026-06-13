# Architecture

## Bootstrap
- `must-hotel-booking.php`: plugin header, constants, autoloader/config includes, activation/deactivation hooks, `plugins_loaded` database upgrade and plugin init hooks.
- `includes/config.php`: compatibility aliases, legacy bootstrap include, and sorted includes for `src/Core`, `src/Database`, `src/Admin`, `src/Frontend`, `src/Engine`, and `src/Elementor`.
- `src/Core/Plugin.php`: activation, deactivation, database upgrade, managed pages, staff capabilities, updater, provider registration, portal, payment, availability, email, Clock, and support diagnostics bootstrapping.

## Main Directories
- `src/Core`: plugin lifecycle, settings, managed pages, staff access, updater, diagnostics, room catalog/view helpers, activity logging.
- `src/Database`: table installer plus repositories for rooms, reservations, payments, refunds, inventory, guests, housekeeping, reports, pricing, coupons, and provider sync state.
- `src/Engine`: business logic for reservation, availability, locks, inventory, pricing, payments, refunds, email, cancellation, coupons, booking validation, and abuse protection.
- `src/Provider`: local and Clock provider abstractions for availability, quote, reservation, booking, mappings, request logs, sync jobs, and reconciliation.
- `src/Admin`: WP-admin menu pages, data providers, actions, settings, dashboard, reports, payments, reservations, rooms, calendar, pricing, emails, guests, coupons.
- `src/Frontend`: public booking, accommodation selection, checkout, confirmation, single-room, formatting, and Clock WBE frontend integration.
- `src/Portal`: staff portal routing, auth, controller, renderer, registry, bootstrap, and access guard.
- `src/Elementor`: Elementor widgets and registration shims.
- `frontend/templates`: public page templates and staff portal partials.
- `assets/css` and `assets/js`: admin, frontend, portal, and Elementor styles/scripts.
- `lib/plugin-update-checker`: bundled updater library.

## Services And Repositories
- Reservation lifecycle: `src/Engine/ReservationEngine.php`, `src/Engine/BookingLifecycleSyncService.php`, `src/Database/ReservationRepository.php`.
- Availability/locks: `src/Engine/AvailabilityEngine.php`, `src/Engine/InventoryEngine.php`, `src/Engine/LockEngine.php`, `src/Database/AvailabilityRepository.php`, `src/Database/InventoryRepository.php`.
- Payments/refunds: `src/Engine/PaymentEngine.php`, `src/Engine/PaymentStatusService.php`, `src/Engine/PaymentRefundService.php`, `src/Database/PaymentRepository.php`, `src/Database/RefundRepository.php`.
- Providers: `src/Provider/ProviderManager.php`, `src/Provider/ProviderRegistry.php`, `src/Provider/Local/*`, `src/Provider/Clock/*`.

## Main Templates And Assets
- Public templates: `frontend/templates/booking.php`, `booking-accommodation.php`, `checkout.php`, `booking-confirmation.php`, `single-room.php`.
- Staff templates: `frontend/templates/staff-portal.php`, `staff-login.php`, and `frontend/templates/portal/*.php`.
- Shared public booking CSS: `assets/css/booking-page.css`.
- Portal CSS/JS: `assets/css/portal.css`, `assets/js/portal-quick-booking.js`.
- Admin CSS/JS: `assets/css/admin-ui.css`, `admin-payments.css`, `admin-calendar.css`, `admin-rooms.css`, and matching targeted JS files.

## Admin Pages
Registered in `src/Admin/dashboard.php`: Dashboard, Reservations, Provider Logs, Calendar, Accommodations, Rates & Pricing, Availability Rules, Payments, Emails, Guests, Coupons, Reports, Settings. Hidden pages include Reservation detail, Add Reservation, Rate Plans, and Taxes & Fees.

## Staff Portal Areas
Registered in `src/Portal/PortalRegistry.php`: dashboard, reservations, calendar, front desk, guests, payments, housekeeping, rooms & availability, reports. Routes are under `/staff`; login is `/staff-login`.

## Integrations
- Stripe and PokPay: `src/Engine/PaymentEngine.php`, payment gateway classes under `src/Engine/Payment`.
- Clock PMS: provider classes under `src/Provider/Clock`, with inbound sync, auto sync, folio payment/refund sync, and reconciliation services. Clock-originated and provider-confirmed local status transitions are routed through `BookingLifecycleSyncService` so standard reservation hooks still run; paid Stripe/PokPay cancellations from provider sync create refund-review rows for staff instead of automatic gateway refunds.
- Elementor: `src/Elementor/*` and matching widget assets.
- GitHub updater: `src/Core/Updater.php` and `lib/plugin-update-checker`.

## Important Hooks/Routes
- `plugins_loaded`: database upgrade and plugin init in `must-hotel-booking.php`.
- `must_hotel_booking/init`: fired after plugin initialization.
- `must_hotel_booking/reservation_created`, `reservation_confirmed`, `reservation_cancelled`, `payment_recorded`, `email_dispatch_result`: activity/email-related hooks found in targeted inspection.
- REST: `must-hotel-booking/v1/stripe/webhook`, `/pokpay/finalize`, `/pokpay/error`, and Clock/support diagnostic routes.
- AJAX: availability checks and staff portal quick-booking endpoints.
- Cron: inventory lock cleanup, provider sync jobs, Clock reservation auto sync.

## Important Pages/Routes
- Managed public pages: `/booking`, `/booking-accommodation`, `/checkout`, `/booking-confirmation`.
- Optional rooms page: `/rooms`.
- Staff portal pages: `/staff`, `/staff-login`.
