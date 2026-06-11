# Integrations

## Stripe
- Main code: `src/Engine/PaymentEngine.php`, `src/Engine/Payment/StripePayment.php`, `src/Engine/PaymentRefundService.php`.
- Settings defaults/sanitization: `src/Core/MustBookingConfig.php`.
- Webhook URL helper returns `must-hotel-booking/v1/stripe/webhook`.
- REST route verifies Stripe signature using the configured webhook secret.
- Successful `checkout.session.completed` confirms reservations, marks payment paid, creates payment rows, and runs Clock payment reconciliation if available.
- Refund webhooks handled by `PaymentRefundService` for `refund.created`, `refund.updated`, and `charge.refunded`.

## PokPay
- Main code: `src/Engine/PaymentEngine.php`, `src/Engine/Payment/PokPayPayment.php`, `src/Engine/PaymentRefundService.php`.
- Settings include environment-specific merchant/key fields in `MustBookingConfig`.
- API base can be production or staging; JS SDK URL is `https://static.pokpay.io/public/dist/pokpayments/pok-payment.js`.
- Finalization endpoint: `must-hotel-booking/v1/pokpay/finalize`.
- Error endpoint: `must-hotel-booking/v1/pokpay/error`.
- Finalization validates REST nonce, order ID, reservation IDs, and payment row/order relationship.
- Refunds may require manual completion if automatic PokPay API refund fails or is unavailable.

## Clock PMS
- Main provider directory: `src/Provider/Clock`.
- Provider registration happens through `src/Provider/ProviderManager.php`.
- Modes found: local and Clock.
- Clock support includes availability, quote, booking/reservation provider behavior, catalog service, inbound sync, auto sync scheduler, payment reconciliation, folio payment sync, folio refund sync, mappings, and diagnostics.
- Clock webhook URL is exposed in Clock config as `must-hotel-booking/v1/clock/webhook`.
- Some Clock payment/refund accounting may require manual review if API rights or folio IDs are missing.

## Elementor Widgets
- Booking search widget: `src/Elementor/Booking_Search_Widget.php` and `src/Elementor/booking-search-widget.php`.
- Rooms list widget: `src/Elementor/Rooms_List_Widget.php` and `src/Elementor/rooms-list-widget.php`.
- Rooms text grid widget: `src/Elementor/Rooms_Text_Grid_Widget.php` and `src/Elementor/rooms-text-grid-widget.php`.
- Assets live in `assets/css/*widget.css` and `assets/js/*widget.js`.
- Widgets register through Elementor hooks and check Elementor classes before registration.

## GitHub Updater/Plugin-Update-Checker
- Main code: `src/Core/Updater.php`.
- Library: `lib/plugin-update-checker/plugin-update-checker.php`.
- Constants in `must-hotel-booking.php` configure repository URL, branch, release asset pattern, token, slug, and updater enablement.
- Updater expects release ZIP names matching the configured pattern.

## Settings/Options
- Payment settings include `payment_methods` plus Stripe and PokPay environment credentials in `MustBookingConfig`.
- Clock settings include PMS/Base API URL details, credentials, webhook shared secret, provider mode, WBE inline mode settings, and mapping settings.
- Staff portal and managed page settings are stored through `MustBookingConfig`.

## Known Limitations
- Exact provider API behavior must be verified from provider docs and current code; do not guess.
- Clock permissions can block automatic folio payment/refund posting.
- Production credentials and provider account configuration are unknown from current code inspection.

## Rules
- Keep integration logic isolated from core booking logic.
- Do not log secrets.
- Preserve idempotency keys and provider request logs.
- Do not change provider endpoints, payloads, or status mapping without verifying current code and provider docs.
