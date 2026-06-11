# Staff Portal

## Inspection Provenance
Verified from targeted current-code inspection on 2026-06-11. Use this document for staff portal navigation, then verify behavior in the referenced files before changing portal routes, permissions, actions, or templates.

## Routes And Pages
- Staff portal page: `/staff`.
- Staff login page: `/staff-login`.
- Managed page definitions live in `src/Core/ManagedPages.php`.
- Portal rewrite/query-var routing lives in `src/Portal/PortalRouter.php`.
- Portal hooks are registered by `src/Portal/PortalBootstrap.php`.

## Modules
Registered in `src/Portal/PortalRegistry.php`:
- `dashboard`: dashboard route.
- `reservations`: reservations route.
- `calendar`: calendar route.
- `front_desk`: `front-desk` route.
- `guests`: guests route.
- `payments`: payments route.
- `housekeeping`: housekeeping route.
- `rooms_availability`: `rooms-availability` route.
- `reports`: reports route.

Deprecated routes redirect to newer modules:
- `quick-booking` -> `front_desk` with `tab=new-booking`.
- `accommodations` -> `rooms_availability` with `tab=rooms`.
- `availability-rules` -> `rooms_availability` with `tab=rules`.

## Permissions And Access
- Roles/capabilities are defined in `src/Core/StaffAccess.php`.
- Portal access is checked by `src/Portal/PortalAccessGuard.php`, `PortalAuthController`, and action methods in `PortalController`.
- `manage_options` commonly acts as an admin override.
- Module visibility depends on settings and current-user capabilities.
- Preserve nonce checks, `current_user_can()` checks, and redirect/notice behavior when changing actions.

## Rendering And Templates
- Main page template: `frontend/templates/staff-portal.php`.
- Login template: `frontend/templates/staff-login.php`.
- Module partials live in `frontend/templates/portal/*.php`.
- `src/Portal/PortalRenderer.php` renders shared layout helpers, notices, navigation, and pagination.
- Portal styles/scripts: `assets/css/portal.css`, `assets/js/portal-quick-booking.js`.

## Main Action Areas
- Quick booking/front desk actions and AJAX are in `src/Portal/PortalController.php` and `assets/js/portal-quick-booking.js`.
- Reservation actions include create, edit, cancel, check-in/check-out, room move, no-show, and notes where capabilities allow.
- Payment actions include mark paid, refund, and manual refund/Clock review flows where capabilities allow.
- Guest actions include contact updates, flags, notes, and merge behavior where capabilities allow.
- Housekeeping actions include room status updates, assignments, issue creation/status updates, and handoffs.
- Reports include operational, finance, management, audit, and export behavior depending on capability.

## Rules To Preserve
- Preserve `/staff` and `/staff-login`.
- Preserve module route keys, aliases, deprecated redirects, and query args unless explicitly changing portal navigation.
- Do not expose staff-only booking, guest, payment, refund, report, housekeeping, or audit data to unauthorized users.
- Keep portal UI under `must-portal-*` wrappers and reuse existing portal patterns.
- Do not mix staff portal actions into WP-admin handlers unless the code already shares a service/data provider boundary.

## Targeted Search Recipes
```bash
rg -n "getDefinitions|DeprecatedRoutes|front_desk|rooms_availability" src/Portal/PortalRegistry.php
rg -n "registerRewriteRules|getModuleUrl|must_hotel_booking_portal_route" src/Portal/PortalRouter.php
rg -n "current_user_can|wp_verify_nonce|must_portal_" src/Portal src/Core/StaffAccess.php
rg -n "frontend/templates/portal|renderPagination|must-portal" src/Portal frontend/templates/portal assets/css/portal.css
```
