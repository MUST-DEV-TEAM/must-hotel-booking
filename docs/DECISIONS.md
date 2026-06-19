# Decisions

## 2026-06-19 - Clock accommodation-charge cleanup remains manual until charge-level ownership is durable
- Decision: Keep Clock accommodation-charge cleanup as a manual-only workflow in MUST Hotel Booking.
- Reason: The published Clock contracts document generic charge voiding and compensating charge creation, but the plugin does not currently persist accommodation charge ids, ownership markers, template ids, or cleanup correlation rows required to prove that a specific charge is eligible for automatic mutation.
- Affected areas: cancellation financial cleanup, Clock folio operations, future amendment/cancellation accounting automation, admin review workflow.
- Implementation note: Preserve the existing `manual_clock_charge_cleanup_required` and `manual_clock_cancellation_fee_required` states until Clock confirms safe accommodation-charge reversal rules and the plugin adds a dedicated charge-cleanup persistence layer.

## 2026-06-19 - Clock public export refresh adds booking-associated bulk charge posting
- Decision: Treat `POST /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source/bulk` as documented Clock behavior and update the research cache to prefer the booking-associated bulk posting path for the POS booking-charge guide.
- Reason: The live public Clock collection now includes `booking_charges_by_source - BULK CREATE` and updates `2. CHARGE BOOKING` to use it. That is a durable accounting contract change, but it still does not document a checked-in room-move write path or coupon-native sync.
- Affected areas: Clock research docs, cancellation-fee/accounting notes, integration boundaries.
- Implementation note: Keep checked-in/current-room and future scheduled room moves blocked; retain partial support for accommodation-charge cleanup, cancellation-fee accounting, and discount synchronization.

## 2026-06-19 - Clock Postman collection narrows, but does not remove, cancellation/accounting blockers
- Decision: Keep checked-in/current-room moves and future scheduled room changes blocked, but treat Clock accommodation-charge cleanup, cancellation-fee accounting, and discount mirroring as partially documented through generic charge contracts rather than as completely undocumented.
- Reason: The full cached Clock Postman collection documents `charge - DELETE`, a compensating negative-quantity `charges/bulk` pattern, and generic booking/folio charge creation, but it still does not document a provider write for `booking_room_changes`, `current_room_id`, or coupon-native synchronization.
- Affected areas: Clock cancellation/review metadata, integration docs, future accounting automation, amendment support boundaries.
- Implementation note: Continue requiring manual review flags until business rules define eligible charge IDs, folio targeting, and closed-folio handling for any future automatic cleanup/posting flow.

## 2026-06-18 - Reservation amendments use one safe service boundary
- Decision: Route local room moves and local/Clock accommodation amendments through `ReservationAmendmentService`; use a destination lock plus conflict-aware atomic update locally and GET -> documented PUT -> GET for Clock.
- Reason: The prior staff move path separated availability validation from assignment, admin lacked a local move path, and Clock retries could repeat mutations without first confirming provider state.
- Boundaries: Same-type moves preserve pricing/payment state; room-type/date/rate changes reprice; checked-in Clock room/type changes remain blocked; all financial differences require manual review.
- Schema impact: None. Audit and amendment state use existing reservation metadata, activity, and provider-sync job storage.

## 2026-06-18 - Clock inbound events are acknowledged only after durable processing
- Decision: Do not mark an SNS `MessageId` successful when the Clock booking fetch or local mirror update failed. Return a retryable response and retain failed attempts for later delivery.
- Reason: Clock PUSH messages normally carry only a booking ID. A transient API failure previously produced a successful webhook response and permanent deduplication without applying the provider change.
- Affected areas: Clock inbound controller, booking-event adapter, request-log deduplication, subscription confirmation, diagnostics, replay tests.
- Implementation note: Documented status-specific subjects may apply a safe status fallback while queuing a detail refresh. Detail-only events require fetched booking data. Database advisory locks serialize concurrent duplicate deliveries.

## 2026-06-14 - Amendment and accounting financial mismatches require manual review
- Decision: Stay amendments that change Clock/provider pricing do not automatically create extra charges, partial refunds, or Clock credit items without an explicit business rule.
- Reason: Production readiness requires cancellation/refund separation and forbids invented automatic financial adjustment policies.
- Affected areas: Clock stay amendments, payment reconciliation, refund/credit review, Clock accounting, admin provider mirror diagnostics.
- Implementation note: Increased amended totals are recorded as `additional_payment_review_required`; reduced amended totals are recorded as `refund_or_credit_review_required`; both require staff review. Ambiguous Clock folio/accounting targets are manual-review states; reconciliation may retry safe transient failures but must not auto-repair uncertain financial mismatches by creating payments, refunds, or Clock accounting rows.

## 2026-06-11 - Initial Codex knowledge base setup
- Decision: The project now uses `AGENTS.md` plus concise root-level `docs/` files as a Codex knowledge base.
- Reason: Future Codex tasks should read relevant docs before searching code, reduce token usage, and preserve booking/payment/dashboard/database behavior.
- Affected areas: Codex workflow, task routing, architecture notes, file map, booking flow, payments, admin/staff/customer docs, UI rules, database notes, integrations, troubleshooting, task logging.

## 2026-06-11 - Knowledge base hardening pass
- Decision: Treat docs as navigation guidance and current code as the final authority when conflicts appear.
- Reason: Future Codex tasks need the docs to reduce scanning without trusting stale behavior blindly.
- Affected areas: `AGENTS.md`, `docs/INDEX.md`, booking, payment, database, admin/staff portal docs, decisions, task log.

## 2026-06-11 - Clock booking cancellation endpoint
- Decision: Use Clock PMS `PUT /bookings/{booking_id}` with `booking.status = canceled` for provider cancellation sync.
- Reason: Clock docs list canceled/no-show as booking statuses and the sandbox API accepted the update without closing folios.
- Affected areas: Clock reservation provider, Clock config defaults, cancellation diagnostics, sandbox tests.

## 2026-06-12 - Provider fee snapshots and default refund math
- Decision: Store provider fee snapshots on successful Stripe/PokPay payments and default refunds to `paid amount - provider fee - cancellation fee`, never below zero.
- Reason: Refunds should retain payment processing fees by default and should not silently refund the full paid amount when the provider fee is unknown.
- Affected areas: payment schema, Stripe/PokPay finalization, refund service, admin payment workspace, customer cancellation page, support diagnostics.

## 2026-06-12 - Customer online cancellation window
- Decision: Customer cancellation links allow automatic cancellation/refund only when check-in is at least the configured policy window away, currently 21 days by default; inside the window, cancellation is hotel/staff handled.
- Reason: The business rule requires online self-service before 21 days and manual support inside 21 days.
- Affected areas: booking confirmation cancellation route/template, refund defaults, Clock cancellation sync.

## 2026-06-12 - Current-version schema repair
- Decision: Run an idempotent payment/refund schema repair even when the stored DB version is already greater than or equal to the plugin version.
- Reason: Release candidates can add columns while local/live databases already store the current version, so relying only on version-gated `dbDelta()` can leave required columns missing.
- Affected areas: plugin database upgrade bootstrap, `must_payments`, `must_refunds`, support diagnostics.

## 2026-06-13 - PokPay automatic refund endpoint
- Decision: Use PokPay's documented merchant SDK-order refund endpoint before falling back to manual dashboard verification.
- Reason: PokPay's public Postman/API collection verified `POST /merchants/{merchantId}/sdk-orders/{sdkOrderId}/refund` with `refundReason` and optional `refundAmount`, and a staging full refund succeeded with the SDK order returning `REFUNDED`.
- Affected areas: PokPay refund service, admin/manual refund workflow, payment docs, troubleshooting notes.

## 2026-06-13 - Clock future payments post to deposit folios
- Decision: In `auto_detect`, future Stripe/PokPay website payments are posted to a Clock booking deposit folio instead of a normal folio.
- Reason: Clock's public API collection exposes booking folio creation with `booking_folio.deposit=true` and folio `credit_items`; a sandbox API trial created a deposit folio, posted the external payment, prevented duplicate posting, and reversed it with a deposit-folio negative payment.
- Affected areas: Clock endpoint registry, Clock folio service, Clock payment accounting service, payment docs, troubleshooting notes.

## 2026-06-13 - Clock cancellation status transitions use lifecycle service
- Decision: Clock inbound sync, scheduled refresh/upsert, and successful outbound Clock cancellation reconciliation apply local reservation status transitions through `BookingLifecycleSyncService`.
- Reason: Direct repository status updates bypassed `BookingStatusEngine`, so Clock-originated cancellations could skip `must_hotel_booking/reservation_cancelled` and the configured cancellation emails.
- Affected areas: Clock inbound sync, Clock reservation sync, Clock reconciliation, cancellation emails, booking lifecycle docs.

## 2026-06-13 - Clock/provider paid cancellations require refund review
- Decision: A paid Stripe/PokPay website booking cancelled from Clock/provider sync creates a single `refund_review_required` row instead of automatically refunding.
- Reason: Clock status does not prove the desired gateway refund amount/timing, and automatic money movement must remain behind explicit payment/refund workflows. The review row makes held funds visible to staff while preserving idempotent cancellation sync.
- Affected areas: booking lifecycle sync, refund table, admin payment warnings, Clock inbound/refresh cancellation handling.
# 2026-06-18 - Cancellation financial cleanup boundaries
- Cancellation, policy calculation, gateway refund, Clock payment accounting, Clock charge cleanup, cancellation-fee accounting, and refund review remain separate states.
- Store one immutable cancellation-time financial snapshot in reservation provider metadata and reuse it for operational review.
- Do not finalize outbound Clock cancellation locally without a provider reread confirming cancelled state.
- Verify each Clock credit-item operation against `balance before + posted amount`; do not assume the correct final balance is zero.
- Generic Clock charge void/create contracts are now documented in the cached Postman collection, but automatic accommodation-charge cleanup and cancellation-fee posting remain manual until the project has an approved charge-selection and folio-targeting policy.
