# Cancellation Financial Cleanup Acceptance

Updated: 2026-06-18

## Overall status

`CODE READY LOCALLY — EXTERNAL ACCEPTANCE BLOCKED`

Reservation cancellation, refund review, gateway refund, Clock payment/refund accounting, accommodation-charge cleanup, and cancellation-fee accounting are tracked as separate states. The complete feature is not production-ready because no documented Clock item-level accommodation-charge reversal or cancellation-fee posting contract was found, and no new provider-write E2E was authorized or run in this task.

## Scenario matrix

| Path | Local result | Gateway result | Clock result | Expected financial result | Idempotency evidence | Status |
| --- | --- | --- | --- | --- | --- | --- |
| Unpaid local cancellation | Lifecycle service cancels once, releases availability, sends cancellation hooks once, stores immutable snapshot | Not required | Not required for local provider | No refund; retained amount `0` | Repeated lifecycle transition is a no-op | PASS locally |
| Paid refundable cancellation | Snapshot and refund-review state are durable; explicit approval required outside existing guest self-service policy | Refund amount capped at remaining paid balance | Refund accounting is independent from gateway outcome | Refund approved amount; remaining retained amount from snapshot | Provider refund row and Clock accounting row use stable idempotency keys | PASS locally / external not rerun |
| Paid non-refundable cancellation | Cancellation completes with refundable amount `0` and retained amount recorded | Not required | Charge/fee review remains separate | Retain policy amount | Duplicate cancellation is harmless | PASS locally; Clock fee representation blocked |
| Partial refund | Remaining paid balance and prior refunds are included in validation | Explicit amount only; currency must match original payment | One negative credit item after confirmed refund | Retain non-refundable amount | Duplicate same-payment/same-amount refund is rejected | PASS locally / external not rerun |
| Pay at hotel cancellation | Cancellation lifecycle completes; no online refund is attempted | Manual/offline if money was collected | Clock charge cleanup remains separate | Usually zero, otherwise manual offline result | Repeated cancellation is harmless | PASS locally; manual financial handling where applicable |
| Clock outbound cancellation | Local status changes only after Clock reread confirms cancelled state | No automatic refund | Failed confirmation queues provider retry | Reservation cancelled; financial cleanup may remain pending | Retry rereads before another cancellation write | CODE READY — EXTERNAL ACCEPTANCE BLOCKED |
| Clock inbound cancellation | Existing frozen inbound path routes through lifecycle service and creates one refund review for paid online bookings | No automatic refund | Existing inbound status evidence is preserved | Refund decision remains explicit | Existing inbound event/request dedupe plus lifecycle no-op | PASS locally; external callback acceptance remains blocked |
| Stripe full refund | Local ledger and cancellation-after-refund recover from synchronous response or webhook | Provider confirmation required | Negative Clock accounting runs separately | Remaining paid balance `0` unless retained fees apply | Duplicate refund row is blocked; webhook ledger is reference-idempotent | PASS locally / external not rerun |
| Stripe partial refund | Local ledger remains partially paid/refunded | Provider confirmation required | Negative Clock accounting uses actual refunded amount | Remaining paid balance equals retained amount | Same-payment/same-amount duplicate blocked | PASS locally / external not rerun |
| PokPay full refund | Automatic-first with manual fallback retained | Provider reread/confirmed status required | Negative Clock accounting runs only after confirmed/manual-completed refund | Remaining paid balance `0` unless retained fees apply | Blocking refund row prevents duplicate request | PASS locally / external not rerun |
| PokPay partial refund | Explicit amount supported by existing provider integration | Provider confirmation required | Negative Clock accounting uses actual amount | Remaining paid balance equals retained amount | Blocking refund row prevents duplicate request | CODE READY — EXTERNAL ACCEPTANCE BLOCKED |
| Clock payment/refund accounting | Payment and refund rows remain independent from reservation cancellation | Gateway success is never repeated by Clock retry | Folio balance is read before and after posting; expected and actual balances are stored | `actual = before + posted amount` for each accounting operation | Unique accounting idempotency key and posting claim | PASS locally / external not rerun |
| Clock accommodation-charge cleanup | Manual state and staff warning are durable | Not applicable | No undocumented reversal is attempted | Exact final accommodation folio balance requires provider contract | No automatic write exists, so no duplicate reversal risk | `BLOCKED — CLOCK FOLIO CHARGE-CLEANUP CONTRACT REQUIRED` |
| Clock cancellation-fee accounting | Retained fee is stored in immutable snapshot and UI | Gateway refund excludes retained amount | No undocumented charge post is attempted | Retained cancellation fee must be represented manually | Manual state prevents duplicate automatic fee posts | `BLOCKED — CLOCK CANCELLATION-FEE CONTRACT REQUIRED` |
| Gateway refund succeeds, Clock posting fails | Refund remains completed locally | No second refund | Clock accounting is retryable independently | Gateway reality preserved; Clock pending/manual review | Separate refund and accounting idempotency keys | PASS locally |
| Clock cancellation succeeds, local reconciliation fails | Retry rereads Clock before mutation | No gateway action | Confirmed provider state can repair local lifecycle | Local state converges to provider state | Reread-first cancellation retry | PASS locally |

## Data and operational visibility

- Cancellation snapshot is stored in `must_reservations.provider_metadata.cancellation_financial_cleanup.snapshot`.
- Snapshot fields include original total, gross paid, previous refunds, remaining paid amount, cancellation policy, provider fee, cancellation fee, refundable/non-refundable amount, currency, payment method, source, reason, and timestamp.
- Clock accounting stores balance before, expected balance, actual balance, and reconciliation status.
- Admin and staff reservation views show the Clock charge-cleanup warning and retained/refundable amounts.

## Remaining blockers

- Official Clock contract for reversing or voiding posted accommodation/service charge items.
- Official Clock contract for safely posting or retaining a cancellation fee without duplicate charges.
- Controlled Stripe, PokPay, and Clock sandbox replay tests for this exact code state.
- Public callback and Clock inbound external acceptance prerequisites documented by the existing production lifecycle harness.
