<?php

if (!\defined('ABSPATH')) {
    exit;
}

$view = \must_hotel_booking\get_accommodation_page_view_data();
$messages = isset($view['messages']) && \is_array($view['messages']) ? $view['messages'] : [];
$rooms = isset($view['rooms']) && \is_array($view['rooms']) ? $view['rooms'] : [];
$has_context = !empty($view['has_context']);
$is_valid = !empty($view['is_valid']);
$checkin = isset($view['checkin']) ? (string) $view['checkin'] : '';
$checkout = isset($view['checkout']) ? (string) $view['checkout'] : '';
$guests = isset($view['guests']) ? (int) $view['guests'] : 1;
$accommodation_type = isset($view['accommodation_type']) ? (string) $view['accommodation_type'] : 'standard-rooms';
$selected_room_count = isset($view['selected_room_count']) ? (int) $view['selected_room_count'] : 0;
$booking_url = isset($view['booking_url']) ? (string) $view['booking_url'] : \home_url('/booking');
$checkout_url = isset($view['checkout_url']) ? (string) $view['checkout_url'] : \home_url('/checkout');
$accommodation_url = isset($view['accommodation_url']) ? (string) $view['accommodation_url'] : \home_url('/booking-accommodation');
$arrow_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowRight.svg' : '';
$back_arrow_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/ArrowLEFT.svg' : '';
$filter_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/poludown.svg' : '';
$bed_icon_url = \defined('MUST_HOTEL_BOOKING_URL') ? MUST_HOTEL_BOOKING_URL . 'assets/img/bed.svg' : '';
$results_date_range = \function_exists('\must_hotel_booking\format_booking_results_date_range')
    ? \must_hotel_booking\format_booking_results_date_range($checkin, $checkout)
    : \__('Select dates', 'must-hotel-booking');
$results_selection_summary = \function_exists('\must_hotel_booking\format_booking_results_selection_summary')
    ? \must_hotel_booking\format_booking_results_selection_summary($accommodation_type, $guests)
    : \__('Standard Rooms / 1 Guests', 'must-hotel-booking');
?>
<?php \get_header(); ?>
<main class="must-hotel-booking-page must-hotel-booking-page-booking must-hotel-booking-page-accommodation must-booking-process-page">
    <div class="must-hotel-booking-container">
        <section class="must-booking-step-header">
            <h1 id="must-booking-step-heading"><?php echo \esc_html__('Available Accommodation', 'must-hotel-booking'); ?></h1>

            <div class="must-booking-stepper-wrap" aria-label="<?php echo \esc_attr__('Booking steps', 'must-hotel-booking'); ?>">
                <div class="must-booking-stepper">
                    <a href="<?php echo \esc_url($booking_url); ?>" class="must-booking-stepper-nav is-back">
                        <?php if ($back_arrow_icon_url !== '') : ?>
                            <img src="<?php echo \esc_url($back_arrow_icon_url); ?>" alt="" aria-hidden="true" />
                        <?php endif; ?>
                        <span><?php echo \esc_html__('Back', 'must-hotel-booking'); ?></span>
                    </a>
                    <a href="<?php echo \esc_url($booking_url); ?>" class="must-booking-stepper-step is-link" data-step="1"><?php echo \esc_html__('Calendar', 'must-hotel-booking'); ?></a>
                    <span class="must-booking-stepper-step is-active" data-step="2"><?php echo \esc_html__('Select Accommodation', 'must-hotel-booking'); ?></span>
                    <span class="must-booking-stepper-step" data-step="3"><?php echo \esc_html__('Guest Information', 'must-hotel-booking'); ?></span>
                    <span class="must-booking-stepper-step" data-step="4"><?php echo \esc_html__('Confirmation', 'must-hotel-booking'); ?></span>
                    <?php if ($selected_room_count > 0) : ?>
                        <a href="<?php echo \esc_url($checkout_url); ?>" class="must-booking-stepper-nav is-next">
                            <span><?php echo \esc_html__('Next', 'must-hotel-booking'); ?></span>
                            <?php if ($arrow_icon_url !== '') : ?>
                                <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                            <?php endif; ?>
                        </a>
                    <?php else : ?>
                        <span class="must-booking-stepper-nav is-next" aria-disabled="true">
                            <span><?php echo \esc_html__('Next', 'must-hotel-booking'); ?></span>
                            <?php if ($arrow_icon_url !== '') : ?>
                                <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                            <?php endif; ?>
                        </span>
                    <?php endif; ?>
                </div>
            </div>
        </section>

        <div id="must-booking-live-messages" class="must-hotel-booking-messages">
            <?php if (!empty($messages)) : ?>
                <?php foreach ($messages as $message) : ?>
                    <p><?php echo \esc_html((string) $message); ?></p>
                <?php endforeach; ?>
            <?php endif; ?>
        </div>

        <?php if (!$has_context || !$is_valid) : ?>
            <p>
                <a href="<?php echo \esc_url($booking_url); ?>">
                    <?php echo \esc_html__('Return to the calendar to choose valid dates.', 'must-hotel-booking'); ?>
                </a>
            </p>
        <?php else : ?>
            <section id="must-booking-results" class="must-hotel-booking-results">
                <div class="must-booking-results-toolbar">
                    <a href="<?php echo \esc_url($booking_url); ?>" class="must-booking-results-filter">
                        <span><?php echo \esc_html($results_date_range); ?></span>
                        <?php if ($filter_icon_url !== '') : ?>
                            <img src="<?php echo \esc_url($filter_icon_url); ?>" alt="" aria-hidden="true" />
                        <?php endif; ?>
                    </a>

                    <a href="<?php echo \esc_url($booking_url); ?>" class="must-booking-results-filter">
                        <span><?php echo \esc_html($results_selection_summary); ?></span>
                        <?php if ($filter_icon_url !== '') : ?>
                            <img src="<?php echo \esc_url($filter_icon_url); ?>" alt="" aria-hidden="true" />
                        <?php endif; ?>
                    </a>

                    <?php if ($selected_room_count > 0) : ?>
                        <a href="<?php echo \esc_url($checkout_url); ?>" class="must-booking-results-filter">
                            <span>
                                <?php
                                echo \esc_html(
                                    \sprintf(
                                        /* translators: %d is selected room count. */
                                        __('%d Room(s) Selected', 'must-hotel-booking'),
                                        $selected_room_count
                                    )
                                );
                                ?>
                            </span>
                            <?php if ($arrow_icon_url !== '') : ?>
                                <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                            <?php endif; ?>
                        </a>
                    <?php endif; ?>
                </div>

                <?php if (empty($rooms)) : ?>
                    <p id="must-booking-no-rooms-message">
                        <?php echo \esc_html__('No rooms are available for the selected dates.', 'must-hotel-booking'); ?>
                    </p>
                <?php else : ?>
                    <div id="must-booking-room-list" class="must-hotel-booking-room-list">
                        <?php foreach ($rooms as $room) : ?>
                            <?php
                            $room_id = isset($room['id']) ? (int) $room['id'] : 0;
                            $room_name = isset($room['name']) ? (string) $room['name'] : '';
                            $room_description = isset($room['description']) ? (string) $room['description'] : '';
                            $max_guests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;
                            $room_size = isset($room['room_size']) ? (string) $room['room_size'] : '';
                            $base_price = isset($room['base_price']) ? (float) $room['base_price'] : 0.0;
                            $currency = isset($room['currency']) ? (string) $room['currency'] : 'USD';
                            $primary_image_url = isset($room['primary_image_url']) ? (string) $room['primary_image_url'] : '';
                            $gallery_images = isset($room['gallery_images']) && \is_array($room['gallery_images']) ? $room['gallery_images'] : [];
                            $lightbox_images = isset($room['lightbox_images']) && \is_array($room['lightbox_images']) ? $room['lightbox_images'] : [];
                            $room_rules = isset($room['room_rules']) ? (string) $room['room_rules'] : '';
                            $amenities = isset($room['amenities']) && \is_array($room['amenities']) ? $room['amenities'] : [];
                            $people_icon_url = isset($room['people_icon_url']) ? (string) $room['people_icon_url'] : '';
                            $surface_icon_url = isset($room['surface_icon_url']) ? (string) $room['surface_icon_url'] : '';
                            $lightbox_json = \wp_json_encode($lightbox_images);
                            $lightbox_attr = \is_string($lightbox_json) ? \esc_attr($lightbox_json) : '[]';
                            $room_rules_lines = \preg_split('/\r\n|\r|\n/', $room_rules) ?: [];
                            $room_rules_lines = \array_values(
                                \array_filter(
                                    \array_map(
                                        static function ($line): string {
                                            return \trim((string) $line);
                                        },
                                        $room_rules_lines
                                    ),
                                    static function (string $line): bool {
                                        return $line !== '';
                                    }
                                )
                            );

                            if (empty($room_rules_lines) && $room_rules !== '') {
                                $room_rules_lines[] = $room_rules;
                            }

                            $nightly_price = \must_hotel_booking\format_frontend_money($base_price, $currency);
                            ?>
                            <article
                                class="must-hotel-booking-room-card must-booking-accommodation-room-card"
                                data-lightbox-images="<?php echo $lightbox_attr; ?>"
                                data-lightbox-title="<?php echo \esc_attr($room_name); ?>"
                            >
                                <div class="must-booking-room-media">
                                    <?php if ($primary_image_url !== '') : ?>
                                        <button type="button" class="must-booking-room-image-trigger must-booking-room-image-trigger-main" data-lightbox-index="0">
                                            <img src="<?php echo \esc_url($primary_image_url); ?>" alt="<?php echo \esc_attr($room_name); ?>" loading="lazy" />
                                        </button>
                                    <?php else : ?>
                                        <div class="must-booking-room-media-placeholder"><?php echo \esc_html__('Add room image in admin', 'must-hotel-booking'); ?></div>
                                    <?php endif; ?>
                                </div>

                                <div class="must-booking-room-content">
                                    <div class="must-booking-room-header">
                                        <h3><?php echo \esc_html($room_name !== '' ? $room_name : __('Room', 'must-hotel-booking')); ?></h3>

                                        <?php if ($room_description !== '') : ?>
                                            <p class="must-booking-room-description"><?php echo \esc_html($room_description); ?></p>
                                        <?php endif; ?>
                                    </div>

                                    <div class="must-booking-room-thumbs">
                                        <?php if (!empty($gallery_images)) : ?>
                                            <?php foreach (\array_slice($gallery_images, 0, 3) as $gallery_image) : ?>
                                                <?php
                                                $gallery_image = (string) $gallery_image;
                                                $thumb_index = \array_search($gallery_image, $lightbox_images, true);
                                                $thumb_index = $thumb_index === false ? 0 : (int) $thumb_index;
                                                ?>
                                                <button type="button" class="must-booking-room-thumb must-booking-room-image-trigger" data-lightbox-index="<?php echo \esc_attr((string) $thumb_index); ?>">
                                                    <img src="<?php echo \esc_url($gallery_image); ?>" alt="" loading="lazy" />
                                                </button>
                                            <?php endforeach; ?>
                                        <?php endif; ?>

                                        <?php if (\count($gallery_images) < 3) : ?>
                                            <?php for ($i = \count($gallery_images); $i < 3; $i++) : ?>
                                                <span class="must-booking-room-thumb is-placeholder" aria-hidden="true"></span>
                                            <?php endfor; ?>
                                        <?php endif; ?>
                                    </div>

                                    <div class="must-booking-room-actions">
                                        <form class="must-hotel-booking-select-room-form" method="post" action="<?php echo \esc_url($accommodation_url); ?>">
                                            <?php \wp_nonce_field('must_accommodation_select_room', 'must_accommodation_nonce'); ?>
                                            <input type="hidden" name="must_accommodation_action" value="select_room" />
                                            <input type="hidden" name="room_id" value="<?php echo \esc_attr((string) $room_id); ?>" />
                                            <input type="hidden" name="checkin" value="<?php echo \esc_attr($checkin); ?>" />
                                            <input type="hidden" name="checkout" value="<?php echo \esc_attr($checkout); ?>" />
                                            <input type="hidden" name="guests" value="<?php echo \esc_attr((string) $guests); ?>" />
                                            <input type="hidden" name="accommodation_type" value="<?php echo \esc_attr($accommodation_type); ?>" />
                                            <button type="submit" class="must-booking-room-book-button">
                                                <span><?php echo \esc_html__('Book Now', 'must-hotel-booking'); ?></span>
                                                <?php if ($arrow_icon_url !== '') : ?>
                                                    <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                                                <?php endif; ?>
                                            </button>
                                        </form>

                                        <button type="button" class="must-booking-room-details must-booking-room-modal-trigger" data-room-modal-id="must-booking-room-modal-template-<?php echo \esc_attr((string) $room_id); ?>">
                                            <span><?php echo \esc_html__('Additional Details', 'must-hotel-booking'); ?></span>
                                            <?php if ($bed_icon_url !== '') : ?>
                                                <img src="<?php echo \esc_url($bed_icon_url); ?>" alt="" aria-hidden="true" />
                                            <?php endif; ?>
                                        </button>
                                    </div>
                                </div>
                            </article>

                            <template id="must-booking-room-modal-template-<?php echo \esc_attr((string) $room_id); ?>">
                                <div class="must-booking-room-popup-layout">
                                    <div class="must-booking-room-popup-main">
                                        <?php if ($max_guests > 0 || $room_size !== '') : ?>
                                            <div class="must-booking-room-popup-meta">
                                                <?php if ($max_guests > 0) : ?>
                                                    <p>
                                                        <?php if ($people_icon_url !== '') : ?>
                                                            <img src="<?php echo \esc_url($people_icon_url); ?>" alt="" aria-hidden="true" />
                                                        <?php endif; ?>
                                                        <span>
                                                            <?php
                                                            echo \esc_html(
                                                                \sprintf(
                                                                    /* translators: %d is guest capacity. */
                                                                    __('%d Guests', 'must-hotel-booking'),
                                                                    $max_guests
                                                                )
                                                            );
                                                            ?>
                                                        </span>
                                                    </p>
                                                <?php endif; ?>

                                                <?php if ($room_size !== '') : ?>
                                                    <p>
                                                        <?php if ($surface_icon_url !== '') : ?>
                                                            <img src="<?php echo \esc_url($surface_icon_url); ?>" alt="" aria-hidden="true" />
                                                        <?php endif; ?>
                                                        <span><?php echo \esc_html($room_size); ?></span>
                                                    </p>
                                                <?php endif; ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if (!empty($room_rules_lines)) : ?>
                                            <section class="must-booking-room-popup-section">
                                                <h3><?php echo \esc_html__('Room Rules', 'must-hotel-booking'); ?></h3>
                                                <ul class="must-booking-room-popup-rules">
                                                    <?php foreach ($room_rules_lines as $room_rules_line) : ?>
                                                        <li><?php echo \esc_html($room_rules_line); ?></li>
                                                    <?php endforeach; ?>
                                                </ul>
                                            </section>
                                        <?php endif; ?>

                                        <section class="must-booking-room-popup-section">
                                            <h3><?php echo \esc_html($nightly_price . '/Night'); ?></h3>
                                            <p><?php echo \esc_html__('Including Taxes & Fees', 'must-hotel-booking'); ?></p>
                                        </section>
                                    </div>

                                    <div class="must-booking-room-popup-side">
                                        <section class="must-booking-room-popup-section">
                                            <h3><?php echo \esc_html__('Amenities', 'must-hotel-booking'); ?></h3>

                                            <?php if (!empty($amenities)) : ?>
                                                <div class="must-booking-room-popup-amenities">
                                                    <?php foreach ($amenities as $amenity) : ?>
                                                        <?php
                                                        $amenity_label = isset($amenity['label']) ? (string) $amenity['label'] : '';
                                                        $amenity_icon = isset($amenity['icon']) ? (string) $amenity['icon'] : '';
                                                        if ($amenity_label === '') {
                                                            continue;
                                                        }
                                                        ?>
                                                        <div class="must-booking-room-popup-amenity">
                                                            <span class="must-booking-room-popup-amenity-icon">
                                                                <?php if ($amenity_icon !== '') : ?>
                                                                    <img src="<?php echo \esc_url($amenity_icon); ?>" alt="" aria-hidden="true" />
                                                                <?php endif; ?>
                                                            </span>
                                                            <span><?php echo \esc_html($amenity_label); ?></span>
                                                        </div>
                                                    <?php endforeach; ?>
                                                </div>
                                            <?php else : ?>
                                                <p><?php echo \esc_html__('No amenities configured for this room yet.', 'must-hotel-booking'); ?></p>
                                            <?php endif; ?>
                                        </section>
                                    </div>
                                </div>
                            </template>
                        <?php endforeach; ?>
                    </div>
                <?php endif; ?>
            </section>
        <?php endif; ?>
    </div>
</main>
<?php \get_footer(); ?>
