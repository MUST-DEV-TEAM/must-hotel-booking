# Troubleshooting & FAQ

**Plugin:** MUST Hotel Booking  
**Version:** 0.3.45  
**Audience:** Site owner, developer, support staff

---

## Table of contents

1. [First step: run the built-in diagnostics](#1-first-step-run-the-built-in-diagnostics)
2. [Installation issues](#2-installation-issues)
3. [Booking flow issues](#3-booking-flow-issues)
4. [Staff portal issues](#4-staff-portal-issues)
5. [Payment and Stripe issues](#5-payment-and-stripe-issues)
6. [Email issues](#6-email-issues)
7. [Settings issues](#7-settings-issues)
8. [Diagnostics and maintenance tools](#8-diagnostics-and-maintenance-tools)

---

## 1. First step: run the built-in diagnostics

Before investigating any problem, go to **MUST Hotel Booking > Settings > Diagnostics & Maintenance**. This tab runs `SettingsDiagnostics::getData()` and shows:

- **Overall status** — healthy / warning / error, plus a count of critical issues and warnings.
- **Table status** — each of the 19 plugin tables is checked individually. Missing tables are flagged as "error".
- **Managed pages** — each auto-created page is checked. Missing or invalid pages are flagged.
- **Cron status** — whether the lock-cleanup cron event is scheduled and when it next runs.
- **Payments** — whether Stripe is enabled, whether keys are entered, whether the webhook secret is set.
- **Emails** — sender email, notification recipient, number of email templates, recent failures.
- **Environment** — plugin version, database version, WordPress version, PHP version, room count.

You can also copy a plain-text **System Report** from this tab to share with a developer or support contact.

---

## 2. Installation issues

### 2a. Missing database tables

**Symptom:** The dashboard shows a warning: "Reservations table was not found. Reactivate the plugin to create the operational booking tables." Or the Diagnostics tab shows one or more tables with status "error / missing".

**Cause:** `install_tables()` did not complete during activation. This can happen if the database user lacks `CREATE TABLE` permission, if activation was interrupted, or if the plugin was updated by file replacement without triggering the normal activation hook.

**Fix:**

1. Go to **MUST Hotel Booking > Settings > Diagnostics & Maintenance** and note which tables are missing.
2. Deactivate the plugin: **Plugins > MUST Hotel Booking > Deactivate**.
3. Reactivate the plugin: **Plugins > MUST Hotel Booking > Activate**. Activation calls `install_tables()` again.
4. Reload **Diagnostics & Maintenance** and confirm all tables now show "healthy".

If tables are still missing after reactivation:
- Confirm the WordPress database user has `CREATE TABLE` and `ALTER TABLE` privileges.
- Check the WordPress debug log (`wp-content/debug.log`) for database errors during activation.
- The plugin also calls `maybeUpgradeDatabase()` on every `plugins_loaded` action; if the stored `must_hotel_booking_db_version` option is older than the current plugin version, `install_tables()` runs automatically on the next page load.

---

### 2b. Managed pages not created / pages show as "missing" in diagnostics

**Symptom:** The Diagnostics tab shows one or more managed pages as "missing" or "invalid". Visiting `/booking`, `/checkout`, or `/booking-confirmation` returns a 404.

**Cause:** Page creation during `ManagedPages::install()` failed, the pages were later trashed or deleted, or `managed_pages_suspended` is set (this happens after a factory reset).

**Fix:**

1. Go to **Settings > Managed Pages**.
2. For any page showing as missing or invalid, click **Recreate** to call `ManagedPages::recreatePage()`, which creates a new page and re-registers the page ID.
3. After recreating pages, go to **Settings > Permalinks** and click **Save Changes** to flush rewrite rules.

**If the Managed Pages tab shows a page with a valid ID but visiting the URL gives a 404:**

- The rewrite rules may be stale. Go to **Settings > Permalinks** and click **Save Changes**.
- The plugin flushes rewrite rules on activation and calls `PortalRouter::flushRewriteRules()` when the portal configuration changes, but a general permalink flush resolves most 404 cases.

**If auto-page creation is suspended:**

The option `must_hotel_booking_managed_pages_suspended` being set to `true` suppresses all automatic page creation and sync. This is set after a factory reset. An administrator must manually recreate or reassign pages via **Settings > Managed Pages** to resume normal behavior. Saving a valid page assignment calls `ManagedPages::resumeAutoManagement()` automatically.

---

### 2c. Plugin updated but new tables are missing

**Symptom:** After a plugin update (not a fresh install), new tables introduced in the update are absent.

**Cause:** The update mechanism updates files but the database migration has not run yet.

**Fix:** The migration runs automatically on the next `plugins_loaded` action via `maybeUpgradeDatabase()`, which compares the stored `must_hotel_booking_db_version` option against `MUST_HOTEL_BOOKING_VERSION`. Loading any page on the site (front end or admin) triggers this. If tables are still missing after one page load, deactivate and reactivate the plugin to force a full `install_tables()` run.

---

## 3. Booking flow issues

### 3a. Booking page or Select Accommodation page returns 404

**Cause:** Permalink structure not flushed, or the managed page was trashed.

**Fix:**
1. Check **Settings > Managed Pages** — confirm `page_booking_id` and `page_booking_accommodation_id` show as "ok".
2. If missing, click **Recreate**.
3. Go to **Settings > Permalinks > Save Changes** to flush rewrite rules.

---

### 3b. Availability always shows "unavailable" / no rooms appear

**Possible causes and fixes:**

| Check | How to verify | Fix |
|-------|--------------|-----|
| No rooms exist in the system | Go to **Accommodations** — if the list is empty, add a room | Add at least one room |
| Room is marked inactive or not bookable | In **Accommodations**, edit the room. Check `is_active`, `is_bookable`, `is_online_bookable` flags | Enable all three flags |
| All dates are blocked by an availability rule | Go to **Availability Rules** and review rules that cover the requested dates | Remove or adjust the blocking rule |
| Requested dates are before today or beyond the booking window | Booking window defaults to 365 days. Check **Settings > Booking Rules > Booking window** | Increase the booking window if needed |
| A minimum nights rule is set that the selected dates do not satisfy | Check **Settings > Booking Rules > Minimum nights** and any pricing rules with `minimum_nights > 1` | Adjust the minimum nights setting or the pricing rule |
| Inventory lock from an incomplete session is blocking the dates | Locks expire automatically. The cron event cleans them up | Confirm the cron is scheduled (Diagnostics tab). Wait for the lock to expire, or deactivate/reactivate the plugin to reschedule the cron |

---

### 3c. Checkout page 404 or booking confirmation page 404

Same resolution as 3a — check **Settings > Managed Pages** for `page_checkout_id` and `page_booking_confirmation_id`, recreate if missing, flush permalinks.

---

### 3d. Booking confirmation does not show a booking reference

**Cause:** The confirmation page is displayed without a valid session or the session expired before the guest reached the page.

**Unverified / needs confirmation:** The exact session/transient mechanism linking the checkout to the confirmation page was not inspected in detail. Verify that the guest is not navigating to the confirmation page directly without completing checkout.

---

## 4. Staff portal issues

### 4a. Staff portal is not enabled

**Symptom:** Visiting `/staff` or `/staff-login` returns 404.

**Cause:** The staff portal is disabled by default (`enable_staff_portal: false`).

**Fix:**
1. Go to **Settings > Staff & Access**.
2. Enable the "Enable Staff Portal" option.
3. Save settings.
4. Go to **Settings > Managed Pages** and confirm both `portal_page_id` and `portal_login_page_id` show as "ok". If missing, recreate them.
5. Go to **Settings > Permalinks > Save Changes** to flush rewrite rules.

---

### 4b. Staff user cannot log in to the portal

**Possible causes:**

1. **User does not have a staff role.** Go to **Settings > Staff Users** and confirm the WordPress user is assigned one of: `mhb_front_desk`, `mhb_supervisor`, `mhb_housekeeping`, `mhb_finance`, or `mhb_ops_manager`.

2. **Staff portal access is restricted by role.** Go to **Settings > Staff & Access > Portal access roles** and confirm the user's role is in the permitted list.

3. **Individual staff access is disabled.** The plugin stores a user meta key `mhb_staff_disabled`. If this is set to a truthy value on the user, portal access is blocked at the plugin level. Check the user's meta in the database or via the Staff Users tab. *(Unverified / needs confirmation: the exact UI control for enabling/disabling individual staff access was not confirmed in code.)*

4. **The portal login page is missing.** Check **Settings > Managed Pages** for `portal_login_page_id`.

---

### 4c. Staff user lands on portal but sees wrong or missing modules

**Cause:** Module visibility is controlled per-role by the capability matrix and the portal module visibility settings.

**Fix:**
1. Go to **Settings > Staff & Access > Capability matrix**.
2. Confirm the capabilities assigned to the user's role match what the user needs.
3. Go to **Settings > Staff & Access > Portal module visibility** and confirm the modules the user should see are enabled for their role.

---

### 4d. Staff portal page returns 404 after permalink change or site migration

**Cause:** The portal rewrite rules use the page slug stored in the plugin settings. If the site URL or permalink structure changed, the stored rewrite signature may not match the current configuration.

**Fix:**
1. Go to **Settings > Permalinks > Save Changes**.
2. The plugin calls `PortalRouter::maybeRefreshRewriteRules()` on `init` and compares a stored signature against the current configuration. If they differ, rules are flushed automatically.
3. If the issue persists, deactivate and reactivate the plugin to force `PortalBootstrap::registerRewriteRules()` and `flush_rewrite_rules()`.

---

## 5. Payment and Stripe issues

### 5a. Stripe payment fails at checkout — keys not configured

**Symptom:** The checkout page does not display Stripe as a payment option, or Stripe is shown but the card form fails to load.

**Fix:**
1. Go to **Settings > Diagnostics & Maintenance**. The Payments section shows `stripe_configured: false` if keys are missing.
2. Go to **Settings > Payments Summary**.
3. Enable Stripe under Payment Methods.
4. Enter the Publishable Key and Secret Key for the environment that matches your site:
   - Local/LocalWP domains → Local key fields
   - Staging/IP-based sites → Staging key fields
   - Live HTTPS site → Production key fields
5. The plugin detects the environment by inspecting the site URL scheme and hostname automatically (`PaymentEngine::detectStripeEnvironmentFromUrl()`).

---

### 5b. Stripe webhook not processing — payment status not updating after checkout

**Symptom:** Guest completes Stripe payment but the reservation status in WordPress remains "pending" or "pending_payment" instead of updating to "confirmed" / "paid".

**Cause:** The Stripe webhook is not reaching the site, or the webhook secret is not configured.

**Fix:**
1. Go to **Settings > Diagnostics & Maintenance**. Note the `stripe_webhook_url` value shown in the payments section. This is the URL Stripe must call.
2. In the Stripe Dashboard, go to **Developers > Webhooks**. Confirm the endpoint URL matches exactly.
3. If the webhook endpoint does not exist in Stripe, create it using the URL from step 1. Select the events the plugin needs *(Unverified / needs confirmation: the exact list of Stripe event types the plugin listens for was not confirmed in the reviewed code)*.
4. Copy the webhook signing secret from Stripe and paste it into **Settings > Payments Summary > Stripe webhook secret** for the matching environment.
5. After saving, **Settings > Diagnostics & Maintenance** should show `stripe_webhook_secret_set: true`.
6. Test by sending a test webhook from the Stripe Dashboard and checking the response code (expect 200).

**If the webhook URL is not publicly reachable (local/staging site):**

- Local sites cannot receive Stripe webhooks over the internet. Use the [Stripe CLI](https://stripe.com/docs/stripe-cli) to forward webhook events to a local URL: `stripe listen --forward-to <your-local-webhook-url>`.
- Staging sites on private/IP addresses may need a publicly accessible URL. Use a tunnel tool such as ngrok during testing.

---

### 5c. "Pay at Hotel" option not appearing at checkout

**Cause:** Pay at Hotel may be disabled in settings, or no enabled payment method passed the gateway availability check.

**Note:** If no payment methods are enabled or available, the plugin falls back to showing "Pay at Hotel" automatically (as a hardcoded fallback in `PaymentEngine::getCheckoutPaymentMethods()`). If even the fallback is not appearing, the checkout page template may not be loading correctly — check that the Checkout managed page is intact and the page template file exists.

**Fix:**
1. Go to **Settings > Payments Summary**.
2. Confirm "Pay at Hotel" is checked under Payment Methods.
3. Save settings.

---

## 6. Email issues

### 6a. Booking confirmation email not sending

**Possible causes and fixes:**

| Check | Fix |
|-------|-----|
| Sender email not set or invalid | Go to **Settings > Notifications & Emails Summary**. Set `email_from_email` to a valid email address |
| Notification recipient not set | Set `booking_notification_email` to a valid email address |
| WordPress mail function not working | Test using a WordPress SMTP plugin (e.g., WP Mail SMTP). The plugin uses `wp_mail()` internally via `EmailEngine` |
| Email template not configured | Go to **MUST Hotel Booking > Emails**. Confirm at least one email template exists. **Settings > Diagnostics & Maintenance** shows `template_count` |

---

### 6b. Recent email failures shown in diagnostics

**Symptom:** **Settings > Diagnostics & Maintenance** shows a non-zero count of recent email failures under the emails section.

**Cause:** `EmailEngine` logs failed sends to `must_activity_log` with `event_type = email_failed`. The diagnostics panel queries the last 10 records.

**Fix:**
1. Check the activity log: **MUST Hotel Booking > Reservations** or the activity panel on the Dashboard may show recent failures.
2. The most common root cause is a misconfigured WordPress `wp_mail()` stack. Install and configure an SMTP plugin to route outgoing mail through a reliable mail provider.
3. Confirm that the sender email address passes `is_email()` validation. The plugin will not attempt to send if the configured sender resolves to an invalid address.

---

### 6c. Emails are sending but going to spam

This is a WordPress/hosting mail deliverability issue, not specific to this plugin. General fixes:

- Configure SPF, DKIM, and DMARC records for the sender domain.
- Use an SMTP relay (e.g., SendGrid, Mailgun, AWS SES) via an SMTP plugin.
- Set the sender name and email in **Settings > Notifications & Emails Summary** to match the domain of the site (e.g., `noreply@yourdomain.com`).

---

## 7. Settings issues

### 7a. Settings changes not saving

**Cause:** Settings are stored as a single serialized option `must_hotel_booking_settings` via `MustBookingConfig::set_all_settings()`. A failed save usually means a nonce verification failure, a permissions issue, or an unexpected form submission.

**Fix:**
1. Confirm you are logged in as a WordPress administrator (`manage_options` capability).
2. The Settings page guards against unauthorized access in `ensure_admin_capability()`. If you are being redirected or seeing "You do not have permission", confirm the logged-in user has `manage_options`.
3. Clear browser cache and try again (stale nonce tokens can fail nonce verification).
4. Check the WordPress debug log for PHP errors during the save request.

---

### 7b. Settings appear reset after saving

**Cause:** `MustBookingConfig::normalize_storage()` normalizes all values on every save. Certain fields have constrained value sets (e.g., `currency_display` accepts only `symbol`, `symbol_code`, or `code`). If a field value does not pass validation, it is replaced with the default.

**Fix:** Confirm the value you are entering is within the allowed set for that field. Refer to `MustBookingConfig::default_storage()` in `src/Core/MustBookingConfig.php` for the default values and the normalize functions for allowed choices.

---

### 7c. Currency or timezone setting not reflecting on the front end

**Cause:** `MustBookingConfig::get_currency()` and `get_timezone()` have validation logic. An unsupported currency code or timezone string will cause the plugin to fall back to the default (`USD` and the WordPress site timezone respectively).

**Fix:** Enter a valid ISO 4217 currency code and a valid PHP timezone string (e.g., `Europe/London`). The plugin reads the WordPress site timezone as the fallback via `wp_timezone_string()`.

---

## 8. Diagnostics and maintenance tools

### 8a. Diagnostics & Maintenance tab

Location: **MUST Hotel Booking > Settings > Diagnostics & Maintenance**

This tab is rendered by `SettingsDiagnostics::getData()` and covers:

- Table health (all 19 plugin tables checked individually)
- Managed page health (all 7 configured pages checked)
- Cron health (lock cleanup hook)
- Payment configuration summary
- Email configuration summary and recent failure count
- Environment info (plugin version, DB version, WordPress version, PHP version, room count)
- Updater status (GitHub repository, branch, version consistency)

A **System Report** button generates a plain-text summary of the above that can be copied and shared with a developer or support contact.

---

### 8b. Operational Data Reset

Location: **Settings > Diagnostics & Maintenance**

Two destructive reset operations are available to administrators only (`manage_options` required, plus current WordPress password confirmation):

**Reset Hotel Operational Data** (`hotel_operational_data`)
- Confirmation phrase required: `RESET HOTEL DATA`
- Deletes: room listings, room inventory, room meta, guests, reservations, payments, pricing rules, availability rules, rate plans, seasons, seasonal prices, taxes, coupons, inventory locks, cancellation policies, activity log.
- Preserves: plugin settings, branding, payment configuration, email settings/templates, managed page assignments, portal access rules, WordPress pages.
- Use case: wiping test/demo data before going live.

**Full Plugin Factory Reset** (`plugin_factory_reset`)
- Confirmation phrase required: `FACTORY RESET MUST HOTEL BOOKING`
- Deletes: everything in the operational reset, plus the main `must_hotel_booking_settings` option.
- Preserves: plugin tables/schema, `must_hotel_booking_db_version` option, WordPress users/roles, existing WordPress pages.
- Side effect: sets `must_hotel_booking_managed_pages_suspended = true`, which stops all automatic page creation and sync until pages are manually reassigned.
- Use case: returning the plugin to a near first-install state without dropping tables or deleting WordPress pages.

Both operations run inside a database transaction with rollback on failure. The operation is logged to `must_activity_log` with `event_type = hotel_operational_reset` or `plugin_factory_reset`.

**Warning:** These operations cannot be undone. Take a full database backup before running either reset.

---

### 8c. Cron not scheduled

**Symptom:** **Settings > Diagnostics & Maintenance** shows "Recurring lock cleanup is not scheduled." This counts as a critical issue.

**Cause:** The cron job (`LockEngine::scheduleCleanupCron()`) is registered during plugin activation. If WordPress cron is disabled (`DISABLE_WP_CRON` set to `true` in `wp-config.php`), or if the plugin was activated abnormally, the event may not be registered.

**Fix:**
1. Deactivate and reactivate the plugin to re-run `LockEngine::scheduleCleanupCron()`.
2. If `DISABLE_WP_CRON` is `true` in `wp-config.php`, ensure you have a real system cron set up to call `wp-cron.php` on a schedule. The plugin relies on WordPress cron to run this cleanup.
3. After reactivation, reload **Settings > Diagnostics & Maintenance** and confirm cron shows as scheduled.
