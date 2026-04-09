# Staff Roles and Capabilities

Source files: `src/Core/StaffAccess.php`, `src/Portal/PortalRegistry.php`

---

## 1. Staff Roles

The plugin registers five custom WordPress roles. All are created (or migrated) when `StaffAccess::syncRoleCapabilities()` runs.

| Role slug | Display name | Purpose |
|---|---|---|
| `mhb_front_desk` | Front Desk Agent | Handles arrivals, departures, guest service, reservations, and standard payments from the staff portal. |
| `mhb_supervisor` | Front Office Supervisor | Oversees desk operations; approves cancellations, refunds, room moves, and exceptions. |
| `mhb_housekeeping` | Housekeeping | Manages room readiness, cleaning status, assignments, and maintenance issues. |
| `mhb_finance` | Finance / Cashier | Handles payment posting, refunds, receipts, invoices, and reconciliation. |
| `mhb_ops_manager` | Operations Manager | Full operational access: approvals, reports, room inventory management, audit log. |

### Deprecated role slugs (automatically migrated on upgrade)

These WP roles were removed in earlier schema migrations. The migration code in `StaffAccess::maybeMigrateLegacyRolesAndSettings()` moves users to the correct current role automatically.

| Old slug | Migrated to |
|---|---|
| `receptionist` | `mhb_front_desk` |
| `mhb_worker` | `mhb_front_desk` |
| `mhb_manager` | `mhb_supervisor` |
| `manager` | `mhb_supervisor` |
| `accountant` | `mhb_finance` |
| `housekeeping` | `mhb_housekeeping` |

---

## 2. Full Capabilities Matrix

All capability constants are defined as `const CAP_*` in `StaffAccess`. The full WP capability string is `mhb_` + the short key shown below.

Legend: `Y` = granted by default, `-` = not granted by default (but can be enabled via the Settings capability matrix UI), `*` = always granted to `administrator` via `syncRoleCapabilities()`.

### Dashboard

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `dashboard_view` | `mhb_dashboard_view` | Access the dashboard portal module | Y | Y | Y | Y | Y |

### Reservations

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `reservation_view` | `mhb_reservation_view` | View reservation list and detail | Y | Y | Y | Y | Y |
| `reservation_create` | `mhb_reservation_create` | Create a new reservation (gates Front Desk module) | Y | Y | - | - | Y |
| `reservation_edit_basic` | `mhb_reservation_edit_basic` | Edit notes, source, guest email | Y | Y | - | - | Y |
| `reservation_edit_stay` | `mhb_reservation_edit_stay` | Edit stay dates, guest count | Y | Y | - | - | Y |
| `reservation_assign_room` | `mhb_reservation_assign_room` | Assign / reassign a room | Y | Y | - | - | Y |
| `reservation_move_room` | `mhb_reservation_move_room` | Move room (requires approval context) | - | Y | - | - | Y |
| `reservation_checkin` | `mhb_reservation_checkin` | Check in a guest | Y | Y | - | - | Y |
| `reservation_checkout` | `mhb_reservation_checkout` | Check out a guest | Y | Y | - | - | Y |
| `reservation_cancel` | `mhb_reservation_cancel` | Cancel a reservation | - | Y | - | - | Y |
| `reservation_mark_no_show` | `mhb_reservation_mark_no_show` | Mark a reservation as no-show | Y | Y | - | - | Y |
| `reservation_bulk_actions` | `mhb_reservation_bulk_actions` | Perform bulk actions on reservations | - | Y | - | - | Y |

### Guests

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `guest_view` | `mhb_guest_view` | View guest records (gates Guests module) | Y | Y | Y | Y | Y |
| `guest_edit_contact` | `mhb_guest_edit_contact` | Edit guest contact information | Y | Y | - | Y | Y |
| `guest_edit_flags` | `mhb_guest_edit_flags` | Set VIP or problem flags on a guest | - | Y | - | - | Y |
| `guest_add_note` | `mhb_guest_add_note` | Add notes or service notes to a guest | Y | Y | Y | Y | Y |

### Payments

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `payment_view` | `mhb_payment_view` | View payment records (gates Payments module) | Y | Y | - | Y | Y |
| `payment_post` | `mhb_payment_post` | Post a full payment | Y | Y | - | Y | Y |
| `payment_post_partial` | `mhb_payment_post_partial` | Post a partial payment | Y | Y | - | Y | Y |
| `payment_mark_paid` | `mhb_payment_mark_paid` | Mark a reservation as paid | Y | Y | - | Y | Y |
| `payment_refund` | `mhb_payment_refund` | Issue a refund | - | Y | - | Y | Y |
| `payment_receipt_issue` | `mhb_payment_receipt_issue` | Issue a payment receipt | Y | Y | - | Y | Y |
| `payment_invoice_issue` | `mhb_payment_invoice_issue` | Issue an invoice | - | Y | - | Y | Y |
| `payment_reconcile` | `mhb_payment_reconcile` | Reconcile payments | - | - | - | Y | Y |

### Calendar

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `calendar_view` | `mhb_calendar_view` | View the calendar module | Y | Y | Y | Y | Y |
| `calendar_move_reservation` | `mhb_calendar_move_reservation` | Drag-and-drop move a reservation on the calendar | Y | Y | - | - | Y |
| `calendar_create_block` | `mhb_calendar_create_block` | Create a date block on the calendar | - | Y | - | - | Y |
| `calendar_edit_block` | `mhb_calendar_edit_block` | Edit or remove a date block | - | Y | - | - | Y |

### Housekeeping

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `housekeeping_view` | `mhb_housekeeping_view` | View the housekeeping board (gates Housekeeping module) | Y | Y | Y | - | Y |
| `housekeeping_update_status` | `mhb_housekeeping_update_status` | Update a room's cleaning status | - | - | Y | - | Y |
| `housekeeping_assign_staff` | `mhb_housekeeping_assign_staff` | Assign housekeeping staff to a room | - | Y | Y | - | Y |
| `housekeeping_inspect_room` | `mhb_housekeeping_inspect_room` | Mark a room inspected / hand it off | - | Y | Y | - | Y |
| `housekeeping_create_issue` | `mhb_housekeeping_create_issue` | Create a maintenance issue | - | Y | Y | - | Y |

### Rooms and Availability

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `inventory_view` | `mhb_inventory_view` | View rooms and availability (gates Rooms & Availability module) | Y | Y | Y | - | Y |
| `availability_rules_view` | `mhb_availability_rules_view` | View availability rules | - | Y | Y | - | Y |
| `availability_rules_edit` | `mhb_availability_rules_edit` | Edit availability rules | - | - | - | - | Y |
| `room_block_manage` | `mhb_room_block_manage` | Create and manage availability blocks | - | Y | - | - | Y |

Note: Room type and room unit CRUD (create / edit / delete) is reserved for the WP Administrator role via wp-admin only. No portal role receives those capabilities.

### Reports

| Capability | WP string | What it controls | FD | SUP | HK | FIN | OPS |
|---|---|---|---|---|---|---|---|
| `report_view_ops` | `mhb_report_view_ops` | View operational reports (primary gate for Reports module) | - | Y | - | - | Y |
| `report_view_finance` | `mhb_report_view_finance` | View finance reports | - | - | - | Y | Y |
| `report_view_management` | `mhb_report_view_management` | View management reports | - | - | - | - | Y |
| `report_export` | `mhb_report_export` | Export report data | - | Y | - | Y | Y |
| `audit_view` | `mhb_audit_view` | View the activity / audit log | - | - | - | - | Y |

### Administration capabilities

These capabilities are not part of the configurable matrix. They are managed separately.

| Capability | WP string | Who has it | Notes |
|---|---|---|---|
| `must_hotel_booking_access_portal` | `must_hotel_booking_access_portal` | All roles in `portal_access_roles` setting | Required to access the staff portal at all. Admins bypass this check. |
| `mhb_plugin_settings_manage` | `mhb_plugin_settings_manage` | No role by default | Checked alongside `manage_options`. Grants access to plugin settings page. |
| `mhb_staff_manage` | `mhb_staff_manage` | No role by default | Defined as constant; not yet assigned in the default matrix. |
| `mhb_role_manage` | `mhb_role_manage` | No role by default | Defined as constant; not yet assigned in the default matrix. |
| `mhb_portal_settings_manage` | `mhb_portal_settings_manage` | No role by default | Defined as constant; not yet assigned in the default matrix. |

---

## 3. Where Capabilities Are Defined

All capability slug constants are in `src/Core/StaffAccess.php`. The class exposes:

- `StaffAccess::getCapabilityKeyMap()` — maps short keys to full WP capability strings.
- `StaffAccess::getDefaultCapabilityMatrix()` — default true/false assignments per role.
- `StaffAccess::getCapabilityMatrix()` — live matrix (reads from `capability_matrix` plugin setting, falls back to defaults).
- `StaffAccess::syncRoleCapabilities()` — the single method that writes all caps to the WP roles table and adds all caps to `administrator`.

---

## 4. How Role-Gated Portal Access Works

Access to the staff portal is evaluated in two layers:

### Layer 1: Portal access

`StaffAccess::userCanAccessPortal()` returns true when:
- The user has `manage_options` (WP Administrator), **or**
- The user has `must_hotel_booking_access_portal` and is not flagged as disabled via the user meta key `mhb_staff_disabled`.

If this check fails the user cannot see any portal module regardless of other capabilities.

### Layer 2: Module access

Each portal module has a required capability defined in `PortalRegistry::getDefinitions()`:

| Module key | Route | Gating capability |
|---|---|---|
| `dashboard` | `/staff/` | `mhb_dashboard_view` |
| `reservations` | `/staff/reservations` | `mhb_reservation_view` |
| `calendar` | `/staff/calendar` | `mhb_calendar_view` |
| `front_desk` | `/staff/front-desk` | `mhb_reservation_create` |
| `guests` | `/staff/guests` | `mhb_guest_view` |
| `payments` | `/staff/payments` | `mhb_payment_view` |
| `housekeeping` | `/staff/housekeeping` | `mhb_housekeeping_view` |
| `rooms_availability` | `/staff/rooms-availability` | `mhb_inventory_view` |
| `reports` | `/staff/reports` | any of: `mhb_report_view_ops`, `mhb_report_view_finance`, `mhb_report_view_management` |

`StaffAccess::userCanAccessPortalModule(string $moduleKey)` performs both checks. Administrators always pass.

### Module visibility

In addition to capabilities, each module can be toggled on or off globally via the `portal_module_visibility` plugin setting. A module that is toggled off is hidden for all users including admins.

### Deprecated routes

The following URL routes issue a 301 redirect to their current equivalents:

| Old route | Redirects to |
|---|---|
| `/staff/quick-booking` | `/staff/front-desk?tab=new-booking` |
| `/staff/accommodations` | `/staff/rooms-availability?tab=rooms` |
| `/staff/availability-rules` | `/staff/rooms-availability?tab=rules` |

---

## 5. How Admin-Level Capabilities Differ

WordPress Administrators (`manage_options`) are not assigned a portal role. Instead:
- `syncRoleCapabilities()` explicitly grants every plugin capability to the `administrator` WP role.
- All `StaffAccess::userCan*()` methods short-circuit for `manage_options` users before checking any plugin-specific capability.
- The `mhb_staff_disabled` user meta has no effect on administrators.
- Administrators can access every portal module regardless of the `portal_module_visibility` setting — the visibility check only hides modules from the navigation; it does not enforce a hard block at the authentication layer.

---

## 6. How to Grant or Modify Roles Programmatically

### Assigning a plugin role to a user

```php
$user = get_user_by('login', 'jane.doe');
$user->set_role('mhb_front_desk'); // replaces all current roles
// or
$user->add_role('mhb_supervisor'); // adds without removing existing roles
```

### Modifying the capability matrix

The capability matrix is stored as a plugin setting (`capability_matrix` key under `must_hotel_booking` option group). After changing it you must call `syncRoleCapabilities()` to propagate the changes to the WP roles table.

```php
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\StaffAccess;

// Read current matrix
$matrix = StaffAccess::getCapabilityMatrix();

// Grant cancellation to front desk
$matrix[StaffAccess::ROLE_FRONT_DESK]['reservation_cancel'] = true;

// Persist
MustBookingConfig::set_group_settings('staff_access', ['capability_matrix' => $matrix]);

// Sync to WP roles table
StaffAccess::syncRoleCapabilities();
```

### Disabling a staff user without removing their role

Set the user meta key `mhb_staff_disabled` to `1`. This blocks portal access without affecting the WP role assignment.

```php
update_user_meta($user_id, 'mhb_staff_disabled', 1);
```

### Checking a capability in code

Use the standard WP function. All plugin capabilities are registered in the WP capabilities table.

```php
if (current_user_can('mhb_reservation_cancel')) {
    // show cancel button
}
```
