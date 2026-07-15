# ADR-0001: Payment-first Clock fulfillment

- Status: Accepted
- Date: 2026-07-12
- Scope: Clock-backed online booking creation and payment finalization
- Supersedes: Pre-payment Clock reservation creation for the standard online path
- Superseded by: —
- Evidence confidence: Static code review; runtime certification pending

## Context

Clock booking mode previously risked creating a provider reservation before an online payment was authoritative. The payment-first flow now also persists authoritative gateway evidence before the Clock write and uses an exclusive owner-token fulfillment lease.

This ordering is a business-integrity requirement, but it does not by itself make the whole workflow atomic. Provider writes and local commits span different systems.

## Decision

For the standard Stripe or PokPay path in Clock booking mode:

1. Persist traceable pending local reservation and payment state.
2. Treat browser returns, redirects, and payment initiation as non-authoritative.
3. Establish payment success through the provider-specific verified completion path.
4. Transactionally persist the verified method, provider references, allocation, amount, currency, environment/account fingerprint, and reservation group without confirming the booking or emitting payment/accounting hooks.
5. Acquire an expiring owner-token lease; only that active owner may issue the Clock create and persist returned identifiers.
6. Treat an active competing callback as `in_progress`; treat expired, ambiguous, persistence-failed, or partial outcomes as manual review requiring Clock reread.
7. After the required provider result is durably available, lock the exact reservation rows and commit local paid rows plus confirmation state as one serialized transaction.
8. Emit payment/accounting and confirmation/email hooks only after that local transaction commits; a concurrent callback observes the committed rows and emits no duplicate events.

Offline and on-request modes retain their separate lifecycle rules. They must not be described as verified online payment flows.

## Alternatives considered

- Create the Clock reservation before redirecting to payment. Rejected for the standard online path because unpaid provider inventory can be created.
- Treat a successful browser return as payment proof. Rejected because return parameters are user-controlled transport state.
- Make the provider and WordPress database one transaction. Not possible across these systems.

## Consequences

### Positive

- Standard online flow does not intentionally allocate Clock inventory before verified payment.
- Pending local records provide correlation for callbacks and support investigation.
- The ordering matches the project's payment-confirmation invariant.

### Negative

- A captured payment can precede failed Clock fulfillment and therefore requires an operational manual-review path.
- Clock success followed by a failed local write remains cross-system ambiguous, but automatic duplicate creation is blocked pending Clock reread.
- Multi-room provider creation is not one atomic operation.

## Implementation constraints

- Verification must bind gateway, transaction/order identity, amount, currency, environment, and intended reservation allocation.
- Fulfillment claims are exclusive owner-token leases; a repeated payment reference does not grant ownership.
- Local paid-row and confirmation writes are serialized under exact reservation-row locks, with hooks deferred until commit.
- Retry must reread provider state before issuing a new create request.
- Provider idempotency must be transmitted when the provider supports it, not only logged locally.
- Ambiguous or partial outcomes require durable recovery/manual-review state; they must not be reported as success.
- Confirmation access and mutation require authorization independent of numeric reservation IDs.

## Verification

- Duplicate, concurrent, late, and wrong-allocation callbacks.
- Captured-payment/Clock-failure recovery.
- Timeout after provider write and retry after local persistence failure.
- Multi-room partial failure and compensation/manual review.
- Anonymous confirmation access and forged return-parameter tests.

## Evidence

- Current `src/Engine/PaymentEngine.php` verified online completion path.
- Current `src/Provider/Clock/ClockReservationProvider.php` fulfillment path.
- Current `src/Database/ReservationRepository.php` durable evidence locking and fulfillment lease columns.
- Commit `1aab2ec` (`v0.4.89`).
- Static payment-first tests under `tests/`; these do not prove database/provider concurrency safety.
