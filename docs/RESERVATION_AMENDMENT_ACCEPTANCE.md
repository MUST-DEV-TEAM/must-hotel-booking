# Reservation Amendment Acceptance

## Status

| Capability | Status | Evidence / limit |
| --- | --- | --- |
| Local same-type physical-room move | PASS | Locking, atomic conflict rejection, assignment, release/unassignment, payment preservation, idempotency, rollback, cancellation release semantics, and audit behavior covered by focused tests. |
| Clock pre-arrival physical-room assignment | CODE READY - EXTERNAL ACCEPTANCE BLOCKED | Documented GET -> PUT -> GET path and reread-first retry are tested with fakes; no Clock write was performed in this task. |
| Clock checked-in/current-room move | BLOCKED | No documented operation was found for creating an in-house room-change entry. UI and service both block it. |
| Clock room-type amendment | CODE READY - EXTERNAL ACCEPTANCE BLOCKED | Mapping, quote, documented payload, lock version, post-write reread, local reconciliation, and retry behavior are covered locally. |
| Upgrade financial review | PASS locally; external Clock acceptance blocked | Positive delta stores `additional_payment_review_required`; payment state remains unchanged. |
| Downgrade financial review | PASS locally; external Clock acceptance blocked | Negative delta stores `refund_or_credit_review_required`; no automatic refund occurs. |
| Combined date and room/type amendment | PASS locally; external Clock acceptance blocked | Shared service validates dates, destination, pricing, capabilities, and provider confirmation. |

## Safety Evidence

- Local destination conflict checks exclude the current reservation.
- Destination rooms must be active, bookable, available, and of the requested room type.
- Local save failure or commit failure restores/preserves the original assignment.
- Duplicate local actions are no-ops.
- Clock retries reread before any repeated write.
- Checked-in date-only Clock writes omit arrival-room, room-type, and rate fields.
- Clock timeouts do not confirm local success.
- Duplicate completed Clock retries preserve the original financial-review delta and do not duplicate audit events.
- Payment status is not written by either amendment service.
- No Stripe, PokPay, refund, or Clock folio accounting service is called.
- Admin actions use the existing admin capability and nonce gate.
- Staff combined amendments verify a reservation-specific nonce and require both stay-edit and assignment/move capabilities.

## Checks

- `php tests/ReservationAmendmentServiceTest.php` - PASS.
- `php tests/ClockReservationAmendmentServiceTest.php` - PASS.
- `php tests/ClockInboundSyncServiceTest.php` - PASS.
- `php tests/ClockInboundWebhookSnsTest.php` - PASS.
- `php tools/lifecycle-sync-smoke-test.php` - PASS.
- `php tools/clock-accounting-readiness.php` - PASS/read-only.
- `php tests/E2E/production-lifecycle-harness.php --correlation-id=MHB-AMEND-20260618-READONLY` - exit `0`, overall `BLOCKED` only for write-enabled provider scenarios.
- PHP lint for changed PHP files - PASS.
- `git diff --check` - PASS, with line-ending conversion warnings only.
- Rendered browser smoke check - BLOCKED by browser security policy for `http://localhost:10016`; no workaround or form submission attempted.

## External Acceptance Checklist

- [ ] Confirm a current disposable-data backup immediately before testing.
- [ ] Confirm Clock is sandbox/demo and the API user has booking read/update rights.
- [ ] Create a fresh disposable pre-arrival Clock booking with mapped room type, room, and rate.
- [ ] Reread and record the original Clock/WordPress state.
- [ ] Test pre-arrival same-type room assignment, then reread both systems.
- [ ] Test pre-arrival room-type upgrade and verify positive financial-review metadata without payment activity.
- [ ] Test pre-arrival downgrade and verify negative financial-review metadata without refund activity.
- [ ] Test combined dates plus room/type amendment and verify final Clock/WordPress equality.
- [ ] Simulate/replay a post-write reread failure and verify the reconciliation job rereads before another mutation.
- [ ] Confirm no checked-in room move control is available and no guessed provider mutation occurs.
- [ ] Confirm no Stripe/PokPay charge/refund and no Clock folio credit item was created.
- [ ] Remove or cancel disposable external records according to the approved cleanup plan.

Do not change the existing Clock inbound PUSH/SNS acceptance checklist; that feature remains separately blocked on real signed external replay.
