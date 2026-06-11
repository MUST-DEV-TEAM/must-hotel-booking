# MUST Hotel Booking Codex Instructions

This is the automatic project instruction file for Codex work on the MUST Hotel Booking WordPress plugin.

## Project
- This is the MUST Hotel Booking WordPress plugin.
- Work only inside this plugin unless explicitly told otherwise.

## Scope
- Do not modify unrelated files.
- Do not copy logic from other projects/plugins unless explicitly requested.
- Preserve existing booking, payment, dashboard, database, shortcode, frontend, admin, staff portal, and integration behavior.

## Mandatory Workflow For Every Future Task
1. Read `AGENTS.md`.
2. Read `docs/INDEX.md`.
3. Read only the relevant docs under `docs/` for the task.
4. Prefer the docs knowledge base before searching the codebase.
5. Run `git status --short` before editing and preserve unrelated dirty worktree changes.
6. If docs conflict with current code, trust current code and update relevant docs only when the task clarifies durable project knowledge.
7. Use targeted search only.
8. Do not scan the whole plugin unless necessary.
9. Keep changes minimal and scoped.
10. Do not modify unrelated files.
11. After editing, run relevant checks.
12. Final response must include:
    - Files changed.
    - What changed.
    - How to test.
    - Checks run.
    - Risks / follow-up.
    - Docs updated or not updated.

## Search Rules
- Use `rg` or equivalent targeted search.
- Search by class, function, table, template, shortcode, hook, or CSS class.
- Avoid broad repo scanning.
- Read relevant docs first.
- Treat docs as a navigation aid, not as proof when current code disagrees.

## WordPress Rules
- Escape output in templates.
- Sanitize input.
- Use nonces for forms and admin/staff/customer actions.
- Check capabilities for admin actions.
- Do not trust `$_GET`, `$_POST`, cookies, sessions, or external API responses.
- Preserve managed pages, routes, rewrite rules, Elementor/theme compatibility, and existing hooks.

## Database Rules
- Schema changes must be idempotent.
- Preserve existing data.
- Do not rename tables/columns without an explicit migration plan.
- Update installer/upgrade logic if schema changes are requested.
- Do not delete customer, guest, booking, payment, room, availability, or integration data unless explicitly requested.

## Booking Rules
- Do not double-reserve inventory.
- Do not confirm unpaid bookings unless the existing lifecycle explicitly allows it.
- Do not release availability except through proper cancellation, expiration, or refund logic.
- Avoid placing business logic directly inside templates.
- Use service/repository/lifecycle classes where they exist.

## Payment Rules
- Never mark a booking as paid without verified payment logic.
- Do not fake successful payments.
- Keep payment provider code isolated from booking core logic.
- Webhook/payment/refund logic must be idempotent.
- Log payment events safely if logging exists.
- Preserve existing `pay_at_hotel`, Stripe, PokPay, refund, and Clock reconciliation behavior.

## Admin Dashboard Rules
- Check capabilities.
- Keep admin pages consistent with existing UI.
- Preserve filters, pagination, actions, and URLs.
- Do not mix admin logic with staff portal or guest-facing logic.

## Staff Portal Rules
- Preserve `/staff` and `/staff-login`.
- Preserve staff portal modules: dashboard, reservations, calendar, front desk, guests, payments, housekeeping, rooms & availability, and reports.
- Check permissions/security before exposing staff actions or data.
- Keep staff portal UI consistent with existing patterns.

## Customer/Guest Rules
- No separate logged-in customer dashboard was found from current code inspection.
- Guest-facing pages are checkout and booking confirmation.
- Do not expose bookings, payments, invoices, or profile data to the wrong user/session.
- Preserve guest checkout/confirmation behavior.

## UI Rules
- Scope CSS under plugin-specific wrapper classes.
- Avoid global selectors.
- Avoid breaking Elementor, Hello Elementor, or the active theme.
- Reuse existing card/button/table/tab/filter styles.
- Keep JS scoped and avoid global side effects.

## Integration Rules
- Do not guess API behavior.
- Use docs and current code first.
- Preserve Stripe, PokPay, Clock PMS, Elementor widget, and GitHub updater behavior where present.
- Keep integration logic isolated from core booking logic.

## Knowledge Base Maintenance Rules
- After every future task, update the relevant docs only if the task changed or clarified architecture, file locations, booking flow, payment flow, refund behavior, database structure, admin behavior, staff portal behavior, UI conventions, integrations, troubleshooting knowledge, or project decisions. Do not update docs for tiny cosmetic/code-only changes unless the change teaches something useful for future Codex tasks.
- Treat `docs/` as the project knowledge base.
- Add major decisions to `docs/DECISIONS.md`.
- Add useful completed-task notes to `docs/TASK_LOG.md`.
- Keep docs concise.
- Do not turn docs into long chat logs.

## Verification
- Run `php -l` for changed PHP files.
- Run existing lint/test/build commands if available.
- For documentation-only tasks, no PHP lint or runtime/plugin test is required.
- If a check cannot be run, say why.

## Final Response Format
- Files changed
- What changed
- How to test
- Checks run
- Risks / follow-up
- Docs updated
