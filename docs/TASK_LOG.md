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
