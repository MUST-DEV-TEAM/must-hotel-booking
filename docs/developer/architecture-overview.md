# Architecture Overview

**Plugin:** MUST Hotel Booking
**Version documented:** 0.3.45
**Namespace root:** `MustHotelBooking\`

---

## System Layers

```
┌─────────────────────────────────────────────────────────────┐
│  Entry points                                               │
│  WP Admin panel  |  Frontend pages  |  Staff portal (SPA)  │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────┐
│  Engine / Service layer  (src/Engine/)                      │
│  AvailabilityEngine · PricingEngine · ReservationEngine     │
│  PaymentEngine · LockEngine · EmailEngine                   │
│  BookingStatusEngine · CancellationEngine · InventoryEngine │
│  RatePlanEngine · BookingValidationEngine                   │
│  AvailabilityRulesService · CouponService · PaymentFactory  │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────┐
│  Repository layer  (src/Database/)                          │
│  ReservationRepository · RoomRepository · GuestRepository   │
│  PaymentRepository · PricingRuleRepository                  │
│  AvailabilityRepository · InventoryRepository               │
│  RatePlanRepository · CancellationPolicyRepository          │
│  CouponRepository · HousekeepingRepository                  │
│  RoomCategoryRepository · ReportRepository                  │
│  ActivityRepository                                         │
└────────────────────────────┬────────────────────────────────┘
                             │
┌────────────────────────────▼────────────────────────────────┐
│  Database  (MySQL via wpdb)                                 │
│  ~20 plugin-managed tables  (see database-schema.md)        │
└─────────────────────────────────────────────────────────────┘
```

The **Core layer** (`src/Core/`) cuts across all layers and provides:
- Plugin bootstrap and lifecycle (`Plugin`, `Updater`)
- Shared configuration (`MustBookingConfig`)
- Managed page registry (`ManagedPages`)
- Access control definitions and synchronisation (`StaffAccess`)
- Value-object types (`ReservationStatus`, `RoomData`, `BookingRules`, `RoomCatalog`, `RoomViewBuilder`)
- Activity logging (`ActivityLogger`)
- Payment method registry (`PaymentMethodRegistry`)

---

## Directory Structure

```
must-hotel-booking/
├── must-hotel-booking.php      Plugin entry point; defines constants; registers hooks
├── includes/
│   ├── autoloader.php          PSR-4-style autoloader for the MustHotelBooking\ namespace
│   └── config.php              Loads legacy-functions.php; eager-loads all src/ module
│                               directories (alphabetical within each dir); registers
│                               class_alias() compat shims for MustBookingConfig
├── src/
│   ├── Core/                   Plugin lifecycle, config, managed pages, access control
│   ├── Database/               Repositories, install-tables.php, migration helpers
│   ├── Engine/                 Business logic engines and services
│   ├── Admin/                  WP-admin page renderers, data providers, action handlers
│   ├── Frontend/               Public-facing booking flow templates and helpers
│   ├── Portal/                 Staff portal router, renderer, access guard, controller
│   ├── Elementor/              Elementor widget integration (loaded in config.php)
│   └── legacy-functions.php    Global helper shims for backward compatibility
├── admin/                      Legacy admin PHP files (superseded by src/Admin/)
├── assets/                     CSS, JS, images for admin, frontend, and portal
├── database/                   (Directory present; migration SQL or seed files — see
│                               managed-pages-and-migrations.md for detail)
├── docs/                       End-user and developer documentation
├── elementor/                  Legacy Elementor files (superseded by src/Elementor/)
├── engine/                     Legacy engine files (superseded by src/Engine/)
├── frontend/
│   └── templates/              PHP template files loaded by managed pages
│       ├── booking.php
│       ├── booking-accommodation.php
│       ├── checkout.php
│       ├── booking-confirmation.php
│       ├── staff-portal.php
│       └── staff-login.php
├── lib/
│   └── plugin-update-checker/  Yahnis Elsts Plugin Update Checker (GitHub updater)
├── templates/                  Additional shared templates
└── uninstall.php               Clean-up on plugin deletion
```

---

## Major Classes

### Core layer (`MustHotelBooking\Core\`)

| Class | Responsibility |
|---|---|
| `Plugin` | Static lifecycle handler. `activate()`, `deactivate()`, `maybeUpgradeDatabase()`, `initPlugin()` are the four entry points called from the main file and `plugins_loaded`. |
| `MustBookingConfig` | Single-option settings store (option key: `must_hotel_booking_settings`). Provides typed getters for every setting group. Internally organises settings into named groups: `general`, `booking_rules`, `checkin_checkout`, `payments_summary`, `staff_access`, `branding`, `managed_pages`, `notifications_summary`, `maintenance`. Schema version constant: `3`. |
| `ManagedPages` | Tracks and auto-creates the seven plugin-managed WordPress pages. Each page maps a `settingKey` (stored inside `managed_pages` in the config option) to a WP page ID. Provides URL and health-check helpers. |
| `StaffAccess` | Defines the five custom WP roles (`mhb_front_desk`, `mhb_supervisor`, `mhb_housekeeping`, `mhb_finance`, `mhb_ops_manager`) and the full capability constant set. `syncRoleCapabilities()` reconciles WP roles with the configured capability matrix on every boot. |
| `ActivityLogger` | Hooks onto internal plugin actions (`must_hotel_booking/reservation_created`, `reservation_confirmed`, `reservation_cancelled`, `payment_recorded`, `email_dispatch_result`) and writes rows to `must_activity_log`. |
| `Updater` | Wraps the Yahnis Elsts Plugin Update Checker. Reads constants from the main plugin file; calls `PucFactory::buildUpdateChecker()` once per request via `boot()`. |
| `BookingRules` | Static helper for reading booking-rules settings (min/max nights, guest limits, room limits). |
| `RoomCatalog` | Static helper for normalising booking categories and retrieving the room catalogue. |
| `ReservationStatus` | Value-object enumerating and classifying reservation status strings. |
| `RoomData` / `RoomViewBuilder` | Data-transfer and view-construction helpers for room display. |
| `PaymentMethodRegistry` | Registry of available payment method labels and configurations. |

### Database / Repository layer (`MustHotelBooking\Database\`)

All concrete repositories extend `AbstractRepository`, which injects `$wpdb` and exposes a `table(string $suffix)` helper that prepends `{prefix}must_`. Note: repositories for the `mhb_` prefixed tables do **not** use this helper and address those tables directly.

| Repository | Primary table(s) |
|---|---|
| `RoomRepository` | `must_rooms`, `must_room_meta`, `must_room_categories` |
| `RoomCategoryRepository` | `must_room_categories` |
| `InventoryRepository` | `mhb_rooms`, `mhb_room_types` |
| `ReservationRepository` | `must_reservations` |
| `GuestRepository` | `must_guests` |
| `PaymentRepository` | `must_payments` |
| `PricingRuleRepository` | `must_pricing` |
| `AvailabilityRepository` | `must_availability` |
| `RatePlanRepository` | `mhb_rate_plans`, `mhb_room_type_rate_plans`, `mhb_rate_plan_prices`, `mhb_seasons`, `mhb_seasonal_prices` |
| `CancellationPolicyRepository` | `mhb_cancellation_policies` |
| `CouponRepository` | `must_coupons` |
| `HousekeepingRepository` | `must_room_housekeeping_statuses`, `must_room_housekeeping_issues`, `must_housekeeping_handoffs` |
| `ActivityRepository` | `must_activity_log` |
| `ReportRepository` | Cross-table aggregation queries (read-only) |

Repository singletons are exposed as global functions defined in `src/Engine/01-repositories.php`:
`get_room_repository()`, `get_reservation_repository()`, `get_guest_repository()`, `get_payment_repository()`, `get_rate_plan_repository()`, etc.

Service classes in this layer:

| Class | Purpose |
|---|---|
| `DefaultInventoryUnitSyncService` | One-time backfill that ensures every `must_rooms` listing has a corresponding `mhb_rooms` inventory unit. Gated by option `must_hotel_booking_default_inventory_unit_sync_marker`. |
| `AccommodationCategoryUpgradeService` | Runs inside `install_tables()` to migrate legacy mixed-model data and ensure category consistency. |

### Engine layer (`MustHotelBooking\Engine\`)

All engine classes are `final` with only `static` methods (service-function style).

| Class | Responsibility |
|---|---|
| `AvailabilityEngine` | Core availability check: validates dates, delegates to `AvailabilityRulesService`, returns available rooms for a given stay. |
| `AvailabilityRulesService` | Evaluates rule rows from `must_availability` (min-stay, max-stay, closed-arrival, closed-departure, maintenance-block). Returns a structured result array. |
| `AvailabilityAjaxController` | Registers `wp_ajax_` / `wp_ajax_nopriv_` handlers that expose availability data to the booking front end. |
| `PricingEngine` | Calculates booking totals, nightly rates, season modifiers, extra-guest charges, coupon discounts. Supports both the legacy `must_pricing` rule model and the new rate-plan model. |
| `RatePlanEngine` | Resolves which rate plans apply to a room type, builds a fallback "base price" plan when none are assigned. |
| `ReservationEngine` | Constructs reservation note strings from guest form data; used during checkout. |
| `BookingValidationEngine` | Parses and validates incoming booking request context (dates, category, room count, guest count). |
| `BookingStatusEngine` | Transitions reservation and payment statuses; fires `must_hotel_booking/reservation_confirmed` and `must_hotel_booking/reservation_cancelled` actions; bridges Stripe return sessions. |
| `PaymentEngine` | Manages payment flow lifecycle: creates Stripe Checkout sessions, stores pending-payment flow data in WP session/option, handles webhook callbacks, and resolves payment method via `PaymentFactory`. |
| `PaymentStatusService` | Resolves human-readable labels for payment statuses. |
| `LockEngine` | Manages short-lived inventory locks in `mhb_inventory_locks`. TTL is 10 minutes. Uses a cookie (`must_hotel_booking_lock_session`) or WP session token as the session identifier. Registers a WP-Cron job (`must_hotel_booking_cleanup_expired_locks`) for periodic cleanup. |
| `InventoryEngine` | Queries available physical inventory units (`mhb_rooms`) for a room type and stay period, excluding locked units. |
| `CancellationEngine` | Calculates cancellation penalty based on the rate plan's cancellation policy (`mhb_cancellation_policies`). |
| `CouponService` | Validates coupon codes against `must_coupons` rules; computes discount amounts. |
| `EmailEngine` | Sends transactional emails using configurable templates. Supports placeholder substitution. Fires `must_hotel_booking/email_dispatch_result`. |
| `EmailLayoutEngine` | Renders the HTML email shell (classic or custom layout). |
| `Payment\PaymentFactory` | Factory that resolves a `PaymentInterface` implementation (`CashPayment` or `StripePayment`) by method key. |
| `Payment\StripePayment` | Implements `PaymentInterface`; creates Stripe Checkout sessions via `PaymentEngine::createStripeCheckoutSession()`. |
| `Payment\CashPayment` | Implements `PaymentInterface`; records a pay-at-hotel payment row without external call. |

### Admin layer (`MustHotelBooking\Admin\`)

The admin layer is structured as page-renderers (PHP include files: `rooms.php`, `reservations.php`, `calendar.php`, `pricing.php`, etc.) paired with typed DataProvider, Query, and Actions classes.

| Pattern | Example classes |
|---|---|
| Page renderer | `src/Admin/reservations.php`, `calendar.php`, `pricing.php`, `guests.php`, `payments.php`, `coupons.php`, `taxes.php`, `emails.php`, `reports.php`, `rate-plans-page.php`, `settings.php`, `dashboard.php`, `quick-booking.php`, `availability-rules.php` |
| DataProvider | `ReservationAdminDataProvider`, `AccommodationAdminDataProvider`, `PricingAdminDataProvider`, `CalendarDataProvider`, `GuestAdminDataProvider`, `PaymentAdminDataProvider`, `HousekeepingAdminDataProvider`, `DashboardDataProvider`, `ReportAdminDataProvider`, `EmailAdminDataProvider`, `CouponAdminDataProvider` |
| Query builder | `AccommodationAdminQuery`, `AvailabilityAdminQuery`, `CalendarViewQuery`, `CouponAdminQuery`, `EmailAdminQuery`, `GuestAdminQuery`, `PaymentAdminQuery`, `PricingAdminQuery`, `ReportAdminQuery` |
| Actions handler | `ReservationAdminActions`, `AccommodationAdminActions`, `AvailabilityAdminActions`, `GuestAdminActions`, `PaymentAdminActions`, `PricingAdminActions`, `CouponAdminActions`, `EmailAdminActions` |
| Utilities | `SettingsPage`, `SettingsDiagnostics`, `SimpleXlsxWorkbook`, `AccommodationImportExportService`, `AccommodationWorkbookSchema`, `DangerousResetService` |

### Frontend layer (`MustHotelBooking\Frontend\`)

Thin PHP modules registered by `config.php`. Each file renders or supports a managed page:

| File | Page |
|---|---|
| `booking-page.php` | Booking landing (date/guest selector) |
| `booking-selection.php` | Accommodation selection step |
| `accommodation-page.php` | Room listing / single-room detail |
| `single-room-page.php` | Single room detail |
| `checkout-page.php` | Checkout form and payment |
| `checkout-country-directory.php` | Country selection helper |
| `confirmation-page.php` | Post-booking confirmation |
| `formatting.php` | Shared formatting helpers (currency, dates) |

### Portal layer (`MustHotelBooking\Portal\`)

| Class | Responsibility |
|---|---|
| `PortalBootstrap` | Called from `Plugin::initPlugin()`; delegates to `PortalRouter` and `PortalAccessGuard`. |
| `PortalRouter` | Registers WP rewrite rules for `/staff/` and `/staff-login/`. Exposes the `must_hotel_booking_portal_route` query var. Tracks a rewrite signature version (current: `2`) to detect when rules need flushing. |
| `PortalAccessGuard` | Hooks `template_redirect` to enforce login and role checks. Hides the WP admin bar and admin area for staff-only roles. Filters the login redirect. |
| `PortalRegistry` | Registers portal modules and their metadata. |
| `PortalAuthController` | Handles portal login/logout form submissions. |
| `PortalRenderer` | Renders the portal SPA shell and module views. |
| `PortalController` | Prepares data for each portal module by delegating to the appropriate Admin DataProvider/Actions classes. |

---

## Plugin Bootstrap Flow

### File load (on every page request)

1. WordPress loads `must-hotel-booking.php`.
2. Constants are defined: `MUST_HOTEL_BOOKING_VERSION`, `MUST_HOTEL_BOOKING_FILE`, `MUST_HOTEL_BOOKING_PATH`, `MUST_HOTEL_BOOKING_URL`, `MUST_HOTEL_BOOKING_BASENAME`, and the updater/GitHub constants.
3. `includes/autoloader.php` is required — registers the `MustHotelBooking\` PSR-4 autoloader.
4. `includes/config.php` is required — registers class aliases for `MustBookingConfig`, loads `src/legacy-functions.php`, then eager-loads all lowercase-named `.php` files from `src/Core/`, `src/Database/`, `src/Admin/`, `src/Frontend/`, `src/Engine/`, `src/Elementor/` (sorted alphabetically; `index.php` and `00-dependencies.php` are skipped).
5. `register_activation_hook` points to `Plugin::activate()`.
6. `register_deactivation_hook` points to `Plugin::deactivate()`.
7. `add_action('plugins_loaded', Plugin::maybeUpgradeDatabase, priority 5)` is registered.
8. `add_action('plugins_loaded', Plugin::initPlugin)` is registered.

### On `plugins_loaded` (priority 5): `Plugin::maybeUpgradeDatabase()`

- Reads `must_hotel_booking_db_version` option.
- If stored version is less than `MUST_HOTEL_BOOKING_VERSION`, calls `install_tables()` (which calls `dbDelta()` for all tables and then `AccommodationCategoryUpgradeService::run()`).
- Updates `must_hotel_booking_db_version` to the current version.

### On `plugins_loaded` (default priority): `Plugin::initPlugin()`

Executes in order:
1. `DefaultInventoryUnitSyncService::maybeRunBackfill()` — one-time sync of `mhb_rooms` units from `must_rooms`.
2. `ManagedPages::sync()` — validates each auto-create managed page; repairs missing or trashed pages.
3. `StaffAccess::syncRoleCapabilities()` — reconciles WP role capabilities with the configured matrix.
4. `Updater::boot()` — initialises the GitHub update checker if enabled and configured.
5. `ActivityLogger::registerHooks()` — hooks reservation and payment lifecycle events.
6. `LockEngine::registerHooks()` — registers the cleanup cron hook handler.
7. `PaymentEngine::registerHooks()` — registers AJAX handlers for payment flow and Stripe webhook.
8. `AvailabilityAjaxController::registerHooks()` — registers availability AJAX endpoints.
9. `EmailEngine::registerHooks()` — hooks confirmation/cancellation email dispatch.
10. `PortalBootstrap::registerHooks()` — registers portal rewrite rules, access guard, and asset enqueueing.
11. Fires `do_action('must_hotel_booking/init')` — extension point for third-party code.

### On activation: `Plugin::activate()`

1. `install_tables()` — creates or updates all database tables via `dbDelta()`.
2. `DefaultInventoryUnitSyncService::maybeRunBackfill()`.
3. `ManagedPages::install()` — creates any missing auto-create pages via `wp_insert_post()`.
4. `StaffAccess::syncRoleCapabilities()` — ensures roles exist with correct capabilities.
5. `LockEngine::scheduleCleanupCron()` — registers the recurring cron event.
6. `PortalBootstrap::registerRewriteRules()` — adds rewrite rules before the flush.
7. `flush_rewrite_rules()`.

### On deactivation: `Plugin::deactivate()`

1. `LockEngine::unscheduleCleanupCron()` — removes the cron event.
2. `flush_rewrite_rules()`.
