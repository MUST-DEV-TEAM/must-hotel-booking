<?php

namespace MustHotelBooking\Elementor;

use MustHotelBooking\Core\MustBookingConfig;

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

    protected function register_controls(): void
    {
        $rooms_list_options = get_rooms_list_widget_options_for_booking_search();

        $this->start_controls_section(
            'section_content',
            [
                'label' => \__('Content', 'must-hotel-booking'),
            ]
        );

        $this->add_control(
            'linked_rooms_list_widget_id',
            [
                'label' => \__('Linked Rooms List', 'must-hotel-booking'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => '',
                'options' => $rooms_list_options,
                'description' => \__('Choose a Rooms List widget from this page. The search will submit that widget category automatically. Select Not Connected to keep this search independent.', 'must-hotel-booking'),
            ]
        );

        $this->end_controls_section();

    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $widget_id = \wp_unique_id('must-hotel-booking-search-');
        $checkin_id = $widget_id . '-checkin';
        $checkout_id = $widget_id . '-checkout';
        $guests_id = $widget_id . '-guests';
        $linked_rooms_list_widget_id = isset($settings['linked_rooms_list_widget_id'])
            ? \sanitize_key((string) $settings['linked_rooms_list_widget_id'])
            : '';
        $legacy_connection_key = isset($settings['rooms_list_connection_key'])
            ? \sanitize_key((string) $settings['rooms_list_connection_key'])
            : '';
        $max_booking_guests = \class_exists(MustBookingConfig::class)
            ? \max(1, MustBookingConfig::get_max_booking_guests())
            : 5;
        $booking_url = get_booking_page_url_for_widget();
        $calendar_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/Calendar2Date.svg';
        $people_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/PeopleFill.svg';
        $arrow_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowRight.svg';

        ?>
        <div
            class="must-hotel-booking-widget must-hotel-booking-widget-booking-search"
            data-linked-room-list-id="<?php echo \esc_attr($linked_rooms_list_widget_id); ?>"
            data-connection-key="<?php echo \esc_attr($legacy_connection_key); ?>"
        >
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
