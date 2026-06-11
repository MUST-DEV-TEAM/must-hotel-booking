# Admin Dashboard And Staff Portal

## Admin Menu/Pages
Registered in `src/Admin/dashboard.php`:
- Dashboard: `must-hotel-booking`
- Reservations: `must-hotel-booking-reservations`
- Provider Logs: `must-hotel-booking-provider-logs`
- Calendar: `must-hotel-booking-calendar`
- Accommodations: `must-hotel-booking-rooms`
- Rates & Pricing: `must-hotel-booking-pricing`
- Availability Rules: `must-hotel-booking-availability-rules`
- Payments: `must-hotel-booking-payments`
- Emails: `must-hotel-booking-emails`
- Guests: `must-hotel-booking-guests`
- Coupons: `must-hotel-booking-coupons`
- Reports: `must-hotel-booking-reports`
- Settings: `must-hotel-booking-settings`

Hidden admin pages: reservation detail, add reservation, rate plans, taxes & fees.

## Dashboard Sections
- Dashboard rendering starts in `src/Admin/dashboard.php`.
- Data comes from `src/Admin/DashboardDataProvider.php`.
- Dashboard includes operational summary/cards and quick booking behavior where available.

## Booking Management
- UI: `src/Admin/reservations.php`.
- Actions: `src/Admin/ReservationAdminActions.php`.
- Data provider/query: `src/Admin/ReservationAdminDataProvider.php`, `src/Admin/ReservationAdminQuery.php`.
- Preserve reservation filters, pagination, detail URLs, status/payment state transitions, and nonces.

## Payment Management
- UI: `src/Admin/payments.php`.
- Actions: `src/Admin/PaymentAdminActions.php`.
- Data provider/query: `src/Admin/PaymentAdminDataProvider.php`, `src/Admin/PaymentAdminQuery.php`.
- Includes filters, pagination, refund forms, manual refund completion, and payment settings.

## Settings Pages
- `src/Admin/SettingsPage.php` handles settings UI including payment methods, provider mode, managed pages, staff access, frontend branding, Clock integration, diagnostics/maintenance, and mappings.
- Settings access can use staff settings capability, but `manage_options` remains the admin override.

## Action Handlers And Security
- Admin pages use nonces such as reservation, payment, settings, pricing, room, email, coupon, calendar, and availability action nonces.
- Admin capability helper: `MustHotelBooking\Admin\get_admin_capability()`.
- Admin capability enforcement: `ensure_admin_capability()`.
- Preserve `current_user_can()` and nonce checks when changing admin actions.

## Filters, Actions, Forms, Pagination
- Reservations, payments, guests, coupons, rooms, reports, and other admin pages use query/data provider classes for filters and pagination.
- Do not remove query args such as `paged`, status filters, payment filters, date filters, search, or tab state unless explicitly requested.

## Important Templates/Classes/Assets
- Shared admin UI: `assets/css/admin-ui.css`.
- Page CSS: `assets/css/admin-payments.css`, `admin-calendar.css`, `admin-rooms.css`, `admin-pricing.css`, `admin-availability.css`, `admin-emails.css`.
- Page JS: `assets/js/admin-calendar.js`, `admin-rooms.js`, `admin-quick-booking.js`, `admin-settings.js`.

## Staff Portal
For detailed staff portal routing and module notes, read `STAFF_PORTAL.md`.

- Staff portal page: `/staff`.
- Staff login page: `/staff-login`.
- Modules: dashboard, reservations, calendar, front desk, guests, payments, housekeeping, rooms & availability, reports.
- Module registry: `src/Portal/PortalRegistry.php`.
- Controller/actions: `src/Portal/PortalController.php`.
- Router: `src/Portal/PortalRouter.php`.
- Auth: `src/Portal/PortalAuthController.php`.
- Capabilities/roles: `src/Core/StaffAccess.php`.
- Templates: `frontend/templates/staff-portal.php`, `staff-login.php`, and `frontend/templates/portal/*.php`.

## UI Conventions
- Admin pages use `wrap`, `must-dashboard-page`, `must-dashboard-panel`, summary cards, filters, tables, tabs, and pagination styles.
- Staff portal uses `must-portal-*` wrappers and components.
- Keep admin and staff portal logic separate even when they share repositories/data providers.
