# Task Log

## 2026-06-13 - Provider cancellation lifecycle completion and preflight
- Changed: Extended `BookingLifecycleSyncService` so paid Stripe/PokPay bookings cancelled from Clock/provider sync create one `refund_review_required` / `clock_cancellation_review` row when no active/completed refund already exists.
- Changed: Added admin payment warning/list metadata for refund-review rows, a standalone lifecycle smoke test, and a read-only provider preflight report for Clock/Stripe/PokPay.
- Verified: Lifecycle smoke test passes for paid Stripe Clock cancellation idempotency: one cancellation hook and one refund-review row with provider-fee-aware amount.
- Verified: Read-only provider preflight reached Clock sandbox booking fetch/folio endpoints, Stripe balance auth, and PokPay staging SDK-order fetch with configured credentials.
- Blocked: Full destructive live provider E2E is still blocked by missing Clock webhook secret, missing Stripe webhook secret, and webhook URLs resolving to localhost because public callback base is blank.
- Checks: PHP syntax checks passed on changed PHP files and CLI tools.

## 2026-06-13 - Clock cancellation lifecycle routing
- Changed: Added `BookingLifecycleSyncService` and routed Clock inbound webhook/refresh status changes, Clock booking upsert status changes, and successful outbound Clock cancellation reconciliation through it.
- Fixed: Clock-originated cancellations no longer update only the reservation repository; they now pass through `BookingStatusEngine::updateReservationStatuses()` so cancellation hooks and email handlers run once when the status first changes to `cancelled`.
- Limits: No live Clock/PokPay/Stripe E2E was run in this pass; provider credentials and callback reachability still need preflight before destructive sandbox tests.
- Checks: PHP syntax checks passed on changed PHP files.

## 2026-06-13 - PokPay automatic refunds and Clock deposit posting
- Changed: Re-enabled PokPay automatic refunds using the documented merchant SDK-order refund endpoint and retained `manual_pending` only when PokPay does not confirm the refund.
- Changed: Added the verified Clock deposit workflow for future online payments: create or reuse a booking folio with `deposit=true`, then post the Stripe/PokPay external provider payment as a credit item on that deposit folio.
- Verified: PokPay staging SDK order `8b29da85-881a-4e05-b4de-2dc9180ad309` refunded successfully and fetched back as `REFUNDED`; duplicate refund returned a controlled provider failure/manual fallback.
- Verified: Clock sandbox booking `36591448` received deposit folio `71442250`, deposit payment credit item `60052192`, duplicate payment sync returned `already_posted`, and deposit refund/reversal credit item `60052222` returned the deposit folio balance to zero.
- Limits: Clock UI Required Deposit / Deposit Payment fields were not visually verified because no dashboard browser session was available; the result is based on official API docs/support behavior plus API read-back.
- Checks: PHP syntax checks passed on changed PHP files, and the Clock accounting readiness/environment report showed sandbox/test configuration with deposit endpoints configured.

## 2026-06-13 - PokPay refund and Clock deposit audit follow-up
- Changed: Corrected stale staff portal/developer docs that still described PokPay automatic refunds through the guessed `sdk-orders/{orderId}/refund` path.
- Evidence: Git history shows releases `v0.4.74`/`v0.4.75` attempted that path, and local refund row `5` was marked succeeded with a provider refund UUID, but PokPay requests were not logged and official docs still do not publish a refund endpoint or contract.
- Clock: Official Clock collection exposes `booking_folio - CREATE` with `deposit=true` and generic folio `credit_items`, but no verified API sequence for an already-captured website payment to appear as Required Deposit / Deposit Payment without settling the normal folio balance.
- Superseded: Later verification in this task found the public PokPay Postman/API refund endpoint and verified the Clock deposit-folio workflow.

## 2026-06-13 - PokPay refund API safety correction
- Changed: Stopped calling the unverified `merchants/{merchantId}/sdk-orders/{orderId}/refund` path and made PokPay refunds manual-required by default.
- Reason: Official PokPay docs verified in this pass list SDK order create/fetch/confirm/capture endpoints, but no refund endpoint, payload, status polling, webhook, or idempotency behavior.
- Behavior: PokPay refund requests now create `manual_pending` refund records; staff must refund in the POK dashboard and mark the refund completed in the plugin before local refund ledger/cancel-after-refund/Clock refund accounting continue.
- Checks: PHP syntax checks were run on the touched PHP files. Full provider sandbox payment/refund E2E was not run in this pass.
- Superseded: Later verification in this task found PokPay's public Postman/API refund endpoint and restored automatic-first refunds with manual fallback.

## 2026-06-13 - Public callback base and Clock/payment E2E attempt
- Changed: Added application-level E2E backup/settings/cleanup CLI helpers, optional public callback base URL support, public-host URL rewriting for provider callback/tunnel requests, and admin/diagnostic display of public Stripe/PokPay/Clock callback URLs.
- Verified: Backup `tools/backups/clock-e2e-20260613-152054` was created before option mutation; public callback base `https://headwear-cranium-daydream.ngrok-free.dev` generated Stripe/PokPay/Clock webhook URLs; emulated public-host booking HTML returned no `localhost` references; local booking -> accommodation -> checkout -> confirmation reached Stripe Checkout with real staging/sandbox credentials.
- Test artifact: Stripe session `cs_test_a1ritVtK4EDrQuinXs6a3DLSrDOPRaIhhKAAZTigeBzMvZd4Ep8tvLkyYg` created local reservation `128` / booking `MHB-20260613-263D7D6F` / Clock sandbox booking `36591448`; payment was not completed.
- Cleanup: Local reservation `128` was cancelled and payment `54` marked failed via `tools/clock-e2e-cleanup.php`; public callback base was restored to blank. Clock cancellation was queued as provider sync job `4` because CLI Clock API access failed with cURL error 7 in the sandbox.
- Blocked: Full paid Stripe/PokPay/Clock accounting E2E was not completed. Remaining blockers are unsandboxed/public network access for provider callbacks/Clock API retries, missing Stripe webhook secret for true webhook verification, and the still-unverified Clock booking deposit/prepayment endpoint for future-stay accounting.

## 2026-06-13 - Clock accounting E2E environment probe
- Changed: Added `tools/clock-accounting-environment-report.php`, a CLI-only read-only report for sanitized site, Clock, payment, recent reservation, payment, and Clock accounting state.
- Verified: Clock is configured as `sandbox`; provider mode is `clock`; checkout can offer `pokpay` and `stripe`; active site environment is `staging`; active ngrok public URL `https://headwear-cranium-daydream.ngrok-free.dev` reaches the local WordPress site when public network access is allowed.
- Blocked: WordPress still generates REST/webhook URLs as `http://localhost:10016/...`, `mysqldump` is not available in the shell, and no full DB backup was created before URL/option mutation. Real provider callback E2E should not proceed until a backup exists and public callback URLs are restored after testing.
- Checks: Read-only readiness/report tools and invalid webhook reachability checks were run; Stripe invalid signature was rejected with a controlled `400`.

## 2026-06-13 - Clock accounting safety cleanup
- Changed: Replaced the unsafe temporary Clock E2E script with a CLI-only read-only readiness tool, added structured Clock accounting reason codes, rejected unsafe endpoint overrides, and added admin handling for payment accounting rows marked `handled_manually`.
- Notes: Deposit/prepayment automation remains intentionally blocked until Clock confirms the endpoint, method, payload, API area, rights, external reference/idempotency behavior, and refund/reversal flow.
- Checks: PHP syntax checks were run on changed PHP files. Endpoint validation and readiness checks were run read-only; DB-mutating accounting probes were not run.

## 2026-06-12 - Clock future-payment accounting guard
- Changed: Added `ClockEndpointRegistry`, endpoint override settings, Clock payment posting mode, diagnostics fields, and a conservative payment-accounting branch that stops future online payments from posting as folio `credit_items` unless a verified booking deposit/prepayment endpoint exists.
- Docs: Updated integration, payment, and troubleshooting notes with the Clock support question needed to verify external already-paid deposit/prepayment posting.
- Verification: PHP syntax checks were run on changed PHP files. No Clock sandbox payment test was run, so deposit automation is not claimed complete.

## 2026-06-12 - PokPay SDK checkout modes
- Changed: Added selectable PokPay SDK checkout modes: `embedded_sdk` renders the PokPay SDK container on the confirmation page, and `sdk_confirm_url_redirect` creates the same SDK order then redirects to the provider `sdkOrder._self.confirmUrl`.
- Notes: Both modes use `POST merchants/{merchantId}/sdk-orders` and only mark paid after server-side `GET sdk-orders/{orderId}` verification. The admin setting is `pokpay_checkout_mode` under Payments -> Payment Settings -> PokPay Checkout.
- Checks: PHP syntax checks for payment/admin/confirmation files and `node --check assets/js/booking-confirmation.js` passed. Full PokPay card payment still requires a manual staging checkout.

## 2026-06-11 - Documentation knowledge base setup
- Changed: Created/updated Codex project instructions and concise docs knowledge base.
- Notes: No behavior changed. Current local plugin header reports `0.4.72`; the request referenced inspected version `0.4.71`.
- Checks: No PHP lint required because only markdown files changed. Verify with `git diff --stat`.

## 2026-06-11 - Knowledge base hardening pass
- Changed: Tightened future workflow rules, added code-vs-docs trust guidance, known uncertain areas, targeted search recipes, and a dedicated staff portal doc.
- Notes: No behavior changed.
- Checks: No PHP lint required because only markdown files changed. Verify with `git diff --stat`.

## 2026-06-11 - Sandbox payment integration blockers
- Changed: Fixed Clock scalar folio ID parsing, nested Clock folio balance verification, documented Clock booking cancellation default, removed unsupported PokPay SDK order fields, and added no-op/claim guards for duplicate payment/refund processing.
- Tests: Stripe test checkout, webhook, Clock payment credit item, duplicate checkout event, Stripe refund, refund webhook resend, negative Clock refund credit item, and Clock cancellation passed against sandbox/demo services.
- Blocked: PokPay staging order creation reached the provider but failed with `Expired token`; refresh the active staging `pokpay_staging_key_id` / `pokpay_staging_key_secret` credentials before rerunning PokPay payment, duplicate finalize, refund, and Clock refund tests.

## 2026-06-12 - Refund and cancellation release-readiness layer
- Changed: Added Stripe/PokPay provider fee snapshots, PokPay fee estimate settings, default refund breakdown storage, admin fee/refund diagnostics, and a two-column customer cancellation page using booking confirmation styling.
- Notes: Stripe was already manually verified end-to-end before this layer. PokPay is code-ready but still needs refreshed staging/live credentials for manual payment/finalize/refund testing.
- Rules: Default refund is paid amount minus stored provider fee and cancellation fee. Inside the 21-day/default cancellation window, customer self-service cancellation/refund is blocked and handled by hotel/staff.
- Checks: PHP syntax checks on changed PHP files. No full live Stripe/PokPay/Clock payment flow was run.

## 2026-06-12 - Final PokPay/refund release verification
- Changed: Added current-version schema repair for payment fee/refund breakdown columns, tightened PokPay nested fee extraction, clarified PokPay diagnostics fee source, and adjusted customer cancellation review to left action panel plus right booking summary.
- Verified: Active staging PokPay credentials passed auth preflight; one staging SDK order smoke test succeeded without local booking/finalize/refund; DB sanity confirmed payment/refund columns and PokPay finalize/error routes.
- Notes: Local PokPay fee estimate settings are currently missing, so PokPay payments that do not return an API fee will correctly block automatic customer refund/cancellation pending manual review.
- Checks: PHP syntax checks on changed PHP files. No full Stripe/Clock browser test or full PokPay payment/refund flow was run.
