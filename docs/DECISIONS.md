# Decisions

## 2026-06-11 - Initial Codex knowledge base setup
- Decision: The project now uses `AGENTS.md` plus concise root-level `docs/` files as a Codex knowledge base.
- Reason: Future Codex tasks should read relevant docs before searching code, reduce token usage, and preserve booking/payment/dashboard/database behavior.
- Affected areas: Codex workflow, task routing, architecture notes, file map, booking flow, payments, admin/staff/customer docs, UI rules, database notes, integrations, troubleshooting, task logging.

## 2026-06-11 - Knowledge base hardening pass
- Decision: Treat docs as navigation guidance and current code as the final authority when conflicts appear.
- Reason: Future Codex tasks need the docs to reduce scanning without trusting stale behavior blindly.
- Affected areas: `AGENTS.md`, `docs/INDEX.md`, booking, payment, database, admin/staff portal docs, decisions, task log.

## 2026-06-11 - Clock booking cancellation endpoint
- Decision: Use Clock PMS `PUT /bookings/{booking_id}` with `booking.status = canceled` for provider cancellation sync.
- Reason: Clock docs list canceled/no-show as booking statuses and the sandbox API accepted the update without closing folios.
- Affected areas: Clock reservation provider, Clock config defaults, cancellation diagnostics, sandbox tests.

## 2026-06-12 - Provider fee snapshots and default refund math
- Decision: Store provider fee snapshots on successful Stripe/PokPay payments and default refunds to `paid amount - provider fee - cancellation fee`, never below zero.
- Reason: Refunds should retain payment processing fees by default and should not silently refund the full paid amount when the provider fee is unknown.
- Affected areas: payment schema, Stripe/PokPay finalization, refund service, admin payment workspace, customer cancellation page, support diagnostics.

## 2026-06-12 - Customer online cancellation window
- Decision: Customer cancellation links allow automatic cancellation/refund only when check-in is at least the configured policy window away, currently 21 days by default; inside the window, cancellation is hotel/staff handled.
- Reason: The business rule requires online self-service before 21 days and manual support inside 21 days.
- Affected areas: booking confirmation cancellation route/template, refund defaults, Clock cancellation sync.

## 2026-06-12 - Current-version schema repair
- Decision: Run an idempotent payment/refund schema repair even when the stored DB version is already greater than or equal to the plugin version.
- Reason: Release candidates can add columns while local/live databases already store the current version, so relying only on version-gated `dbDelta()` can leave required columns missing.
- Affected areas: plugin database upgrade bootstrap, `must_payments`, `must_refunds`, support diagnostics.
