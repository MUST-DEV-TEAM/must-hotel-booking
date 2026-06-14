# E2E Harness

This folder contains production-readiness E2E tooling for the MUST Hotel Booking plugin.

Run from the plugin root:

```bash
php tests/E2E/production-lifecycle-harness.php
```

The harness is read-only by default. It verifies non-production configuration, backup readiness, callback/webhook readiness, and reports which Stripe, PokPay, and Clock lifecycle checks can safely run.

External writes must only be enabled in confirmed non-production systems:

```bash
php tests/E2E/production-lifecycle-harness.php --allow-external-writes
```

Even with `--allow-external-writes`, the harness blocks writes unless backups are present, Stripe/PokPay/Clock are non-production, and public callback/webhook prerequisites are satisfied.
