# Customer And Guest Pages

## Current Finding
No separate logged-in customer account dashboard was found from current code inspection.

## Guest-Facing Pages
- `/checkout`: guest information, selected room summary, coupon/price validation, and continue-to-confirmation flow.
- `/booking-confirmation`: confirmation/payment step and booking summary.

## Visibility
- Guests interact with booking selection/session state and confirmation URLs.
- Staff/admin booking visibility is handled separately in admin pages and `/staff`.
- Do not expose bookings, payments, invoices, guest profile data, or provider metadata to the wrong user/session.

## Session/Security
- Booking locks use a session cookie managed by `LockEngine`.
- Checkout form actions use nonces.
- PokPay finalization uses a REST nonce and validates the order/reservation relationship against stored payment rows.
- Confirmation row access from URL parameters exists; exact ownership constraints beyond current flow/session are Unknown from current code inspection.

## Important Files
- `src/Frontend/checkout-page.php`
- `src/Frontend/confirmation-page.php`
- `frontend/templates/checkout.php`
- `frontend/templates/booking-confirmation.php`
- `assets/css/booking-page.css`
- `assets/js/booking-confirmation.js`
- `assets/js/booking-phone-fields.js`

## Rules To Preserve
- Do not invent a customer dashboard unless explicitly requested.
- Preserve guest checkout/confirmation flow.
- Keep guest-facing data minimal and scoped to the booking flow.
- Sanitize all request values and escape template output.
