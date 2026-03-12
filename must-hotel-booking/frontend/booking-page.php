<?php

namespace must_hotel_booking;

/**
 * Get plugin settings option name.
 */
function get_settings_option_name(): string
{
    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
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
    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
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
    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        MustBookingConfig::set_all_settings($settings);
    }
}

/**
 * Get the configured booking guest cap.
 */
function get_max_booking_guests_limit(): int
{
    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        return \max(1, MustBookingConfig::get_max_booking_guests());
    }

    $settings = get_plugin_settings();
    $value = isset($settings['max_booking_guests']) ? \absint((string) $settings['max_booking_guests']) : 5;

    return $value > 0 ? $value : 5;
}

/**
 * Get managed frontend pages configuration.
 *
 * @return array<string, array<string, string>>
 */
function get_frontend_pages_config(): array
{
    return [
        'page_rooms_id' => [
            'title' => 'Rooms',
            'slug' => 'rooms',
            'template' => 'frontend/templates/rooms.php',
        ],
        'page_booking_id' => [
            'title' => 'Booking',
            'slug' => 'booking',
            'template' => 'frontend/templates/booking.php',
        ],
        'page_booking_accommodation_id' => [
            'title' => 'Select Accommodation',
            'slug' => 'booking-accommodation',
            'template' => 'frontend/templates/booking-accommodation.php',
        ],
        'page_checkout_id' => [
            'title' => 'Checkout',
            'slug' => 'checkout',
            'template' => 'frontend/templates/checkout.php',
        ],
        'page_booking_confirmation_id' => [
            'title' => 'Booking Confirmation',
            'slug' => 'booking-confirmation',
            'template' => 'frontend/templates/booking-confirmation.php',
        ],
    ];
}

/**
 * Create plugin pages and store their IDs in plugin settings.
 */
function install_frontend_pages(): void
{
    $settings = get_plugin_settings();

    foreach (get_frontend_pages_config() as $setting_key => $page_config) {
        $existing_page = \get_page_by_path($page_config['slug'], OBJECT, 'page');
        $page_id = 0;

        if ($existing_page instanceof \WP_Post) {
            $page_id = (int) $existing_page->ID;
        } else {
            $created_page_id = \wp_insert_post(
                [
                    'post_title' => $page_config['title'],
                    'post_name' => $page_config['slug'],
                    'post_type' => 'page',
                    'post_status' => 'publish',
                    'post_content' => '',
                    'comment_status' => 'closed',
                    'ping_status' => 'closed',
                ],
                true
            );

            if (!\is_wp_error($created_page_id)) {
                $page_id = (int) $created_page_id;
            }
        }

        if ($page_id > 0) {
            $settings[$setting_key] = $page_id;
        }
    }

    update_plugin_settings($settings);
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
    $settings = get_plugin_settings();
    $page_id = isset($settings[$setting_key]) ? (int) $settings[$setting_key] : 0;

    if ($page_id > 0) {
        $permalink = \get_permalink($page_id);

        if (\is_string($permalink) && $permalink !== '') {
            return $permalink;
        }
    }

    return \home_url($fallback_path);
}

/**
 * Get booking page URL.
 */
function get_booking_page_url(): string
{
    return get_frontend_page_url('page_booking_id', '/booking');
}

/**
 * Build booking page URL with a booking context.
 *
 * @param array<string, mixed> $context
 */
function get_booking_context_url(array $context): string
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
    $args['accommodation_type'] = (string) ($normalized_context['accommodation_type'] ?? 'standard-rooms');

    return \add_query_arg($args, get_booking_page_url());
}

/**
 * Get checkout page URL.
 */
function get_checkout_page_url(): string
{
    return get_frontend_page_url('page_checkout_id', '/checkout');
}

/**
 * Get booking accommodation step URL.
 */
function get_booking_accommodation_page_url(): string
{
    return get_frontend_page_url('page_booking_accommodation_id', '/booking-accommodation');
}

/**
 * Get booking confirmation page URL.
 */
function get_booking_confirmation_page_url(): string
{
    return get_frontend_page_url('page_booking_confirmation_id', '/booking-confirmation');
}

/**
 * Parse booking request context.
 *
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function parse_booking_request_context(array $source, bool $require_dates = false): array
{
    $checkin = isset($source['checkin']) ? \sanitize_text_field((string) \wp_unslash($source['checkin'])) : '';
    $checkout = isset($source['checkout']) ? \sanitize_text_field((string) \wp_unslash($source['checkout'])) : '';
    $guests_raw = isset($source['guests']) ? \wp_unslash($source['guests']) : 1;
    $max_booking_guests = get_max_booking_guests_limit();
    $guests = \max(1, \min($max_booking_guests, \absint($guests_raw)));
    $accommodation_type_raw = isset($source['accommodation_type']) ? \sanitize_text_field((string) \wp_unslash($source['accommodation_type'])) : 'standard-rooms';
    $accommodation_type = \function_exists(__NAMESPACE__ . '\normalize_room_category')
        ? normalize_room_category($accommodation_type_raw)
        : 'standard-rooms';
    $errors = [];

    if ($require_dates && ($checkin === '' || $checkout === '')) {
        $errors[] = \__('Please provide check-in and check-out dates.', 'must-hotel-booking');
    }

    if ($checkin !== '' && (!\function_exists(__NAMESPACE__ . '\is_valid_booking_date') || !is_valid_booking_date($checkin))) {
        $errors[] = \__('Check-in date is invalid.', 'must-hotel-booking');
    }

    if ($checkout !== '' && (!\function_exists(__NAMESPACE__ . '\is_valid_booking_date') || !is_valid_booking_date($checkout))) {
        $errors[] = \__('Check-out date is invalid.', 'must-hotel-booking');
    }

    if ($checkin !== '' && $checkout !== '' && \function_exists(__NAMESPACE__ . '\is_valid_booking_date')) {
        if (is_valid_booking_date($checkin) && is_valid_booking_date($checkout) && $checkin >= $checkout) {
            $errors[] = \__('Check-out date must be after check-in date.', 'must-hotel-booking');
        }
    }

    return [
        'checkin' => $checkin,
        'checkout' => $checkout,
        'guests' => $guests,
        'accommodation_type' => $accommodation_type,
        'is_valid' => empty($errors),
        'errors' => $errors,
    ];
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

    $action = isset($_POST['must_booking_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_booking_action'])) : '';

    if ($action !== 'select_room') {
        return '';
    }

    $nonce = isset($_POST['must_booking_nonce']) ? (string) \wp_unslash($_POST['must_booking_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_booking_select_room')) {
        return \__('Security check failed. Please try again.', 'must-hotel-booking');
    }

    $room_id = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $context = parse_booking_request_context($_POST, true);

    if (!$context['is_valid']) {
        $first_error = isset($context['errors'][0]) ? (string) $context['errors'][0] : '';

        return $first_error !== '' ? $first_error : \__('Booking request is invalid.', 'must-hotel-booking');
    }

    if ($room_id <= 0) {
        return \__('Please select a room to continue.', 'must-hotel-booking');
    }

    if (!\function_exists(__NAMESPACE__ . '\create_temporary_reservation_lock')) {
        return \__('Lock engine is not available.', 'must-hotel-booking');
    }

    $lock_created = create_temporary_reservation_lock(
        $room_id,
        (string) $context['checkin'],
        (string) $context['checkout']
    );

    if (!$lock_created) {
        return \__('This room is no longer available for the selected dates.', 'must-hotel-booking');
    }

    $redirect_url = \add_query_arg(
        [
            'room_id' => $room_id,
            'checkin' => (string) $context['checkin'],
            'checkout' => (string) $context['checkout'],
            'guests' => (int) $context['guests'],
            'accommodation_type' => (string) $context['accommodation_type'],
        ],
        get_checkout_page_url()
    );

    \wp_safe_redirect($redirect_url);
    exit;
}

/**
 * Build enriched room card data for booking step 2.
 *
 * @param array<string, mixed> $room
 * @return array<string, mixed>|null
 */
function get_booking_results_room_view_data(array $room): ?array
{
    $room_id = isset($room['id']) ? (int) $room['id'] : 0;

    if ($room_id <= 0) {
        return null;
    }

    $room_slug = isset($room['slug']) ? (string) $room['slug'] : '';
    $room_category = isset($room['category']) ? (string) $room['category'] : 'standard-rooms';
    $currency = \class_exists(__NAMESPACE__ . '\MustBookingConfig')
        ? (string) MustBookingConfig::get_currency()
        : 'USD';
    $primary_image_url = \function_exists(__NAMESPACE__ . '\get_room_main_image_url')
        ? get_room_main_image_url($room_id, 'large')
        : '';
    $gallery_images = \function_exists(__NAMESPACE__ . '\get_room_gallery_image_urls')
        ? get_room_gallery_image_urls($room_id, 12, 'large')
        : [];
    $gallery_images = \array_values(
        \array_filter(
            \array_map('strval', \is_array($gallery_images) ? $gallery_images : []),
            static function (string $url): bool {
                return $url !== '';
            }
        )
    );

    if ($primary_image_url === '' && !empty($gallery_images)) {
        $primary_image_url = (string) \array_shift($gallery_images);
    }

    $lightbox_images = [];

    if ($primary_image_url !== '') {
        $lightbox_images[] = $primary_image_url;
    }

    foreach ($gallery_images as $gallery_image) {
        $gallery_image = (string) $gallery_image;

        if ($gallery_image !== '') {
            $lightbox_images[] = $gallery_image;
        }
    }

    $lightbox_images = \array_values(\array_unique($lightbox_images));
    $gallery_images = \array_values(
        \array_filter(
            \array_unique($gallery_images),
            static function (string $url) use ($primary_image_url): bool {
                return $url !== '' && $url !== $primary_image_url;
            }
        )
    );
    $gallery_images = \array_slice($gallery_images, 0, 3);
    $details_url = $room_slug !== '' && \function_exists(__NAMESPACE__ . '\get_single_room_url')
        ? get_single_room_url($room_slug)
        : get_frontend_page_url('page_rooms_id', '/rooms');

    return [
        'id' => $room_id,
        'name' => isset($room['name']) ? (string) $room['name'] : '',
        'slug' => $room_slug,
        'category' => $room_category,
        'category_label' => \function_exists(__NAMESPACE__ . '\get_room_category_label')
            ? get_room_category_label($room_category)
            : '',
        'description' => isset($room['description']) ? (string) $room['description'] : '',
        'max_guests' => isset($room['max_guests']) ? (int) $room['max_guests'] : 0,
        'base_price' => isset($room['base_price']) ? (float) $room['base_price'] : 0.0,
        'extra_guest_price' => isset($room['extra_guest_price']) ? (float) $room['extra_guest_price'] : 0.0,
        'room_size' => isset($room['room_size']) ? (string) $room['room_size'] : '',
        'beds' => isset($room['beds']) ? (string) $room['beds'] : '',
        'currency' => $currency,
        'price_preview_total' => isset($room['price_preview_total']) ? (float) $room['price_preview_total'] : null,
        'dynamic_total_price' => isset($room['dynamic_total_price']) ? (float) $room['dynamic_total_price'] : null,
        'dynamic_room_subtotal' => isset($room['dynamic_room_subtotal']) ? (float) $room['dynamic_room_subtotal'] : null,
        'dynamic_nights' => isset($room['dynamic_nights']) ? (int) $room['dynamic_nights'] : null,
        'details_url' => $details_url,
        'primary_image_url' => $primary_image_url,
        'gallery_images' => \array_slice($gallery_images, 0, 3),
        'lightbox_images' => $lightbox_images,
        'room_rules' => \function_exists(__NAMESPACE__ . '\get_room_rules_text')
            ? get_room_rules_text($room_id)
            : '',
        'amenities' => \function_exists(__NAMESPACE__ . '\get_room_amenity_display_items')
            ? get_room_amenity_display_items($room_id)
            : [],
        'people_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/PeopleFill.svg',
        'surface_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/Surface.svg',
    ];
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
function format_booking_results_selection_summary(string $accommodation_type, int $guests): string
{
    $category_label = \function_exists(__NAMESPACE__ . '\get_room_category_label')
        ? get_room_category_label($accommodation_type)
        : \__('Standard Rooms', 'must-hotel-booking');

    return \sprintf(
        /* translators: 1: accommodation type label, 2: guests count. */
        \__('%1$s / %2$d Guests', 'must-hotel-booking'),
        $category_label,
        \max(1, $guests)
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
    $context = parse_booking_request_context($raw_get, false);
    $has_search = (string) $context['checkin'] !== '' || (string) $context['checkout'] !== '';

    if (!$context['is_valid'] && $has_search) {
        foreach ((array) $context['errors'] as $error_message) {
            $messages[] = (string) $error_message;
        }
    }

    $rooms = [];

    return [
        'booking_url' => get_booking_page_url(),
        'accommodation_url' => get_booking_accommodation_page_url(),
        'checkout_url' => get_checkout_page_url(),
        'checkin' => (string) $context['checkin'],
        'checkout' => (string) $context['checkout'],
        'guests' => (int) $context['guests'],
        'max_booking_guests' => get_max_booking_guests_limit(),
        'accommodation_type' => (string) $context['accommodation_type'],
        'has_search' => $has_search,
        'is_valid' => (bool) $context['is_valid'],
        'initial_step' => 1,
        'messages' => $messages,
        'rooms' => $rooms,
    ];
}

/**
 * Check if current frontend request is the managed booking page.
 */
function is_frontend_booking_page(): bool
{
    if (\is_admin()) {
        return false;
    }

    $settings = get_plugin_settings();
    $booking_page_id = isset($settings['page_booking_id']) ? (int) $settings['page_booking_id'] : 0;

    if ($booking_page_id > 0 && \is_page($booking_page_id)) {
        return true;
    }

    return \is_page('booking');
}

/**
 * Ensure managed frontend pages exist after plugin updates.
 */
function maybe_sync_frontend_pages(): void
{
    if (!\function_exists(__NAMESPACE__ . '\install_frontend_pages')) {
        return;
    }

    $settings = get_plugin_settings();

    foreach (get_frontend_pages_config() as $setting_key => $page_config) {
        $page_id = isset($settings[$setting_key]) ? (int) $settings[$setting_key] : 0;

        if ($page_id > 0 && \get_post($page_id) instanceof \WP_Post) {
            continue;
        }

        install_frontend_pages();
        return;
    }
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
    $context = parse_booking_request_context($raw_get, false);
    $initial_checkin = (string) ($context['checkin'] ?? '');
    $initial_checkout = (string) ($context['checkout'] ?? '');
    $initial_guests = (int) ($context['guests'] ?? 1);
    $initial_accommodation_type = (string) ($context['accommodation_type'] ?? 'standard-rooms');
    $max_booking_guests = get_max_booking_guests_limit();
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
        ['must-hotel-booking-design-system'],
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
            'bookingUrl' => get_booking_page_url(),
            'accommodationUrl' => get_booking_accommodation_page_url(),
            'availabilityAction' => 'must_check_availability',
            'disabledDatesAction' => 'must_get_disabled_dates',
            'selectRoomNonce' => \wp_create_nonce('must_booking_select_room'),
            'windowDays' => 180,
            'today' => \current_time('Y-m-d'),
            'defaultAccommodationType' => 'standard-rooms',
            'maxGuests' => $max_booking_guests,
            'pageMode' => 'calendar',
            'initialStep' => (!empty($context['is_valid']) && $initial_checkin !== '' && $initial_checkout !== '') ? 2 : 1,
            'arrowIconUrl' => $arrow_icon_url,
            'bedIconUrl' => $bed_icon_url,
            'currencySymbol' => get_frontend_currency_symbol(\class_exists(__NAMESPACE__ . '\MustBookingConfig') ? (string) MustBookingConfig::get_currency() : 'USD'),
            'initial' => [
                'checkin' => $initial_checkin,
                'checkout' => $initial_checkout,
                'guests' => \max(1, \min($max_booking_guests, $initial_guests)),
                'accommodationType' => $initial_accommodation_type,
            ],
            'strings' => [
                'loading' => \__('Loading room availability...', 'must-hotel-booking'),
                'noRooms' => \__('No rooms are available for the selected dates.', 'must-hotel-booking'),
                'invalidRange' => \__('Please select valid check-in and check-out dates.', 'must-hotel-booking'),
                'requestFailed' => \__('Unable to load availability. Please try again.', 'must-hotel-booking'),
                'selectDatesHeading' => \__('Select your dates', 'must-hotel-booking'),
                'availableAccommodationHeading' => \__('Available Accommodation', 'must-hotel-booking'),
                'selectDatesSummary' => \__('Select dates', 'must-hotel-booking'),
                'roomLabel' => \__('Room', 'must-hotel-booking'),
                'capacityFormat' => \__('%d Guests', 'must-hotel-booking'),
                'basePriceFormat' => \__('Base Price: %s', 'must-hotel-booking'),
                'estimatedTotalFormat' => \__('Estimated Total: %s', 'must-hotel-booking'),
                'selectionSummaryFormat' => \__('%1$s / %2$d Guests', 'must-hotel-booking'),
                'bookNow' => \__('Book Now', 'must-hotel-booking'),
                'additionalDetails' => \__('Additional Details', 'must-hotel-booking'),
                'noImage' => \__('Add room image in admin', 'must-hotel-booking'),
            ],
        ]
    );
}

\add_action('wp_enqueue_scripts', __NAMESPACE__ . '\enqueue_booking_page_assets');
\add_filter('template_include', __NAMESPACE__ . '\maybe_load_frontend_template', 99);
