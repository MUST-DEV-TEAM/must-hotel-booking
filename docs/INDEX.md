# Codex Knowledge Base Index

This folder is the concise project knowledge base for Codex task navigation. Do not read every doc by default. Read only the docs relevant to the task, then use targeted code search if details are still unknown.

## Routing
- Booking task: read `BOOKING_FLOW.md`, `DATABASE.md`, and maybe `PAYMENTS.md`.
- Payment/refund task: read `PAYMENTS.md`, `BOOKING_FLOW.md`, `DATABASE.md`, and maybe `INTEGRATIONS.md`.
- Admin dashboard task: read `ADMIN_DASHBOARD.md`, `UI_RULES.md`, and `DATABASE.md` if data-related.
- Staff portal task: read `STAFF_PORTAL.md`, `UI_RULES.md`, `FILE_MAP.md`, and maybe `DATABASE.md` or `PAYMENTS.md`.
- Customer/guest checkout task: read `CUSTOMER_DASHBOARD.md`, `BOOKING_FLOW.md`, and `PAYMENTS.md`.
- CSS/UI task: read `UI_RULES.md` plus the relevant dashboard or frontend doc.
- Database task: read `DATABASE.md`, `ARCHITECTURE.md`, and `FILE_MAP.md`.
- Integration task: read `INTEGRATIONS.md` plus `PAYMENTS.md` if payment-related.
- Debugging task: read `TROUBLESHOOTING.md` plus the relevant flow doc.
- Reservation amendment acceptance: read `RESERVATION_AMENDMENT_ACCEPTANCE.md`.

## Trust And Staleness
- These docs were generated from targeted current-code inspection on 2026-06-11.
- Use docs to route the task, then verify behavior in code before changing runtime logic.
- If docs and current code disagree, current code wins. Update the relevant doc only if the task clarifies durable project knowledge.
- Always run `git status --short` before editing and preserve unrelated existing changes.

## Known Uncertain Areas
- Older task prompts referenced inspected versions `0.4.71`/`0.4.72`; the current local plugin header reports `0.4.80`.
- No separate logged-in customer account dashboard was found.
- No `add_shortcode` registration was found in targeted inspection.
- Production provider credentials, Clock API permissions, and site/theme overrides are unknown from current code inspection.

## Core Docs
- `PROJECT_CONTEXT.md`: short project summary, user types, and current-code assumptions.
- `ARCHITECTURE.md`: bootstrap, main layers, routes, pages, hooks, and integrations.
- `FILE_MAP.md`: quick file-to-purpose map for token-saving navigation.
- `STAFF_PORTAL.md`: staff portal modules, routes, capabilities, templates, and action safety notes.
- `DECISIONS.md`: concise decision log.
- `TASK_LOG.md`: lightweight completed-task notes useful to future Codex runs.
