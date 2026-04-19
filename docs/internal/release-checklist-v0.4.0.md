# Release Checklist — v0.4.0

## Before releasing

- [ ] `MUST_HOTEL_BOOKING_VERSION` in `must-hotel-booking.php` is `0.4.0`
- [ ] `Stable tag` in `readme.txt` is `0.4.0`
- [ ] `php -l` passes on all modified PHP files
- [ ] Local mode booking flow works end-to-end (search → checkout → confirmation → payment)
- [ ] Local mode admin actions work (cancel, check-in, payment update)
- [ ] No frontend design changes introduced

## Clock mode pre-flight (if deploying Clock)

- [ ] Clock enabled and base URL / API user / API key set
- [ ] Property ID set
- [ ] All three public booking paths configured (availability, quote, reservation_create)
- [ ] At least one accommodation mapping saved
- [ ] Rate plan mappings saved for all rate plans exposed to guests
- [ ] Physical room mappings saved for rooms used in room-assignment operations
- [ ] Settings > Provider > Readiness checklist shows no red items for intended capabilities
- [ ] WP-Cron is running (real cron strongly preferred)
- [ ] `must_hotel_booking_provider_sync` cron event is scheduled (visible in Diagnostics)
- [ ] Webhook URL registered in Clock dashboard pointing to `{site}/wp-json/must-hotel-booking/v1/clock/webhook`
- [ ] `clock_webhook_secret` set and matches Clock dashboard secret
- [ ] Test reservation created via public booking flow — mirror record appears in admin
- [ ] Test cancellation from admin — Clock receives cancel request (check request log)
- [ ] Inbound status webhook received and mirror reservation updated

## Database

- [ ] After upgrade, `must_hotel_booking_db_version` option equals `0.4.0`
- [ ] Tables `mhb_provider_mappings`, `mhb_provider_request_logs`, `mhb_provider_sync_jobs` exist
- [ ] No errors in `dbDelta` output (check via Diagnostics > Run Database Check)

## Post-release

- [ ] Monitor Settings > Provider > Sync Health for failed/exhausted jobs in first 24h
- [ ] Confirm inbound sync is receiving and applying webhook payloads
- [ ] Confirm no `bad_response_body` or unresolved-token errors in request log
