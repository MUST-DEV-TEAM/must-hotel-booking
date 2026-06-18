# Clock Inbound Research Cache

This directory stores concise, sanitized implementation notes and acceptance evidence for Clock PMS inbound PUSH notifications delivered through Amazon SNS.

Raw vendor documentation snapshots belong under `tmp/vendor-docs/clock/` and must not be committed or included in production packages.

Authoritative sources:
- Official Clock PMS+ API documentation for Clock PUSH behavior.
- Official AWS documentation for Amazon SNS message envelopes, signature verification, and subscription confirmation.
