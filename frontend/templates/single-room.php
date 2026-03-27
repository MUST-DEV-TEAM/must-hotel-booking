<?php

if (!\defined('ABSPATH')) {
    exit;
}

$view = \must_hotel_booking\get_single_room_page_view_data();
$success = !empty($view['success']);
$rooms_url = isset($view['rooms_url']) ? (string) $view['rooms_url'] : '';
?>
<?php \get_header(); ?>
<main class="must-hotel-booking-page must-hotel-booking-page-single-room">
    <div class="must-hotel-booking-container must-hotel-booking-single-room">
        <?php if (!$success) : ?>
            <h1><?php echo \esc_html__('Room Details', 'must-hotel-booking'); ?></h1>
            <p><?php echo \esc_html((string) ($view['message'] ?? __('Room was not found.', 'must-hotel-booking'))); ?></p>
            <?php if ($rooms_url !== '') : ?>
                <p>
                    <a href="<?php echo \esc_url($rooms_url); ?>">
                        <?php echo \esc_html__('Back to Rooms', 'must-hotel-booking'); ?>
                    </a>
                </p>
            <?php endif; ?>
        <?php else : ?>
            <?php
            $room_title = isset($view['room_title']) ? (string) $view['room_title'] : '';
            $max_guests = isset($view['max_guests']) ? (int) $view['max_guests'] : 1;
            $room_size = isset($view['room_size']) ? (string) $view['room_size'] : '';
            $room_rules = isset($view['room_rules']) ? (string) $view['room_rules'] : '';
            $amenities_intro = isset($view['amenities_intro']) ? (string) $view['amenities_intro'] : '';
            $amenities = isset($view['amenities']) && \is_array($view['amenities']) ? $view['amenities'] : [];
            $main_image_url = isset($view['main_image_url']) ? (string) $view['main_image_url'] : '';
            $gallery_urls = isset($view['gallery_urls']) && \is_array($view['gallery_urls']) ? $view['gallery_urls'] : [];
            $booking_url = isset($view['booking_url']) ? (string) $view['booking_url'] : \home_url('/booking');
            $inquiry_url = isset($view['inquiry_url']) ? (string) $view['inquiry_url'] : '#';
            $terms_url = isset($view['terms_url']) ? (string) $view['terms_url'] : '#';
            $people_icon_url = isset($view['people_icon_url']) ? (string) $view['people_icon_url'] : '';
            $surface_icon_url = isset($view['surface_icon_url']) ? (string) $view['surface_icon_url'] : '';
            $arrow_icon_url = isset($view['arrow_icon_url']) ? (string) $view['arrow_icon_url'] : '';
            $bed_icon_url = isset($view['bed_icon_url']) ? (string) $view['bed_icon_url'] : '';
            $related_rooms = isset($view['related_rooms']) && \is_array($view['related_rooms']) ? $view['related_rooms'] : [];
            $category_label = isset($view['category_label']) ? (string) $view['category_label'] : '';
            $included_accommodations_title = isset($view['included_accommodations_title']) ? (string) $view['included_accommodations_title'] : '';
            $included_accommodations_kicker = isset($view['included_accommodations_kicker']) ? (string) $view['included_accommodations_kicker'] : '';
            $included_accommodations = isset($view['included_accommodations']) && \is_array($view['included_accommodations']) ? $view['included_accommodations'] : [];
            $lightbox_urls = [];

            if ($main_image_url !== '') {
                $lightbox_urls[] = $main_image_url;
            }

            foreach ($gallery_urls as $gallery_url) {
                $gallery_url = (string) $gallery_url;

                if ($gallery_url === '') {
                    continue;
                }

                $lightbox_urls[] = $gallery_url;
            }

            $lightbox_urls = \array_values(\array_unique($lightbox_urls));
            $visible_thumb_urls = \array_slice($gallery_urls, 0, 3);
            $lightbox_images_json = \wp_json_encode($lightbox_urls);
            $lightbox_images_attr = \is_string($lightbox_images_json) ? \esc_attr($lightbox_images_json) : '[]';
            ?>

            <div class="must-hotel-booking-single-room-grid">
                <section class="must-hotel-booking-single-room-content">
                    <h1 class="must-hotel-booking-single-room-title"><?php echo \esc_html($room_title); ?></h1>

                    <div class="must-hotel-booking-single-room-actions">
                        <a class="must-hotel-booking-single-room-action-link" href="<?php echo \esc_url($booking_url); ?>">
                            <span><?php echo \esc_html__('Book Now', 'must-hotel-booking'); ?></span>
                            <?php if ($arrow_icon_url !== '') : ?>
                                <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                            <?php endif; ?>
                        </a>
                        <a class="must-hotel-booking-single-room-action-link" href="<?php echo \esc_url($inquiry_url); ?>">
                            <span><?php echo \esc_html__('Make an inquiry', 'must-hotel-booking'); ?></span>
                            <?php if ($arrow_icon_url !== '') : ?>
                                <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                            <?php endif; ?>
                        </a>
                    </div>

                    <div class="must-hotel-booking-single-room-meta">
                        <p>
                            <?php if ($people_icon_url !== '') : ?>
                                <img src="<?php echo \esc_url($people_icon_url); ?>" alt="" aria-hidden="true" />
                            <?php endif; ?>
                            <span>
                                <?php
                                echo \esc_html(
                                    \sprintf(
                                        /* translators: %d is maximum room guests. */
                                        __('%d Guests', 'must-hotel-booking'),
                                        $max_guests
                                    )
                                );
                                ?>
                            </span>
                        </p>
                        <?php if ($room_size !== '') : ?>
                            <p>
                                <?php if ($surface_icon_url !== '') : ?>
                                    <img src="<?php echo \esc_url($surface_icon_url); ?>" alt="" aria-hidden="true" />
                                <?php endif; ?>
                                <span><?php echo \esc_html($room_size); ?></span>
                            </p>
                        <?php endif; ?>
                    </div>

                    <section class="must-hotel-booking-single-room-section">
                        <h2><?php echo \esc_html__('Room Rules', 'must-hotel-booking'); ?></h2>
                        <?php if ($room_rules !== '') : ?>
                            <p><?php echo \wp_kses_post(\nl2br(\esc_html($room_rules))); ?></p>
                        <?php else : ?>
                            <p><?php echo \esc_html__('Room rules have not been provided yet.', 'must-hotel-booking'); ?></p>
                        <?php endif; ?>
                    </section>

                    <?php if ($amenities_intro !== '' || !empty($amenities)) : ?>
                        <section class="must-hotel-booking-single-room-section">
                            <h2><?php echo \esc_html__('Amenities', 'must-hotel-booking'); ?></h2>
                            <?php if ($amenities_intro !== '') : ?>
                                <p><?php echo \wp_kses_post(\nl2br(\esc_html($amenities_intro))); ?></p>
                            <?php endif; ?>

                            <?php if (!empty($amenities)) : ?>
                                <div class="must-hotel-booking-single-room-amenities-grid">
                                    <?php foreach ($amenities as $amenity) : ?>
                                        <?php
                                        $amenity_label = isset($amenity['label']) ? (string) $amenity['label'] : '';
                                        $amenity_icon = isset($amenity['icon']) ? (string) $amenity['icon'] : '';
                                        ?>
                                        <?php if ($amenity_label !== '') : ?>
                                            <div class="must-hotel-booking-single-room-amenity-item">
                                                <?php if ($amenity_icon !== '') : ?>
                                                    <span class="must-hotel-booking-single-room-amenity-icon-wrap">
                                                        <img src="<?php echo \esc_url($amenity_icon); ?>" alt="" aria-hidden="true" />
                                                    </span>
                                                <?php endif; ?>
                                                <span><?php echo \esc_html($amenity_label); ?></span>
                                            </div>
                                        <?php endif; ?>
                                    <?php endforeach; ?>
                                </div>
                            <?php endif; ?>
                        </section>
                    <?php endif; ?>

                    <a class="must-hotel-booking-single-room-terms-link" href="<?php echo \esc_url($terms_url); ?>">
                        <span><?php echo \esc_html__('Terms and Conditions', 'must-hotel-booking'); ?></span>
                        <?php if ($arrow_icon_url !== '') : ?>
                            <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                        <?php endif; ?>
                    </a>
                </section>

                <section
                    class="must-hotel-booking-single-room-media"
                    data-lightbox-images="<?php echo $lightbox_images_attr; ?>"
                    data-lightbox-title="<?php echo \esc_attr($room_title); ?>"
                >
                    <?php if ($main_image_url !== '') : ?>
                        <figure class="must-hotel-booking-single-room-main-media">
                            <button
                                type="button"
                                class="must-hotel-booking-single-room-main-image-trigger"
                                data-lightbox-index="0"
                                aria-label="<?php echo \esc_attr__('Open room gallery', 'must-hotel-booking'); ?>"
                            >
                                <img
                                    id="must-single-room-main-image"
                                    class="must-hotel-booking-single-room-main-image"
                                    src="<?php echo \esc_url($main_image_url); ?>"
                                    alt="<?php echo \esc_attr($room_title); ?>"
                                    loading="lazy"
                                />
                            </button>
                        </figure>
                    <?php else : ?>
                        <div class="must-hotel-booking-single-room-image-placeholder">
                            <?php echo \esc_html__('Gallery coming soon.', 'must-hotel-booking'); ?>
                        </div>
                    <?php endif; ?>

                    <?php if (!empty($visible_thumb_urls)) : ?>
                        <div class="must-hotel-booking-single-room-thumbs" aria-label="<?php echo \esc_attr__('Room Gallery', 'must-hotel-booking'); ?>">
                            <?php foreach ($visible_thumb_urls as $thumb_url) : ?>
                                <?php
                                $thumb_url = (string) $thumb_url;
                                $thumb_index = \array_search($thumb_url, $lightbox_urls, true);
                                $thumb_index = \is_int($thumb_index) ? $thumb_index : 0;
                                ?>
                                <button
                                    type="button"
                                    class="must-hotel-booking-single-room-thumb-button"
                                    data-lightbox-index="<?php echo \esc_attr((string) $thumb_index); ?>"
                                    aria-label="<?php echo \esc_attr__('Open room gallery', 'must-hotel-booking'); ?>"
                                >
                                    <img src="<?php echo \esc_url($thumb_url); ?>" alt="" loading="lazy" />
                                </button>
                            <?php endforeach; ?>

                            <?php if (\count($visible_thumb_urls) < 3) : ?>
                                <?php for ($i = \count($visible_thumb_urls); $i < 3; $i++) : ?>
                                    <span class="must-hotel-booking-single-room-thumb-placeholder" aria-hidden="true"></span>
                                <?php endfor; ?>
                            <?php endif; ?>
                        </div>
                    <?php endif; ?>
                </section>
            </div>

            <?php if (!empty($related_rooms)) : ?>
                <section class="must-hotel-booking-related-rooms-section" aria-label="<?php echo \esc_attr__('Related Rooms', 'must-hotel-booking'); ?>">
                    <div class="must-hotel-booking-related-rooms-inner">
                        <p class="must-hotel-booking-related-rooms-kicker">
                            <?php
                            $kicker_label = $category_label !== '' ? $category_label : \__('Rooms', 'must-hotel-booking');
                            echo \esc_html('/ ' . $kicker_label);
                            ?>
                        </p>

                        <div class="must-hotel-booking-related-rooms-grid">
                            <?php foreach ($related_rooms as $related_room) : ?>
                                <?php
                                if (!\is_array($related_room)) {
                                    continue;
                                }

                                $related_room_name = isset($related_room['name']) ? (string) $related_room['name'] : '';
                                $related_room_url = isset($related_room['permalink']) ? (string) $related_room['permalink'] : '';
                                $related_room_booking_url = isset($related_room['booking_url']) ? (string) $related_room['booking_url'] : $booking_url;
                                $related_room_images = isset($related_room['images']) && \is_array($related_room['images']) ? $related_room['images'] : [];
                                $related_room_cover_image = isset($related_room['cover_image']) ? (string) $related_room['cover_image'] : '';
                                $related_room_images_json = \wp_json_encode($related_room_images);
                                $related_room_images_attr = \is_string($related_room_images_json) ? \esc_attr($related_room_images_json) : '[]';
                                $is_multi_image = \count($related_room_images) > 1;
                                ?>
                                <article
                                    class="must-hotel-booking-related-room-card"
                                    data-related-room-images="<?php echo $related_room_images_attr; ?>"
                                    data-related-room-title="<?php echo \esc_attr($related_room_name); ?>"
                                >
                                    <div class="must-hotel-booking-related-room-media">
                                        <?php if ($related_room_cover_image !== '') : ?>
                                            <button
                                                type="button"
                                                class="must-hotel-booking-related-room-image-trigger"
                                                data-related-room-open="1"
                                                aria-label="<?php echo \esc_attr__('Open room gallery', 'must-hotel-booking'); ?>"
                                            >
                                                <img
                                                    class="must-hotel-booking-related-room-image"
                                                    src="<?php echo \esc_url($related_room_cover_image); ?>"
                                                    alt="<?php echo \esc_attr($related_room_name); ?>"
                                                    loading="lazy"
                                                />
                                            </button>
                                        <?php else : ?>
                                            <div class="must-hotel-booking-related-room-image-placeholder">
                                                <?php echo \esc_html__('No image', 'must-hotel-booking'); ?>
                                            </div>
                                        <?php endif; ?>

                                        <?php if ($is_multi_image) : ?>
                                            <button
                                                type="button"
                                                class="must-hotel-booking-related-room-arrow must-hotel-booking-related-room-arrow-prev"
                                                data-related-room-direction="prev"
                                                aria-label="<?php echo \esc_attr__('Previous image', 'must-hotel-booking'); ?>"
                                            >
                                                <?php if ($arrow_icon_url !== '') : ?>
                                                    <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                                                <?php endif; ?>
                                            </button>
                                            <button
                                                type="button"
                                                class="must-hotel-booking-related-room-arrow must-hotel-booking-related-room-arrow-next"
                                                data-related-room-direction="next"
                                                aria-label="<?php echo \esc_attr__('Next image', 'must-hotel-booking'); ?>"
                                            >
                                                <?php if ($arrow_icon_url !== '') : ?>
                                                    <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                                                <?php endif; ?>
                                            </button>
                                        <?php endif; ?>
                                    </div>

                                    <div class="must-hotel-booking-related-room-content">
                                        <h3><?php echo \esc_html($related_room_name); ?></h3>
                                        <div class="must-hotel-booking-related-room-actions">
                                            <a class="must-hotel-booking-related-room-book" href="<?php echo \esc_url($related_room_booking_url); ?>">
                                                <span><?php echo \esc_html__('Book Now', 'must-hotel-booking'); ?></span>
                                                <?php if ($arrow_icon_url !== '') : ?>
                                                    <img src="<?php echo \esc_url($arrow_icon_url); ?>" alt="" aria-hidden="true" />
                                                <?php endif; ?>
                                            </a>
                                            <?php if ($related_room_url !== '') : ?>
                                                <a class="must-hotel-booking-related-room-details" href="<?php echo \esc_url($related_room_url); ?>">
                                                    <span><?php echo \esc_html__('Additional Details', 'must-hotel-booking'); ?></span>
                                                    <?php if ($bed_icon_url !== '') : ?>
                                                        <img src="<?php echo \esc_url($bed_icon_url); ?>" alt="" aria-hidden="true" />
                                                    <?php endif; ?>
                                                </a>
                                            <?php endif; ?>
                                        </div>
                                    </div>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>

            <?php if (!empty($included_accommodations)) : ?>
                <section class="must-hotel-booking-included-accommodations-section" aria-label="<?php echo \esc_attr__('Included accommodations', 'must-hotel-booking'); ?>">
                    <div class="must-hotel-booking-included-accommodations-inner">
                        <p class="must-hotel-booking-included-accommodations-kicker">
                            <?php
                            $accommodation_kicker = $included_accommodations_kicker !== '' ? $included_accommodations_kicker : 'ACCOMODATIONS';
                            echo \esc_html('/ ' . $accommodation_kicker);
                            ?>
                        </p>

                        <h2 class="must-hotel-booking-included-accommodations-title">
                            <?php
                            echo \esc_html(
                                $included_accommodations_title !== ''
                                    ? $included_accommodations_title
                                    : \__('Included for all', 'must-hotel-booking')
                            );
                            ?>
                        </h2>

                        <div class="must-hotel-booking-included-accommodations-grid">
                            <?php foreach ($included_accommodations as $included_item) : ?>
                                <?php
                                if (!\is_array($included_item)) {
                                    continue;
                                }

                                $included_item_label = isset($included_item['label']) ? (string) $included_item['label'] : '';
                                $included_item_icon_url = isset($included_item['icon_url']) ? (string) $included_item['icon_url'] : '';

                                if ($included_item_label === '') {
                                    continue;
                                }
                                ?>
                                <article class="must-hotel-booking-included-accommodations-card">
                                    <span class="must-hotel-booking-included-accommodations-icon-wrap">
                                        <?php if ($included_item_icon_url !== '') : ?>
                                            <img src="<?php echo \esc_url($included_item_icon_url); ?>" alt="" aria-hidden="true" />
                                        <?php endif; ?>
                                    </span>
                                    <h3><?php echo \esc_html($included_item_label); ?></h3>
                                </article>
                            <?php endforeach; ?>
                        </div>
                    </div>
                </section>
            <?php endif; ?>
        <?php endif; ?>
    </div>
</main>
<?php \get_footer(); ?>
