<?php

namespace must_hotel_booking;

/**
 * Register Elementor widget styles.
 */
function register_elementor_booking_search_widget_styles(): void
{
    \wp_register_style(
        'must-hotel-booking-flatpickr',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.css',
        [],
        '4.6.13'
    );

    \wp_register_style(
        'must-hotel-booking-booking-search-widget',
        MUST_HOTEL_BOOKING_URL . 'assets/css/booking-search-widget.css',
        ['must-hotel-booking-design-system'],
        MUST_HOTEL_BOOKING_VERSION
    );
}

/**
 * Register Elementor widget scripts.
 */
function register_elementor_booking_search_widget_scripts(): void
{
    $booking_window_days = 365;
    $max_booking_guests = 5;

    if (\class_exists(__NAMESPACE__ . '\MustBookingConfig')) {
        $booking_window_days = \max(1, MustBookingConfig::get_booking_window());
        $max_booking_guests = \max(1, MustBookingConfig::get_max_booking_guests());
    }

    $today_date = \current_time('Y-m-d');
    $today_obj = new \DateTimeImmutable($today_date);
    $max_date = $today_obj->modify('+' . $booking_window_days . ' day')->format('Y-m-d');

    \wp_register_script(
        'must-hotel-booking-flatpickr',
        'https://cdn.jsdelivr.net/npm/flatpickr/dist/flatpickr.min.js',
        [],
        '4.6.13',
        true
    );

    \wp_register_script(
        'must-hotel-booking-booking-search-widget',
        MUST_HOTEL_BOOKING_URL . 'assets/js/booking-search-widget.js',
        ['must-hotel-booking-flatpickr'],
        MUST_HOTEL_BOOKING_VERSION,
        true
    );

    \wp_localize_script(
        'must-hotel-booking-booking-search-widget',
        'mustHotelBookingWidgetConfig',
        [
            'today' => $today_date,
            'maxDate' => $max_date,
            'bookingWindowDays' => $booking_window_days,
            'maxGuests' => $max_booking_guests,
            'displayDateFormat' => 'd/m/Y',
            'queryDateFormat' => 'Y-m-d',
        ]
    );
}

/**
 * Get booking page URL for the widget redirect.
 */
function get_booking_page_url_for_widget(): string
{
    $default_url = \home_url('/booking');

    if (!\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
        return $default_url;
    }

    $settings = get_plugin_settings();
    $booking_page_id = isset($settings['page_booking_id']) ? (int) $settings['page_booking_id'] : 0;

    if ($booking_page_id <= 0) {
        return $default_url;
    }

    $permalink = \get_permalink($booking_page_id);

    return \is_string($permalink) && $permalink !== '' ? $permalink : $default_url;
}

/**
 * Register Elementor booking search widget.
 *
 * @param mixed $widgets_manager Elementor widgets manager instance.
 */
function register_elementor_booking_search_widget($widgets_manager): void
{
    static $is_registered = false;

    if ($is_registered) {
        return;
    }

    if (!\class_exists('\Elementor\Widget_Base') || !\is_object($widgets_manager)) {
        return;
    }

    if (!\class_exists(__NAMESPACE__ . '\Booking_Search_Widget')) {
        class Booking_Search_Widget extends \Elementor\Widget_Base
        {
            public function get_name(): string
            {
                return 'must_hotel_booking_booking_search';
            }

            public function get_title(): string
            {
                return \esc_html__('Booking Search', 'must-hotel-booking');
            }

            public function get_icon(): string
            {
                return 'eicon-search';
            }

            public function get_categories(): array
            {
                return ['must-hotel-booking', 'general'];
            }

            public function get_keywords(): array
            {
                return ['booking', 'hotel', 'reservation', 'search'];
            }

            public function get_style_depends(): array
            {
                return [
                    'must-hotel-booking-flatpickr',
                    'must-hotel-booking-booking-search-widget',
                ];
            }

            public function get_script_depends(): array
            {
                return [
                    'must-hotel-booking-flatpickr',
                    'must-hotel-booking-booking-search-widget',
                ];
            }

            protected function render(): void
            {
                $widget_id = \wp_unique_id('must-hotel-booking-search-');
                $checkin_id = $widget_id . '-checkin';
                $checkout_id = $widget_id . '-checkout';
                $guests_id = $widget_id . '-guests';
                $max_booking_guests = \class_exists(__NAMESPACE__ . '\MustBookingConfig')
                    ? \max(1, MustBookingConfig::get_max_booking_guests())
                    : 5;
                $booking_url = get_booking_page_url_for_widget();
                $calendar_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/Calendar2Date.svg';
                $people_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/PeopleFill.svg';
                $arrow_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowRight.svg';

                ?>
                <div class="must-hotel-booking-widget must-hotel-booking-widget-booking-search">
                    <form class="must-hotel-booking-booking-search" method="get" action="<?php echo \esc_url($booking_url); ?>">
                        <div class="must-hotel-booking-booking-search-fields">
                            <div class="must-hotel-booking-field must-hotel-booking-field-date must-hotel-booking-field-checkin">
                                <label class="screen-reader-text" for="<?php echo \esc_attr($checkin_id); ?>"><?php echo \esc_html__('Check In Date', 'must-hotel-booking'); ?></label>
                                <input
                                    id="<?php echo \esc_attr($checkin_id); ?>"
                                    type="text"
                                    name="checkin"
                                    class="must-hotel-booking-date-input must-hotel-booking-checkin"
                                    placeholder="<?php echo \esc_attr__('Check In Date', 'must-hotel-booking'); ?>"
                                    autocomplete="off"
                                    required
                                />
                                <img
                                    class="must-hotel-booking-field-icon"
                                    src="<?php echo \esc_url($calendar_icon_url); ?>"
                                    alt=""
                                    aria-hidden="true"
                                />
                            </div>

                            <div class="must-hotel-booking-field must-hotel-booking-field-date must-hotel-booking-field-checkout">
                                <label class="screen-reader-text" for="<?php echo \esc_attr($checkout_id); ?>"><?php echo \esc_html__('Check Out Date', 'must-hotel-booking'); ?></label>
                                <input
                                    id="<?php echo \esc_attr($checkout_id); ?>"
                                    type="text"
                                    name="checkout"
                                    class="must-hotel-booking-date-input must-hotel-booking-checkout"
                                    placeholder="<?php echo \esc_attr__('Check Out Date', 'must-hotel-booking'); ?>"
                                    autocomplete="off"
                                    required
                                />
                                <img
                                    class="must-hotel-booking-field-icon"
                                    src="<?php echo \esc_url($calendar_icon_url); ?>"
                                    alt=""
                                    aria-hidden="true"
                                />
                            </div>

                            <div class="must-hotel-booking-field must-hotel-booking-field-guests">
                                <label class="screen-reader-text" for="<?php echo \esc_attr($guests_id); ?>"><?php echo \esc_html__('Guests Number', 'must-hotel-booking'); ?></label>
                                <input
                                    id="<?php echo \esc_attr($guests_id); ?>"
                                    type="number"
                                    name="guests"
                                    min="1"
                                    max="<?php echo \esc_attr((string) $max_booking_guests); ?>"
                                    step="1"
                                    value="1"
                                    inputmode="numeric"
                                    pattern="[0-9]*"
                                    placeholder="<?php echo \esc_attr__('Guests Number', 'must-hotel-booking'); ?>"
                                    required
                                />
                                <img
                                    class="must-hotel-booking-field-icon"
                                    src="<?php echo \esc_url($people_icon_url); ?>"
                                    alt=""
                                    aria-hidden="true"
                                />
                            </div>
                        </div>

                        <div class="must-hotel-booking-submit">
                            <button type="submit">
                                <span class="must-hotel-booking-submit-text"><?php echo \esc_html__('Check Availability', 'must-hotel-booking'); ?></span>
                                <img
                                    class="must-hotel-booking-submit-icon"
                                    src="<?php echo \esc_url($arrow_icon_url); ?>"
                                    alt=""
                                    aria-hidden="true"
                                />
                            </button>
                        </div>
                    </form>
                </div>
                <?php
            }
        }
    }

    $widget_instance = new Booking_Search_Widget();

    if (\method_exists($widgets_manager, 'register')) {
        $widgets_manager->register($widget_instance);
        $is_registered = true;
        return;
    }

    if (\method_exists($widgets_manager, 'register_widget_type')) {
        $widgets_manager->register_widget_type($widget_instance);
        $is_registered = true;
    }
}

/**
 * Register Elementor widget for legacy Elementor hooks.
 */
function register_elementor_booking_search_widget_legacy(): void
{
    if (!\class_exists('\Elementor\Plugin')) {
        return;
    }

    $plugin_instance = \Elementor\Plugin::$instance ?? null;
    $widgets_manager = (\is_object($plugin_instance) && isset($plugin_instance->widgets_manager))
        ? $plugin_instance->widgets_manager
        : null;

    if (!\is_object($widgets_manager)) {
        return;
    }

    register_elementor_booking_search_widget($widgets_manager);
}

/**
 * Register custom Elementor widget category.
 *
 * @param mixed $elements_manager Elementor elements manager instance.
 */
function register_elementor_booking_widget_category($elements_manager): void
{
    if (!\is_object($elements_manager) || !\method_exists($elements_manager, 'add_category')) {
        return;
    }

    $elements_manager->add_category(
        'must-hotel-booking',
        [
            'title' => \esc_html__('MUST Hotel Booking', 'must-hotel-booking'),
            'icon' => 'fa fa-calendar',
        ]
    );
}

\add_action('elementor/frontend/after_register_styles', __NAMESPACE__ . '\register_elementor_booking_search_widget_styles');
\add_action('elementor/frontend/after_register_scripts', __NAMESPACE__ . '\register_elementor_booking_search_widget_scripts');
\add_action('elementor/elements/categories_registered', __NAMESPACE__ . '\register_elementor_booking_widget_category');
\add_action('elementor/widgets/register', __NAMESPACE__ . '\register_elementor_booking_search_widget');
\add_action('elementor/widgets/widgets_registered', __NAMESPACE__ . '\register_elementor_booking_search_widget_legacy');
