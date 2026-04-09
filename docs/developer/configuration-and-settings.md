# Configuration and Settings

Developer reference for the `MustBookingConfig` class and the plugin's settings storage.

---

## Storage

All plugin settings are stored in a single WordPress option:

| Option name | Type | Access |
|---|---|---|
| `must_hotel_booking_settings` | Serialized PHP array | `get_option()` / `update_option()` |

The option value is a structured array keyed by **group names**. Each group is itself an associative array of field keys and their values. A `settings_version` key (currently `3`) is maintained at the top level to support future migrations.

**Do not read or write this option directly.** Always go through `MustBookingConfig`.

---

## MustBookingConfig class

Namespace: `MustHotelBooking\Core\MustBookingConfig`
File: `src/Core/MustBookingConfig.php`

### Constants

| Constant | Value | Description |
|---|---|---|
| `MustBookingConfig::OPTION_NAME` | `'must_hotel_booking_settings'` | The WP option name |

### Primary API

#### `get_setting( string $key, mixed $default = null ): mixed`

Retrieves a single setting by its field key. Works transparently across all groups — you do not need to know which group a field belongs to.

```php
$currency = MustBookingConfig::get_setting( 'currency' );           // 'USD'
$tax_rate  = MustBookingConfig::get_setting( 'tax_rate', 0.0 );    // float
```

If `$key` matches a group name (e.g. `'general'`), the full group array is returned.

#### `set_setting( string $key, mixed $value ): void`

Writes a single field. The method automatically determines the correct group from the internal field-group map and calls `update_option()`.

```php
MustBookingConfig::set_setting( 'currency', 'EUR' );
MustBookingConfig::set_setting( 'tax_rate', 10.0 );
```

If `$key` is a group name and `$value` is an array, the group is merged with the supplied values.

#### `get_all_settings(): array`

Returns all settings as a single flat array (groups merged/flattened). Useful when reading many settings at once, as in `PaymentEngine`.

```php
$settings = MustBookingConfig::get_all_settings();
$currency  = $settings['currency'] ?? 'USD';
```

#### `set_all_settings( array $settings ): void`

Bulk-updates settings. Accepts a flat or mixed array; group keys are merged, individual field keys are written directly.

#### `get_default_settings(): array`

Returns the flat array of compiled defaults (same structure as `get_all_settings()` on a fresh install).

#### `get_settings_groups(): array`

Returns the current stored value keyed by group name, without flattening.

#### `get_group_settings( string $group ): array`

Returns the stored settings for a single group.

#### `set_group_settings( string $group, array $values ): void`

Merges `$values` into an existing group and persists.

```php
MustBookingConfig::set_group_settings( 'general', [
    'currency' => 'EUR',
    'tax_rate'  => 7.5,
] );
```

### Convenience accessor methods

These typed accessors apply validation, normalization, and fallbacks automatically. Prefer them over raw `get_setting()` when a typed value is required.

| Method | Return type | Default |
|---|---|---|
| `get_hotel_name()` | `string` | WordPress site name |
| `get_hotel_address()` | `string` | `''` |
| `get_hotel_phone()` | `string` | `''` |
| `get_currency()` | `string` | `'USD'` |
| `get_timezone()` | `string` | WordPress timezone string |
| `get_tax_rate()` | `float` | `0.0` |
| `get_booking_window()` | `int` | `365` |
| `get_max_booking_guests()` | `int` | `12` |
| `get_max_booking_rooms()` | `int` | `3` |
| `get_checkin_time()` | `string` (HH:MM) | `'14:00'` |
| `get_checkout_time()` | `string` (HH:MM) | `'11:00'` |
| `get_booking_notification_email()` | `string` | WordPress admin email |
| `get_email_from_name()` | `string` | Hotel name |
| `get_email_from_email()` | `string` | Notification email |
| `get_email_reply_to()` | `string` | `''` |
| `get_email_logo_url()` | `string` | `''` |
| `get_email_footer_text()` | `string` | `'We look forward to welcoming you.'` |
| `get_email_layout_type()` | `string` | `'classic'` |
| `get_custom_email_layout_html()` | `string` | `''` |
| `get_email_button_color()` | `string` (hex) | `'#141414'` |

---

## Settings groups and their keys

### `general`

Core property and display settings.

| Key | Type | Default | Notes |
|---|---|---|---|
| `hotel_name` | string | WordPress site name | Used as the Stripe line-item product name |
| `hotel_legal_name` | string | `''` | |
| `hotel_address` | string | `''` | Textarea; used in emails |
| `hotel_phone` | string | `''` | |
| `hotel_email` | string | WordPress admin email | Must be a valid email address |
| `default_country` | string | Derived from `get_locale()` | Two-letter ISO 3166-1 alpha-2 |
| `timezone` | string | WordPress timezone | Must be a valid PHP timezone identifier or `UTC±N` format |
| `currency` | string | `'USD'` | Upper-case currency code; used in Stripe session creation and display |
| `currency_display` | string | `'symbol_code'` | One of `symbol`, `symbol_code`, `code` |
| `date_format` | string | WordPress `date_format` option | |
| `time_format` | string | WordPress `time_format` option | |
| `hotel_logo_url` | string | `''` | |
| `portal_logo_url` | string | `''` | |
| `site_environment` | string | `''` | One of `local`, `staging`, `production`; controls which Stripe credential set is active; auto-detected from site URL if blank |

### `booking_rules`

Controls the booking flow and validation behaviour.

| Key | Type | Default | Notes |
|---|---|---|---|
| `booking_window` | int | `365` | Days ahead guests can book; range 1–3650 |
| `same_day_booking_allowed` | bool | `true` | |
| `same_day_booking_cutoff_time` | string | `'18:00'` | HH:MM; ignored if same-day booking is disabled |
| `minimum_nights` | int | `1` | Range 1–365 |
| `maximum_nights` | int | `30` | Range 1–365 |
| `max_booking_guests` | int | `12` | Range 1–100; surfaced to frontend via `mustHotelBookingBookingPage.maxGuests` |
| `max_booking_rooms` | int | `3` | Range 1–25; surfaced to frontend via `mustHotelBookingBookingPage.maxRooms` |
| `allow_multi_room_booking` | bool | `true` | |
| `default_reservation_source` | string | `'website'` | One of `website`, `phone`, `walk_in`, `email`, `portal`, `admin` |
| `pending_reservation_expiration_minutes` | int | `35` | Range 5–1440; used by the cleanup cron |
| `require_phone` | bool | `true` | |
| `require_country` | bool | `true` | |
| `enable_special_requests` | bool | `true` | |
| `require_terms_acceptance` | bool | `true` | |
| `default_reservation_status` | string | `'confirmed'` | One of `pending`, `pending_payment`, `confirmed`, `completed`, `cancelled` |
| `default_payment_mode` | string | `'guest_choice'` | One of `guest_choice`, `pay_now`, `pay_at_hotel` |
| `cancellation_allowed` | bool | `true` | |
| `cancellation_notice_hours` | int | `48` | Range 0–720 |

### `checkin_checkout`

Check-in and check-out times and guest-facing labels.

| Key | Type | Default | Notes |
|---|---|---|---|
| `checkin_time` | string | `'14:00'` | HH:MM format |
| `checkout_time` | string | `'11:00'` | HH:MM format |
| `allow_early_checkin_request` | bool | `true` | |
| `allow_late_checkout_request` | bool | `true` | |
| `arrival_instructions` | string | `''` | Textarea |
| `departure_instructions` | string | `''` | Textarea |
| `guest_checkin_label` | string | `'Check-in'` | Translatable |
| `guest_checkout_label` | string | `'Check-out'` | Translatable |

### `payments_summary`

Payment gateway configuration and deposit rules.

| Key | Type | Default | Notes |
|---|---|---|---|
| `payment_methods` | array | `['pay_at_hotel' => true, 'stripe' => false]` | Map of method slug to enabled boolean |
| `deposit_required` | bool | `false` | Whether a deposit is collected at checkout |
| `deposit_type` | string | `'percentage'` | One of `fixed`, `percentage` |
| `deposit_value` | float | `0.0` | Amount (fixed) or percentage (0–100) |
| `tax_rate` | float | `0.0` | Applied as a percentage; range 0–100 |
| `stripe_publishable_key` | string | `''` | Generic/legacy key field |
| `stripe_secret_key` | string | `''` | Generic/legacy key field |
| `stripe_webhook_secret` | string | `''` | Generic/legacy key field |
| `stripe_local_publishable_key` | string | `''` | Used when `site_environment` = `local` |
| `stripe_local_secret_key` | string | `''` | |
| `stripe_local_webhook_secret` | string | `''` | |
| `stripe_staging_publishable_key` | string | `''` | Used when `site_environment` = `staging` |
| `stripe_staging_secret_key` | string | `''` | |
| `stripe_staging_webhook_secret` | string | `''` | |
| `stripe_production_publishable_key` | string | `''` | Used when `site_environment` = `production` |
| `stripe_production_secret_key` | string | `''` | |
| `stripe_production_webhook_secret` | string | `''` | |

### `staff_access`

Staff portal access and capability configuration.

| Key | Type | Default | Notes |
|---|---|---|---|
| `enable_staff_portal` | bool | `false` | |
| `redirect_worker_after_login` | string | `'dashboard'` | Target portal module key |
| `hide_wp_admin_for_workers` | bool | `true` | |
| `portal_access_roles` | array | See `StaffAccess::getDefaultCapabilityMatrix()` | List of WordPress role slugs that can access the portal |
| `capability_matrix` | array | See `StaffAccess::getDefaultCapabilityMatrix()` | Nested map of role → capability → bool |
| `portal_module_visibility` | array | See `StaffAccess` defaults | Map of portal module slug → visibility settings |

### `branding`

Frontend and email visual customisation.

| Key | Type | Default | Notes |
|---|---|---|---|
| `primary_color` | string (hex) | `'#0f766e'` | |
| `secondary_color` | string (hex) | `'#155e75'` | |
| `accent_color` | string (hex) | `'#f59e0b'` | |
| `text_color` | string (hex) | `'#16212b'` | |
| `border_radius` | int | `18` | Pixels; range 0–40 |
| `font_family` | string | `'Instrument Sans'` | |
| `inherit_elementor_colors` | bool | `false` | |
| `inherit_elementor_typography` | bool | `false` | |
| `portal_welcome_title` | string | `'Welcome back'` | |
| `portal_welcome_text` | string | `'Manage arrivals...'` | |
| `booking_form_style_preset` | string | `'balanced'` | One of `balanced`, `editorial`, `minimal` |

### `managed_pages`

WordPress page IDs for plugin-managed pages.

| Key | Type | Default | Notes |
|---|---|---|---|
| `page_rooms_id` | int | `0` | |
| `page_booking_id` | int | `0` | |
| `page_booking_accommodation_id` | int | `0` | |
| `page_checkout_id` | int | `0` | |
| `page_booking_confirmation_id` | int | `0` | |
| `portal_page_id` | int | `0` | |
| `portal_login_page_id` | int | `0` | |

### `notifications_summary`

Email sending configuration and template state.

| Key | Type | Default | Notes |
|---|---|---|---|
| `booking_notification_email` | string | `''` | Falls back to WordPress admin email |
| `email_from_name` | string | `''` | Falls back to hotel name |
| `email_from_email` | string | `''` | Falls back to notification email |
| `email_reply_to` | string | `''` | Optional; must be a valid email |
| `email_logo_url` | string | `''` | |
| `email_button_color` | string (hex) | `'#141414'` | |
| `email_footer_text` | string | `'We look forward to welcoming you.'` | |
| `email_layout_type` | string | `'classic'` | One of `classic`, `luxury`, `compact`, `custom` |
| `custom_email_layout_html` | string | `''` | Used when `email_layout_type` = `custom` |
| `email_templates` | array | `[]` | Keyed by template slug; stores per-template enabled/subject/body overrides |

### `maintenance`

No fixed keys. Used internally for diagnostic and maintenance state flags. Read and written as a raw array without normalization.

---

## How settings affect runtime behaviour

| Setting | Engine / class that reads it | Effect |
|---|---|---|
| `currency` | `PaymentEngine::createStripeCheckoutSession()`, `BookingStatusEngine::createPaymentRows()`, `StripePayment::resolveCurrency()` | Stripe session currency and payment ledger currency |
| `site_environment` | `PaymentEngine::getActiveSiteEnvironment()` | Selects the `local`, `staging`, or `production` Stripe credential set |
| `stripe_*_publishable_key` / `stripe_*_secret_key` / `stripe_*_webhook_secret` | `PaymentEngine::getStripeEnvironmentCredentials()` | Credential resolution for Stripe API calls and webhook verification |
| `deposit_required`, `deposit_type`, `deposit_value` | Read in `SettingsPage` and `SettingsDiagnostics`; **Unverified / needs confirmation** — the checkout flow that actually applies the deposit amount to the Stripe session total was not located in the searched files |
| `tax_rate` | `MustBookingConfig::get_tax_rate()` | Exposed for pricing calculations — the specific engine that reads it was not traced in the searched files; **Unverified / needs confirmation** |
| `booking_window`, `max_booking_guests`, `max_booking_rooms` | Booking page JS object, `AvailabilityAjaxController` | Calendar window and guest/room caps in the frontend |
| `payment_methods` | `PaymentMethodRegistry::getEnabled()` → `PaymentEngine::getCheckoutPaymentMethods()` | Which payment methods are presented at checkout |
| `booking_notification_email`, `email_from_*`, `email_reply_to` | `EmailEngine` | Controls all outbound email headers |
| `email_layout_type`, `email_templates` | `EmailEngine` | Controls email rendering |

---

## Programmatic examples

### Reading a single setting

```php
use MustHotelBooking\Core\MustBookingConfig;

$currency = MustBookingConfig::get_currency(); // typed accessor, recommended
$window   = MustBookingConfig::get_setting( 'booking_window', 365 ); // generic accessor
```

### Writing a single setting

```php
MustBookingConfig::set_setting( 'currency', 'EUR' );
```

### Writing multiple settings in one group

```php
MustBookingConfig::set_group_settings( 'payments_summary', [
    'deposit_required' => true,
    'deposit_type'     => 'percentage',
    'deposit_value'    => 20.0,
] );
```

### Bulk-reading all settings

```php
$all = MustBookingConfig::get_all_settings();
// $all is a flat associative array of every field key across all groups.
```

### Reading the active Stripe credentials programmatically

```php
use MustHotelBooking\Engine\PaymentEngine;

$creds = PaymentEngine::getStripeEnvironmentCredentials();
// Returns: ['publishable_key' => '...', 'secret_key' => '...', 'webhook_secret' => '...']
// The active environment is determined by the 'site_environment' setting or auto-detected.
```
