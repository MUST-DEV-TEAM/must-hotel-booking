# Managed Pages and Migrations

**Plugin:** MUST Hotel Booking
**Version documented:** 0.3.45
**Primary sources:** `src/Core/ManagedPages.php`, `src/Core/MustBookingConfig.php`, `src/Core/Plugin.php`, `src/Core/Updater.php`, `must-hotel-booking.php`

---

## Managed Pages System

### Overview

The plugin automatically creates and tracks seven WordPress pages needed for its booking flow and staff portal. Each page is identified by a **setting key** stored in the `must_hotel_booking_settings` option under the `managed_pages` group.

The managing class is `MustHotelBooking\Core\ManagedPages` (final, all static methods).

### Page Registry

The full set of managed pages, as returned by `ManagedPages::getConfig()`:

| Setting key | Title | Slug | Group | Auto-create | Required |
|---|---|---|---|---|---|
| `page_rooms_id` | Rooms | `rooms` | `public_booking` | No | No |
| `page_booking_id` | Booking | `booking` | `public_booking` | Yes | Yes |
| `page_booking_accommodation_id` | Select Accommodation | `booking-accommodation` | `public_booking` | Yes | Yes |
| `page_checkout_id` | Checkout | `checkout` | `guest_pages` | Yes | Yes |
| `page_booking_confirmation_id` | Booking Confirmation | `booking-confirmation` | `guest_pages` | Yes | Yes |
| `portal_page_id` | Staff Portal | `staff` | `staff_portal` | Yes | Conditional |
| `portal_login_page_id` | Staff Portal Login | `staff-login` | `staff_portal` | Yes | Conditional |

**Required vs conditional:**
- Pages marked required (`page_booking_id`, `page_booking_accommodation_id`, `page_checkout_id`, `page_booking_confirmation_id`) must always exist for the booking flow.
- Portal pages (`portal_page_id`, `portal_login_page_id`) are required only when the `enable_staff_portal` setting is `true`. `ManagedPages::isRequired()` checks this condition at runtime.

**Auto-create vs manual:**
- `page_rooms_id` has `auto_create => false`; it must be created and assigned manually in the admin Settings page.
- All other six pages have `auto_create => true` and are created automatically.

### Page Templates

Each auto-create page has a corresponding PHP template file inside the plugin:

| Setting key | Template path (relative to plugin root) |
|---|---|
| `page_booking_id` | `frontend/templates/booking.php` |
| `page_booking_accommodation_id` | `frontend/templates/booking-accommodation.php` |
| `page_checkout_id` | `frontend/templates/checkout.php` |
| `page_booking_confirmation_id` | `frontend/templates/booking-confirmation.php` |
| `portal_page_id` | `frontend/templates/staff-portal.php` |
| `portal_login_page_id` | `frontend/templates/staff-login.php` |

The health check (`ManagedPages::getPageHealth()`) verifies that the template file physically exists on disk. If it does not, the page health is set to `'invalid'`.

### How Page IDs Are Stored

Page IDs are stored as individual fields in the `managed_pages` settings group within the single `must_hotel_booking_settings` WordPress option (managed by `MustBookingConfig`).

```php
// Reading a page ID
$pageId = ManagedPages::getAssignedPageId('page_booking_id');
// Equivalent to:
$pageId = absint(MustBookingConfig::get_setting('page_booking_id', 0));

// Writing a page ID
ManagedPages::assignPage('page_booking_id', $pageId);
// Equivalent to:
MustBookingConfig::set_setting('page_booking_id', absint($pageId));
```

The `managed_pages` group defaults in `MustBookingConfig`:
```php
'managed_pages' => [
    'page_rooms_id'                 => 0,
    'page_booking_id'               => 0,
    'page_booking_accommodation_id' => 0,
    'page_checkout_id'              => 0,
    'page_booking_confirmation_id'  => 0,
    'portal_page_id'                => 0,
    'portal_login_page_id'          => 0,
],
```

A value of `0` means no page is assigned. `ManagedPages::hasAssignedPage()` returns `true` only when the stored ID points to a published, non-trashed WP page.

### Auto-creation Logic (`ManagedPages::install()`)

Called from `Plugin::activate()`. For each setting key where `auto_create === true`:

1. Calls `ManagedPages::ensurePage($settingKey)`.
2. `ensurePage()` first checks if a valid (non-trashed, type `page`) post is already assigned. If so, returns immediately.
3. If no valid assignment exists, calls `get_page_by_path($slug)` to find any existing page with the expected slug.
4. If a matching page is found and is not trashed, calls `ManagedPages::assignPage()` to record it.
5. If no matching page exists, calls `wp_insert_post()` with:
   - `post_title` from config
   - `post_name` (slug) from config
   - `post_type` = `'page'`
   - `post_status` = `'publish'`
   - `comment_status` and `ping_status` = `'closed'`
6. Stores the resulting page ID via `assignPage()`.

**Suspension check:** `install()` and `sync()` both check `ManagedPages::isManagementSuspended()` at entry. If the option `must_hotel_booking_managed_pages_suspended` is set to truthy, all auto-management is skipped.

### Sync Logic (`ManagedPages::sync()`)

Called from `Plugin::initPlugin()` on every page load after `plugins_loaded`. For each auto-create page:

1. Retrieves the currently assigned `WP_Post`.
2. Checks for "needs repair" conditions:
   - No assigned post, or post is not type `page`.
   - Post status is `'trash'`.
   - Template file is missing from disk.
3. If any condition is true, calls `ensurePage($settingKey, $forceAssign = true)` to recreate or re-assign.

This means any managed page that is accidentally trashed will be automatically recreated on the next page load.

### Detecting the Current Page

```php
// Check if the current WP request is a specific managed page
ManagedPages::isCurrentPage('page_checkout_id', 'checkout'); // true/false

// Check only by assigned page ID (no slug fallback)
ManagedPages::isAssignedCurrentPage('page_checkout_id'); // true/false
```

`isCurrentPage()` falls back to `is_page($slug)` if no page ID is assigned. `isAssignedCurrentPage()` does not fall back.

### URL Resolution

```php
ManagedPages::getBookingPageUrl();           // assigned page permalink or home_url('/booking')
ManagedPages::getCheckoutPageUrl();          // assigned page permalink or home_url('/checkout')
ManagedPages::getBookingConfirmationPageUrl(); // ...
ManagedPages::getPortalPageUrl();            // ...
ManagedPages::getPortalLoginPageUrl();       // ...
ManagedPages::getRoomsPageUrl();             // returns '' if no page assigned (not auto-created)
```

### Page Health Reporting

`ManagedPages::getHealthRows()` returns an array of health-check records for all seven pages. Each record includes:

| Field | Values |
|---|---|
| `health` | `'ok'`, `'warning'`, `'missing'`, `'invalid'`, `'disabled'` |
| `page_id` | Stored page ID |
| `page_status` | WP post status string |
| `message` | Human-readable diagnostic message |
| `edit_url` | WP admin edit link |
| `view_url` | Frontend permalink |

This data is used by the Settings diagnostics panel in WP admin.

### Suspending Auto-Management

Auto-management can be suspended to prevent the plugin from recreating pages that an administrator has intentionally deleted:

```php
ManagedPages::suspendAutoManagement();  // sets option must_hotel_booking_managed_pages_suspended
ManagedPages::resumeAutoManagement();   // deletes the option
ManagedPages::isManagementSuspended();  // boolean check
```

Calling `ManagedPages::assignPage($settingKey, $pageId)` with a non-zero `$pageId` automatically calls `resumeAutoManagement()`.

### Portal Rewrite Rules

Portal pages require custom rewrite rules beyond standard WP page routing. `PortalRouter` registers:

- `^staff/?$` → portal page
- `^staff/([^/]+)/?$` → portal page with `must_hotel_booking_portal_route=$matches[1]`
- `^staff-login/?$` → login page

The slug used in these rules comes from `ManagedPages::getConfiguredPagePath()` at rule-registration time, so if the portal page is moved to a different slug, rewrite rules must be flushed. The router tracks a `REWRITE_SCHEMA_VERSION = 2` in the option `must_hotel_booking_portal_rewrite_signature` to detect when a flush is needed.

---

## Migration / Database Upgrade System

### Version Tracking

The plugin does not use a numbered migration file system (no migrations directory was found in the source). Instead it uses a **single-option version gate**:

- WordPress option: `must_hotel_booking_db_version`
- Compared against: `MUST_HOTEL_BOOKING_VERSION` constant (e.g. `'0.3.45'`)
- Comparison method: `version_compare($db_version, MUST_HOTEL_BOOKING_VERSION, '>=')`

If the stored version is less than the current version, `install_tables()` is re-run. `dbDelta()` handles the idempotent `CREATE TABLE` / `ALTER TABLE` logic.

### How Upgrades Run

`Plugin::maybeUpgradeDatabase()` is hooked to `plugins_loaded` at priority `5` (before `initPlugin` at default priority `10`):

```php
public static function maybeUpgradeDatabase(): void
{
    $db_version = (string) get_option('must_hotel_booking_db_version', '0.0.0');

    if (version_compare($db_version, MUST_HOTEL_BOOKING_VERSION, '>=')) {
        return;
    }

    install_tables();
    // install_tables() calls update_option('must_hotel_booking_db_version', MUST_HOTEL_BOOKING_VERSION)
}
```

`install_tables()` always:
1. Runs `dbDelta()` for all ~20 table definitions.
2. Calls `(new AccommodationCategoryUpgradeService($wpdb))->run()` for category data migration.
3. Updates `must_hotel_booking_db_version` to `MUST_HOTEL_BOOKING_VERSION`.

### Adding a Migration

Because there is no discrete migration file system, data migrations are handled by:

1. **`AccommodationCategoryUpgradeService::run()`** — called every time `install_tables()` runs. This service is idempotent: it stores its last-run summary in the option `must_hotel_booking_category_cleanup_summary` but does not gate on it. Any destructive or one-time logic inside this service must be made idempotent manually.

2. **Option-gated one-time services** — the pattern used by `DefaultInventoryUnitSyncService`: store a marker string in a WP option; check the marker at runtime; run once and write the marker. Example:

```php
// In your service constructor or run method:
private const SYNC_OPTION = 'must_hotel_booking_my_migration_marker';
private const SYNC_MARKER = 'my_migration_v1';

public function maybeRun(): void
{
    if ((string) get_option(self::SYNC_OPTION, '') === self::SYNC_MARKER) {
        return; // already ran
    }

    $this->doMigration();

    update_option(self::SYNC_OPTION, self::SYNC_MARKER, false);
}
```

3. **`dbDelta()` itself** — handles adding new columns and new tables automatically. It does not drop columns or change column types destructively, so schema rollbacks require manual SQL.

**To add a new table or column:** Add the `CREATE TABLE` definition to the `$tables` array in `install_tables()`. `dbDelta()` will create new tables and add missing columns on the next upgrade cycle.

**To run a one-time data migration:** Implement the option-gated pattern above and call your service from `install_tables()` or from `Plugin::initPlugin()`.

---

## Activation Behaviour

### Fresh install

1. `Plugin::activate()` is called via `register_activation_hook`.
2. All tables are created by `install_tables()` / `dbDelta()`.
3. `must_hotel_booking_db_version` is written with the current version (no upgrade needed on next load).
4. `DefaultInventoryUnitSyncService::maybeRunBackfill()` runs (no-op on fresh install; no rooms exist yet).
5. Six managed pages are created via `wp_insert_post()` by `ManagedPages::install()`.
6. WP custom roles (`mhb_front_desk`, `mhb_supervisor`, etc.) are created and capabilities are synced by `StaffAccess::syncRoleCapabilities()`.
7. LockEngine cron is scheduled.
8. Portal rewrite rules are added.
9. `flush_rewrite_rules()` is called.

### Update (version bump, existing install)

On the first page load after a plugin file update:

1. `Plugin::maybeUpgradeDatabase()` (priority 5 on `plugins_loaded`) detects `db_version < MUST_HOTEL_BOOKING_VERSION`.
2. `install_tables()` runs: `dbDelta()` adds any new tables or columns; `AccommodationCategoryUpgradeService` runs.
3. `must_hotel_booking_db_version` is updated.
4. `Plugin::initPlugin()` (default priority) then runs: `ManagedPages::sync()` repairs any broken page assignments; `StaffAccess::syncRoleCapabilities()` updates role capabilities; `Updater::boot()` initialises the update checker.

No `activate()` hook fires during an update — only during a true deactivation-reactivation cycle.

### Deactivation

1. `LockEngine::unscheduleCleanupCron()` removes the WP-Cron event.
2. `flush_rewrite_rules()` is called to remove the portal rewrite rules from the active rewrite table.
3. Managed pages, roles, database tables, and settings are **not** removed on deactivation.

### Uninstall

`uninstall.php` is registered. Examine that file for what is cleaned up on plugin deletion (Unverified / needs confirmation — `uninstall.php` was not read during this documentation pass).

---

## GitHub Updater

### Library

The plugin uses the **Yahnis Elsts Plugin Update Checker** (version 5), bundled at:

```
lib/plugin-update-checker/plugin-update-checker.php
```

It is loaded on demand inside `Updater::boot()` only if the file exists and the updater is enabled.

### Managing class

`MustHotelBooking\Core\Updater` (final, all static methods).

Entry point: `Updater::boot()` — called from `Plugin::initPlugin()`.

### Constants

All constants are defined in `must-hotel-booking.php` with `if (!defined(...))` guards, making them overridable from `wp-config.php`.

| Constant | Default | Purpose |
|---|---|---|
| `MUST_HOTEL_BOOKING_UPDATER_ENABLED` | `true` | Master switch. Set to `false` to disable the updater entirely. |
| `MUST_HOTEL_BOOKING_GITHUB_REPOSITORY` | `'https://github.com/MUST-DEV-TEAM/must-hotel-booking'` | Full HTTPS URL of the GitHub repository. |
| `MUST_HOTEL_BOOKING_GITHUB_BRANCH` | `'main'` | Branch from which release tags are read. |
| `MUST_HOTEL_BOOKING_GITHUB_RELEASE_ASSET_PATTERN` | `'/^must-hotel-booking-[0-9]+\.[0-9]+\.[0-9]+\.zip$/i'` | Regex to match the canonical release ZIP asset. |
| `MUST_HOTEL_BOOKING_GITHUB_TOKEN` | `''` | Personal access token for private repositories or private release assets. |
| `MUST_HOTEL_BOOKING_PLUGIN_SLUG` | `'must-hotel-booking'` | Must match the plugin directory name. |

### How the updater is configured

`Updater::boot()` executes the following sequence:

```php
// 1. Guard checks
if (!self::isEnabled()) return;          // MUST_HOTEL_BOOKING_UPDATER_ENABLED must be true
if (!is_file($libraryFile)) return;      // lib/plugin-update-checker/plugin-update-checker.php must exist
if (!self::isRepositoryConfigured()) return; // URL must be a valid URL, not empty, not placeholder

// 2. Create the update checker
$updateChecker = PucFactory::buildUpdateChecker(
    MUST_HOTEL_BOOKING_GITHUB_REPOSITORY,
    MUST_HOTEL_BOOKING_FILE,
    MUST_HOTEL_BOOKING_PLUGIN_SLUG
);

// 3. Set branch
$updateChecker->setBranch(MUST_HOTEL_BOOKING_GITHUB_BRANCH);

// 4. Set authentication token (if provided)
if (MUST_HOTEL_BOOKING_GITHUB_TOKEN !== '') {
    $updateChecker->setAuthentication(MUST_HOTEL_BOOKING_GITHUB_TOKEN);
}

// 5. Enable release asset matching
$updateChecker->getVcsApi()->enableReleaseAssets(MUST_HOTEL_BOOKING_GITHUB_RELEASE_ASSET_PATTERN);
```

### Configuring for a custom repository

To point the updater at a different GitHub repository, add the following to `wp-config.php` before the plugin loads:

```php
define('MUST_HOTEL_BOOKING_GITHUB_REPOSITORY', 'https://github.com/your-org/your-fork');
define('MUST_HOTEL_BOOKING_GITHUB_BRANCH', 'main');
define('MUST_HOTEL_BOOKING_GITHUB_TOKEN', 'ghp_yourPersonalAccessToken'); // for private repos
```

To disable the updater entirely (e.g. on a site managed via Composer or manual deployment):

```php
define('MUST_HOTEL_BOOKING_UPDATER_ENABLED', false);
```

### Release asset naming

The updater only installs release assets whose filename matches `MUST_HOTEL_BOOKING_GITHUB_RELEASE_ASSET_PATTERN`. The recommended (and default) pattern requires exactly one ZIP named:

```
must-hotel-booking-{major}.{minor}.{patch}.zip
```

The `Updater::getStatus()` method (accessible from the WP admin Settings diagnostics) validates:
- Whether the repository URL is configured correctly.
- Whether the plugin header version, `MUST_HOTEL_BOOKING_VERSION` constant, and `readme.txt` `Stable tag` field all match.
- Whether the asset pattern matches the recommended strict pattern.
- Whether a GitHub token is configured.

A mismatch between any of these will produce a warning in the diagnostics panel.

### Updater status check

```php
$status = \MustHotelBooking\Core\Updater::getStatus();
// Returns an array with keys:
// 'enabled', 'configured', 'repository', 'branch', 'plugin_slug',
// 'release_asset_pattern', 'recommended_release_asset_pattern',
// 'expected_release_asset_name', 'version', 'readme_stable_tag',
// 'version_consistent', 'asset_pattern_strict', 'token_configured',
// 'release_readiness_message', 'library_loaded'
```
