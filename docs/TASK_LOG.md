# Task Log

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
