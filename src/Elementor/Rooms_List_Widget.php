<?php

namespace MustHotelBooking\Elementor;

class Rooms_List_Widget extends \Elementor\Widget_Base
{
    public function get_name(): string
    {
        return 'must_hotel_booking_rooms_list';
    }

    public function get_title(): string
    {
        return \esc_html__('Rooms List', 'must-hotel-booking');
    }

    public function get_icon(): string
    {
        return 'eicon-post-list';
    }

    public function get_categories(): array
    {
        return ['must-hotel-booking', 'general'];
    }

    public function get_keywords(): array
    {
        return ['rooms', 'hotel', 'suites', 'duplex'];
    }

    public function get_style_depends(): array
    {
        return ['must-hotel-booking-rooms-list-widget'];
    }

    public function get_script_depends(): array
    {
        return ['must-hotel-booking-rooms-list-widget'];
    }

    protected function register_controls(): void
    {
        $category_options = ['all' => \__('All Categories', 'must-hotel-booking')];

        if (\function_exists(__NAMESPACE__ . '\\get_room_categories')) {
            $category_options = \array_merge($category_options, get_room_categories());
        }

        $this->start_controls_section(
            'section_content',
            [
                'label' => \__('Content', 'must-hotel-booking'),
            ]
        );

        $this->add_control(
            'room_category',
            [
                'label' => \__('Room Category', 'must-hotel-booking'),
                'type' => \Elementor\Controls_Manager::SELECT,
                'default' => 'all',
                'options' => $category_options,
            ]
        );

        $this->add_control(
            'rooms_limit',
            [
                'label' => \__('Rooms Limit', 'must-hotel-booking'),
                'type' => \Elementor\Controls_Manager::NUMBER,
                'default' => 20,
                'min' => 1,
                'max' => 200,
            ]
        );

        $this->add_control(
            'show_category_heading',
            [
                'label' => \__('Show Category Heading', 'must-hotel-booking'),
                'type' => \Elementor\Controls_Manager::SWITCHER,
                'label_on' => \__('Yes', 'must-hotel-booking'),
                'label_off' => \__('No', 'must-hotel-booking'),
                'default' => 'yes',
            ]
        );

        $this->add_control(
            'empty_text',
            [
                'label' => \__('Empty State Text', 'must-hotel-booking'),
                'type' => \Elementor\Controls_Manager::TEXT,
                'default' => \__('No rooms found for the selected category.', 'must-hotel-booking'),
            ]
        );

        $this->end_controls_section();

    }

    protected function render(): void
    {
        $settings = $this->get_settings_for_display();
        $selected_category = isset($settings['room_category']) ? \sanitize_key((string) $settings['room_category']) : 'all';
        $legacy_connection_key = isset($settings['booking_search_connection_key'])
            ? \sanitize_key((string) $settings['booking_search_connection_key'])
            : '';
        $rooms_limit = isset($settings['rooms_limit']) ? (int) $settings['rooms_limit'] : 20;
        $rooms_limit = \max(1, \min(200, $rooms_limit));
        $show_heading = isset($settings['show_category_heading']) && (string) $settings['show_category_heading'] === 'yes';
        $empty_text = isset($settings['empty_text']) ? (string) $settings['empty_text'] : '';

        if ($empty_text === '') {
            $empty_text = \__('No rooms found for the selected category.', 'must-hotel-booking');
        }

        $rooms = get_rooms_for_widget_render($selected_category, $rooms_limit);

        $category_label = '';

        if ($selected_category !== 'all' && \function_exists(__NAMESPACE__ . '\\get_room_category_label')) {
            $category_label = get_room_category_label($selected_category);
        }

        $rooms_page_url = get_rooms_widget_rooms_page_url();
        $booking_page_url = get_rooms_widget_booking_page_url();
        $arrow_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowRight.svg';
        $bed_icon_url = MUST_HOTEL_BOOKING_URL . 'assets/img/bed.svg';

        echo '<div class="must-hotel-booking-widget must-hotel-booking-rooms-list-widget" data-room-list-widget-id="' . \esc_attr($this->get_id()) . '" data-room-category="' . \esc_attr($selected_category) . '" data-connection-key="' . \esc_attr($legacy_connection_key) . '">';

        if ($show_heading && $category_label !== '') {
            echo '<p class="must-hotel-booking-rooms-list-heading">/ ' . \esc_html(\strtoupper($category_label)) . '</p>';
        }

        if (empty($rooms)) {
            echo '<p class="must-hotel-booking-rooms-list-empty">' . \esc_html($empty_text) . '</p>';
            echo '</div>';
            return;
        }

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $room_id = isset($room['id']) ? (int) $room['id'] : 0;
            $room_name = isset($room['name']) ? (string) $room['name'] : '';
            $room_slug = isset($room['slug']) ? (string) $room['slug'] : '';
            $room_description = isset($room['description']) ? (string) $room['description'] : '';
            $gallery_urls = $room_id > 0 ? get_room_gallery_urls_for_widget($room_id, 4) : [];
            $primary_image = $room_id > 0 ? get_room_main_image_url_for_widget($room_id) : '';
            $thumbnail_urls = $gallery_urls;

            if ($primary_image === '' && !empty($gallery_urls)) {
                $primary_image = (string) $gallery_urls[0];
                $thumbnail_urls = \array_slice($gallery_urls, 1, 3);
            } else {
                $thumbnail_urls = \array_slice($gallery_urls, 0, 3);
            }

            $lightbox_images = [];

            if ($primary_image !== '') {
                $lightbox_images[] = (string) $primary_image;
            }

            foreach ($thumbnail_urls as $thumbnail_url) {
                $thumbnail_url = (string) $thumbnail_url;

                if ($thumbnail_url !== '') {
                    $lightbox_images[] = $thumbnail_url;
                }
            }

            $lightbox_images = \array_values(\array_unique($lightbox_images));
            $lightbox_images_json = \wp_json_encode($lightbox_images);
            $lightbox_images_attr = \is_string($lightbox_images_json) ? \esc_attr($lightbox_images_json) : '[]';
            $room_name_attr = \esc_attr($room_name);

            $details_url = \add_query_arg(
                ['room' => $room_slug],
                $rooms_page_url
            );

            $book_url = \add_query_arg(
                ['room_id' => $room_id],
                $booking_page_url
            );

            echo '<article class="must-hotel-booking-rooms-list-card" data-lightbox-images="' . $lightbox_images_attr . '" data-lightbox-title="' . $room_name_attr . '">';
            echo '<div class="must-hotel-booking-rooms-list-media">';

            if ($primary_image !== '') {
                echo '<button type="button" class="must-hotel-booking-rooms-list-image-trigger must-hotel-booking-rooms-list-image-trigger-main" data-lightbox-index="0" aria-label="' . \esc_attr__('Open room image', 'must-hotel-booking') . '">';
                echo '<img src="' . \esc_url($primary_image) . '" alt="' . \esc_attr($room_name) . '" loading="lazy" />';
                echo '</button>';
            } else {
                echo '<div class="must-hotel-booking-rooms-list-placeholder">' . \esc_html__('Add room image in admin', 'must-hotel-booking') . '</div>';
            }

            echo '</div>';
            echo '<div class="must-hotel-booking-rooms-list-content">';
            echo '<div class="must-hotel-booking-rooms-list-header">';
            echo '<h3>' . \esc_html($room_name) . '</h3>';

            if ($room_description !== '') {
                echo '<p class="must-hotel-booking-rooms-list-description">' . \esc_html($room_description) . '</p>';
            }

            echo '</div>';
            echo '<div class="must-hotel-booking-rooms-list-thumbs">';

            if (!empty($thumbnail_urls)) {
                foreach ($thumbnail_urls as $index => $thumbnail_url) {
                    $thumb_index = \array_search((string) $thumbnail_url, $lightbox_images, true);
                    $thumb_index = \is_int($thumb_index) ? $thumb_index : (int) $index + 1;
                    echo '<button type="button" class="must-hotel-booking-rooms-list-image-trigger must-hotel-booking-rooms-list-image-trigger-thumb" data-lightbox-index="' . \esc_attr((string) $thumb_index) . '" aria-label="' . \esc_attr__('Open room image', 'must-hotel-booking') . '">';
                    echo '<img src="' . \esc_url((string) $thumbnail_url) . '" alt="" loading="lazy" />';
                    echo '</button>';
                }
            } else {
                for ($i = 0; $i < 3; $i++) {
                    echo '<span class="must-hotel-booking-thumb-placeholder" aria-hidden="true"></span>';
                }
            }

            echo '</div>';
            echo '<div class="must-hotel-booking-rooms-list-actions">';
            echo '<a class="must-hotel-booking-rooms-list-book" href="' . \esc_url($book_url) . '">';
            echo '<span class="must-hotel-booking-rooms-list-book-text">' . \esc_html__('Book Now', 'must-hotel-booking') . '</span>';
            echo '<img class="must-hotel-booking-rooms-list-book-icon" src="' . \esc_url($arrow_icon_url) . '" alt="" aria-hidden="true" />';
            echo '</a>';
            echo '<a class="must-hotel-booking-rooms-list-details" href="' . \esc_url($details_url) . '">';
            echo '<span class="must-hotel-booking-rooms-list-details-text">' . \esc_html__('Additional Details', 'must-hotel-booking') . '</span>';
            echo '<img class="must-hotel-booking-rooms-list-details-icon" src="' . \esc_url($bed_icon_url) . '" alt="" aria-hidden="true" />';
            echo '</a>';
            echo '</div>';
            echo '</div>';
            echo '</article>';
        }

        echo '</div>';
    }

}
