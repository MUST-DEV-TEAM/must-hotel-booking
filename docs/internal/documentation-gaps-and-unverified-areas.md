# Documentation Gaps and Unverified Areas

Internal document. Each item describes an area of the codebase that is ambiguous, partially implemented, or where the code does not conclusively answer a documentation question. Items are cross-referenced to the affected docs.

---

## 1. Dual Room Data Model: `must_rooms` vs. `mhb_room_types` + `mhb_rooms`

**What was found in code:**

Three tables coexist: `must_rooms` (the authoritative sellable room table), `mhb_room_types` (a mirror derived from `must_rooms` IDs), and `mhb_rooms` (physical inventory units linked to `mhb_room_types`). Repository-level comments state explicitly:

- `RoomRepository.php`: "`must_rooms` is the authoritative table for reservations and admin flows."
- `InventoryRepository.php`: "`mhb_room_types` mirrors authoritative `must_rooms` rows for internal inventory ... UI and business rules should treat `must_rooms` as the source of truth."
- `install-tables.php`: `seed_inventory_model_from_legacy_rooms()` copies `must_rooms` records into `mhb_room_types`/`mhb_rooms` on demand.

**Why it is unclear:**

The comment in `install-tables.php` calls `must_rooms` the "legacy" table in one function name (`seed_inventory_model_from_legacy_rooms`) but the other repository comments say it is authoritative. It is not clear whether:
- `must_rooms` is the permanent source of truth with `mhb_room_types`/`mhb_rooms` as a derived index, or
- `must_rooms` is a legacy table that is in the process of being replaced by `mhb_room_types`/`mhb_rooms`.

**Which docs are affected:** `docs/developer/roles-capabilities.md` (indirect), any future database schema doc, any admin guide that explains room management.

**Recommended clarification:** Confirm the intended long-term authority table. If `must_rooms` is the permanent source of truth, remove the word "legacy" from the sync function name. If migration away from `must_rooms` is planned, document the migration path and timeline.

---

## 2. Legacy Pricing (`must_pricing`) vs. Modern Rate Plans (`mhb_rate_plans`)

**What was found in code:**

Both `must_pricing` (a legacy date-range price override table) and `mhb_rate_plans` / `mhb_room_type_rate_plans` / `mhb_rate_plan_prices` exist in the schema. `PricingEngine` references `must_pricing` directly at line 322 (`$wpdb->prefix . 'must_pricing'`). It also calls `RatePlanEngine::getRoomRatePlan()` and `RatePlanEngine::getRatePlanPrice()` for rate-plan-based pricing. A `rate-plans-page.php` admin file exists in `src/Admin/`.

**Why it is unclear:**

- Whether `must_pricing` is still the primary pricing mechanism or whether `mhb_rate_plans` has superseded it is not documented.
- The relationship between pricing rules in `must_pricing` and rate plan prices in `mhb_rate_plan_prices` is not explained — it is possible they run simultaneously and one takes priority over the other, but this is not stated in comments.
- The `mhb_seasons` and `mhb_seasonal_prices` tables exist in the schema but no admin UI file for seasons was found (see item 3 below). It is unclear whether seasonal pricing is fully functional.

**Which docs are affected:** Any pricing or rate management admin guide; indirectly affects the guest booking guide (what price the guest sees).

**Recommended clarification:** Document which pricing mechanism has precedence when both are configured. Confirm whether `must_pricing` is deprecated and being phased out.

---

## 3. Seasons Admin UI — Does `mhb_seasons` Have an Admin Interface?

**What was found in code:**

The `mhb_seasons` and `mhb_seasonal_prices` tables are created by `install_tables()`. `PricingEngine` references `RatePlanEngine` which reads `mhb_rate_plan_prices`. However, no admin PHP file dedicated to seasons management was found in `src/Admin/`. The `rate-plans-page.php` file manages rate plans and cancellation policies but contains no references to seasons.

**Why it is unclear:**

It is not known whether:
- A seasons admin UI exists but was not found (e.g., in a file not yet searched, or in a Vue/React admin component loaded separately).
- The seasons feature is planned but not yet implemented (tables exist as scaffolding).
- Seasons are managed indirectly through another interface.

**Which docs are affected:** Any admin guide for pricing; `docs/internal/documentation-gaps-and-unverified-areas.md`.

**Recommended clarification:** Confirm whether the seasons admin UI exists and where. If it does not exist, mark the feature as incomplete or not yet released.

---

## 4. Deposit Remainder Collection — How Is the Balance After a Deposit Collected?

**What was found in code:**

The plugin settings page (`SettingsPage.php`) includes `deposit_required`, `deposit_type` (percentage or fixed), and `deposit_value` fields. These settings are read and stored. However, no code was found in the guest-facing payment flow (`PaymentEngine.php`, `confirmation-page.php`, `checkout.php`) that references `deposit_required`, calculates a deposit amount from the setting, or creates a partial payment intent for just the deposit portion.

**Why it is unclear:**

It is not known whether:
- The deposit settings are used by the Stripe checkout to collect only a deposit amount, with the remainder collected at the hotel or via a later charge.
- The deposit settings only affect staff-side workflows (e.g., marking a payment as partial deposit in the portal).
- The guest-facing Stripe checkout always charges the full total regardless of the deposit setting.

The guest booking guide currently states that the deposit remainder is "contact the hotel" but this may be incorrect if the system handles it automatically.

**Which docs are affected:** `docs/guest/guest-booking-guide.md` (deposit section marked unverified).

**Recommended clarification:** Trace the deposit settings through the full checkout flow (from `PricingEngine::buildCheckoutRoomItems` through `PaymentEngine::getStripeCheckoutUrl`) to confirm whether the deposit amount is passed to Stripe or ignored.

---

## 5. Guest-Facing Cancellation Flow — Is There a Self-Service Cancellation Page?

**What was found in code:**

Guest cancellation is handled in `src/Frontend/confirmation-page.php` via `handle_confirmation_cancellation_request()`. The function processes a GET request with `?must_action=cancel_reservation&reservation_id=N&booking_id=X&cancel_token=Y`. This URL is generated in `EmailEngine` and embedded in the confirmation email. The booking confirmation page template (`booking-confirmation.php`) is where this action is processed on load.

There is no dedicated `/cancel-booking` managed page. Cancellation only works when the guest visits the booking confirmation page URL with the specific query parameters from the email link.

**Why it is unclear:**

- The confirmation page doubles as the cancellation endpoint. If a guest visits the confirmation page without the cancellation parameters, they see the booking review/payment form — not a cancellation interface.
- There is no separate "manage my booking" page.
- It is not documented whether the confirmation page URL (with booking parameters) is accessible by guests at any time, or only immediately after checkout.

**Which docs are affected:** `docs/guest/guest-booking-guide.md` (cancellation section).

**Recommended clarification:** Confirm that the only supported guest cancellation path is the email link. If a direct-access cancellation page is planned, document its URL and flow.

---

## 6. Cancellation Policies (`mhb_cancellation_policies`) — Is There an Admin UI?

**What was found in code:**

`mhb_cancellation_policies` is created by `install_tables()`. `CancellationPolicyRepository` reads this table. The `rate-plans-page.php` admin file calls `get_cancellation_policy_repository_instance()->getPolicies()` and renders a dropdown to assign a policy to a rate plan. This confirms cancellation policies can be read and assigned to rate plans.

However, no admin form was found for **creating or editing** cancellation policies (no policy name input, no `hours_before_checkin` field, no `penalty_percent` field in any admin PHP file outside the rate plans page dropdown).

**Why it is unclear:**

Cancellation policies may be:
- Created only via direct database insert (no admin UI).
- Managed in an admin UI that was not found in the file search (e.g., a separate page file, or embedded in the settings page under a section not yet searched).
- Planned but not yet implemented.

**Which docs are affected:** Any admin guide for rate plans or cancellation policies; affects guest-facing cancellation documentation (penalty amounts cannot be set without this).

**Recommended clarification:** Search remaining admin PHP files for cancellation policy CRUD forms. If no UI exists, document that policies must be created directly in the database.

---

## 7. Quick Booking — Overlap Between Admin Quick-Booking and Portal Front-Desk New Booking

**What was found in code:**

`src/Admin/quick-booking.php` contains a full quick-booking form and action handler used in the WP Admin area. `PortalRegistry` shows that the `/staff/quick-booking` route has been deprecated and redirects to `/staff/front-desk?tab=new-booking`. The portal's `front-desk-new-booking.php` template also provides a new-booking interface.

`get_admin_quick_booking_source_options()` delegates to `get_calendar_booking_source_options()` if available, which is defined in `src/Admin/calendar.php` and includes source options: website, phone, walk-in, booking.com, Airbnb, agency.

**Why it is unclear:**

- The relationship between the WP Admin quick-booking form and the staff portal Front Desk new-booking tab is not documented. They appear to be parallel implementations of the same feature.
- It is not documented whether the Admin quick-booking form is still supported and linked from the WP Admin menu, or whether it has been fully replaced by the portal Front Desk module.
- The deprecated route redirect suggests intent to replace the old quick-booking, but the admin-side file still exists and presumably still works.

**Which docs are affected:** Any admin or staff guide covering reservation creation.

**Recommended clarification:** Confirm whether `src/Admin/quick-booking.php` is still actively used or is dead code pending removal. Document which interface (WP Admin quick-booking vs. portal Front Desk) is the preferred path for staff to create new reservations.

---

## 8. Email Layout "Custom" Type — Is There a UI for Editing Custom HTML?

**What was found in code:**

`EmailLayoutEngine` defines four layout types: `classic`, `luxury`, `compact`, `custom`. For the `custom` type, `EmailLayoutEngine::getStarterCustomLayoutHtml()` provides a starter HTML template with placeholder tokens. `MustBookingConfig::get_custom_email_layout_html()` reads a stored setting `custom_email_layout_html`. `EmailAdminActions` saves `custom_email_layout_html` from a POST field. `EmailAdminDataProvider` exposes `custom_email_layout_html` to the admin view. The `emails.php` admin page references a "Layout Placeholder Reference" section for the custom layout.

**Why it is unclear:**

Based on the above, a textarea or HTML editor field for `custom_email_layout_html` exists in the WP Admin Emails settings page. However, it is not clear:
- Whether the input is a plain `<textarea>`, a code editor widget, or uses the WP editor (`wp_editor`).
- Whether there is any validation or sanitization of the custom HTML beyond storing the raw string.
- Whether the starter HTML from `getStarterCustomLayoutHtml()` is pre-populated into the field on first use or only shown as reference documentation.

**Which docs are affected:** Any admin guide for email customization.

**Recommended clarification:** Read the full `emails.php` admin rendering code to confirm the exact input type and any pre-population logic for the custom layout HTML field.

---

## 9. OTA / Channel Source Tracking — Is This Actual Integration or Just Labeling?

**What was found in code:**

The `booking_source` column in `must_reservations` stores a source string. `get_calendar_booking_source_options()` (in `src/Admin/calendar.php`) returns these options: `website`, `phone`, `walk_in`, `booking_com`, `airbnb`, `agency`. The same options appear in the quick-booking admin form. `ReservationAdminActions` and `CalendarDataProvider` read and display `booking_source`. No code was found that connects to any OTA API, webhook, or channel manager endpoint.

**Why it is unclear:**

"Booking.com" and "Airbnb" appearing as source labels strongly implies OTA integration, but no API connection code, XML feed, iCal import, or channel manager bridge was found anywhere in the plugin source. The labels are used purely as human-readable identifiers for reservations created manually by staff (to record where the booking came from).

**Which docs are affected:** Any admin or staff guide covering reservation sources; no guest-facing docs affected.

**Recommended clarification:** Confirm explicitly that `booking_com` and `airbnb` source values are labels only, with no automated channel manager integration. If integration is planned, document the intended approach.

---

## 10. Single Room Page Routing — How Does a Guest Reach a Single Room Detail Page?

**What was found in code:**

`src/Frontend/single-room-page.php` resolves a room by `?room=<slug>` or `?room_id=<id>` query parameters on the current request. `frontend/templates/single-room.php` is the template rendered in that case. `ManagedPages` shows a `page_rooms_id` setting with `auto_create: false` and `required: false`, meaning the Rooms page is optional and not auto-created.

The Rooms List widget constructs the details link as `add_query_arg(['room' => $room_slug], $rooms_page_url)`. The Rooms Text Grid widget links to the same pattern via `get_rooms_text_grid_item_link_url()`. The single-room template is rendered when `is_single_room_request()` returns true, which is when either query parameter is present on the Rooms page.

**Why it is unclear:**

- The `page_rooms_id` setting implies the hotel must create and assign a WordPress page for rooms. That page serves as both the rooms listing page and the single-room detail page (differentiated by the presence of `?room=` in the URL). This dual role is not documented.
- It is not clear whether a separate single-room page can be created or whether the `?room=` query parameter must always be on the same page that the Rooms List widget is placed on.
- It is not documented how the Rooms page template is triggered — whether a specific Elementor template is required, or whether the plugin's PHP template fires automatically when `?room=` is detected.

**Which docs are affected:** `docs/elementor/elementor-widgets-guide.md` (Rooms List widget, "Additional Details" link); `docs/guest/guest-booking-guide.md` (browsing rooms step).

**Recommended clarification:** Read `src/Frontend/single-room-page.php` fully and the ManagedPages rendering hooks to document exactly how the page template is triggered and whether the Rooms page requires any specific Elementor template setup.
