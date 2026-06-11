# File Map

## Plugin Bootstrap
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `must-hotel-booking.php` | Plugin header/constants, activation/deactivation, `plugins_loaded` hooks. | Version, boot order, constants, update source, activation behavior. |
| `includes/autoloader.php` | Autoloading. | Class loading issues. |

## Config
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `includes/config.php` | Compatibility aliases and module includes. | New classes are not loading or bootstrap order matters. |
| `src/Core/MustBookingConfig.php` | Settings defaults, sanitization, provider/payment options. | Settings, provider mode, payment config, frontend/admin option behavior. |

## Database/Install/Upgrade
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Database/install-tables.php` | `dbDelta` table creation/upgrades and DB version option update. | Schema changes, install/upgrade issues, table/column checks. |
| `src/Database/*Repository.php` | Data access layer. | Query/data behavior for a specific domain. |

## Booking Logic
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Engine/ReservationEngine.php` | Guest creation, reservation creation, status/payment defaults, confirmation rows. | Booking lifecycle or reservation creation changes. |
| `src/Engine/BookingValidationEngine.php` | Request and guest form validation. | Checkout validation issues. |
| `src/Engine/BookingStatusEngine.php` | Status/payment status updates and payment row creation. | Confirm/cancel/expire/payment state changes. |
| `src/Provider/Local/LocalReservationProvider.php` | Provider adapter for local reservation behavior. | Local booking flow differs from engine expectations. |
| `src/Provider/Clock/ClockReservationProvider.php` | Clock-backed reservation behavior. | Clock mode booking or confirmation behavior. |

## Availability/Inventory Locks
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Engine/AvailabilityEngine.php` | Availability checks. | Date/room sellability issues. |
| `src/Engine/InventoryEngine.php` | Physical room inventory assignment/reserve/release logic. | Double-booking, room-unit assignment, physical inventory behavior. |
| `src/Engine/LockEngine.php` | Session locks and cleanup cron. | Expired holds, cookie/session locks, cleanup jobs. |
| `src/Database/InventoryRepository.php` | `mhb_rooms` and `mhb_inventory_locks` queries. | Lock or physical room SQL behavior. |

## Payment Logic
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Core/PaymentMethodRegistry.php` | Payment method catalog/default enabled states. | Enabled payment methods or labels. |
| `src/Engine/PaymentEngine.php` | Checkout method selection, Stripe/PokPay flows, REST routes, cleanup. | Payments, webhooks, finalization, gateway settings. |
| `src/Engine/Payment/PaymentFactory.php` | Gateway class selection. | Adding or normalizing payment methods. |
| `src/Engine/Payment/CashPayment.php` | `pay_at_hotel` behavior. | Pay-at-hotel checkout/refund behavior. |
| `src/Engine/Payment/StripePayment.php` | Stripe gateway behavior. | Stripe checkout/refund behavior. |
| `src/Engine/Payment/PokPayPayment.php` | PokPay gateway behavior. | PokPay checkout/refund behavior. |
| `src/Database/PaymentRepository.php` | Payment row storage and ledger reads. | Payment table/query/debug work. |

## Refund Logic
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Engine/PaymentRefundService.php` | Stripe/PokPay refunds, local refund ledger, Clock refund sync, manual completion. | Refunds or manual review behavior. |
| `src/Database/RefundRepository.php` | Refund row persistence and lookup. | Refund state/query changes. |

## Clock Reconciliation
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Provider/Clock/ClockPaymentReconciliationService.php` | Payment succeeded reconciliation with Clock/provider jobs. | Clock payment posting or local-only reconciliation. |
| `src/Provider/Clock/ClockFolioPaymentSyncService.php` | Clock folio payment posting. | Website payment should appear in Clock. |
| `src/Provider/Clock/ClockFolioRefundSyncService.php` | Clock folio refund credit items. | Refund sync/manual review. |

## Admin Dashboard/Pages
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Admin/dashboard.php` | Admin menu registration and dashboard rendering. | Admin menu, dashboard cards, quick booking. |
| `src/Admin/*AdminActions.php` | Admin action handlers. | Form submissions/actions. |
| `src/Admin/*AdminDataProvider.php` | Admin page data assembly. | Filters, rows, cards, pagination. |
| `src/Admin/payments.php` | Payment admin UI. | Payment filters, refund forms, payment settings. |
| `src/Admin/reservations.php` | Reservation admin UI. | Reservation lists/detail/actions. |
| `src/Admin/SettingsPage.php` | Settings UI and provider/payment/staff config. | Settings tabs, managed pages, integrations. |

## Staff Portal
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Portal/PortalRegistry.php` | Module definitions and deprecated route redirects. | Portal navigation/modules. |
| `src/Portal/PortalRouter.php` | `/staff` routing and query vars. | Route/permalink issues. |
| `src/Portal/PortalController.php` | Staff portal data/actions. | Portal modules, permissions, form handling. |
| `src/Portal/PortalAuthController.php` | `/staff-login` behavior. | Staff login/access issues. |
| `src/Core/StaffAccess.php` | Roles and capabilities. | Portal permissions or staff roles. |
| `frontend/templates/portal/*.php` | Portal module partials. | Portal UI changes. |

## Guest Checkout/Confirmation Pages
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Frontend/booking-page.php` | Public date/guest search and booking template loading. | `/booking` behavior. |
| `src/Frontend/accommodation-page.php` | Accommodation selection. | `/booking-accommodation` behavior. |
| `src/Frontend/checkout-page.php` | Checkout form, selected rooms, guest data, continue action. | `/checkout` behavior. |
| `src/Frontend/confirmation-page.php` | Confirmation/payment step and summary. | `/booking-confirmation` behavior. |

## Frontend Templates
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `frontend/templates/booking.php` | Booking search view. | Public booking UI. |
| `frontend/templates/booking-accommodation.php` | Accommodation result/selection view. | Room card/filter/selection UI. |
| `frontend/templates/checkout.php` | Guest checkout form. | Checkout UI/forms. |
| `frontend/templates/booking-confirmation.php` | Confirmation and payment UI. | Final confirmation/payment UI. |

## CSS Files
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `assets/css/booking-page.css` | Shared public booking/checkout/confirmation styling. | Guest UI changes. |
| `assets/css/portal.css` | Staff portal styling. | Portal UI changes. |
| `assets/css/admin-ui.css` | Shared admin dashboard UI. | Admin UI pattern changes. |
| `assets/css/admin-payments.css`, `admin-calendar.css`, `admin-rooms.css`, `admin-pricing.css`, `admin-availability.css`, `admin-emails.css` | Page-specific admin styles. | Targeted admin UI changes. |

## JS Files
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `assets/js/booking-page.js` | Public booking dynamic availability. | Booking interaction bugs. |
| `assets/js/booking-accommodation.js` | Accommodation selection/filter interactions. | Selection page JS. |
| `assets/js/booking-confirmation.js` | Confirmation/payment page interactions. | Payment UI behavior. |
| `assets/js/booking-phone-fields.js` | Checkout phone field behavior. | Phone input issues. |
| `assets/js/portal-quick-booking.js` | Portal quick booking AJAX. | Front desk quick booking issues. |
| `assets/js/admin-*.js` | Admin page interactions. | Targeted admin JS changes. |

## Email Templates
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Engine/EmailEngine.php` | Email template definitions, rendering, send triggers. | Booking/cancellation emails. |
| `src/Engine/EmailLayoutEngine.php` | Shared email layout rendering. | Email HTML/layout. |
| `src/Admin/emails.php` | Admin email template UI. | Email settings/templates UI. |

## Integrations
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Provider/Clock/*` | Clock PMS provider, API, sync, diagnostics, reconciliation. | Clock behavior. |
| `src/Engine/PaymentEngine.php` | Stripe/PokPay endpoints/settings. | Payment integration behavior. |
| `src/Core/Updater.php` | GitHub plugin updater boot/status. | Release/update behavior. |

## Elementor Widgets
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Elementor/Booking_Search_Widget.php`, `booking-search-widget.php` | Booking search widget. | Elementor search form. |
| `src/Elementor/Rooms_List_Widget.php`, `rooms-list-widget.php` | Rooms list widget. | Elementor room listing UI. |
| `src/Elementor/Rooms_Text_Grid_Widget.php`, `rooms-text-grid-widget.php` | Room text grid widget. | Elementor category/text grid UI. |

## GitHub Updater/Plugin-Update-Checker
| Path | Purpose | Inspect when |
| --- | --- | --- |
| `src/Core/Updater.php` | Builds update checker from GitHub constants. | Release metadata and updater status. |
| `lib/plugin-update-checker` | Bundled library. | Library loading/version issues only. |
