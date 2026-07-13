# Project Context

## Evidence baseline

- Current branch inspected: `main`.
- Current commit/tag: `dcff3b4` / `v0.4.90`.
- Plugin header and version constant: `0.4.90`.
- Evidence date: 2026-07-13.
- Current executable source outranks documentation. Production configuration and database state were not inspected.

## Product purpose

MUST Hotel Booking is a WordPress plugin for hotel sales and operations. It covers accommodation catalogues, physical room inventory, availability, pricing, reservations, guest records, checkout, payments, refunds, email, administration, staff operations, reporting, and optional Clock PMS integration.

## Current scope and status

| Capability | Status | Evidence-based boundary |
| --- | --- | --- |
| Local public booking | Implemented | Managed booking pages, local availability/quote/reservation providers, locks, pricing, coupons, checkout and confirmation exist. |
| Clock-backed public booking | Implemented with critical risks | Online payment uses a local pending mirror and post-payment Clock fulfillment. Paid-outcome durability, callback concurrency, and recovery are incomplete. |
| Stripe | Implemented | Checkout sessions, signed webhooks, return verification, refunds, fee snapshots, and Clock accounting exist. Production configuration is unknown. |
| PokPay | Implemented | SDK order creation/fetch/finalization, embedded/redirect modes, refund attempt/manual fallback, and credential verification exist. Production configuration is unknown. |
| Pay at Hotel | Implemented, opt-in | Disabled by default. Explicit enablement creates confirmed/unpaid reservations and manual cash refund behavior. |
| On-request booking | Not found as a distinct public mode | Do not equate staff cancellation approval or Pay at Hotel with a separate on-request lifecycle. |
| WordPress admin | Implemented | Reservations, provider logs, calendar, accommodations, pricing, availability, payments, email, guests, coupons, reports and settings. |
| Staff portal | Implemented, configurable | `/staff` and `/staff-login`; nine capability-gated modules. |
| Guest account dashboard | Not found | Guests use the booking, checkout, confirmation and signed cancellation-link surfaces; there is no separate logged-in customer account area. |
| Clock inbound/outbound sync | Implemented with partial external acceptance | SNS inbound, scheduled fallback, catalog/availability sync, amendments and cancellation paths exist; some operations remain manual or unverified externally. |
| Clock accounting | Implemented with manual-review boundaries | Verified deposit-folio payment/refund paths exist; ambiguous targets, accommodation-charge cleanup and cancellation-fee posting remain manual. |
| Elementor | Implemented | Booking search, rooms list and rooms text-grid widgets. |
| Release updater/package | Implemented | Bundled plugin-update-checker, GitHub release contract, and package workflow. |

## Supported booking modes

### Provider mode

- `local`: WordPress data is used for availability, pricing and reservations.
- `clock`: Clock PMS supplies provider-backed availability, quote and reservation behavior; WordPress keeps local mirror/content/payment state.

Clock can be configured to fall back to local behavior when unavailable. That option can create split-brain inventory if enabled during a Clock outage and therefore requires an explicit operational decision.

### Website flow mode

- `plugin_checkout`: managed multi-step plugin booking flow.
- `clock_wbe_inline`: Clock WBE inline mode, which can bypass legacy plugin booking-flow pages.

### Payment mode

- `stripe` and `pokpay`: online, pending until authoritative server verification.
- `pay_at_hotel`: offline, explicit opt-in, confirmed with payment due at the property.

## Users and surfaces

- Guests: `/booking`, `/booking-accommodation`, `/checkout`, `/booking-confirmation`, optional `/rooms`, and cancellation links.
- WordPress administrators: plugin admin menu and hidden detail/create pages.
- Hotel staff: capability-gated `/staff` portal and `/staff-login`.
- External systems: Stripe, PokPay, Clock PMS/AWS SNS, WordPress mail, Elementor, and GitHub releases.

## Domain terminology

| Term | Meaning |
| --- | --- |
| Accommodation / sellable room | Commercial listing stored primarily in `must_rooms`. |
| Room type | Internal/provider-facing inventory category in `mhb_room_types`; may mirror a sellable room. |
| Physical room / inventory unit | Assignable unit in `mhb_rooms`. |
| Reservation | Local row in `must_reservations`; may be local, a pending Clock mirror, or a Clock mirror with provider IDs. |
| Booking ID | Local human-facing `MHB-...` reference; not the same as a Clock booking ID or payment transaction ID. |
| Payment row | Local ledger projection in `must_payments`; distinct from reservation `payment_status`. |
| Refund row | Explicit refund/review state in `must_refunds`; cancellation alone is not a refund. |
| Provider mapping | Local/external identity mapping in `mhb_provider_mappings`. |
| Accounting row | Clock payment/refund posting and verification state in `must_clock_folio_accounting`. |
| Quote draft | Signed, session-bound snapshot used to detect tampering/staleness before final creation. |

## Source-of-truth boundaries

- Clock mode: Clock owns Clock identifiers, live availability/restrictions, provider reservation state, and supported provider amendments.
- Website: WordPress owns presentation/content, managed pages, local mirror state, staff UI, and operational metadata.
- Payment providers/local ledger: Stripe/PokPay results and local payment/refund rows own payment transaction evidence.
- A Clock booking status, folio balance, browser return, or local reservation flag is not interchangeable with payment proof.

## Current environments

Code supports local, staging and production payment credential slots, plus Clock sandbox, production and custom endpoints. The current saved environment, callback reachability, credentials, API rights, WordPress cron configuration, database version/shape, and theme overrides are **unverified**.

## Current limitations and unresolved risks

1. Public confirmation lookups are not bound to a guest session or signed view token and can expose guest/reservation data by ID.
2. A forged PokPay return query can fail another pending reservation.
3. Provider-paid evidence is written locally only after Clock fulfillment succeeds; a Clock failure can strand a captured payment without an internal recovery job.
4. Concurrent duplicate callbacks may create duplicate Clock bookings; current payment-first tests do not behaviorally test this.
5. Concurrent refund and ambiguous Clock accounting retries have unresolved idempotency risks.
6. Selected local rate-plan propagation and final cancellation-policy comparison have code/documentation discrepancies requiring focused tests.
7. Clock checked-in room/type moves, accommodation-charge cleanup, cancellation-fee accounting, and ambiguous financial adjustments remain manual or unsupported.
8. Distribution metadata declares PHP 7.4 support while active code uses PHP 8-only helpers; PHP 7.4 runtime compatibility is not credible without remediation/testing.
9. Public rendering can trigger a PokPay credential authentication probe when saved credential state is unverified; passive requests should not own provider diagnostics.
10. Guest cancellation tokens have no explicit expiry, provider/activity log retention is unspecified, and no durable email retry queue was verified.
11. Production acceptance is not established by repository evidence. Dated sandbox/E2E notes are historical, not current readiness proof.

Detailed evidence and future phases are in `REPOSITORY_CONSOLIDATION_PLAN.md`.

## Current priorities

1. Close the confirmation IDOR and forged-return state change.
2. Add durable provider-paid outcome recording and recoverable, exclusive Clock fulfillment.
3. Add behavioral database/concurrency tests for confirmation, callbacks, refunds, inventory and accounting.
4. Reconcile the unmerged confirmation-integrity design with current `main` without assuming branch code is released.
5. Reconcile declared/runtime PHP compatibility and move provider credential probes out of passive public rendering.
6. Complete later repository cleanup only after integrity coverage exists.
