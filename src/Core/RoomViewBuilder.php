<?php

namespace MustHotelBooking\Core;

final class RoomViewBuilder
{
    /**
     * @param array<string, mixed> $room
     * @return array<string, mixed>|null
     */
    public static function buildBookingResultsRoomViewData(array $room): ?array
    {
        $roomId = isset($room['id']) ? (int) $room['id'] : 0;

        if ($roomId <= 0) {
            return null;
        }

        $roomSlug = isset($room['slug']) ? (string) $room['slug'] : '';
        $roomCategory = isset($room['category']) ? (string) $room['category'] : 'standard-rooms';
        $currency = MustBookingConfig::get_currency();
        $primaryImageUrl = RoomData::getRoomMainImageUrl($roomId, 'large');
        $galleryImages = RoomData::getRoomGalleryImageUrls($roomId, 12, 'large');
        $galleryImages = \array_values(
            \array_filter(
                \array_map('strval', \is_array($galleryImages) ? $galleryImages : []),
                static function (string $url): bool {
                    return $url !== '';
                }
            )
        );

        if ($primaryImageUrl === '' && !empty($galleryImages)) {
            $primaryImageUrl = (string) \array_shift($galleryImages);
        }

        $lightboxImages = [];

        if ($primaryImageUrl !== '') {
            $lightboxImages[] = $primaryImageUrl;
        }

        foreach ($galleryImages as $galleryImage) {
            $galleryImage = (string) $galleryImage;

            if ($galleryImage !== '') {
                $lightboxImages[] = $galleryImage;
            }
        }

        $lightboxImages = \array_values(\array_unique($lightboxImages));
        $galleryImages = \array_values(
            \array_filter(
                \array_unique($galleryImages),
                static function (string $url) use ($primaryImageUrl): bool {
                    return $url !== '' && $url !== $primaryImageUrl;
                }
            )
        );
        $galleryImages = \array_slice($galleryImages, 0, 3);
        $detailsUrl = $roomSlug !== ''
            ? \add_query_arg(['room' => \sanitize_title($roomSlug)], ManagedPages::getRoomsPageUrl())
            : ManagedPages::getRoomsPageUrl();

        return [
            'id' => $roomId,
            'name' => isset($room['name']) ? (string) $room['name'] : '',
            'slug' => $roomSlug,
            'category' => $roomCategory,
            'category_label' => RoomCatalog::getCategoryLabel($roomCategory),
            'description' => isset($room['description']) ? (string) $room['description'] : '',
            'max_guests' => isset($room['max_guests']) ? (int) $room['max_guests'] : 0,
            'effective_max_guests' => isset($room['effective_max_guests'])
                ? (int) $room['effective_max_guests']
                : (isset($room['max_guests']) ? (int) $room['max_guests'] : 0),
            'available_count' => isset($room['available_count']) ? \max(0, (int) $room['available_count']) : 0,
            'base_price' => isset($room['base_price']) ? (float) $room['base_price'] : 0.0,
            'selected_rate_plan_id' => isset($room['selected_rate_plan_id']) ? (int) $room['selected_rate_plan_id'] : 0,
            'selected_rate_plan_name' => isset($room['selected_rate_plan_name']) ? (string) $room['selected_rate_plan_name'] : '',
            'selected_rate_plan_description' => isset($room['selected_rate_plan_description']) ? (string) $room['selected_rate_plan_description'] : '',
            'selected_rate_plan_max_occupancy' => isset($room['selected_rate_plan_max_occupancy']) ? (int) $room['selected_rate_plan_max_occupancy'] : 0,
            'rate_plans' => isset($room['rate_plans']) && \is_array($room['rate_plans']) ? $room['rate_plans'] : [],
            'room_size' => isset($room['room_size']) ? (string) $room['room_size'] : '',
            'beds' => isset($room['beds']) ? (string) $room['beds'] : '',
            'currency' => $currency,
            'price_preview_total' => isset($room['price_preview_total']) ? (float) $room['price_preview_total'] : null,
            'dynamic_total_price' => isset($room['dynamic_total_price']) ? (float) $room['dynamic_total_price'] : null,
            'dynamic_room_subtotal' => isset($room['dynamic_room_subtotal']) ? (float) $room['dynamic_room_subtotal'] : null,
            'dynamic_nights' => isset($room['dynamic_nights']) ? (int) $room['dynamic_nights'] : null,
            'details_url' => $detailsUrl,
            'primary_image_url' => $primaryImageUrl,
            'gallery_images' => \array_slice($galleryImages, 0, 3),
            'lightbox_images' => $lightboxImages,
            'room_rules' => RoomData::getRoomRulesText($roomId),
            'amenities' => RoomData::getRoomAmenityDisplayItems($roomId),
            'people_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/PeopleFill.svg',
            'surface_icon_url' => MUST_HOTEL_BOOKING_URL . 'assets/img/Surface.svg',
        ];
    }
}
