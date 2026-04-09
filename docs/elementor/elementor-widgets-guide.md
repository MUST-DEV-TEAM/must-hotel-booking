# Elementor Widgets Guide

The plugin registers three Elementor widgets, all available in the **MUST Hotel Booking** and **General** Elementor widget categories. They are defined in `src/Elementor/`.

---

## Widget 1: Booking Search

**Class:** `MustHotelBooking\Elementor\Booking_Search_Widget`
**Widget name (internal):** `must_hotel_booking_booking_search`
**Title shown in Elementor:** Booking Search
**Icon:** `eicon-search`

### Purpose

Renders a three-field search form — check-in date, check-out date, and number of guests. When submitted, the form sends the visitor either to the Booking page (calendar/date selection) or directly to the Select Accommodation page, passing the search parameters as URL query strings (`checkin`, `checkout`, `guests`).

### Controls (Settings panel)

| Control name | Type | Default | Description |
|---|---|---|---|
| `linked_rooms_list_widget_id` | SELECT | `''` (Not Connected) | Links this search form to a specific Rooms List widget on the same page. When linked, the search will filter the target Rooms List by the category that widget is configured to show. |
| `direct_to_accommodation` | SWITCHER (Yes/No) | No | When enabled, the form submits directly to the **Select Accommodation** page instead of the **Booking** (calendar) page. |

The guest number input enforces a minimum of 1 and a maximum set by the `max_booking_guests` plugin setting. All three fields are `required` in HTML.

### Dependencies

- **Flatpickr** — the date pickers rely on the `must-hotel-booking-flatpickr` script and style (enqueued automatically via `get_script_depends()` and `get_style_depends()`).
- **Booking page or Select Accommodation page** — at least one of these managed pages must exist. The target URL is resolved from the plugin's Managed Pages settings.

### How it fits into the booking flow

This is the entry point of the guest booking flow:

```
Booking Search Widget
    |
    +--> (direct_to_accommodation = No)  --> Booking Page (calendar view)
    |                                           |
    +--> (direct_to_accommodation = Yes) --> Select Accommodation Page
```

### The "Linked Rooms List" widget ID setting

When this widget is placed on the same page as a Rooms List widget, you can link them so that a search submission pre-filters the Rooms List by category.

**How it works technically:**

The Rooms List widget renders a `data-room-list-widget-id` attribute set to Elementor's internal widget ID (`$this->get_id()`). The Booking Search widget stores the target widget ID in `linked_rooms_list_widget_id`. On form submission, JavaScript reads this stored ID, finds the matching Rooms List widget on the page, and passes its category filter along with the search parameters.

**How the dropdown is populated:**

The SELECT control is populated by `get_rooms_list_widget_options_for_booking_search()`, which scans the current page's Elementor data for all Rooms List widget instances. Each option is labelled "Rooms List N (Category Name)" where N is a sequential index. The stored value is the Elementor widget ID string (e.g. `abc1234`).

**How to find the Elementor widget ID:**

1. Open the page in the Elementor editor.
2. Click the Rooms List widget you want to link.
3. In the URL bar or in Elementor's panel, look at the element ID. Alternatively, preview the page and inspect the rendered HTML. The Rooms List widget renders a `<div>` with `data-room-list-widget-id="<id>"`. Copy that value.
4. The Booking Search widget's SELECT dropdown automatically lists all Rooms List widgets found on the page. Simply choose the correct "Rooms List N" entry — you do not need to paste the ID manually.

**Warning:** The dropdown only shows Rooms List widgets that are on the **same page** as the Booking Search widget. If you add the Booking Search widget to a different page from the Rooms List, the SELECT dropdown will show "Not Connected" as the only option and no link is possible.

### Common mistakes that silently break the guest flow

- **`direct_to_accommodation` is disabled but the Booking page is not assigned in plugin settings.** The form submits to an empty or 404 URL. Guests see a broken page. Fix: assign the Booking page in wp-admin → MUST Hotel Booking → Settings → Pages.
- **`direct_to_accommodation` is enabled but the Select Accommodation page is not assigned.** Same result. Fix: assign the Select Accommodation page.
- **Linked Rooms List widget is on a different page.** The link silently does nothing; the category filter is ignored. The SELECT dropdown will show "Not Connected" rather than the widget on the other page.
- **The Rooms List widget is added after the Booking Search widget is configured.** The dropdown may have been empty when the Booking Search was saved, leaving `linked_rooms_list_widget_id` blank. Re-open the Booking Search widget and re-select the Rooms List.
- **max_booking_guests plugin setting is 0 or not set.** The code enforces `max(1, ...)` so the input accepts 1 as maximum. Guests cannot enter more than 1 even if the room supports more. Fix: set a sensible value in plugin settings.

---

## Widget 2: Rooms List

**Class:** `MustHotelBooking\Elementor\Rooms_List_Widget`
**Widget name (internal):** `must_hotel_booking_rooms_list`
**Title shown in Elementor:** Rooms List
**Icon:** `eicon-post-list`

### Purpose

Displays a visual card-based list of hotel rooms. Each card shows a primary photo, thumbnail gallery, room name, description, a "Book Now" link, and optionally an "Additional Details" link. The widget renders a lightbox-enabled image viewer on click.

### Controls (Settings panel)

| Control name | Type | Default | Description |
|---|---|---|---|
| `room_category` | SELECT | `all` | Filter rooms by category. Options are populated from `RoomCatalog::getCategories()`. Select "All Categories" to show every room. |
| `rooms_limit` | NUMBER | `20` | Maximum number of rooms to display. Allowed range: 1–200. |
| `show_category_heading` | SWITCHER | Yes | When enabled, displays the category name as a heading above the list (only shown when a specific category is selected, not "All"). |
| `empty_text` | TEXT | "No rooms found for the selected category." | Text shown when no rooms match the selected category. |

### Dependencies

- **Rooms data** — rooms must exist in the `must_rooms` database table. If no rooms are configured, the widget shows the empty state text.
- **Rooms page (optional)** — the "Additional Details" link is only rendered if a Rooms page is assigned in plugin settings. The link uses the room's slug as a query parameter: `?room=<slug>`.
- **Booking page** — the "Book Now" link points to the Booking page with `?room_id=<id>`. This page must be assigned in plugin settings.
- **Script:** `must-hotel-booking-rooms-list-widget` (handles lightbox and image interaction).

### How it fits into the booking flow

The Rooms List widget is a browsing layer before checkout. A guest can:
- Click **Book Now** to go directly to the Booking page for that specific room.
- Click **Additional Details** to see a detailed single-room page (routed via the Rooms page with `?room=<slug>`).
- Click any photo to open the lightbox gallery.

When linked to a Booking Search widget, the widget can receive a category filter from the search form submission.

### Common mistakes that silently break the guest flow

- **Booking page not assigned in plugin settings.** All "Book Now" links point to `home_url() + /booking`. If that page does not exist guests hit a 404. Fix: assign the Booking page in plugin settings.
- **Rooms page not assigned but "Additional Details" links are expected.** The details link is silently omitted — no error, just no link. Fix: assign the Rooms page in plugin settings.
- **Rooms have no images uploaded.** The widget shows "Image unavailable" in place of the primary photo and blank placeholder boxes for thumbnails. This is a valid state but may appear broken to guests. Fix: add images to rooms via wp-admin → MUST Hotel Booking → Rooms.
- **`room_category` is set to a category that has no rooms.** The widget shows the empty state text. No error. Check the category slug is correct.
- **`rooms_limit` set to a very low number on a page shared with a Booking Search.** When the search filters by category the widget may silently show fewer results than expected because the limit applies before category filtering. Set the limit high enough to cover all rooms.

---

## Widget 3: Rooms Text Grid

**Class:** `MustHotelBooking\Elementor\Rooms_Text_Grid_Widget`
**Widget name (internal):** `must-rooms-text-grid`
**Title shown in Elementor:** Rooms Text Grid
**Icon:** `eicon-editor-list-ul`

### Purpose

Displays a compact, text-only grid of room names. Each name can be linked to the single accommodation page or a custom URL. The widget highlights the currently viewed room with an `is-current` CSS class. It is designed as a sidebar navigation or quick-link grid, not as a full room browser.

### Controls (Settings panel)

#### Content section

| Control name | Type | Default | Description |
|---|---|---|---|
| `source_mode` | SELECT | `all_rooms` | Choose **All Rooms** to display every active room, or **Selected Rooms** to manually curate the list. |
| `selected_rooms` | REPEATER | `[]` | Visible only in Selected Rooms mode. Add one entry per room. Each entry has a **Room** dropdown and an optional **Custom Link Override** URL. |
| `items_limit` | NUMBER | `9` | Maximum items to display. Set to `0` for no limit. Allowed range: 0–200. |
| `link_behavior` | SELECT | `single_room_page` | Controls where each item links to. See options below. |
| `open_in_new_tab` | SWITCHER | No | Open links in a new browser tab. Only available when link_behavior is not "No Link". |

**Link Behavior options:**

| Option | Value | Behaviour |
|---|---|---|
| Single Accommodation Page | `single_room_page` | Every item links to the Rooms page with `?room=<slug>`. |
| Custom Override When Set | `custom_override_or_single_room_page` | Uses the custom URL from the repeater item if set; otherwise falls back to the single accommodation page. |
| No Link | `no_link` | Items render as `<span>` instead of `<a>`. No links at all. |

#### Layout section (responsive)

| Control name | Type | Default | Description |
|---|---|---|---|
| `columns` | SELECT (responsive) | 3 desktop / 2 tablet / 1 mobile | Number of grid columns. Options: 1–6. |
| `column_gap` | SLIDER | 24px | Horizontal gap between columns. |
| `row_gap` | SLIDER | 16px | Vertical gap between rows. |
| `text_align` | CHOOSE | Left | Text alignment within each cell: Left, Center, or Right. |

#### Style section (Items tab)

| Control name | Type | Description |
|---|---|---|
| `item_typography` | GROUP (Typography) | Font settings for all item labels. |
| `item_text_shadow` | GROUP (Text Shadow) | Shadow for item labels. |
| `item_text_color` | COLOR | Normal state text colour. |
| `item_background_color` | COLOR | Normal state background colour. |
| `item_hover_text_color` | COLOR | Hover state text colour. |
| `item_hover_background_color` | COLOR | Hover state background colour. |
| `item_current_text_color` | COLOR | Colour for the currently active room item (`.is-current`). |
| `item_current_background_color` | COLOR | Background for the currently active room item. |
| `item_border` | GROUP (Border) | Border applied to each item inner element. |
| `item_border_radius` | SLIDER | Border radius (px) for item corners. |
| `item_padding` | DIMENSIONS | Padding inside each item. |
| `transition_duration` | NUMBER | 220ms | Hover transition speed in milliseconds. |

### Dependencies

- **Room data** — rooms must exist in `must_rooms`. If none, the widget shows "No accommodations found."
- **Rooms page** — required for `single_room_page` and `custom_override_or_single_room_page` link behaviours. If the Rooms page is not assigned, links for those modes will resolve to an empty string and items will render without a link (silently falling back to no-link behaviour).
- No JavaScript dependency (the widget is pure PHP rendering with CSS styling applied via Elementor selectors).

### How it fits into the booking flow

The Rooms Text Grid is a navigation aid, typically placed in a sidebar or hero section of a room listing layout. It does not initiate booking directly. Clicking a room name takes the visitor to the single room detail page (`?room=<slug>` on the Rooms page), where they can then click through to the booking flow.

It also serves as a "you are here" indicator: the widget checks the current URL's `room` query parameter and adds the `is-current` class to the matching item.

### Common mistakes that silently break the guest flow

- **Rooms page not assigned but `link_behavior` is `single_room_page`.** Items render without links — no error, no warning, just unlinked text. Fix: assign the Rooms page in plugin settings.
- **`source_mode` is `selected_rooms` but the repeater is empty.** The widget shows "No accommodations found." Fix: add at least one room to the repeater.
- **Room selected in the repeater has been deleted from the database.** The widget silently skips items with an empty or missing `name` field. The deleted room simply does not appear.
- **`link_behavior` is `custom_override_or_single_room_page` but no custom links are set and the Rooms page is also unassigned.** Items render as unlinked text with no error.
- **`items_limit` is set to `0` on a site with many rooms.** All rooms are rendered at once. This can produce a very long list on the page if rooms are numerous.

---

## General Integration Notes

### Required plugin pages

For the full booking flow to work, the following plugin-managed pages must be assigned in wp-admin under MUST Hotel Booking settings:

| Page | Slug | Required for |
|---|---|---|
| Booking | `/booking` | Date selection step; target of Booking Search (default mode) and Rooms List "Book Now" |
| Select Accommodation | `/booking-accommodation` | Room selection step; target of Booking Search (direct mode) |
| Checkout | `/checkout` | Guest information step |
| Booking Confirmation | `/booking-confirmation` | Payment and confirmation step |
| Rooms | `/rooms` | Single room pages (`?room=<slug>`); used by Rooms List "Additional Details" and Rooms Text Grid |

The Booking, Select Accommodation, Checkout, and Booking Confirmation pages are auto-created on plugin activation if not already assigned. The Rooms page is optional and not auto-created.

### Testing a widget in Elementor editor vs. live site

The Booking Search widget's "Linked Rooms List" dropdown scans the page's Elementor data at the time the Booking Search widget panel opens. If you are editing in the Elementor editor and a linked Rooms List widget was added in the same session but not yet saved, it may not appear in the dropdown. Save the page, reload the editor, and re-open the Booking Search widget panel.
