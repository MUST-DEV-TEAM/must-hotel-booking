# Booking Flow

## Inspection Provenance
Verified from targeted current-code inspection on 2026-06-11. Use this document for navigation, then verify behavior in the referenced files before changing booking logic.

## Managed Pages
- `/booking`: public booking search.
- `/booking-accommodation`: accommodation selection.
- `/checkout`: guest details and checkout review.
- `/booking-confirmation`: confirmation/payment step.

Managed pages are configured in `src/Core/ManagedPages.php` and installed/synced by `src/Core/Plugin.php`.

## Flow Summary
1. Guest selects dates/party on `/booking`.
2. Guest reviews available accommodations on `/booking-accommodation`.
3. Selected rooms and context are stored through frontend booking selection helpers.
4. `/checkout` validates selected rooms, dates, guest data, coupon data, and session locks.
5. `ReservationEngine::continueCheckout()` validates guest form and locks, then redirects to `/booking-confirmation`.
6. Confirmation/payment creates reservations through `ReservationEngine::createReservations()` or provider-backed equivalents.
7. Reservation rows get a generated `booking_id`, guest ID, room/listing ID, optional assigned inventory room, status, payment status, and pricing data.

## Server-Side Quote Draft
- Checkout stores a signed quote draft inside the visitor's booking-selection transient, which is bound to the unpredictable lock-session cookie.
- The draft includes a random token, room/accommodation IDs, dates, room count, guest composition, selected rates, availability, price/tax/fee, guarantee/cancellation policy snapshots, creation/expiry times, and current flow step.
- Refresh and back navigation normally restore this draft without repeating Clock availability, product-price, and guarantee-policy reads.
- Changing dates, occupancy, coupon, selected room/rate, guest allocation, expiry, or any signed value invalidates the draft and requires a refreshed quote.
- The current public form has total guests only, so its normalized composition is all adults with zero children/child ages until separate child fields are introduced.
- Final Clock reservation submission validates the server draft, restores trusted context from it, then performs fresh uncached availability, pricing, and guarantee-policy reads.
- Changed room availability, total/currency, or guarantee policy stops before guest persistence, Clock reservation creation, or payment creation and sends the guest back to review a fresh quote.

## Room/Date/Guest Selection
- Public flow code lives in `src/Frontend/booking-page.php`, `src/Frontend/accommodation-page.php`, `src/Frontend/checkout-page.php`, and `src/Frontend/confirmation-page.php`.
- Templates live in `frontend/templates/booking.php`, `booking-accommodation.php`, `checkout.php`, and `booking-confirmation.php`.
- Guest form parsing and validation use `src/Engine/BookingValidationEngine.php`.

## Availability Validation
- Availability checks use `src/Engine/AvailabilityEngine.php` and provider abstractions through `src/Provider/ProviderManager.php`.
- Physical room inventory is handled by `src/Engine/InventoryEngine.php`.
- Inventory-blocking reservation statuses are `pending`, `pending_payment`, `confirmed`, `completed`, and `blocked`.
- Non-blocking statuses are `cancelled`, `expired`, and `payment_failed`.

## Inventory Locks
- Locks use `src/Engine/LockEngine.php` and `mhb_inventory_locks`.
- Lock session cookie: `must_hotel_booking_lock_session`.
- Cleanup cron hook: `must_hotel_booking_cleanup_expired_locks`.
- Reservation creation requires an exact valid lock when inventory exists, then releases that lock after successful reservation creation.

## Reservation Creation
- Main local implementation: `src/Engine/ReservationEngine.php`.
- Repository: `src/Database/ReservationRepository.php`.
- Provider adapters: `src/Provider/Local/LocalReservationProvider.php` and `src/Provider/Clock/ClockReservationProvider.php`.
- The reservation engine creates or finds guest data, validates pricing, starts a DB transaction where supported, creates reservation rows, saves coupon data, optionally assigns physical inventory, and clears booking selection.

## Booking Statuses
- Inventory-blocking: `pending`, `pending_payment`, `confirmed`, `completed`, `blocked`.
- Inventory-non-blocking: `cancelled`, `expired`, `payment_failed`.
- Confirmed statuses: `confirmed`, `completed`.
- Additional status values may exist in UI filters or provider data. Unknown from current code inspection.

## Confirmation Behavior
- Confirmation rows are loaded by reservation IDs or booking ID through `ReservationEngine::getConfirmationRowsByIds()` and `getConfirmationRowsByBookingId()`.
- Payment status and reservation status can be updated after verified gateway events.

## Cancellation Behavior
- Cancellation rules and penalties are calculated in `src/Engine/CancellationEngine.php`.
- `CancellationFinancialCleanupService` stores an immutable cancellation-time financial snapshot in reservation provider metadata before the lifecycle status changes.
- Admin and portal actions can cancel/update reservations through their respective action handlers.
- Local reservation status transitions that come from Clock inbound webhooks, scheduled Clock refreshes, Clock booking upserts, or successful outbound Clock cancellation reconciliation go through `src/Engine/BookingLifecycleSyncService.php`. That service delegates to `BookingStatusEngine::updateReservationStatuses()` so `must_hotel_booking/reservation_cancelled` fires exactly once when a reservation first becomes `cancelled`.
- For paid Stripe/PokPay website bookings cancelled from Clock/provider sync, the lifecycle service creates a single refund-review row when no existing active/completed refund blocks it. Cancellation still releases availability through status change; actual money movement remains a staff/payment workflow.
- Availability release is tied to reservation status becoming non-blocking; preserve this behavior.
- Clock-backed outbound cancellation is reread before retry and after a successful write. WordPress is not marked cancelled until the Clock reread confirms a cancelled state.

## Clock Stay Amendments
- Admin and staff room assignment, stay-date, room-type, rate-plan, and combined accommodation amendments route through `ReservationAmendmentService`.
- Local physical-room moves acquire reservation/destination advisory locks, revalidate after locking, and use a conflict-aware atomic reservation update. Same-type moves preserve total and payment state; cancellation releases the newly assigned room through existing non-blocking status semantics.
- Clock pre-arrival amendments resolve required mappings, reread the booking, validate a provider quote, send documented booking fields inside the `booking` wrapper, and reread again before updating WordPress.
- Clock retries reread before deciding whether another mutation is needed. If Clock confirms a write but the local mirror update fails, a `reservation_amendment` reconciliation job is queued.
- Checked-in Clock room or room-type changes remain blocked because no documented operation was found for creating an in-house room-change entry.
- Increased totals are marked `additional_payment_review_required`; reduced totals are marked `refund_or_credit_review_required`. Both use `manual_review_required` and never automatically charge, refund, or post Clock credit items.
- Date-only, same-type physical-room, room-type, and combined date/accommodation operations remain distinct UI/action paths even though they share the amendment service boundary.

## Expiration Behavior
- Expired locks are cleaned by `LockEngine`.
- Expired pending online payments are handled by `PaymentEngine::cleanupExpiredPendingPaymentReservations()` on the lock cleanup cron hook.
- Stripe pending payment expiration calls `BookingStatusEngine::failPendingStripeReservations()`.
- PokPay pending payment cleanup first tries finalization, then can fail pending reservations if not paid.

## Visibility
- Admin visibility: reservations appear in admin pages/data providers under `src/Admin`.
- Staff portal visibility: modules under `/staff` show reservations, calendar, front desk, payments, guests, housekeeping, rooms & availability, and reports based on capabilities.
- Guest/customer visibility: checkout and confirmation pages only; no separate logged-in customer dashboard was found.

## Important Files
- `src/Core/ManagedPages.php`
- `src/Frontend/booking-page.php`
- `src/Frontend/accommodation-page.php`
- `src/Frontend/checkout-page.php`
- `src/Frontend/confirmation-page.php`
- `src/Engine/ReservationEngine.php`
- `src/Engine/BookingLifecycleSyncService.php`
- `src/Engine/AvailabilityEngine.php`
- `src/Engine/InventoryEngine.php`
- `src/Engine/LockEngine.php`
- `src/Core/ReservationStatus.php`
- `src/Database/ReservationRepository.php`
- `src/Database/InventoryRepository.php`

## Rules To Preserve
- Do not double-reserve inventory.
- Do not bypass locks for guest checkout unless an existing staff/admin flow explicitly does so.
- Do not confirm unpaid online bookings without verified gateway/provider logic.
- Do not release availability except through cancellation, expiration, payment failure, or existing non-blocking status transitions.
- Keep business logic in engines/providers/repositories, not templates.
- Intermediate quote display may use a 45-second Clock read cache; final pre-reservation availability, price, and guarantee-policy validation must always bypass request-local and persistent caches.

## Targeted Search Recipes
```bash
rg -n "class ReservationEngine|createReservations|createReservation" src/Engine src/Provider
rg -n "mhb_inventory_locks|lockRoomType|releaseExactLock|cleanupExpiredLocks" src
rg -n "page_booking|booking-accommodation|booking-confirmation" src/Core src/Frontend frontend/templates
rg -n "pending_payment|payment_failed|ReservationStatus" src
```
