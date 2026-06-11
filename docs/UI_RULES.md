# UI Rules

## Main Wrapper Classes
- Public booking: `must-hotel-booking-page`, `must-booking-process-page`, `must-booking-*`.
- Accommodation cards/results: `must-hotel-booking-room-card`, `must-booking-accommodation-*`.
- Admin: `must-dashboard-page`, `must-dashboard-panel`, `must-*-admin`, page-specific wrappers such as `must-payments-admin`, `must-emails-admin`, `must-hotel-booking-rooms-admin`.
- Staff portal: `must-portal-*`.
- Elementor widgets: `must-hotel-booking-widget-booking-search`, `must-hotel-booking-rooms-list-*`, `must-hotel-booking-rooms-text-grid-*`.

## Existing Component Patterns
- Cards/panels: `must-dashboard-panel`, `must-dashboard-card`, `must-accommodation-record-card`, `must-portal-card`.
- Buttons/actions: WordPress `button`/`button-primary` in admin; plugin-specific action buttons under wrapper classes.
- Tables: WP admin `widefat striped` plus plugin wrapper/table classes.
- Tabs/filters: page-specific tab/filter classes in admin, portal, and booking pages.
- Pagination: admin pages and portal renderer use dedicated pagination wrappers.

## CSS Files
- `assets/css/booking-page.css`: public booking, checkout, confirmation.
- `assets/css/portal.css`: staff portal.
- `assets/css/admin-ui.css`: shared admin layout/components.
- `assets/css/admin-payments.css`, `admin-calendar.css`, `admin-rooms.css`, `admin-pricing.css`, `admin-availability.css`, `admin-emails.css`: page-specific admin styling.
- `assets/css/booking-search-widget.css`, `rooms-list-widget.css`, `rooms-text-grid-widget.css`: Elementor widget styling.

## JS Files
- `assets/js/booking-page.js`: booking availability/search interactions.
- `assets/js/booking-accommodation.js`: accommodation page filters/selection.
- `assets/js/booking-confirmation.js`: confirmation/payment interactions.
- `assets/js/booking-phone-fields.js`: checkout phone fields.
- `assets/js/portal-quick-booking.js`: staff portal quick-booking AJAX.
- `assets/js/admin-calendar.js`, `admin-rooms.js`, `admin-quick-booking.js`, `admin-settings.js`: targeted admin behavior.

## Staff Portal UI
- Use `must-portal-*` classes and portal templates under `frontend/templates/portal`.
- Preserve module navigation and capability-gated module visibility.
- Keep actions and notices consistent with `PortalController` and `PortalRenderer`.

## Admin UI
- Use existing WP admin conventions and plugin dashboard classes.
- Reuse existing cards, panels, filters, badges, tables, tabs, and pagination.
- Preserve admin page query args and action URLs.

## Guest Checkout/Booking UI
- Use shared booking process classes and `assets/css/booking-page.css`.
- Preserve booking step flow, forms, hidden inputs, nonces, and selected-room summaries.

## Elementor/Theme Safety
- Avoid global selectors.
- Scope CSS under plugin wrappers.
- Do not override broad Elementor, Hello Elementor, body, form, input, button, or table styles globally.
- Preserve Elementor global color/typography compatibility where settings support it.

## Rules For Adding CSS
- Scope under the nearest plugin wrapper.
- Reuse existing spacing, panel, card, table, filter, and button patterns.
- Keep responsive behavior consistent with nearby CSS.
- Do not add new frameworks unless explicitly requested.

## Rules For Adding JS
- Scope selectors to the relevant wrapper.
- Avoid global side effects.
- Preserve existing localized data, nonce checks, and AJAX/REST endpoints.
- Do not change booking/payment state client-side without server verification.

## Responsive Conventions
- Existing CSS uses page-specific responsive blocks and wrapper-scoped layout changes.
- Preserve mobile form/table/card readability.
- Unknown from current code inspection whether there is a single shared breakpoint system.
