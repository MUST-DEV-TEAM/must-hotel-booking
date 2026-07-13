# Changelog

Notable product, architecture, integration, operational, compatibility, and security changes are recorded here. Exact implementation history remains in Git.

## Unreleased

### Changed

- Consolidated repository documentation into the canonical structure routed by `docs/INDEX.md`; no runtime behavior changed.

### Security

- Documented unresolved confirmation-page access-control and payment/Clock recovery risks for phased remediation. No security fix is included in this documentation change.

## 0.4.90 - 2026-07-13

### Fixed

- Made the GitHub release-package workflow produce a Linux-compatible canonical plugin ZIP.

## 0.4.89 - 2026-07-12

### Changed

- Changed Clock-backed Stripe/PokPay checkout to create a local pending-payment mirror first and defer the Clock reservation until server-verified payment.
- Added amount, currency, reservation-set, and stored-payment binding checks around Stripe and PokPay completion.
- Added a local Clock-fulfillment claim and pending-fulfilment state for retryable failures.

### Known limitations

- The release did not add a durable provider-paid outcome ledger before Clock fulfillment, an internal recovery job for stranded fulfillment, or behavioral concurrency coverage for duplicate callbacks.

## 0.4.88 - 2026-07-07

### Added

- Split Clock synchronization into full-catalog, availability/rate, and reservation-fallback schedules with separate diagnostics and repair controls.

## 0.4.87 - 2026-07-06

### Added

- Added configurable public date-picker and stored price-breakdown display modes.
- Added bidirectional website/Clock booking-reference metadata and retry diagnostics.

### Changed

- Disabled Pay at Hotel by default and blocked public checkout when no explicitly allowed payment method is configured.

## 0.4.86 - 2026-06-24

### Fixed

- Hardened Clock deposit verification for signed raw folio balances, normalized held amounts, credit-item evidence, and standard-folio isolation.

## 0.4.85 - 2026-06-23

### Added

- Added signed server-side quote drafts, bounded performance diagnostics, and short caches for intermediate Clock reads.

### Fixed

- Required uncached final Clock availability, price, and guarantee-policy validation before reservation creation.
- Bounded provider-sync work per WP-Cron slice.

## 0.4.83 - 2026-06-22

### Fixed

- Hardened PokPay credential diagnostics, provider-data redaction, Clock rate limiting, and deposit-folio idempotency/isolation handling.

## 0.4.82 - 2026-06-18

### Added

- Added shared reservation amendment services for local and supported Clock pre-arrival changes.
- Added cancellation-time financial snapshots and explicit manual-review boundaries.

### Fixed

- Hardened Clock SNS inbound retry/deduplication and routed provider status changes through the local lifecycle service.

## 0.4.81 - 2026-06-14

### Added

- Added Clock PMS Amazon SNS webhook handling and broader payment/refund/cancellation duplicate-event protections.

## 0.4.76 - 2026-06-12

### Added

- Added provider-fee snapshots, default refund breakdowns, customer cancellation policy handling, and equal-version payment/refund schema repair.

## 0.4.74 - 2026-06-11

### Added

- Added the first Codex knowledge base and provider/payment accounting hardening documentation.

## 0.4.5 - 2026-05-18

### Added

- Added PokPay SDK payment-method integration.

## 0.4.0 - 2026-04-19

### Added

- Introduced provider contracts, local adapters, Clock PMS availability/quote/reservation/sync/accounting services, mappings, request logs, and provider job infrastructure.

## Initial development - 2026-03-12 to 2026-03-27

### Added

- Established the WordPress plugin, frontend booking flow, Stripe environment support, inventory/availability services, admin reservation/payment/report services, staff portal, and Elementor widgets.
