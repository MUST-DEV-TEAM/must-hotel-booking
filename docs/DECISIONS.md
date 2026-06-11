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
