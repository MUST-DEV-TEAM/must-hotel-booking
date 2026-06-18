# Clock Inbound Acceptance Evidence

## Status

BLOCKED.

Local fixture tests alone are not sufficient for PASS. The code audit and local verification passed, but the expected production host was unreachable and no real Clock SNS subscription confirmation, notification, duplicate replay, or Clock/WordPress reread comparison was available.

## Root cause

1. Code defect: transient Clock booking-fetch failures could still be acknowledged and permanently deduplicated even though no local booking change was durably applied.
2. External gap: the inspected local site generates an ngrok Clock webhook URL, while the requested production URL uses `new.empirebeachresort.al`. The production host timed out and the Clock account endpoint configuration/deployed plugin version could not be inspected.

## Evidence

- Repository: branch `main`, commit `33c591621034f374f67ae9595f910488f0404062`.
- Inspected plugin version: `0.4.81`.
- CLI PHP: `8.2.12`; Local web PHP observed through the callback: `8.5.1`.
- WordPress environment: local/staging; `WP_DEBUG=false`.
- Local effective webhook: `https://headwear-cranium-daydream.ngrok-free.dev/wp-json/must-hotel-booking/v1/clock/webhook`.
- Expected production webhook: `https://new.empirebeachresort.al/wp-json/must-hotel-booking/v1/clock/webhook`.
- Local callback probe: controlled `403` for unsigned `{}`, `Allow: POST`, no redirect.
- Production callback probe: connection timed out.
- Clock provider mode enabled; sandbox environment; `/bookings/{booking_id}` fetch path; automatic sync enabled.
- Clock API credentials are present; values were not recorded.
- Mappings: 4 accommodation, 81 physical-room, 4 rate-plan.
- Optional Basic auth is not configured and is not half-configured.
- Legacy token/HMAC secret is not configured and is not required for official SNS.
- No real SNS acceptance evidence was found.

## Code defects fixed

- Retryable failures now return `503` and are not permanently deduplicated.
- Official status-specific subjects provide a safe status fallback when booking detail fetch is temporarily unavailable.
- Detail-only events are not acknowledged as applied without fetched details.
- Missing mapping/upsert failures remain retryable and queue a later booking-upsert reconciliation job.
- Half-configured Basic authentication is rejected.
- Reverse-proxy/server authorization fallbacks are supported.
- Malformed nested SNS JSON is rejected.
- Concurrent deliveries use a database advisory lock.
- Repeated successful subscription confirmations do not follow `SubscribeURL` again.
- Request logs retain sanitized summaries only.

## Checks

- PHP lint passed for all changed PHP files.
- `php tests/ClockInboundWebhookSnsTest.php`: `Clock inbound SNS replay tests passed.`
- `php tests/ClockInboundSyncServiceTest.php`: `Clock inbound service tests passed.`
- `php tools/lifecycle-sync-smoke-test.php`: `PASS`.
- Read-only lifecycle harness: exit `0`, `overall_status=BLOCKED`; configuration checks passed, but no real inbound replay ran.
- `git diff --check`: passed; line-ending warnings only.
- Static secret scan: no credentials or private keys found.
- `tmp/vendor-docs/clock/` ignore check: passed.
- Provider preflight retry could not initialize PHP because the paging file was too small.

## Acceptance matrix

| Requirement | Result |
| --- | --- |
| Exact deployed production plugin version known | BLOCKED |
| Public production HTTPS route reachable | BLOCKED |
| Real SNS subscription confirmation | BLOCKED |
| Real signed notification accepted | BLOCKED |
| Correct mapped WordPress reservation updates | BLOCKED |
| Real duplicate replay has no duplicate side effects | BLOCKED |
| Invalid signatures and unsafe URLs rejected | PASS locally |
| Sanitized support logs | PASS locally |
| Clock/WordPress state reread comparison | BLOCKED |

## External records or settings changed

None. Public probes were unsigned/invalid and non-destructive.

## Rollback

Revert the Clock inbound controller/service/request-log repository, settings diagnostics, E2E harness, focused tests, and documentation changes from this task. No schema migration or external rollback is required.

## Remaining blockers

- Deploy and confirm the version containing these fixes on the intended site.
- Set the intended production public callback base to `https://new.empirebeachresort.al` if that is the actual deployed origin.
- Restore production-host reachability and confirm POST requests are not redirected or blocked.
- Configure the exact endpoint in Clock PUSH.
- Receive one real signed `SubscriptionConfirmation`.
- Use an approved disposable Clock booking to verify notification processing, duplicate replay, lifecycle side effects, and both-system rereads.
- Add/configure an expected SNS `TopicArn` allow-list and consider explicit signing-certificate chain/issuer validation.

## Production readiness

No. Real Clock subscription confirmation, notification processing, duplicate replay, and state comparison have not passed.
