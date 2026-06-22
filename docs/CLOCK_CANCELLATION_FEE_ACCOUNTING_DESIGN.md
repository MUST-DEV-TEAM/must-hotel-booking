# Clock Cancellation-Fee Accounting Design

Updated: 2026-06-19

## Outcome

`BLOCKED — CANCELLATION-FEE ACCOUNTING MUST REMAIN MANUAL`

The current MUST Hotel Booking codebase cannot safely create and reconcile a retained cancellation-fee charge in Clock after cancellation.

Why this remains blocked:

- The documented Clock contracts are generic charge-create contracts, not a cancellation-fee-specific accounting contract.
- The accommodation-charge cleanup boundary is still `BLOCKED — MANUAL CLEANUP MUST REMAIN`, so the plugin cannot safely assume that original Clock accommodation revenue was removed or never posted.
- The plugin does not currently persist a Clock cancellation-fee charge id, charge-template id, source marker, local charge idempotency row, or cleanup-confirmed prerequisite state.
- The plugin stores payment/refund credit-item accounting evidence and immutable cancellation snapshots, but not the charge-level ownership and duplicate-prevention evidence required for retained-fee posting.

Because duplicate revenue cannot be ruled out with current stored data, Clock cancellation-fee accounting must remain manual.

## Scope Boundary

- This investigation does not change the accommodation-charge cleanup outcome: `BLOCKED — MANUAL CLEANUP MUST REMAIN`.
- The plugin must not automatically delete, void, identify, or compensate for accommodation charges as part of cancellation-fee accounting.
- Reservation cancellation, gateway refund, Clock payment/refund credit-item accounting, accommodation-charge cleanup, and cancellation-fee accounting remain separate states.
- No production behavior changes are proposed in this document.

## Official Contract Summary

### A. Booking-associated single charge

- Endpoint: `POST /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source`
- Documented request body root: `charge`
- Documented request fields:
  - `charge_template_id`
  - `revenue_group`
  - `revenue_category`
  - `text`
  - `price`
  - `currency`
  - `qty`
  - `tax_rate`
  - `service_date`
  - `capacity_pool_id`
- Documented purpose: create a charge associated with the booking for reporting.
- Documented response: a charge object with fields including `id`, `folio_id`, `source_id`, `source_type`, `text`, `price_cents`, `currency`, `revenue_group`, `revenue_category`, `tax_rate`, `qty`, `service_date`, `print_text`, `invoice_to_id`, and `invoice_to_type`.
- Charge-template rule: when `charge_template_id` is present, only `service_date` must be supplied if the template already provides the required values. If the template does not define them, Clock requires `revenue_group`, `price`, and `text`.
- What the contract proves:
  - Clock can create a booking-associated charge.
  - Clock returns the created charge id.
  - Clock returns the selected folio id.
  - Clock returns `source_id` and `source_type`.
- What the contract does not prove:
  - cancellation-fee-specific semantics;
  - client-supplied `source_id` or `source_type`;
  - documented idempotency behavior;
  - duplicate-write behavior after timeouts;
  - cancelled-booking acceptance;
  - checked-out-booking acceptance;
  - open-versus-closed folio write rules;
  - invoiced/fiscalized folio mutation rules;
  - required permissions.

### B. Booking-associated bulk charges

- Endpoint: `POST /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source/bulk`
- Documented request body root: `charges`
- Documented request fields per item: same generic charge fields as the single-create endpoint.
- Documented response: empty body on success.
- What the contract proves:
  - Clock supports booking-associated bulk charge creation.
- What the contract does not prove:
  - returned charge ids without a reread;
  - cancellation-fee semantics;
  - client-supplied ownership markers;
  - idempotency or timeout recovery semantics.

### C. Generic folio single charge

- Endpoint: `POST /base_api/:subscription_id/:account_id/folios/:folio_id/charges/`
- Documented request body root: `charge`
- Documented request fields: same generic charge fields as `charges_by_source`.
- Documented response: a charge object with fields including `id`, `folio_id`, `source_id`, `source_type`, `text`, `price_cents`, `currency`, `revenue_group`, `revenue_category`, `tax_rate`, `qty`, `service_date`, `print_text`, `invoice_to_id`, and `invoice_to_type`.
- Charge-template rule: same as the booking-associated single-create contract.
- What the contract proves:
  - Clock can create a generic charge on an explicit folio.
  - Clock returns the created charge id and folio id.
- What the contract does not prove:
  - booking-owned retained-fee semantics;
  - cancellation-fee semantics;
  - safe use on a closed, cancelled, checked-out, invoiced, or fiscalized folio;
  - any automatic folio-selection policy.

### D. Generic folio bulk charges

- Endpoint: `POST /base_api/:subscription_id/:account_id/folios/:folio_id/charges/bulk`
- Documented request body root: `charges`
- Documented request fields per item:
  - the generic charge fields above;
  - the bulk example also shows `custom_fields`.
- Documented response: empty body on success.
- What the contract proves:
  - Clock supports bulk charge creation on an explicit folio.
  - The cached research also documents a separate compensating-charge pattern that uses positive `price` with negative `qty`.
- What the contract does not prove:
  - a retained cancellation-fee business rule;
  - returned charge ids without a reread;
  - safe automatic duplicate recovery.

### E. Relevant reread operations

- `GET /pms_api/:subscription_id/:account_id/bookings/:booking_id`
  - provides booking status and booking-level balance/total context.
- `GET /pms_api/:subscription_id/:account_id/bookings/:booking_id/folios/default`
  - documented as returning a single open, valid folio suitable for charging.
- `GET /pms_api/:subscription_id/:account_id/bookings/:booking_id/folios/`
  - documented as returning all booking folio ids and explicitly warns that some may be voided, empty, or closed.
- `GET /base_api/:subscription_id/:account_id/folios/:folio_id`
  - exposes `balance`, closure fields, invoice fields, and fiscalization fields.
- `GET /base_api/:subscription_id/:account_id/folios/:folio_id/charges`
  - exposes folio charge lists including `id`, `folio_id`, `source_id`, `source_type`, amount, tax, text, `voided_at`, and `print_text`.
- `GET /base_api/:subscription_id/:account_id/folios/:folio_id/charges/:charge_id`
  - exposes a single charge with the same identifying and financial fields.
- `GET /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source`
  - exposes all charges associated with the booking even when transferred to another folio.

### F. Contract gaps that matter for cancellation fees

- `source_id` and `source_type` are returned by Clock, not supplied by the plugin.
- `print_text` is returned in responses, but the targeted charge-create request examples do not document `print_text` as an accepted request field.
- The targeted charge-create examples show positive `price` values only.
- The collection does not explicitly document whether zero or negative values are accepted for retained-fee posting.
- The collection does not document whether cancelled bookings or checked-out bookings may still accept charge writes.
- The collection does not document endpoint-specific duplicate handling or timeout recovery.
- The collection-wide guidance only documents a 5 requests/second rate limit and `429` backoff. It explicitly advises against automatic retries for other `4xx`/`5xx` errors without correcting the request first.
- The collection does not document endpoint-specific permission requirements.

## Existing Plugin Flow Inspection

### What the plugin already does

- `src/Engine/CancellationFinancialCleanupService.php`
  - captures one immutable cancellation-time financial snapshot in reservation `provider_metadata`;
  - stores `original_reservation_total`, `gross_paid_amount`, `previously_refunded_amount`, `paid_amount`, `provider_fee_retained`, `cancellation_fee_amount`, `refundable_amount`, `non_refundable_amount`, currency, payment method, source, reason, and timestamps;
  - sets `clock_charge_cleanup_status = manual_clock_charge_cleanup_required`;
  - sets `clock_cancellation_fee_status = manual_clock_cancellation_fee_required` when a retained cancellation fee exists.
- `src/Engine/BookingLifecycleSyncService.php`
  - captures the cancellation snapshot before status transitions;
  - creates `refund_review_required` rows for paid Stripe/PokPay cancellation-first flows;
  - keeps cancellation and refund review separate from payment execution.
- `src/Engine/PaymentRefundService.php`
  - executes explicit Stripe/PokPay refunds;
  - stores refund rows with provider references, Clock booking ids, Clock folio ids, idempotency keys, and refund breakdown fields;
  - keeps refund execution separate from cancellation and separate from any future fee-charge posting.
- `src/Provider/Clock/ClockPaymentAccountingService.php`
  - posts Clock credit items for payments and refunds only;
  - uses durable accounting rows keyed by idempotency;
  - rereads folio balances before and after posting;
  - recovers ambiguous retries by rereading expected balances before reposting.
- `src/Provider/Clock/ClockFolioService.php`
  - lists booking folios;
  - reads folio details and balances;
  - selects deposit/payment folios;
  - treats closed, voided, or correction folios as not postable;
  - posts payment/refund credit items only.
- `src/Provider/Clock/ClockPaymentReconciliationService.php`
  - handles booking cancellation/state sync and stay/pricing reconciliation;
  - marks pricing deltas as manual financial review;
  - does not create retained cancellation-fee charges.
- `src/Provider/Storage/ProviderRequestLogRepository.php`
  - stores generic provider `correlation_id` and `idempotency_key` logs;
  - does not link a cancellation snapshot to a specific Clock charge id.
- Admin data providers and reservation admin views
  - expose the immutable cancellation snapshot and the two manual Clock badges;
  - do not expose a confirmed cleanup-complete state or a persisted cancellation-fee charge id.

### What the plugin does not do

- It does not create a retained cancellation-fee charge in Clock.
- It does not store any Clock charge id for accommodation or cancellation fees.
- It does not store a charge-template id chosen for retained fees.
- It does not store `source_id` or `source_type` for created charges.
- It does not store a charge-level idempotency row.
- It does not store a prerequisite state proving that accommodation cleanup was completed.
- It does not store a cancellation-fee folio-selection decision.
- It does not store charge reread verification results.

### Test evidence

- `tests/CancellationPaymentStateTest.php` proves that a retained cancellation fee changes local derived payment state without requiring a Clock fee charge.
- `tests/PaymentRefundSafetyTest.php` proves refund safety gates reject currency mismatches and duplicate gateway refunds before any provider write.
- `tests/ClockPaymentAccountingRetryTest.php` proves reread-first retry recovery exists for Clock payment/refund credit items, not for charge creation.

## Current Financial Data Map

| Field | Classification | Notes |
| --- | --- | --- |
| Original booking total | Already stored | Cancellation snapshot stores `original_reservation_total`. |
| Accommodation value | Missing | No separate stored accommodation-only value for cancellation-fee accounting. |
| Retained cancellation fee | Already stored | Cancellation snapshot stores `cancellation_fee_amount`. |
| Refundable amount | Already stored | Cancellation snapshot stores `refundable_amount`. |
| Refunded amount | Already stored | Snapshot stores `previously_refunded_amount`; refund rows also persist amounts. |
| Outstanding refund amount | Derivable safely | Can be derived from snapshot/refund rows for local review. |
| Payment provider | Already stored | Snapshot stores payment method; payment/refund rows persist gateway/provider references. |
| Payment currency | Already stored | Snapshot stores currency; payment/refund rows also store currency. |
| Cancellation correlation id | Missing | Generic provider logs exist, but cancellation cleanup has no dedicated correlation record. |
| Cancellation snapshot version | Missing | Snapshot is immutable by behavior, but no explicit version field exists. |
| Clock booking id | Already stored | Reservation rows and refund/accounting rows persist Clock booking/reservation ids. |
| Clock folio id | Already stored | Reservation metadata, refund rows, and payment/refund accounting rows persist folio ids. |
| Selected Clock payment subtype | Missing | Payment-credit-item subtype is resolved at runtime, not stored. |
| Charge template id suitable for cancellation fees | Requires configuration | No current plugin setting or stored reservation-level value. |
| Clock charge id | Missing | No stored fee or accommodation charge ids. |
| Clock charge source id/type | Available only by Clock reread | Returned by charge reads, not stored locally. |
| Charge idempotency key | Missing | No dedicated local charge-accounting row exists. |
| Pre-write folio state for fee charge | Missing | Payment/refund accounting stores this only for credit items. |
| Expected post-write folio state for fee charge | Missing | Payment/refund accounting stores this only for credit items. |
| Reread verification result for fee charge | Missing | No retained-fee charge flow exists. |

## Central Accounting Question

Automatic retained-fee posting is unsafe unless the plugin can prove that adding a new fee charge will not duplicate revenue while accommodation charges remain present or ambiguous.

### Scenario analysis

1. Original Clock accommodation charges remain unchanged
- Automatic fee posting is unsafe.
- Reason: the folio would contain both the original accommodation revenue and a new retained-fee revenue row.

2. Hotel staff manually remove or reverse accommodation charges
- Automatic fee posting is still unsafe in the current codebase.
- Reason: the plugin has no durable state proving the manual cleanup completed, what amount was removed, or whether a fee already exists.

3. Original accommodation charges were never posted
- Automatic fee posting is still unsafe in the current codebase.
- Reason: the plugin does not persist a reliable no-accommodation-posted fact.

4. Accommodation charges exist, but ownership or amount is unknown
- Automatic fee posting is unsafe.
- Reason: duplicate revenue cannot be ruled out.

5. Folio already contains a manually created cancellation fee
- Automatic fee posting is unsafe.
- Reason: the plugin cannot reliably distinguish a staff-created fee from a future plugin-owned fee without charge ids and ownership markers.

6. Booking has multiple folios
- Automatic fee posting is unsafe.
- Reason: `charges_by_source` folio-selection rules are not documented, and generic folio-charge writes require an explicit folio target the plugin cannot safely choose for retained-fee revenue.

7. Folio is closed
- Automatic fee posting is unsafe.
- Reason: the contract does not document charge-create behavior for closed folios, and current folio logic treats closed folios as non-postable.

8. Folio is invoiced or fiscalized
- Automatic fee posting is unsafe.
- Reason: the contract does not document whether post-invoice or fiscalized folios may accept new retained-fee charges.

9. Booking is cancelled before arrival
- Automatic fee posting is still unsafe.
- Reason: the contract does not prove whether accommodation charges already exist, nor whether a cancelled booking still accepts retained-fee writes.

10. Booking is cancelled after check-in or check-out
- Automatic fee posting is unsafe.
- Reason: accommodation revenue may already be complete or partially complete, and the contract does not define a safe retained-fee conversion path.

Conclusion: there are no safe automatic retained-fee posting cases with the current stored data and documented contracts.

## Proposed Fee Ownership Model

Future automation would need a clearly plugin-owned charge model. The current contract only partially supports that.

| Proposed marker | Supported by official contract | Current status |
| --- | --- | --- |
| Booking-associated `charges_by_source` write path | Partially | Documented and preferable to generic folio charge creation for reporting, but not cancellation-fee-specific. |
| Stable `source_id` chosen by plugin | No | `source_id` is returned by Clock, not requested from the plugin. |
| Stable `source_type` chosen by plugin | No | `source_type` is returned by Clock, not requested from the plugin. |
| Deterministic visible text | Partially | `text` is documented in requests; `print_text` is not documented as a request field for the targeted endpoints. |
| Stored Clock charge id | Yes, after single-create only | Current plugin does not store it. |
| Local idempotency record | Local only | Must be added by MUST; Clock does not document charge-create idempotency. |
| Cancellation correlation id | Local only | Must be stored locally and linked to the created charge id. |

Implication:

- A future plugin-owned fee should use the booking-associated single-create endpoint, not a blind generic folio charge, so the response returns the created charge id immediately.
- The plugin still cannot rely on `source_id` or `source_type` as client-controlled ownership markers.
- A future implementation must persist its own ownership row and use the returned Clock charge id as the durable external identifier.

## Configuration Requirements

| Setting or policy | Needed for future automation | Can be auto-detected now |
| --- | --- | --- |
| Cancellation-fee charge template id | Yes | No. Must be explicit hotel configuration unless the hotel approves fully template-driven posting. |
| Revenue group and revenue category | Yes | Partially. They may come from a chosen charge template, but the plugin cannot safely guess them. |
| Tax rate or tax code | Yes | Partially. Can follow a configured template, otherwise explicit configuration is required. |
| Service date policy | Yes | No. The business must choose whether the fee uses cancellation date, original arrival date, departure date, or another policy. |
| Target folio policy | Yes | No. `charges_by_source` folio selection is undocumented and multi-folio handling requires an explicit hotel rule. |
| Visible text | Yes | Partially. `text` is configurable, but request-side `print_text` support is not documented for the targeted endpoints. |
| Currency policy | Yes | Partially. The plugin stores payment/snapshot currency, but cannot safely infer a cross-currency fee policy. |
| Clock source type | No direct setting | No. It is returned by Clock and cannot be chosen by the plugin. |
| Permission diagnostics | Yes | Partially. Diagnostics can detect request failures, but the collection does not document required permissions upfront. |

Required configuration conclusion:

- The charge template id, tax/business classification, service-date rule, and target-folio rule must be explicitly configured or explicitly approved as template-derived.
- Currency can usually follow the stored payment/snapshot currency only when that matches the selected Clock folio currency.
- `source_id` and `source_type` are not configuration inputs.

## Decision Matrix

Current implementation boundary: every scenario remains manual.

| Scenario | Auto-post retained fee | Manual review | Reason |
| --- | ---: | ---: | --- |
| Original accommodation charges still present |  | Yes | Duplicate revenue cannot be ruled out. |
| Accommodation cleanup manually confirmed complete |  | Yes | No durable cleanup-confirmed state or stored fee ownership exists yet. |
| No accommodation charges exist |  | Yes | The current plugin does not persist a trusted no-charge baseline. |
| Existing plugin-owned fee with exact charge id |  | Yes | Current plugin cannot create or store this state yet. |
| Existing staff-created cancellation fee |  | Yes | Plugin cannot distinguish or mutate it safely. |
| Unknown existing fee charge |  | Yes | Ownership is ambiguous. |
| Multiple folios |  | Yes | Safe retained-fee folio targeting is not defined. |
| Closed folio |  | Yes | Postability is blocked/undocumented. |
| Invoiced or fiscalized folio |  | Yes | Mutation rules are undocumented. |
| Full free cancellation with zero retained fee |  |  | No fee charge is required; refund remains a separate workflow. |
| No-refund cancellation |  | Yes | Retained amount remains local/manual in Clock. |
| Partial retained fee |  | Yes | Duplicate revenue risk remains unresolved. |
| Clock inbound cancellation |  | Yes | Inbound cancellation sync does not imply safe fee posting. |
| Ambiguous prior timeout |  | Yes | No charge idempotency or recovery contract exists. |

## Financial Examples

### Scenario 1. Free cancellation

- Desired local result:
  - booking value: `500 EUR`
  - retained fee: `0 EUR`
  - gateway refund: `500 EUR`
- Booking or accommodation charges:
  - remain separate from the fee workflow;
  - may still need manual Clock cleanup.
- Cancellation-fee charge:
  - no fee charge should be posted.
- Payment credit:
  - existing payment credit items remain governed by normal Clock payment accounting only.
- Refund or payment reversal:
  - gateway refund may proceed independently;
  - any Clock refund credit item remains part of refund accounting, not fee accounting.
- Expected folio balance:
  - not deterministically automatable from current data because accommodation charges may still be present.
- Manual action required:
  - manual Clock work only if accommodation charges or folio cleanup still need review.

### Scenario 2. Partial cancellation fee

- Desired local result:
  - booking value: `500 EUR`
  - retained fee: `100 EUR`
  - gateway refund: `400 EUR`
- Booking or accommodation charges:
  - remain manual until the hotel proves whether accommodation revenue stays, is removed, or is converted.
- Cancellation-fee charge:
  - not safe to post automatically.
- Payment credit:
  - unchanged; existing payment/deposit credit items stay on their own accounting path.
- Refund or payment reversal:
  - refund may occur independently for `400 EUR`.
- Expected folio balance:
  - not deterministically automatable because posting a new `100 EUR` fee could duplicate still-present accommodation revenue.
- Manual action required:
  - yes, for any retained-fee representation in Clock.

### Scenario 3. No-refund cancellation

- Desired local result:
  - booking value: `500 EUR`
  - retained fee: `500 EUR`
  - gateway refund: `0 EUR`
- Booking or accommodation charges:
  - remain manual; the plugin cannot automatically convert full accommodation revenue into a cancellation-fee row.
- Cancellation-fee charge:
  - not safe to post automatically.
- Payment credit:
  - existing payment/deposit credit items remain separate.
- Refund or payment reversal:
  - none.
- Expected folio balance:
  - not deterministically automatable; original accommodation revenue may already satisfy the retained amount or may still need manual folio correction.
- Manual action required:
  - yes.

### Scenario 4. Partial payment

- Desired local result:
  - booking value: `500 EUR`
  - paid: `200 EUR`
  - retained fee: `100 EUR`
  - gateway refund: `100 EUR`
- Booking or accommodation charges:
  - still manual because the retained amount does not prove what Clock has already posted.
- Cancellation-fee charge:
  - not safe to post automatically.
- Payment credit:
  - only the paid amount may have matching payment/deposit credit items.
- Refund or payment reversal:
  - refund path may reverse `100 EUR` independently.
- Expected folio balance:
  - not deterministically automatable because the unpaid portion, any accommodation revenue, and any manual cleanup state are separate concerns.
- Manual action required:
  - yes.

### Scenario 5. Gateway refund completed but Clock fee posting fails

- Booking or accommodation charges:
  - unchanged unless manual cleanup occurs.
- Cancellation-fee charge:
  - must not trigger a rollback of the completed gateway refund.
- Payment credit:
  - unchanged.
- Refund or payment reversal:
  - completed refund remains completed.
- Expected folio balance:
  - unresolved manually in Clock.
- Manual action required:
  - yes. This is exactly why gateway refund and fee posting must remain separate states.

### Scenario 6. Clock fee write times out and outcome is unknown

- Booking or accommodation charges:
  - must be reread before any future decision.
- Cancellation-fee charge:
  - no blind replay is safe.
- Payment credit:
  - unchanged.
- Refund or payment reversal:
  - must not repeat.
- Expected folio balance:
  - unknown until reread proves whether a fee row exists.
- Manual action required:
  - yes. Ambiguous timeout must terminate in manual review.

### Scenario 7. Clock inbound cancellation arrives after local cancellation processing

- Booking or accommodation charges:
  - still separate from the retained-fee decision.
- Cancellation-fee charge:
  - must not be posted again.
- Payment credit:
  - unchanged.
- Refund or payment reversal:
  - refund review/refund execution must remain idempotent and separate.
- Expected folio balance:
  - unchanged by the duplicate cancellation event alone.
- Manual action required:
  - yes, if Clock fee representation is still needed.

## State Machine Proposal

No schema or runtime changes are proposed now. A future safe implementation would need a dedicated retained-fee accounting record, not just metadata flags.

### Required future persistence

- reservation id
- cancellation snapshot version or immutable snapshot hash
- cancellation correlation id
- explicit prerequisite state: `manual_cleanup_confirmed_complete` or `no_accommodation_charges_confirmed`
- chosen Clock booking id
- chosen target folio id
- configured cancellation-fee charge-template id
- requested text, amount, currency, tax rate, service date
- returned Clock charge id
- returned `source_id`
- returned `source_type`
- local idempotency key
- linked provider request-log ids
- pre-write folio balance and state
- expected post-write balance and state
- reread verification result
- terminal staff/manual note when automation stops

### Proposed future states

1. `not_required`
2. `manual_review_required`
3. `waiting_for_accommodation_cleanup_confirmation`
4. `eligible`
5. `write_claimed`
6. `write_in_progress`
7. `verification_pending`
8. `completed`
9. `already_present`
10. `failed_retryable`
11. `ambiguous_timeout_manual_review`
12. `blocked_closed_folio`
13. `blocked_fiscalized_folio`

Transition rules:

- Current code enters a manual-only equivalent of `manual_review_required` only.
- No automatic transition beyond that state is safe today.
- A future implementation may advance to `eligible` only after a durable prerequisite record proves accommodation cleanup completed or no accommodation charges exist.
- `write_claimed` must happen before any provider write.
- `verification_pending` must reread both the created charge and the target folio.
- Any ambiguity about prior writes, existing fee rows, folio status, or charge ownership must fall back to manual review.
- This state machine must stay separate from booking cancellation state, gateway-refund state, Clock payment/reversal state, and accommodation-cleanup state.

## Idempotency And Verification Design

Any future automatic retained-fee flow must require all of the following:

1. An immutable cancellation financial snapshot.
2. A deterministic local operation key.
3. A durable local claim before the provider write.
4. A Clock reread before the first write.
5. Detection of an existing plugin-owned fee.
6. Exactly one Clock mutation.
7. Storage of the returned charge id and folio id.
8. A Clock reread after the write.
9. Verification of charge amount, currency, and source markers.
10. No blind replay after an ambiguous timeout.
11. No repeat gateway refund.
12. Manual handling whenever the outcome cannot be proven.

Recommended future sequence:

1. Reuse the immutable cancellation snapshot and derive a deterministic operation key from reservation id, snapshot version/hash, retained amount, and target policy.
2. Create a durable local retained-fee accounting row in `write_claimed`.
3. Reread booking, candidate folios, booking-associated charges, and target folio charges before posting.
4. Stop if an existing plugin-owned fee is already present.
5. Stop if accommodation-cleanup prerequisites are not durably confirmed.
6. Send at most one single-create `charges_by_source` request so the response returns the created charge id immediately.
7. Persist the returned charge id and folio id before marking the write successful.
8. Reread `charges_by_source`, folio charges, and folio balance.
9. Verify exact amount, currency, and returned `source_type`/`source_id`.
10. Mark ambiguous timeout or missing-reread cases as manual review with no automatic replay.

## Required Future Implementation Files

- New `src/Provider/Clock/ClockCancellationFeeAccountingService.php`
- New `src/Database/ClockCancellationFeeAccountingRepository.php`
- `src/Database/install-tables.php`
- `src/Engine/CancellationFinancialCleanupService.php`
- `src/Engine/BookingLifecycleSyncService.php`
- `src/Engine/PaymentRefundService.php`
- `src/Provider/Clock/ClockFolioService.php`
- `src/Provider/Clock/ClockPaymentAccountingService.php`
- `src/Provider/Clock/ClockPaymentReconciliationService.php`
- `src/Provider/Storage/ProviderRequestLogRepository.php`
- `src/Database/ReservationRepository.php`
- `src/Admin/ReservationAdminDataProvider.php`
- `src/Admin/reservations.php`

## Required Focused Tests

- Snapshot immutability and retained-fee state creation for Clock cancellations.
- No automatic fee posting when accommodation cleanup is unconfirmed.
- No automatic fee posting when accommodation charges still exist.
- No automatic fee posting when multiple folios exist.
- No automatic fee posting when the folio is closed, invoiced, or fiscalized.
- Existing plugin-owned fee detection before any second write.
- Deterministic idempotency claim behavior across duplicate cancellation events.
- Ambiguous timeout handling with no blind replay.
- Reread verification of returned charge id, folio id, amount, currency, and source markers.
- Separation between gateway refund completion and fee-posting/manual-review failure.
- Clock inbound duplicate cancellation after local processing does not create a second fee workflow.

## Unresolved Clock Questions

- Are retained cancellation fees an approved use of the generic `charges_by_source` contract?
- Are cancelled bookings allowed to accept new charge writes?
- Are checked-out bookings allowed to accept new charge writes?
- Does Clock expose a supported cancellation-fee charge template pattern for hotel folios?
- Does `charges_by_source` always target the default open folio, or can it route elsewhere?
- What is the supported behavior for closed, invoiced, or fiscalized folios?
- Is request-side `print_text` accepted on the targeted booking/folio charge-create endpoints?
- What provider-side permissions are required for retained-fee charge posting?
- What is the supported duplicate-recovery path after a timeout when the client did not receive the response?

## Current Safe Boundary

- Keep `clock_cancellation_fee_status = manual_clock_cancellation_fee_required` whenever a retained fee exists on a Clock-backed cancellation.
- Do not add a new Clock cancellation-fee charge automatically.
- Do not couple gateway refunds to future fee-charge posting.
- Do not reuse Clock payment/refund credit-item accounting as proof that a retained-fee charge is safe.
- Do not change production behavior until the plugin has explicit fee-charge persistence, prereq confirmation, and reread-based idempotent recovery.
