<?php

namespace MustHotelBooking\Frontend;

use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Core\ManagedPages;
use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;
use MustHotelBooking\Core\RoomViewBuilder;
use MustHotelBooking\Engine\BookingValidationEngine;
use MustHotelBooking\Engine\PricingEngine;
use MustHotelBooking\Engine\ReservationEngine;

/**
 * Get plugin settings option name.
 */
function get_settings_option_name(): string
{
    if (\class_exists(MustBookingConfig::class)) {
        return MustBookingConfig::get_option_name();
    }

    return 'must_hotel_booking_settings';
}

/**
 * Get plugin settings as an array.
 *
 * @return array<string, mixed>
 */
function get_plugin_settings(): array
{
    if (\class_exists(MustBookingConfig::class)) {
        return MustBookingConfig::get_all_settings();
    }

    return [];
}

/**
 * Persist plugin settings.
 *
 * @param array<string, mixed> $settings Settings to persist.
 */
function update_plugin_settings(array $settings): void
{
    if (\class_exists(MustBookingConfig::class)) {
        MustBookingConfig::set_all_settings($settings);
    }
}

/**
 * Get the configured booking guest cap.
 */
function get_max_booking_guests_limit(): int
{
    return BookingRules::getMaxBookingGuestsLimit();
}

/**
 * Get the max number of rooms supported by the multi-room booking flow.
 */
function get_max_booking_rooms_limit(): int
{
    return BookingRules::getMaxBookingRoomsLimit();
}

/**
 * Normalize requested room count. Zero means auto.
 *
 * @param mixed $value
 */
function normalize_booking_room_count($value): int
{
    return BookingRules::normalizeRoomCount($value);
}

/**
 * Load the maximum capacity available for each room category.
 *
 * @return array<string, int>
 */
function get_booking_room_category_capacity_map(): array
{
    return BookingRules::getRoomCategoryCapacityMap();
}

/**
 * Get the largest single-room capacity for a booking context.
 *
 * @param array<string, mixed>|null $fixed_room
 */
function get_booking_context_max_room_capacity(string $accommodation_type = 'standard-rooms', ?array $fixed_room = null): int
{
    return BookingRules::getContextMaxRoomCapacity($accommodation_type, $fixed_room);
}

/**
 * Resolve the room count that should be used for a booking party.
 *
 * @param array<string, mixed>|null $fixed_room
 */
function resolve_booking_room_count(int $guests, int $room_count = 0, string $accommodation_type = 'standard-rooms', ?array $fixed_room = null): int
{
    return BookingRules::resolveRoomCount($guests, $room_count, $accommodation_type, $fixed_room);
}

/**
 * Get the max guest count available for a booking context and room-count selection.
 *
 * @param array<string, mixed>|null $fixed_room
 */
function get_booking_context_guest_limit(string $accommodation_type = 'standard-rooms', int $room_count = 0, ?array $fixed_room = null): int
{
    return BookingRules::getContextGuestLimit($accommodation_type, $room_count, $fixed_room);
}

/**
 * Format a room-count label for booking summaries and selectors.
 */
function format_booking_room_count_label(int $room_count): string
{
    return BookingRules::formatRoomCountLabel($room_count);
}

/**
 * Get managed frontend pages configuration.
 *
 * @return array<string, array<string, string>>
 */
function get_frontend_pages_config(): array
{
    return ManagedPages::getConfig();
}

/**
 * Create plugin pages and store their IDs in plugin settings.
 */
function install_frontend_pages(): void
{
    ManagedPages::install();
}

/**
 * Load plugin frontend templates for managed pages.
 *
 * @param string $template Current resolved theme template.
 */
function maybe_load_frontend_template(string $template): string
{
    if (\is_admin()) {
        return $template;
    }

    $settings = get_plugin_settings();

    foreach (get_frontend_pages_config() as $setting_key => $page_config) {
        $page_id = isset($settings[$setting_key]) ? (int) $settings[$setting_key] : 0;

        if ($page_id <= 0 || !\is_page($page_id)) {
            continue;
        }

        $plugin_template = MUST_HOTEL_BOOKING_PATH . $page_config['template'];

        if (\file_exists($plugin_template)) {
            return $plugin_template;
        }
    }

    return $template;
}

/**
 * Resolve managed frontend page URL.
 */
function get_frontend_page_url(string $setting_key, string $fallback_path): string
{
    return ManagedPages::getPageUrl($setting_key, $fallback_path);
}

/**
 * Get booking page URL.
 */
function get_booking_page_url(): string
{
    return ManagedPages::getBookingPageUrl();
}

/**
 * Resolve a requested fixed room ID from booking entry URLs.
 *
 * @param array<string, mixed> $source
 */
function get_requested_booking_room_id(array $source): int
{
    if (!isset($source['room_id'])) {
        return 0;
    }

    return \absint(\wp_unslash($source['room_id']));
}

/**
 * Load the requested fixed room row for booking page mode.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>|null
 */
function get_requested_booking_room_data(array $source): ?array
{
    return RoomData::getRoom(get_requested_booking_room_id($source));
}

/**
 * Apply fixed-room context rules to a parsed booking request.
 *
 * @param array<string, mixed> $context
 * @param array<string, mixed> $room
 * @return array<string, mixed>
 */
function apply_fixed_room_booking_context(array $context, array $room): array
{
    return BookingValidationEngine::applyFixedRoomContext($context, $room);
}

/**
 * Build booking page URL with a booking context.
 *
 * @param array<string, mixed> $context
 */
function get_booking_context_url(array $context, int $room_id = 0): string
{
    $normalized_context = normalize_booking_selection_context($context);
    $args = [];

    if ((string) ($normalized_context['checkin'] ?? '') !== '') {
        $args['checkin'] = (string) $normalized_context['checkin'];
    }

    if ((string) ($normalized_context['checkout'] ?? '') !== '') {
        $args['checkout'] = (string) $normalized_context['checkout'];
    }

    $args['guests'] = (int) ($normalized_context['guests'] ?? 1);
    $args['room_count'] = BookingRules::normalizeRoomCount($normalized_context['room_count'] ?? 0);
    $args['accommodation_type'] = (string) ($normalized_context['accommodation_type'] ?? 'standard-rooms');

    if ($room_id > 0) {
        $args['room_id'] = $room_id;
    }

    return \add_query_arg($args, get_booking_page_url());
}

/**
 * Build checkout page URL with a booking context.
 *
 * @param array<string, mixed> $context
 */
function get_checkout_context_url(array $context, int $room_id = 0): string
{
    $normalized_context = normalize_booking_selection_context($context);
    $args = [];

    if ((string) ($normalized_context['checkin'] ?? '') !== '') {
        $args['checkin'] = (string) $normalized_context['checkin'];
    }

    if ((string) ($normalized_context['checkout'] ?? '') !== '') {
        $args['checkout'] = (string) $normalized_context['checkout'];
    }

    $args['guests'] = (int) ($normalized_context['guests'] ?? 1);
    $args['room_count'] = BookingRules::normalizeRoomCount($normalized_context['room_count'] ?? 0);
    $args['accommodation_type'] = (string) ($normalized_context['accommodation_type'] ?? 'standard-rooms');

    if ($room_id > 0) {
        $args['room_id'] = $room_id;
    }

    return \add_query_arg($args, get_checkout_page_url());
}

/**
 * Get checkout page URL.
 */
function get_checkout_page_url(): string
{
    return ManagedPages::getCheckoutPageUrl();
}

/**
 * Get booking accommodation step URL.
 */
function get_booking_accommodation_page_url(): string
{
    return ManagedPages::getBookingAccommodationPageUrl();
}

/**
 * Get booking confirmation page URL.
 */
function get_booking_confirmation_page_url(): string
{
    return ManagedPages::getBookingConfirmationPageUrl();
}

/**
 * Parse booking request context.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function parse_booking_request_context(array $source, bool $require_dates = false): array
{
    return BookingValidationEngine::parseRequestContext($source, $require_dates);
}

/**
 * Handle room selection and lock creation from booking page.
 */
function maybe_process_booking_room_selection(): string
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return '';
    }

    $result = ReservationEngine::handleBookingRoomSelectionRequest(\is_array($_POST) ? $_POST : []);

    if (empty($result['handled'])) {
        return '';
    }

    if (empty($result['success'])) {
        return isset($result['message']) ? (string) $result['message'] : '';
    }

    $redirect_url = isset($result['redirect_url']) ? (string) $result['redirect_url'] : '';

    if ($redirect_url !== '') {
        \wp_safe_redirect($redirect_url);
        exit;
    }

    return '';
}

/**
 * Build enriched room card data for booking step 2.
 *
 * @param array<string, mixed> $room
 * @return array<string, mixed>|null
 */
function get_booking_results_room_view_data(array $room): ?array
{
    return RoomViewBuilder::buildBookingResultsRoomViewData($room);
}

/**
 * Format the visible date range summary for booking step 2.
 */
function format_booking_results_date_range(string $checkin, string $checkout): string
{
    if ($checkin === '' || $checkout === '') {
        return \__('Select dates', 'must-hotel-booking');
    }

    $checkin_timestamp = \strtotime($checkin . ' 00:00:00');
    $checkout_timestamp = \strtotime($checkout . ' 00:00:00');

    if ($checkin_timestamp === false || $checkout_timestamp === false) {
        return \__('Select dates', 'must-hotel-booking');
    }

    return \sprintf(
        /* translators: 1: check-in day, 2: check-in month, 3: check-out day, 4: check-out month, 5: year. */
        \__('%1$s %2$s - %3$s %4$s %5$s', 'must-hotel-booking'),
        \wp_date('d', $checkin_timestamp),
        \wp_date('F', $checkin_timestamp),
        \wp_date('d', $checkout_timestamp),
        \wp_date('F', $checkout_timestamp),
        \wp_date('Y', $checkout_timestamp)
    );
}

/**
 * Format the visible selection summary for booking step 2.
 */
function format_booking_results_selection_summary(string $accommodation_type, int $guests, int $room_count = 0): string
{
    $category_label = RoomCatalog::getBookingCategoryLabel($accommodation_type);
    $resolved_room_count = BookingRules::resolveRoomCount($guests, $room_count, $accommodation_type);
    $room_count_label = BookingRules::formatRoomCountLabel($resolved_room_count);

    return \sprintf(
        /* translators: 1: accommodation type label, 2: guests count, 3: room count label. */
        \__('%1$s / %2$d Guests / %3$s', 'must-hotel-booking'),
        $category_label,
        \max(1, $guests),
        $room_count_label
    );
}

/**
 * Build view data for the booking page template.
 *
 * @return array<string, mixed>
 */
function get_booking_page_view_data(): array
{
    $messages = [];
    $selection_error = maybe_process_booking_room_selection();

    if ($selection_error !== '') {
        $messages[] = $selection_error;
    }

    /** @var array<string, mixed> $raw_get */
    $raw_get = \is_array($_GET) ? $_GET : [];
    $requested_room_id = get_requested_booking_room_id($raw_get);
    $fixed_room = get_requested_booking_room_data($raw_get);
    $fixed_room_mode = \is_array($fixed_room);
    $context = BookingValidationEngine::parseRequestContext($raw_get, false);

    if ($requested_room_id > 0 && !$fixed_room_mode) {
        $messages[] = \__('The selected room could not be found.', 'must-hotel-booking');
    }

    if ($fixed_room_mode) {
        $context = apply_fixed_room_booking_context($context, $fixed_room);
    }

    $has_search = (string) $context['checkin'] !== '' || (string) $context['checkout'] !== '';

    if (!$context['is_valid'] && $has_search) {
        foreach ((array) $context['errors'] as $error_message) {
            $messages[] = (string) $error_message;
        }
    }

    $rooms = [];
    $fixed_room_view = null;
    $max_booking_guests = get_max_booking_guests_limit();
    $max_booking_rooms = get_max_booking_rooms_limit();

    if ($fixed_room_mode) {
        $room_max_guests = isset($fixed_room['max_guests']) ? \max(1, (int) $fixed_room['max_guests']) : 1;
        $max_booking_guests = \max(1, \min($max_booking_guests, $room_max_guests));
        $max_booking_rooms = 1;

        $room_for_view = $fixed_room;

        if (
            !empty($context['is_valid']) &&
            (string) ($context['checkin'] ?? '') !== '' &&
            (string) ($context['checkout'] ?? '') !== ''
        ) {
            $pricing = PricingEngine::calculateTotal(
                (int) ($fixed_room['id'] ?? 0),
                (string) $context['checkin'],
                (string) $context['checkout'],
                (int) $context['guests']
            );

            if (\is_array($pricing) && !empty($pricing['success'])) {
                if (isset($pricing['total_price'])) {
                    $room_for_view['price_preview_total'] = (float) $pricing['total_price'];
                    $room_for_view['dynamic_total_price'] = (float) $pricing['total_price'];
                }

                if (isset($pricing['room_subtotal'])) {
                    $room_for_view['dynamic_room_subtotal'] = (float) $pricing['room_subtotal'];
                }

                if (isset($pricing['nights'])) {
                    $room_for_view['dynamic_nights'] = (int) $pricing['nights'];
                }
            }
        }

        $fixed_room_view = \function_exists(__NAMESPACE__ . '\get_booking_results_room_view_data')
            ? get_booking_results_room_view_data($room_for_view)
            : $room_for_view;
    }

    return [
        'booking_url' => get_booking_context_url($context, $fixed_room_mode ? (int) ($fixed_room['id'] ?? 0) : 0),
        'accommodation_url' => $fixed_room_mode
            ? get_checkout_context_url($context, (int) ($fixed_room['id'] ?? 0))
            : get_booking_accommodation_page_url(),
        'checkout_url' => get_checkout_context_url($context, $fixed_room_mode ? (int) ($fixed_room['id'] ?? 0) : 0),
        'checkin' => (string) $context['checkin'],
        'checkout' => (string) $context['checkout'],
        'guests' => (int) $context['guests'],
        'room_count' => $fixed_room_mode ? 1 : (int) ($context['room_count'] ?? 0),
        'resolved_room_count' => $fixed_room_mode
            ? 1
            : resolve_booking_room_count(
                (int) ($context['guests'] ?? 1),
                (int) ($context['room_count'] ?? 0),
                (string) ($context['accommodation_type'] ?? 'standard-rooms')
            ),
        'max_booking_guests' => $max_booking_guests,
        'max_booking_rooms' => $max_booking_rooms,
        'accommodation_type' => (string) $context['accommodation_type'],
        'booking_categories' => RoomCatalog::getBookingCategories(),
        'has_search' => $has_search,
        'is_valid' => (bool) $context['is_valid'],
        'initial_step' => 1,
        'fixed_room_mode' => $fixed_room_mode,
        'fixed_room_id' => $fixed_room_mode ? (int) ($fixed_room['id'] ?? 0) : 0,
        'fixed_room' => \is_array($fixed_room_view) ? $fixed_room_view : null,
        'messages' => $messages,
        'rooms' => $rooms,
    ];
}

/**
 * Check if current frontend request is the managed booking page.
 */
function is_frontend_booking_page(): bool
{
    return ManagedPages::isCurrentPage('page_booking_id', 'booking');
}

/**
 * Ensure managed frontend pages exist after plugin updates.
 */
function maybe_sync_frontend_pages(): void
{
    ManagedPages::sync();
}

/**
 * Enqueue booking page assets for dynamic availability updates.
 */
function enqueue_booking_page_assets(): void
{
    if (!is_frontend_booking_page()) {
        return;
    }

    /** @var array<string, mixed> $raw_get */
    $raw_get = \is_array($_GET) ? $_GET : [];
    $fixed_room = get_requested_booking_room_data($raw_get);
    $fixed_room_mode = \is_array($fixed_room);
    $context = BookingValidationEngine::parseRequestContext($raw_get, false);

    if ($fixed_room_mode) {
        $context = apply_fixed_room_booking_context($context, $fixed_room);
    }

    $initial_checkin = (string) ($context['checkin'] ?? '');
    $initial_checkout = (string) ($context['checkout'] ?? '');
    $initial_guests = (int) ($context['guests'] ?? 1);
    $initial_room_count = $fixed_room_mode ? 1 : (int) ($context['room_count'] ?? 0);
    $initial_accommodation_type = (string) ($context['accommodation_type'] ?? 'standard-rooms');
    $max_booking_guests = get_max_booking_guests_limit();
    $max_booking_rooms = $fixed_room_mode ? 1 : get_max_booking_rooms_limit();
    $category_capacities = get_booking_room_category_capacity_map();

    if ($fixed_room_mode) {
        $room_max_guests = isset($fixed_room['max_guests']) ? \max(1, (int) $fixed_room['max_guests']) : 1;
        $max_booking_guests = \max(1, \min($max_booking_guests, $room_max_guests));
    }

    $fixed_room_id = $fixed_room_mode ? (int) ($fixed_room['id'] ?? 0) : 0;
    $fixed_room_name = $fixed_room_mode ? (string) ($fixed_room['name'] ?? '') : '';
    $fixed_room_category_label = $fixed_room_mode
        ? RoomCatalog::getCategoryLabel((string) ($fixed_room['category'] ?? 'standard-rooms'))
        : '';
    $arrow_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowRight.svg';
    $bed_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/bed.svg';

    \wp_enqueue_style(
        'must-hotel-booking-flatpickr',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_script(
        'must-hotel-booking-flatpickr',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js',
        [],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_enqueue_style(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-page.css',
        [],
        MUST_HOTEL_BOOKING_VERSION
    );

    \wp_enqueue_script(
        'must-hotel-booking-booking-page',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-page.js',
        ['must-hotel-booking-flatpickr'],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_localize_script(
        'must-hotel-booking-booking-page',
        'mustHotelBookingBookingPage',
        [
            'ajaxUrl' => \admin_url('admin-ajax.php'),
            'homeUrl' => \home_url('/'),
            'bookingUrl' => get_booking_context_url($context, $fixed_room_id),
            'accommodationUrl' => $fixed_room_mode
                ? get_checkout_context_url($context, $fixed_room_id)
                : get_booking_accommodation_page_url(),
            'checkoutUrl' => get_checkout_context_url($context, $fixed_room_id),
            'availabilityAction' => 'must_check_availability',
            'disabledDatesAction' => 'must_get_disabled_dates',
            'selectRoomNonce' => \wp_create_nonce('must_booking_select_room'),
            'windowDays' => 180,
            'today' => \current_time('Y-m-d'),
            'defaultAccommodationType' => $initial_accommodation_type,
            'maxGuests' => $max_booking_guests,
            'maxRooms' => $max_booking_rooms,
            'categoryCapacities' => $category_capacities,
            'pageMode' => 'calendar',
            'fixedRoomMode' => $fixed_room_mode,
            'fixedRoomId' => $fixed_room_id,
            'fixedRoom' => [
                'id' => $fixed_room_id,
                'name' => $fixed_room_name,
                'categoryLabel' => $fixed_room_category_label,
                'maxGuests' => $fixed_room_mode ? (int) ($fixed_room['max_guests'] ?? 1) : 0,
            ],
            'initialStep' => 1,
            'arrowIconUrl' => $arrow_icon_url,
            'bedIconUrl' => $bed_icon_url,
            'currencySymbol' => get_frontend_currency_symbol((string) MustBookingConfig::get_currency()),
            'initial' => [
                'checkin' => $initial_checkin,
                'checkout' => $initial_checkout,
                'guests' => \max(1, \min($max_booking_guests, $initial_guests)),
                'roomCount' => $initial_room_count,
                'accommodationType' => $initial_accommodation_type,
            ],
            'strings' => [
                'loading' => \__('Loading room availability...', 'must-hotel-booking'),
                'noRooms' => \__('No rooms are available for the selected dates.', 'must-hotel-booking'),
                'invalidRange' => \__('Please select valid check-in and check-out dates.', 'must-hotel-booking'),
                'requestFailed' => \__('Unable to load availability. Please try again.', 'must-hotel-booking'),
                'selectDatesHeading' => \__('Select your dates', 'must-hotel-booking'),
                'selectDatesHeadingFixedRoom' => \__('Select your dates', 'must-hotel-booking'),
                'availableAccommodationHeading' => \__('Available Accommodation', 'must-hotel-booking'),
                'selectDatesSummary' => \__('Select dates', 'must-hotel-booking'),
                'roomLabel' => \__('Room', 'must-hotel-booking'),
                'capacityFormat' => \__('%d Guests', 'must-hotel-booking'),
                'basePriceFormat' => \__('Base Price: %s', 'must-hotel-booking'),
                'estimatedTotalFormat' => \__('Estimated Total: %s', 'must-hotel-booking'),
                'stayTotalFormat' => \__('Stay Total: %s', 'must-hotel-booking'),
                'selectionSummaryFormat' => \__('%1$s / %2$d Guests / %3$s', 'must-hotel-booking'),
                'roomCountSingular' => \__('%d Room', 'must-hotel-booking'),
                'roomCountPlural' => \__('%d Rooms', 'must-hotel-booking'),
                'roomCountAuto' => \__('Auto', 'must-hotel-booking'),
                'continueToGuestInfo' => \__('Continue to Guest Information', 'must-hotel-booking'),
                'bookNow' => \__('Book Now', 'must-hotel-booking'),
                'additionalDetails' => \__('Additional Details', 'must-hotel-booking'),
                'noImage' => \__('Add room image in admin', 'must-hotel-booking'),
            ],
        ]
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_booking_page_assets');
\add_filter('template_include', __NAMESPACE__ . '\maybe_load_frontend_template', 99);
