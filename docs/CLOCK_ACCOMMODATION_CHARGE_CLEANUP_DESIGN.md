# Clock Accommodation Charge Cleanup Design

Updated: 2026-06-19

## Outcome

`BLOCKED - MANUAL CLEANUP MUST REMAIN`

The current MUST Hotel Booking codebase cannot safely automate Clock accommodation-charge cleanup after cancellation or amendment.

Why this remains blocked:

- The documented Clock contracts are generic folio/booking charge contracts, not an accommodation-charge-specific cleanup contract.
- The plugin does not currently create Clock accommodation charges.
- The plugin does not currently persist exact Clock charge IDs, charge-template IDs, charge ownership markers, or cleanup correlation records for accommodation rows.
- The plugin stores payment/refund accounting state and folio/payment identifiers, but not the charge-level evidence required to prove that a specific accommodation charge is plugin-owned, reversible, still eligible, and not already voided, compensated, invoiced, or fiscalized.

Because the plugin must never mutate unknown or staff-created charges automatically, every accommodation-charge cleanup case must remain manual until Clock confirms the missing contracts and the plugin persists charge-level ownership data.

## Official Contract Summary

### A. Charge deletion

- Endpoint: `DELETE /base_api/:subscription_id/:account_id/folios/:folio_id/charges/:charge_id`
- Request body:

```json
{
  "void_reason": "test"
}
```

- Response: no documented response body in the cached collection.
- Meaning: documented as "Voiding charge from folio." This supports treating the operation as voiding, not as a guaranteed hard delete.
- Success reread: use `GET /base_api/:subscription_id/:account_id/folios/:folio_id/charges/:charge_id` or `GET /base_api/:subscription_id/:account_id/folios/:folio_id/charges`.
- Open folios: not explicitly documented.
- Closed folios: not explicitly documented.
- Rights: not documented in the cached collection.
- Safe retry: not documented. The plugin must assume duplicate DELETE behavior is undefined.
- Deleting the same charge twice: not documented.
- Integration-ownership restriction: not documented by Clock, but MUST Hotel Booking must impose it locally.

### B. Negative-quantity compensating charge

- Endpoint: `POST /base_api/:subscription_id/:account_id/folios/:folio_id/charges/bulk`
- Generic documented request shape:

```json
{
  "charges": [
    {
      "charge_template_id": 367891,
      "service_date": "2022-01-08",
      "qty": 2
    }
  ]
}
```

- POS guide refund/void pattern: the local research cache documents a compensating example using positive `price` with negative `qty`, plus `charge_template_id`, `service_date`, `revenue_category`, `text`, `currency`, `tax_rate`, and `print_text`.
- Response: no documented response body in the cached collection.
- Meaning: creates new compensating charges; does not document mutation of the original charge.
- Open folios: not explicitly documented.
- Closed folios: not explicitly documented.
- Rights: not documented in the cached collection.
- Safe retry: not documented. The plugin would need its own duplicate-prevention record plus reread-before-retry logic.
- Stable identity for the compensating row: not documented in the bulk-create response, so later identification would require a reread and a local correlation strategy.

### C. Booking-associated charges by source

- Endpoints:
  - `POST /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source`
  - `POST /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source/bulk`
- Single-create request:

```json
{
  "charge": {
    "charge_template_id": 367891,
    "revenue_group": "f&b",
    "revenue_category": "test",
    "text": "Tea",
    "price": 1,
    "currency": "USD",
    "qty": 2,
    "tax_rate": 0.2,
    "service_date": "2019-11-22",
    "capacity_pool_id": "623"
  }
}
```

- Bulk-create request:

```json
{
  "charges": [
    {
      "charge_template_id": 367891,
      "service_date": "2022-01-08",
      "qty": 2
    }
  ]
}
```

- Documented purpose: "Create a charge which is associated with the booking for better reporting."
- Single-create response: returns a charge object with `id`, `folio_id`, `source_id`, `source_type`, amounts, tax fields, and text fields.
- Booking/source link: documented by returned `source_id` and `source_type`, and by the companion read endpoint `GET /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source`.
- Folio selection: Clock chooses the folio; the contract does not document folio-selection rules.
- Accommodation-revenue intent: not documented. The contract is generic booking-associated charging.
- Negative values or quantities: not explicitly documented for this endpoint.
- Compensating Clock-generated accommodation charges: not documented.
- Stable source identifier for idempotency: not documented. The response includes charge `id`, but no documented idempotency field.
- Reread: `GET /pms_api/:subscription_id/:account_id/bookings/:booking_id/charges_by_source`.

## Current Plugin Data Map

The plugin currently persists payment/refund/folio accounting evidence, not charge-level Clock evidence.

| Field | Status | Current source |
| --- | --- | --- |
| Clock folio ID | Already stored | Reservation `provider_metadata.clock_folio_id`; `must_refunds.clock_folio_id`; `must_clock_folio_accounting.clock_folio_id` |
| Clock charge ID | Unavailable | No storage for folio charge IDs |
| charge template ID | Unavailable | No storage for charge-template IDs tied to reservations/cancellations |
| charge source | Available only through Clock reread | Clock charge reads expose `source_id` and `source_type`; plugin does not store them |
| service date | Available only through Clock reread | Charge reads expose `service_date`; plugin does not store it |
| quantity | Available only through Clock reread | Charge reads expose `qty`; plugin does not store it |
| unit price | Available only through Clock reread | Charge reads expose `price_cents`; plugin does not store it |
| currency | Available only through Clock reread | Charge reads expose `currency`; plugin does not store it per Clock charge |
| tax rate | Available only through Clock reread | Charge reads expose `tax_rate`; plugin does not store it per Clock charge |
| revenue group | Available only through Clock reread | Charge reads expose `revenue_group`; plugin does not store it per Clock charge |
| revenue category | Available only through Clock reread | Charge reads expose `revenue_category`; plugin does not store it |
| print text | Available only through Clock reread | Charge reads expose `print_text`; plugin does not store it |
| provider request ID | Available only for provider logs, not linked to a charge | `mhb_provider_request_logs.id` exists, but no charge-level local foreign key exists |
| cancellation correlation ID | Unavailable for charge cleanup | Generic provider logs have `correlation_id`; cleanup attempts have no dedicated record |
| idempotency key | Partially available | Payment/refund/provider-sync idempotency exists; no charge-cleanup idempotency record exists |
| original folio balance | Already stored for payment/refund credit-item accounting only | `must_clock_folio_accounting.balance_before` |
| expected folio balance | Already stored for payment/refund credit-item accounting only | `must_clock_folio_accounting.expected_balance` |
| actual folio balance | Already stored for payment/refund credit-item accounting only | `must_clock_folio_accounting.actual_balance` |

### Migration assessment

- No migration is needed to preserve the current manual-only design.
- A future automatic charge-cleanup flow would require a new durable local record for:
  - cleanup action id
  - reservation id
  - cancellation/amendment correlation id
  - target folio id
  - original charge id
  - charge ownership class
  - charge fingerprint fields reread from Clock
  - chosen Clock operation
  - local idempotency key
  - provider request-log ids
  - pre-write and post-write verification snapshots

## Ownership Classification

The plugin must classify each candidate charge before any automatic mutation.

1. Plugin-created charge
- Proof required: local row proving the plugin created the exact Clock charge id, plus reread confirmation that the current charge still matches the stored fingerprint.

2. Charge created by another integration
- Proof required: explicit non-plugin source marker or a mismatching local ownership marker.

3. Clock-generated accommodation charge
- Probable reread indicators: `source_type = Booking`, room-like `revenue_group`, booking-linked `source_id`.
- This is still insufficient for automatic mutation because the contract does not state that generic delete/compensation endpoints are approved for Clock-generated accommodation revenue.

4. Staff-created manual charge
- Proof required: reread indicates no plugin ownership record and no trusted integration origin.

5. Unknown-source charge
- Default when ownership cannot be proven from local state plus Clock reread.

6. Already voided or compensated
- Reread indicators: `voided_at`, `void_reason`, negative compensating siblings, archived/master/corrected relationships, or a matching local cleanup record.

7. Charge on closed folio
- Reread indicators: folio `closed`, `closed_at`, `voided`, `voided_at`, or equivalent non-postable state.

8. Charge already invoiced or fiscalized
- Reread indicators: `invoice_to_id`, `invoice_to_type`, or folio fiscal/finalization state.
- Exact fiscalized-charge mutation rules are not documented in the cached contract.

Classification rule: if ownership or eligibility is not proven, the charge is `manual cleanup`.

## Decision Matrix

Current implementation boundary: no row is justified for automatic mutation by both the official contract and the plugin's existing stored data.

| Charge situation | Delete original | Post compensating charge | Manual cleanup |
| --- | ---: | ---: | ---: |
| Plugin-created charge on open folio with exact charge ID |  |  | Yes |
| Plugin-created charge on closed folio |  |  | Yes |
| Clock-generated accommodation charge with exact charge ID |  |  | Yes |
| Clock-generated charge without exact ID |  |  | Yes |
| Staff-created charge |  |  | Yes |
| Unknown-source charge |  |  | Yes |
| Already voided charge |  |  | Yes |
| Already compensated charge |  |  | Yes |
| Fiscalized or invoiced charge |  |  | Yes |

Reason: the current plugin does not store the charge-level ownership and eligibility evidence needed to make any automatic path safe.

## Financial Safety Guarantees

The future implementation must preserve these boundaries:

1. Reservation cancellation, gateway refund, Clock payment/refund accounting, Clock accommodation-charge cleanup, and cancellation-fee posting stay as separate states.
2. A successful gateway refund must never be repeated because Clock cleanup later fails.
3. One cleanup action may create at most one Clock mutation.
4. Retries must reread Clock before any second write.
5. Existing expected-balance verification must remain intact.
6. Retained cancellation fees must never be reversed accidentally.
7. Compensation must never exceed the eligible accommodation amount.
8. Manual staff charges must never be changed automatically.
9. Unknown-source charges must remain manual.
10. Closed or fiscalized folios require an explicitly documented method.
11. Every provider mutation needs a durable local correlation row.
12. Timeout recovery must never create duplicate compensating charges.

## Proposed State Machine

This is the future-safe state machine boundary. In the current codebase, `clock_charge_cleanup_status` should remain manual-only.

1. `not_required`
2. `manual_clock_charge_cleanup_required`
3. `candidate_identification_pending`
4. `candidate_unproven_manual_required`
5. `eligible_for_delete`
6. `eligible_for_compensation`
7. `write_in_progress`
8. `verification_pending`
9. `completed`
10. `manual_review_required`
11. `ambiguous_timeout_manual_review`

Transition rules:

- Current code enters `manual_clock_charge_cleanup_required` at snapshot time for Clock reservations.
- No automatic transition beyond that state is currently safe.
- A future implementation may move to `candidate_identification_pending` only after rereading Clock charges and proving ownership/eligibility.
- `write_in_progress` may be entered only after a durable local cleanup-attempt row is created.
- `verification_pending` must reread the charge list and folio state before deciding `completed`.
- Any ambiguity, timeout, missing charge id, closed folio, unknown source, or invoiced/fiscalized signal must end in `manual_review_required`.

## Retry And Idempotency Proposal

Current state:

- Provider request logs support `correlation_id` and `idempotency_key`.
- Payment/refund Clock accounting has unique idempotency rows and reread-based retry recovery.
- Charge cleanup has no dedicated local idempotency record.

Required future design:

- Add a dedicated cleanup-attempt table or repository, not just metadata flags.
- Create one local idempotency key per intended mutation:
  - delete: `clock-charge-cleanup-delete-{reservationId}-{chargeId}-{cancellationRevision}`
  - compensate: `clock-charge-cleanup-comp-{reservationId}-{chargeFingerprintHash}-{eligibleAmount}-{cancellationRevision}`
- Persist pre-write evidence:
  - folio id
  - charge id
  - ownership class
  - service date
  - qty
  - price/tax/currency
  - source id/source type
  - invoice/fiscal indicators
  - folio status
- Before any retry:
  - reread the target charge or booking-associated charge list
  - reread the folio
  - detect whether the intended result already happened
  - if the outcome cannot be proven, stop at manual review

Timeout rule:

- For DELETE, ambiguous timeout is unsafe because duplicate delete semantics are undocumented.
- For compensating charges, ambiguous timeout is unsafe because the bulk-create response does not document a stable idempotency token or returned ids.
- Therefore any timeout after the request leaves the system in `ambiguous_timeout_manual_review` unless reread proves success exactly once.

## Cancellation-Fee Interaction

The plugin already stores a cancellation snapshot with:

- `original_reservation_total`
- `gross_paid_amount`
- `previously_refunded_amount`
- `paid_amount`
- `provider_fee_retained`
- `cancellation_fee_amount`
- `refundable_amount`
- `non_refundable_amount`
- `expected_clock_result.expected_retained_amount`

Use that snapshot as the financial source of truth. Do not recompute later from changed provider state.

### Scenario 1. Full free cancellation

- Original accommodation value: 100
- Cancellation fee retained: 0
- Refundable amount: 100
- Gateway refund amount: 100
- Clock payment reversal: existing negative credit-item flow may post 100 if the original website payment was posted
- Clock accommodation-charge reversal: still manual
- Expected final folio balance: unknown to the plugin because accommodation-charge cleanup is manual

### Scenario 2. Partial cancellation fee retained

- Original accommodation value: 100
- Cancellation fee retained: 20
- Refundable amount: 80
- Gateway refund amount: 80
- Clock payment reversal: negative 80 only
- Clock accommodation-charge reversal: must not exceed 80 if ever automated
- Expected final folio balance: should retain 20, but the plugin cannot safely automate the accommodation-side representation yet

### Scenario 3. No-refund cancellation

- Original accommodation value: 100
- Cancellation fee retained: 100
- Refundable amount: 0
- Gateway refund amount: 0
- Clock payment reversal: none
- Clock accommodation-charge reversal: none
- Expected final folio balance: manual hotel policy result

### Scenario 4. Partial gateway refund already completed

- Use stored snapshot plus completed refund rows
- Remaining eligible accommodation cleanup must never exceed the not-yet-retained portion
- Cleanup failure must not reopen or repeat the refund

### Scenario 5. Clock charge cleanup succeeds after gateway refund

- Refund ledger remains final
- Cleanup records only Clock accommodation-side state
- No second gateway action

### Scenario 6. Clock charge cleanup fails after gateway refund

- Refund ledger remains final
- Clock cleanup enters manual review
- Existing payment/refund accounting rows remain unchanged

### Scenario 7. Clock mutation times out but may have succeeded

- No automatic retry write
- Reread first
- If reread cannot prove whether the intended charge was voided or compensated exactly once, stop at manual review

### Scenario 8. Cancellation initiated from Clock inbound synchronization

- Local lifecycle cancellation and refund review may complete
- Gateway refund remains staff-approved/explicit
- Accommodation-charge cleanup remains manual
- Do not assume the Clock-originated cancellation identifies which accommodation charges should be reversed

## Implementation Boundary

### Current safe boundary

- Keep `clock_charge_cleanup_status = manual_clock_charge_cleanup_required`.
- Keep `clock_cancellation_fee_status = manual_clock_cancellation_fee_required`.
- Do not add any automatic Clock charge delete or compensating charge writes.

### Exact files that would require future changes if Clock confirms a safe contract

- `src/Engine/CancellationFinancialCleanupService.php`
- `src/Engine/BookingLifecycleSyncService.php`
- `src/Provider/Clock/ClockFolioService.php`
- `src/Provider/Clock/ClockPaymentReconciliationService.php`
- `src/Database/install-tables.php`
- `src/Database/ReservationRepository.php`
- `src/Provider/Storage/ProviderRequestLogRepository.php`
- `src/Admin/ReservationAdminDataProvider.php`
- `src/Admin/reservations.php`
- new `src/Provider/Clock/ClockChargeCleanupService.php`
- new `src/Database/ClockChargeCleanupRepository.php`
- new focused tests for cleanup classification, timeout recovery, and cancellation-fee boundaries

### Required future migration

Yes, if automation is ever approved. A new cleanup-attempt persistence layer is required.

## Required Focused Tests

1. Cancellation snapshot remains immutable while cleanup retries occur later.
2. A completed gateway refund is never repeated when Clock cleanup retries.
3. Unknown-source charges remain manual.
4. Staff-created charges remain manual.
5. Closed-folio candidates remain manual.
6. Invoiced/fiscalized candidates remain manual.
7. Timeout after DELETE does not auto-repeat the delete.
8. Timeout after compensating POST does not auto-repeat the POST without reread proof.
9. Compensation is capped to the eligible accommodation amount after retained cancellation fees.
10. Partial refund scenarios do not reverse the retained amount.
11. Clock inbound cancellation still creates refund review without charge writes.
12. Existing payment/refund credit-item accounting regression suite still passes.

## External Questions Requiring Clock Confirmation

1. Is `DELETE /folios/:folio_id/charges/:charge_id` supported for already-posted accommodation charges generated by Clock itself, or only for user/integration-created charges?
2. Does DELETE work on open folios only, or also on closed folios?
3. What is the documented result of deleting the same charge twice?
4. Is there any documented rights/role matrix for charge delete and compensating charge creation?
5. Is there a supported way to identify whether a charge was created by Clock accommodation posting, staff manual entry, or an external integration?
6. Is there a supported way to compensate an accommodation charge on a closed or fiscalized folio?
7. Does `charges/bulk` return created charge ids in any officially supported response shape?
8. Are negative quantities or negative values supported for `charges_by_source`, or only for folio `charges/bulk`/`charges/` examples?
9. For booking-associated charges by source, how does Clock choose the folio?
10. Are booking-associated charges by source intended for accommodation revenue correction, or only for external service posting/reporting?
11. Is there any official idempotency mechanism for charge create/delete operations beyond client-side correlation?
12. What reread pattern does Clock recommend after a timeout on a charge write?

