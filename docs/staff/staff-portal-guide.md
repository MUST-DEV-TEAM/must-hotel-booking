# MUST Hotel Booking — Staff Portal Guide

> **Audience:** Hotel staff using the staff portal — Front Desk Agents, Front Office Supervisors, Housekeeping staff, Finance / Cashiers, and Operations Managers.
>
> **Scope:** This guide covers only the staff portal (the front-end interface). WordPress admin screens are not covered except where a brief comparison is needed for context.
>
> **Role callout format:** Each section that is restricted by role shows an "Available to" callout listing the roles that have the relevant capability by default. Administrators (`manage_options`) always have full access and are not repeated in every callout.

---

## Quick navigation

| I want to... | Go to |
|---|---|
| Understand what the portal is | [Portal Overview](#1-portal-overview) |
| Log in | [Logging In](#2-logging-in) |
| See what is on the dashboard | [Dashboard](#3-dashboard) |
| Search or edit reservations | [Reservations Module](#4-reservations-module) |
| Check in or check out a guest | [Check-In Workflow](#43-check-in-a-guest) / [Check-Out Workflow](#44-check-out-a-guest) |
| Create a new booking at the desk | [Front Desk — New Booking](#52-new-booking-tab) |
| View today's arrivals or departures | [Front Desk — Queue](#53-check-in-and-check-out-queue-tabs) |
| Move a guest to a different room | [Front Desk — Room Move](#54-room-move--upgrade-tab) |
| Read or create blocks on the calendar | [Calendar Module](#6-calendar-module) |
| View or edit a guest profile | [Guests Module](#7-guests-module) |
| Post a payment or issue a receipt | [Payments Module](#8-payments-module) |
| Update a room's cleaning status | [Housekeeping — Room Board](#92-room-board-tab) |
| Log a maintenance issue | [Housekeeping — Maintenance Issues](#94-maintenance-issues-tab) |
| Run a shift handoff | [Housekeeping — Shift Handoff](#95-shift-handoff-tab) |
| View inventory rooms | [Rooms & Availability — Rooms Tab](#101-rooms-tab) |
| Manage availability blocks | [Rooms & Availability — Blocks Tab](#102-blocks-tab) |
| Run operational or finance reports | [Reports Module](#11-reports-module) |
| Understand who can do what | [Role Permissions Matrix](#12-role-permissions-matrix) |

---

## 1. Portal Overview

The staff portal is a dedicated front-end workspace for hotel staff, served as a WordPress page (not inside the WordPress admin panel). Its purpose is to centralise the daily operational tasks — arrivals, departures, payments, guest profiles, housekeeping, and reporting — in one place that does not require WordPress knowledge.

**How it differs from WordPress admin:**

- Staff with portal roles (`mhb_front_desk`, `mhb_supervisor`, `mhb_housekeeping`, `mhb_finance`, `mhb_ops_manager`) are redirected away from the WP admin dashboard and into the portal automatically on login. WP admin is hidden for those roles by default (configurable).
- Room type and room CRUD (create/edit/delete) are reserved for the Administrator role in WP admin. The portal provides view access to inventory but not room configuration.
- All portal pages live at `yoursite.com/staff/` (or the slug configured by the administrator).

**Portal modules** (sidebar navigation items):

| Module | URL segment | Primary capability required |
|---|---|---|
| Dashboard | `/staff/` or `/staff/dashboard` | `mhb_dashboard_view` |
| Reservations | `/staff/reservations` | `mhb_reservation_view` |
| Calendar | `/staff/calendar` | `mhb_calendar_view` |
| Front Desk | `/staff/front-desk` | `mhb_reservation_create` |
| Guests | `/staff/guests` | `mhb_guest_view` |
| Payments | `/staff/payments` | `mhb_payment_view` |
| Housekeeping | `/staff/housekeeping` | `mhb_housekeeping_view` |
| Rooms & Availability | `/staff/rooms-availability` | `mhb_inventory_view` |
| Reports | `/staff/reports` | `mhb_report_view_ops` OR `mhb_report_view_finance` OR `mhb_report_view_management` |

A module that is disabled site-wide by an administrator will not appear in the navigation for any role.

---

## 2. Logging In

### 2.1 Login URL

The portal login page is located at:

```
https://yoursite.com/staff-login/
```

The exact slug is configured by your administrator. If you are unsure of the address, ask your manager or the person who set up the system.

### 2.2 Credentials

Enter your **username or email address** and your **password**. Tick "Remember me" to stay logged in across browser sessions.

### 2.3 What happens on login

1. The system verifies your credentials.
2. It checks that the staff portal is enabled and that your account has a portal role and has not been individually disabled.
3. If your account has a default post-login module configured, you are redirected there. Otherwise you land on the first module your role can access (typically Dashboard).
4. If you have a portal role, the WordPress admin bar is hidden and navigating to `/wp-admin` redirects you back to the portal.

### 2.4 Error messages

| Message | Meaning |
|---|---|
| "Unable to sign you in with those credentials." | Wrong username/email or password. |
| "Your staff account has been disabled. Please contact an administrator." | Your account has been individually suspended in the plugin settings. Contact your manager. |
| "Your account does not have staff portal access." | Your WordPress user does not have a portal role assigned. |
| "The staff portal is currently disabled." | The portal has been turned off site-wide by an administrator. |
| "No portal modules are currently enabled for your account." | Your role exists but no modules are visible for it. Contact an administrator. |

### 2.5 Signing out

Use the sign-out link visible in the portal navigation. After signing out you will see a "You have been signed out of the staff portal." confirmation message on the login page.

---

## 3. Dashboard

**Available to:** All roles with `mhb_dashboard_view` — Front Desk Agent, Front Office Supervisor, Housekeeping, Finance / Cashier, Operations Manager (all roles by default).

The Dashboard is the landing page after login. It is a read-only operational overview; no data is changed from this page.

### 3.1 Hero metrics bar

Four pill counters appear at the top:

| Pill | What it counts |
|---|---|
| Needs action | Items in the "Needs action now" panel |
| Recent reservations | Reservations in the recent feed |
| Activity items | Rows in the recent activity feed |
| Health checks to review | Admin-only: configuration issues (visible to administrators only) |

### 3.2 KPI summary cards

A row of summary cards appears below the hero. The exact metrics rendered depend on the role's permissions (finance details are hidden for roles without `mhb_payment_view` or finance report caps).

### 3.3 Approval queue

Visible when there are pending reservation cancellation requests that require a supervisor or manager decision. Each item shows the booking reference, the nature of the request, a severity badge, and an "Open decision" link that navigates to the reservation workspace.

**Available to:** Roles with `mhb_reservation_cancel` — Front Office Supervisor, Operations Manager.

### 3.4 Needs action now

A feed of operational items that should be reviewed before they become guest-facing problems. Each item has a title, message, severity badge, and a "Review item" link.

### 3.5 Jump into work (quick actions)

A grid of shortcut cards linking to the most common next operational area for the logged-in role. Links are generated only for modules the current user can actually access.

### 3.6 Recent reservations

A feed showing the most recent bookings created or updated. Each row shows:
- Booking ID and guest name
- Accommodation name and date range
- Payment status and total (shown only if user has `mhb_payment_view` or finance report caps)
- "Open reservation" and "Edit" inline links

### 3.7 Recent activity

A feed of reservation, housekeeping, guest, and email events. Finance roles see payment and reservation events instead. Each row shows the event message, timestamp, a reference, a severity badge, and an "Open record" link where applicable.

### 3.8 System health (Administrators only)

An admin-only panel showing plugin configuration checks with direct links to the area that needs review. Hidden for all staff roles.

---

## 4. Reservations Module

**Available to:** All roles with `mhb_reservation_view` — Front Desk Agent, Front Office Supervisor, Housekeeping, Finance / Cashier, Operations Manager.

The Reservations module has two views: the **queue list** and the **reservation workspace** (detail view).

### 4.1 Reservation list / queue

The list is the default view at `/staff/reservations`. It shows all reservations with filters.

**Filters available:**

| Filter | Field |
|---|---|
| Free-text search | Booking ID, guest name, email |
| Reservation status | Dropdown (confirmed, pending, cancelled, etc.) |
| Payment status | Dropdown |
| Payment method | Dropdown |
| Check-in from / to | Date range |
| Check-in month | Month picker |
| Room | Specific room |
| Per page | 20 by default |

Each row in the list shows booking ID, guest, accommodation, dates, status, and payment status. Click a row or the "Open reservation" link to open the reservation workspace.

### 4.2 Reservation workspace (detail view)

Navigating to a specific reservation (e.g. via `?reservation_id=123`) opens the workspace. The page is split into sections:

**Hero bar:** Booking ID, guest name, accommodation name, date range. Inline navigation buttons link to the queue, calendar, guest profile, and "Add reservation".

**Status grid:**
- Reservation status badge
- Payment status and amount due (visible only if user has `mhb_payment_view` or relevant finance/report caps)
- Stay facts (nights, guests)

**Immediate actions panel:** All workflow actions described in sections 4.3–4.9 appear here as buttons, shown only when the action is valid for the current reservation state and the user has the required capability.

**Attention items:** Warnings are automatically surfaced when:
- Reservation is pending or pending payment
- There is an outstanding balance
- The guest email is missing or invalid
- No physical room has been assigned
- A cancellation request is pending
- The checkout date has passed without completion
- The arrival date has passed without a check-in

**Edit panels** (separate collapsible sections below the hero):
- Guest contact details (requires `mhb_guest_edit_contact`)
- Stay edit: dates and guest count (requires `mhb_reservation_edit_stay`)
- Room assignment (requires `mhb_reservation_assign_room` or `mhb_reservation_move_room` if in-house)
- Internal notes (requires `mhb_guest_add_note`)
- Booking source and basic fields (requires `mhb_reservation_edit_basic`)

**Payment section:** Visible to users with `mhb_payment_view`, `mhb_report_view_finance`, or `mhb_report_view_management`.

**Timeline:** A chronological log of all status changes, emails sent, and payment events for the reservation.

**Emails sent:** A list of email communications sent to the guest.

### 4.3 Check in a guest

**Available to:** Roles with `mhb_reservation_checkin` — Front Desk Agent, Front Office Supervisor, Operations Manager.

The "Check in guest" button appears in the Immediate Actions panel when:
- The reservation status is `confirmed`, `pending`, or `pending_payment`
- The guest has not already been checked in

**Steps:**
1. Open the reservation workspace.
2. Review the attention items panel for any outstanding balance or missing room assignment.
3. Click **Check in guest** in the Immediate Actions panel.
4. Confirm the browser confirmation dialog.
5. The reservation status updates and the check-in timestamp is recorded.

The "Check in" quick-action button also appears directly in the Front Desk Check-in Queue (see section 5.3) for faster processing.

### 4.4 Check out a guest

**Available to:** Roles with `mhb_reservation_checkout` — Front Desk Agent, Front Office Supervisor, Operations Manager.

The "Check out guest" button appears when:
- The reservation status is `confirmed`
- The guest has already been checked in
- The guest has not yet been checked out

**Steps:**
1. Open the reservation workspace.
2. Verify any outstanding balance has been settled (see Payments module, section 8).
3. Click **Check out guest** in the Immediate Actions panel.
4. Confirm the browser confirmation dialog.
5. The check-out timestamp is recorded.

The "Check out" quick-action button also appears in the Front Desk Check-out Queue.

### 4.5 Mark no-show

**Available to:** Roles with `mhb_reservation_mark_no_show` — Front Desk Agent, Front Office Supervisor, Operations Manager.

The "Mark no-show" button appears when:
- The reservation status is `confirmed`, `pending`, or `pending_payment`
- The guest has not been checked in
- The check-in date is on or before today's date

**Steps:**
1. Open the reservation workspace.
2. Confirm the guest will not arrive.
3. Click **Mark no-show** in the Immediate Actions panel.

### 4.6 Cancel a reservation

**Available to:** Roles with `mhb_reservation_cancel` — Front Office Supervisor, Operations Manager.

Cancellation is a two-track process depending on the user's capability:

**Direct cancellation** (roles with `mhb_reservation_cancel`):
1. Open the reservation workspace.
2. Click **Cancel reservation** in the Immediate Actions panel.
3. Confirm the browser confirmation dialog.

**Cancellation request** (roles with `mhb_reservation_edit_basic` but not `mhb_reservation_cancel`, e.g. Front Desk Agent):
1. Open the reservation workspace.
2. Click **Request cancellation** in the Immediate Actions panel.
3. The system records the requester and timestamp and flags the reservation in the Approval Queue on the Dashboard.
4. A supervisor or manager must then **Approve** or **Reject** the request.

### 4.7 Confirm a reservation

**Available to:** Roles with `mhb_reservation_edit_basic` — Front Desk Agent, Front Office Supervisor, Operations Manager.

The "Confirm reservation" button appears when the reservation is in a non-confirmed, non-completed, non-cancelled state. Click it in the Immediate Actions panel to confirm the booking.

### 4.8 Assign or move a room

**Available to:**
- Assign room: `mhb_reservation_assign_room` — Front Desk Agent, Front Office Supervisor, Operations Manager.
- Move room (in-house guests): `mhb_reservation_move_room` — Front Office Supervisor, Operations Manager.

When the guest is not yet checked in, the "Assign room" section displays a dropdown of available rooms. When the guest is already checked in (in-house), the system uses the move-room capability instead.

Room moves can also be performed in bulk from the Front Desk module's Room Move tab (see section 5.4).

### 4.9 Bulk actions

**Available to:** Roles with `mhb_reservation_bulk_actions` — Front Office Supervisor, Operations Manager.

Bulk actions are accessible from the reservation list. Select multiple reservations using checkboxes and apply a batch operation. The available bulk actions are determined by what the UI renders for that role.

> **Note:** The specific bulk action options available (e.g., bulk-confirm, bulk-export) are Unverified / needs confirmation — the code confirms the capability exists but the exact actions rendered depend on the controller implementation not fully read in this audit.

---

## 5. Front Desk Module

**Available to:** Roles with `mhb_reservation_create` — Front Desk Agent, Front Office Supervisor, Operations Manager.

The Front Desk module (`/staff/front-desk`) is a tabbed workspace with six tabs:

| Tab | Purpose |
|---|---|
| New Booking | Create a confirmed reservation directly from the portal |
| Walk-in | Currently inactive — redirects to New Booking |
| Check-in Queue | Arrivals expected today or overdue, with inline check-in action |
| Check-out Queue | Departures due today or overdue, with inline check-out action |
| Room Move / Upgrade | In-house guests that can be moved to a different room |
| Desk Log | Chronological activity log for recent front-desk events |

### 5.1 Tab navigation

Click any tab label in the tab bar at the top of the module. The active tab is highlighted. The URL updates to include `?tab=<tab-key>`.

### 5.2 New Booking tab

Creates a confirmed reservation without leaving the portal.

**Fields:**

| Field | Required |
|---|---|
| Room | Yes — select from a dropdown of available rooms |
| Check-in date | Yes |
| Check-out date | Yes |
| Number of guests | Yes |
| Guest name | Yes |
| Phone | No |
| Email | Yes |
| Booking source | Yes — dropdown (e.g. direct, phone, OTA) |
| Notes | No |

An **Estimated total** is displayed based on the selected room and dates before the form is submitted.

**Steps:**
1. Navigate to **Front Desk > New Booking**.
2. Select the room from the dropdown.
3. Enter check-in and check-out dates.
4. Enter the number of guests.
5. Enter the guest name, phone (optional), and email.
6. Select the booking source.
7. Add any notes.
8. Review the estimated total.
9. Click **Create reservation**.

The system creates the reservation and records the booking source. A success or error message is shown in place.

### 5.3 Check-in and Check-out Queue tabs

Both queue tabs display a tabular list of reservations relevant to today's shift.

**Columns:**
- Guest name, email, and booking ID
- Stay dates
- Accommodation type and assigned room (or "No room assigned")
- Queue state badge and reservation status badge
- Payment status and amount due
- Action links: "Open reservation", "Payments" (if accessible), and the inline check-in or check-out button

**Inline check-in (Check-in Queue tab):**
1. Locate the guest in the queue.
2. Click **Check in** in the Actions column.
3. Confirm the dialog ("Check in this reservation now?").

**Inline check-out (Check-out Queue tab):**
1. Locate the guest in the queue.
2. Click **Check out** in the Actions column.
3. Confirm the dialog ("Check out this reservation now?").

> **Tip:** For guests with an outstanding balance, click "Payments" to open the payment workspace first before checking out.

### 5.4 Room Move / Upgrade tab

**Available to:** Roles with `mhb_reservation_move_room` — Front Office Supervisor, Operations Manager.

Displays a list of in-house guests whose reservation can be re-assigned to a different physical room.

**Columns:**
- Guest name, email, and booking ID
- Stay dates
- Current assigned room
- Accommodation type
- State and reservation status badges
- Move dropdown and "Move guest" button

**Steps:**
1. Navigate to **Front Desk > Room Move / Upgrade**.
2. Find the guest to move.
3. Select the new room from the "Select room" dropdown (only compatible rooms are listed).
4. Click **Move guest**.

If no compatible room is available, "No compatible room is currently available" is displayed. If the user does not have the `mhb_reservation_move_room` capability, "Room moves require room-move authority" is displayed instead.

### 5.5 Desk Log tab

A read-only chronological table of front-desk events.

**Columns:** When, Actor, Event (badge), Summary, Link.

This log shows who did what at the desk and provides links back to the affected records.

---

## 6. Calendar Module

**Available to:** All roles with `mhb_calendar_view` — Front Desk Agent, Front Office Supervisor, Housekeeping, Finance / Cashier, Operations Manager.

The Calendar module (`/staff/calendar`) is a room-based timeline view covering a configurable number of weeks.

### 6.1 Reading the calendar

The calendar displays rooms as rows and dates as columns. Each cell shows:
- Reservation bars spanning the occupied date range, labelled with the booking reference and guest name
- Colour coding by reservation status (via badge tone)
- Availability block indicators

**Navigation:**
- Use the date navigation controls to move forward or backward in time.
- A `weeks` parameter controls how many weeks are shown at once (defaults to 2).
- A `focus_room_id` parameter highlights a specific room.
- A `reservation_id` parameter highlights a specific reservation.

**Clicking a cell:**
Clicking on a room/date cell selects it and loads a side panel (`selected summary`) showing all reservations arriving, departing, or staying through that room on that date. Each reservation in the panel shows:
- Booking reference and guest name
- Status label, date range, and booking source
- "Open reservation" link
- "Payments" link (if user has payment access)

### 6.2 Moving a reservation on the calendar

**Available to:** Roles with `mhb_calendar_move_reservation` — Front Desk Agent, Front Office Supervisor, Operations Manager.

> **Unverified / needs confirmation:** The calendar template confirms that the `mhb_calendar_move_reservation` capability is checked, but the drag-and-drop or form-based move interaction is handled by JavaScript that was not fully audited here. The exact steps for triggering a calendar move should be confirmed against the live interface.

### 6.3 Creating an availability block

**Available to:** Roles with `mhb_calendar_create_block` — Front Office Supervisor, Operations Manager.

Availability blocks prevent new bookings from being accepted for a room during a date range. To create a block:

1. Navigate to **Calendar**.
2. Use the block creation control in the calendar interface.
3. Select the room, start date, and end date.
4. Optionally add a block name or reason.
5. Save the block.

> **Unverified / needs confirmation:** The exact block creation UI control on the calendar page was not rendered in the template extract (the block form may be injected by JavaScript). Verify the step-by-step flow on the live calendar page.

Blocks can also be managed from the Rooms & Availability module (see section 10.2).

### 6.4 Editing or removing a block

**Available to:** Roles with `mhb_calendar_edit_block` — Front Office Supervisor, Operations Manager.

> **Unverified / needs confirmation:** See note in section 6.3.

---

## 7. Guests Module

**Available to:** All roles with `mhb_guest_view` — Front Desk Agent, Front Office Supervisor, Housekeeping, Finance / Cashier, Operations Manager.

The Guests module (`/staff/guests`) shows a searchable list of guest profiles and a detail workspace.

### 7.1 Guest list

**Search bar fields:** Guest name, email, phone, or booking ID.

**Table columns:**
- Guest name and country
- Email and phone
- Stay context: total reservation count, upcoming stay dates, last stay dates, total spend (visible only if user has `mhb_payment_view`)
- Status badges (e.g. VIP, flagged)
- "View" link to open the guest profile

### 7.2 Guest profile workspace

Opening a guest profile loads a two-panel layout below the list.

**Guest summary panel:**
- Total reservations
- Completed stays
- Currently in house (Yes/No)
- Next stay dates (or "No upcoming stay")
- Total spend, upcoming amount due, and preferred payment method (visible only if user has `mhb_payment_view`)
- "Open reservations" and "Open payments" shortcut buttons

**Operational warnings panel:** Alerts surfaced automatically about the guest's active reservations (e.g. pending balance, missing email, past checkout without completion). Payment warnings are shown only if the user has `mhb_payment_view`.

### 7.3 Editing guest contact information

**Available to:** Roles with `mhb_guest_edit_contact` — Front Desk Agent, Front Office Supervisor, Finance / Cashier, Operations Manager.

The contact edit form shows fields such as first name, last name, email, and phone. Make changes and save.

> **Unverified / needs confirmation:** The exact field set rendered in the contact form was not captured in the template extract read. Confirm the full field list on the live guest profile.

### 7.4 Editing guest flags

**Available to:** Roles with `mhb_guest_edit_flags` — Front Office Supervisor, Operations Manager.

Flags visible in the profile:
- **VIP flag** (`vip_flag`): Marks the guest as a VIP.
- **Problem flag** (`problem_flag`): Flags the guest as requiring special attention (sometimes referred to as a "problem guest" or blacklist flag in the UI labels).

Toggle these flags from the guest profile. Changes are saved immediately.

### 7.5 Adding a guest note

**Available to:** Roles with `mhb_guest_add_note` — Front Desk Agent, Front Office Supervisor, Housekeeping, Finance / Cashier, Operations Manager.

A "Handoff Notes" or internal notes text area is available in the guest profile. Enter the note and save. Notes are appended to the existing `admin_notes` field for the guest.

### 7.6 Reservation and payment history

The guest profile also shows:
- A list of the guest's reservations with links back to each reservation workspace.
- A list of email communications sent to the guest.
- Duplicate profile warnings when another guest record appears to be the same person (visible to users with `mhb_guest_edit_contact` or `mhb_guest_edit_flags`).

---

## 8. Payments Module

**Available to:** Roles with `mhb_payment_view` — Front Desk Agent, Front Office Supervisor, Finance / Cashier, Operations Manager.

The Payments module (`/staff/payments`) has a filterable list and a payment detail workspace.

### 8.1 Payment list

**Search:** Booking ID, guest name, or email.

**Payment group filter:** A dropdown that groups payments by state (e.g. outstanding, paid, all).

**Table columns:**
- Booking ID and accommodation type
- Guest name
- Payment method
- Payment status badge
- Amount due
- "View" link

### 8.2 Payment detail workspace

Opening a payment record shows:

**Payment detail panel:**
- Guest name and accommodation
- Payment method label
- Reservation total, amount paid, amount refunded (if any), and amount due
- Gateway reference / transaction ID (if recorded)
- Payment status badge and reservation status badge

**Operational actions panel:** All payment actions listed below. Only actions appropriate to the current reservation and payment state are displayed.

**Transaction rows:** A ledger table showing individual payment entries (amount, method, status, reference, recorded date).

**Payment timeline:** A feed of recent payment events logged for the reservation.

### 8.3 Post full payment

**Available to:** Roles with `mhb_payment_post` — Front Desk Agent, Front Office Supervisor, Finance / Cashier, Operations Manager.

Appears when there is an outstanding balance and the reservation is not cancelled or blocked.

**Steps:**
1. Open the payment detail for the reservation.
2. Optionally enter a Reference / receipt number (e.g. a cash register receipt number or ledger reference).
3. Click **Post full payment**.
4. The full outstanding amount is recorded as collected and the payment status updates.

### 8.4 Post partial payment

**Available to:** Roles with `mhb_payment_post_partial` — Front Desk Agent, Front Office Supervisor, Finance / Cashier, Operations Manager.

**Steps:**
1. Open the payment detail for the reservation.
2. Enter the partial amount in the "Partial amount" field (pre-filled with the outstanding balance; adjust as needed).
3. Optionally enter a reference number.
4. Click **Post partial payment**.

### 8.5 Mark as paid

**Available to:** Roles with `mhb_payment_mark_paid` — Front Desk Agent, Front Office Supervisor, Finance / Cashier, Operations Manager.

The **Mark paid** button appears when the payment state allows this transition (determined by `PaymentStatusService::canTransition`). This is a status override — it marks the reservation as fully paid without recording a transaction row. Use this when payment was received through an external channel not tracked by the plugin.

**Steps:**
1. Open the payment detail for the reservation.
2. Click **Mark paid**.

### 8.6 Issue refund

**Available to:** Roles with `mhb_payment_refund` — Front Office Supervisor, Finance / Cashier, Operations Manager.

Refund behaviour differs by payment method:

**Stripe payments:** A refund form appears when `amount_paid > 0` and a transaction ID exists.
1. Enter the refund amount (pre-filled with the amount paid; adjust for partial refunds).
2. Click **Issue refund**.
3. The refund is submitted to Stripe via the API.

**Pay at hotel:** The portal displays "Pay-at-hotel refunds still need to be handled manually outside the plugin." No automated refund button is shown; the staff member must process the refund outside the system and update the payment state manually.

### 8.7 Reconcile payment state

**Available to:** Roles with `mhb_payment_reconcile` — Finance / Cashier, Operations Manager.

Reconciliation actions allow Finance to correct the payment state when it has drifted from reality. The following transitions are available when allowed by `PaymentStatusService::canTransition`:
- **Mark unpaid** — resets to an unpaid state
- **Mark pending** — resets to a pending state
- **Set pay at hotel** — marks the method as pay-at-hotel
- **Mark failed** — marks the payment as failed

### 8.8 Generate receipt

**Available to:** Roles with `mhb_payment_receipt_issue` — Front Desk Agent, Front Office Supervisor, Finance / Cashier, Operations Manager.

The **Generate receipt** button appears when the reservation has at least one payment record or a positive amount paid. Click it to generate and open the receipt document in a new tab.

### 8.9 Generate invoice

**Available to:** Roles with `mhb_payment_invoice_issue` — Front Office Supervisor, Finance / Cashier, Operations Manager.

The **Generate invoice** button appears when the reservation total is greater than zero. Click it to generate and open the invoice document in a new tab.

---

## 9. Housekeeping Module

**Available to:** All roles with `mhb_housekeeping_view` — Housekeeping, Front Office Supervisor, Operations Manager.

The Housekeeping module (`/staff/housekeeping`) is a tabbed workspace with five tabs:

| Tab | Key |
|---|---|
| Room Board | `room-board` |
| Assignments | `assignments` |
| Inspection | `inspection` (Unverified / needs confirmation — marked "coming in later slice" in code) |
| Maintenance Issues | `maintenance` |
| Shift Handoff | `handoff` |

### 9.1 Tab navigation

Click the tab name in the tab bar. The URL updates with `?tab=<key>`.

### 9.2 Room Board tab

The Room Board is the default view. It displays every active inventory unit with its current operational context and housekeeping status.

**Table columns:**
- Room / Unit name and any metadata (floor, location)
- Room type
- Operational context: the reservation state context (e.g. "Arriving today", "In house", "Departing today", "Vacant") plus the inventory status badge
- Housekeeping status: a badge showing the current cleaning state (e.g. Clean, Dirty, Inspected) and any status note
- Actions: status-change buttons

**Status action buttons** (shown only when the transition is valid for that room's current state):

| Button | Action constant | Capability required |
|---|---|---|
| Mark dirty | `housekeeping_mark_dirty` | `mhb_housekeeping_update_status` |
| Mark clean | `housekeeping_mark_clean` | `mhb_housekeeping_update_status` |
| Mark inspected | `housekeeping_mark_inspected` | `mhb_housekeeping_inspect_room` |

**Steps — mark a room dirty:**
1. Navigate to **Housekeeping > Room Board**.
2. Find the room in the table.
3. Click **Mark dirty**.

**Steps — mark a room clean:**
1. Navigate to **Housekeeping > Room Board**.
2. Find the room in the table.
3. Click **Mark clean**.

**Steps — mark a room inspected:**
1. Navigate to **Housekeeping > Room Board**.
2. Find the room in the table.
3. Click **Mark inspected**.

> **Available to:**
> - Mark dirty / Mark clean: Roles with `mhb_housekeeping_update_status` — Housekeeping, Operations Manager.
> - Mark inspected: Roles with `mhb_housekeeping_inspect_room` — Housekeeping, Front Office Supervisor, Operations Manager.

### 9.3 Assignments tab

**Available to:** Roles with `mhb_housekeeping_assign_staff` — Housekeeping, Front Office Supervisor, Operations Manager.

The Assignments tab shows the same room list as the Room Board but with an additional "Assigned To" column and an assignment form per row.

**Table columns:**
- Room / Unit and room type
- Operational context
- Housekeeping status badge
- Open / resolved maintenance issue counts
- Currently assigned staff member and assignment timestamp
- Assignment form: a dropdown of assignable staff members and a Save button

**Steps — assign a room to a staff member:**
1. Navigate to **Housekeeping > Assignments**.
2. Find the room.
3. Select the staff member from the "Assign to" dropdown.
4. Click **Save assignment** (or the equivalent submit button).

### 9.4 Maintenance Issues tab

**Available to:**
- Viewing issues: All roles with `mhb_housekeeping_view`.
- Creating issues: Roles with `mhb_housekeeping_create_issue` — Housekeeping, Front Office Supervisor, Operations Manager.

**Creating a maintenance issue:**
1. Navigate to **Housekeeping > Maintenance Issues**.
2. Select the affected Room / Unit from the dropdown.
3. Enter an Issue Title (e.g. "AC not cooling").
4. Enter Issue Details (optional but recommended).
5. Click **Create Issue**.

**Issue list columns:**
- Room / Unit
- Issue title and details
- Status badge
- Last updated date/time
- Actions (resolve, update — Unverified / needs confirmation: exact action buttons depend on the controller not fully read in this audit)

### 9.5 Shift Handoff tab

**Available to:** Roles with `mhb_housekeeping_update_status` or `mhb_housekeeping_inspect_room` — Housekeeping, Front Office Supervisor, Operations Manager.

The Shift Handoff captures the current room board state and any unresolved issues so the next shift can pick up without missing context.

**Creating a handoff:**
1. Navigate to **Housekeeping > Shift Handoff**.
2. Enter a Shift Label (e.g. "Morning to Evening").
3. Enter Handoff Notes — practical points the next shift should know.
4. Click **Capture Handoff**.

The page also displays:
- **Unresolved Issues:** A table of open maintenance issues that carry over to the next shift (Room, Issue, Status).
- **Previous handoffs:** A table of past handoffs logged during the period (Unverified / needs confirmation: the `handoff_rows` data is rendered but the exact columns were not fully captured in this audit).

---

## 10. Rooms & Availability Module

**Available to:** All roles with `mhb_inventory_view` — Front Desk Agent, Front Office Supervisor, Housekeeping, Operations Manager.

> **Note:** Finance / Cashier does not have `mhb_inventory_view` by default and will not see this module.

The Rooms & Availability module (`/staff/rooms-availability`) is a tabbed workspace with six tabs:

| Tab key | Label |
|---|---|
| `rooms` | Rooms |
| `room-types` | Room Types |
| `statuses` | Statuses |
| `blocks` | Blocks |
| `rules` | Rules |
| `maintenance` | Maintenance |

### 10.1 Rooms tab

Displays all active inventory units with filtering by search term, room type, and inventory state.

**Table columns:**
- Room / Unit identifier, title, and location/metadata
- Room type
- Inventory state badge (available, occupied, blocked, etc.)
- Housekeeping status badge
- Stay context (upcoming or current reservation dates)
- Workspace links (e.g. link to housekeeping module for that room)

Room type and room CRUD is not available in the portal — this is reserved for the WordPress admin. The portal is view-only for this tab.

### 10.2 Blocks tab

**View access:** Roles with `mhb_availability_rules_view` OR `mhb_room_block_manage` — Front Office Supervisor, Operations Manager.

**Manage/create/edit/delete blocks:** Roles with `mhb_room_block_manage` — Front Office Supervisor, Operations Manager.

Availability blocks prevent new guest bookings for a room during a date range.

**Filters:**
- Search (block name, room listing)
- Room listing dropdown
- Timeline: All / Current / Future / Past

**Block list:** Shows existing blocks with the affected room, date range, block name/reason, and edit/delete actions.

**Creating a block:**
1. Navigate to **Rooms & Availability > Blocks**.
2. Use the block creation form.
3. Select the room, enter start and end dates, and optionally a name/reason.
4. Submit the form.

**Editing or deleting a block:** Click the edit or delete action on an existing block row.

### 10.3 Rules tab

**View access:** Roles with `mhb_availability_rules_view` — Housekeeping, Front Office Supervisor, Operations Manager.

**Edit rules site-wide:** Roles with `mhb_availability_rules_edit` — Operations Manager only.

**Edit room-specific rules (blocks):** Roles with `mhb_room_block_manage` — Front Office Supervisor, Operations Manager.

Availability rules define minimum stay lengths, booking windows, and other constraints. The rules tab lists current rules with their scope (global or per-room) and allows editing if the user has the appropriate capability.

### 10.4 Statuses tab

Displays a summary of current room status states across the inventory.

> **Unverified / needs confirmation:** The exact columns and actions on the Statuses tab were not captured in the template audit. Verify on the live portal.

### 10.5 Room Types and Maintenance tabs

> **Unverified / needs confirmation:** The Room Types and Maintenance tabs within the Rooms & Availability module display "coming in the next implementation phase" or are rendered by sub-templates not fully read in this audit. Verify which content is live on these tabs.

---

## 11. Reports Module

**Available to:** Roles with at least one of: `mhb_report_view_ops`, `mhb_report_view_finance`, or `mhb_report_view_management`. Each tab is gated separately.

The Reports module (`/staff/reports`) is a tabbed workspace. Each role sees only the tabs they are permitted to access:

| Tab key | Label | Capability required |
|---|---|---|
| `daily-operations` | Daily Operations | `mhb_report_view_ops` |
| `payments-finance` | Payments & Finance | `mhb_report_view_finance` |
| `occupancy` | Occupancy | `mhb_report_view_ops` (Unverified / needs confirmation — confirm exact cap) |
| `audit-log` | Audit Log | `mhb_audit_view` |

> **Available to by default:**
> - Daily Operations: Front Office Supervisor, Operations Manager
> - Payments & Finance: Finance / Cashier, Operations Manager
> - Audit Log: Operations Manager

### 11.1 Report filters

A shared filter bar drives all tabs. The same date range applies across all views.

**Common filters:**
- **Preset:** Date range presets (e.g. Today, This week, This month — Unverified / needs confirmation: exact preset labels depend on the data provider)
- **Date from / Date to:** Manual date range
- **Accommodation:** Filter to a specific room (not available on the Audit Log tab)

**Tab-specific filters:**
- Daily Operations / Occupancy: Reservation status dropdown
- Payments & Finance: Payment method dropdown

**Export:** If the user has `mhb_report_export`, an **Export CSV** button appears in the filter panel header. Clicking it downloads the current report data as a CSV file.

### 11.2 Daily Operations tab

**Available to:** Front Office Supervisor, Operations Manager.

Shows operational summary cards followed by panels including:
- Reservation status mix (breakdown by status)
- Booking trends over the selected period
- Stay facts (arrivals, departures, in-house counts)
- Top accommodations by reservation count
- Coupon usage
- Notes/alerts

### 11.3 Payments & Finance tab

**Available to:** Finance / Cashier, Operations Manager.

Shows finance summary cards followed by:
- Payment method breakdown
- Revenue by accommodation
- Refund summary
- Outstanding balance overview

### 11.4 Occupancy tab

**Available to:** Front Office Supervisor, Operations Manager (Unverified / needs confirmation — verify capability gate).

Shows occupancy summary cards and occupancy-related breakdowns.

### 11.5 Audit Log tab

**Available to:** Operations Manager (requires `mhb_audit_view`).

Shows a table of all activity records in the selected date range with summary cards for:
- Total activity rows
- Warning-severity rows
- Error-severity rows

Each row in the audit table shows the event timestamp, actor, event type (badge), and summary message.

---

## 12. Role Permissions Matrix

This table is built directly from `src/Core/StaffAccess.php` — `getDefaultCapabilityMatrix()`. These are the **default** assignments. An administrator can customise individual capabilities per role from the plugin settings; the table reflects what is configured out of the box.

Legend: **Y** = capability granted by default | **—** = not granted by default

| Capability | Constant | Front Desk Agent | Supervisor | Housekeeping | Finance / Cashier | Ops Manager |
|---|---|:---:|:---:|:---:|:---:|:---:|
| **Dashboard** | | | | | | |
| View dashboard | `mhb_dashboard_view` | Y | Y | Y | Y | Y |
| **Reservations** | | | | | | |
| View reservations | `mhb_reservation_view` | Y | Y | Y | Y | Y |
| Create reservations | `mhb_reservation_create` | Y | Y | — | — | Y |
| Edit reservation basics (notes, source, email) | `mhb_reservation_edit_basic` | Y | Y | — | — | Y |
| Edit stay details (dates, guest count) | `mhb_reservation_edit_stay` | Y | Y | — | — | Y |
| Assign / reassign room | `mhb_reservation_assign_room` | Y | Y | — | — | Y |
| Move room (in-house guest) | `mhb_reservation_move_room` | — | Y | — | — | Y |
| Check in guest | `mhb_reservation_checkin` | Y | Y | — | — | Y |
| Check out guest | `mhb_reservation_checkout` | Y | Y | — | — | Y |
| Cancel reservation | `mhb_reservation_cancel` | — | Y | — | — | Y |
| Mark no-show | `mhb_reservation_mark_no_show` | Y | Y | — | — | Y |
| Bulk reservation actions | `mhb_reservation_bulk_actions` | — | Y | — | — | Y |
| **Guests** | | | | | | |
| View guests | `mhb_guest_view` | Y | Y | Y | Y | Y |
| Edit guest contact info | `mhb_guest_edit_contact` | Y | Y | — | Y | Y |
| Edit guest flags (VIP, blacklist) | `mhb_guest_edit_flags` | — | Y | — | — | Y |
| Add guest / service note | `mhb_guest_add_note` | Y | Y | Y | Y | Y |
| **Payments** | | | | | | |
| View payments | `mhb_payment_view` | Y | Y | — | Y | Y |
| Post payment | `mhb_payment_post` | Y | Y | — | Y | Y |
| Post partial payment | `mhb_payment_post_partial` | Y | Y | — | Y | Y |
| Mark as paid | `mhb_payment_mark_paid` | Y | Y | — | Y | Y |
| Issue refund | `mhb_payment_refund` | — | Y | — | Y | Y |
| Issue receipt | `mhb_payment_receipt_issue` | Y | Y | — | Y | Y |
| Issue invoice | `mhb_payment_invoice_issue` | — | Y | — | Y | Y |
| Reconcile payments | `mhb_payment_reconcile` | — | — | — | Y | Y |
| **Calendar** | | | | | | |
| View calendar | `mhb_calendar_view` | Y | Y | Y | Y | Y |
| Move reservation on calendar | `mhb_calendar_move_reservation` | Y | Y | — | — | Y |
| Create availability block | `mhb_calendar_create_block` | — | Y | — | — | Y |
| Edit / remove availability block | `mhb_calendar_edit_block` | — | Y | — | — | Y |
| **Housekeeping** | | | | | | |
| View housekeeping board | `mhb_housekeeping_view` | Y | Y | Y | — | Y |
| Update room cleaning status | `mhb_housekeeping_update_status` | — | — | Y | — | Y |
| Assign housekeeping staff | `mhb_housekeeping_assign_staff` | — | Y | Y | — | Y |
| Mark room inspected / hand off | `mhb_housekeeping_inspect_room` | — | Y | Y | — | Y |
| Create maintenance issue | `mhb_housekeeping_create_issue` | — | Y | Y | — | Y |
| **Rooms & Availability** | | | | | | |
| View rooms and availability | `mhb_inventory_view` | Y | Y | Y | — | Y |
| View availability rules | `mhb_availability_rules_view` | — | Y | Y | — | Y |
| Edit availability rules | `mhb_availability_rules_edit` | — | — | — | — | Y |
| Manage availability blocks | `mhb_room_block_manage` | — | Y | — | — | Y |
| **Reports** | | | | | | |
| View operational reports | `mhb_report_view_ops` | — | Y | — | — | Y |
| View finance reports | `mhb_report_view_finance` | — | — | — | Y | Y |
| View management reports | `mhb_report_view_management` | — | — | — | — | Y |
| Export reports | `mhb_report_export` | — | Y | — | Y | Y |
| View audit log | `mhb_audit_view` | — | — | — | — | Y |
| **Administration** | | | | | | |
| Manage plugin settings | `mhb_plugin_settings_manage` | — | — | — | — | — |

> **Notes on the table:**
> - `mhb_plugin_settings_manage` is not granted to any staff portal role by default. Plugin settings are managed by the WordPress Administrator (`manage_options`).
> - The WordPress Administrator role has all capabilities added to it automatically and is not listed in this table.
> - Capabilities can be customised per role by an administrator in the plugin's capability matrix settings page. The table above reflects only the defaults shipped with the plugin.
> - The portal access capability `must_hotel_booking_access_portal` is automatically granted to all five portal roles and does not appear in the matrix because it is not user-configurable per role in the matrix UI.

---

## Appendix A — Role reference

| Role slug | Display label | Primary purpose |
|---|---|---|
| `mhb_front_desk` | Front Desk Agent | Arrivals, departures, guest service, standard payments |
| `mhb_supervisor` | Front Office Supervisor | Oversight, cancellation approvals, refunds, room moves, exceptions |
| `mhb_housekeeping` | Housekeeping | Room readiness, cleaning status, assignments, maintenance issues |
| `mhb_finance` | Finance / Cashier | Payment posting, refunds, receipts, invoices, reconciliation |
| `mhb_ops_manager` | Operations Manager | Full operational access: approvals, all reports, inventory management, audit log |

---

## Appendix B — URL reference

| Location | Default URL |
|---|---|
| Portal login | `https://yoursite.com/staff-login/` |
| Portal dashboard | `https://yoursite.com/staff/` |
| Reservations | `https://yoursite.com/staff/reservations/` |
| Specific reservation | `https://yoursite.com/staff/reservations/?reservation_id=<ID>` |
| Calendar | `https://yoursite.com/staff/calendar/` |
| Front Desk | `https://yoursite.com/staff/front-desk/` |
| Front Desk — New Booking | `https://yoursite.com/staff/front-desk/?tab=new-booking` |
| Front Desk — Check-in Queue | `https://yoursite.com/staff/front-desk/?tab=checkin` |
| Front Desk — Check-out Queue | `https://yoursite.com/staff/front-desk/?tab=checkout` |
| Front Desk — Room Move | `https://yoursite.com/staff/front-desk/?tab=room-move` |
| Front Desk — Desk Log | `https://yoursite.com/staff/front-desk/?tab=log` |
| Guests | `https://yoursite.com/staff/guests/` |
| Specific guest | `https://yoursite.com/staff/guests/?guest_id=<ID>` |
| Payments | `https://yoursite.com/staff/payments/` |
| Specific payment | `https://yoursite.com/staff/payments/?reservation_id=<ID>` |
| Housekeeping | `https://yoursite.com/staff/housekeeping/` |
| Housekeeping — Room Board | `https://yoursite.com/staff/housekeeping/?tab=room-board` |
| Housekeeping — Assignments | `https://yoursite.com/staff/housekeeping/?tab=assignments` |
| Housekeeping — Maintenance | `https://yoursite.com/staff/housekeeping/?tab=maintenance` |
| Housekeeping — Shift Handoff | `https://yoursite.com/staff/housekeeping/?tab=handoff` |
| Rooms & Availability | `https://yoursite.com/staff/rooms-availability/` |
| Rooms & Availability — Blocks | `https://yoursite.com/staff/rooms-availability/?tab=blocks` |
| Rooms & Availability — Rules | `https://yoursite.com/staff/rooms-availability/?tab=rules` |
| Reports | `https://yoursite.com/staff/reports/` |

> Replace `yoursite.com` with your actual site domain. The `/staff/` and `/staff-login/` slugs are the plugin defaults and may be customised by your administrator.

---

*Generated from source code audit of MUST Hotel Booking plugin. Items marked "Unverified / needs confirmation" are based on code structure where the runtime rendering was not fully captured and should be verified against the live portal.*
