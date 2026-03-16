<?php

namespace MustHotelBooking\Core;

final class ManagedPages
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function getConfig(): array
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

    public static function install(): void
    {
        $settings = MustBookingConfig::get_all_settings();

        foreach (self::getConfig() as $settingKey => $pageConfig) {
            $existingPage = \get_page_by_path((string) $pageConfig['slug'], OBJECT, 'page');
            $pageId = 0;

            if ($existingPage instanceof \WP_Post) {
                $pageId = (int) $existingPage->ID;
            } else {
                $createdPageId = \wp_insert_post(
                    [
                        'post_title' => (string) $pageConfig['title'],
                        'post_name' => (string) $pageConfig['slug'],
                        'post_type' => 'page',
                        'post_status' => 'publish',
                        'post_content' => '',
                        'comment_status' => 'closed',
                        'ping_status' => 'closed',
                    ],
                    true
                );

                if (!\is_wp_error($createdPageId)) {
                    $pageId = (int) $createdPageId;
                }
            }

            if ($pageId > 0) {
                $settings[$settingKey] = $pageId;
            }
        }

        MustBookingConfig::set_all_settings($settings);
    }

    public static function sync(): void
    {
        $settings = MustBookingConfig::get_all_settings();

        foreach (self::getConfig() as $settingKey => $pageConfig) {
            unset($pageConfig);

            $pageId = isset($settings[$settingKey]) ? (int) $settings[$settingKey] : 0;

            if ($pageId > 0 && \get_post($pageId) instanceof \WP_Post) {
                continue;
            }

            self::install();
            return;
        }
    }

    public static function getAssignedPageId(string $settingKey): int
    {
        $settings = MustBookingConfig::get_all_settings();

        return isset($settings[$settingKey]) ? (int) $settings[$settingKey] : 0;
    }

    public static function getPageUrl(string $settingKey, string $fallbackPath): string
    {
        $pageId = self::getAssignedPageId($settingKey);

        if ($pageId > 0) {
            $permalink = \get_permalink($pageId);

            if (\is_string($permalink) && $permalink !== '') {
                return $permalink;
            }
        }

        return \home_url($fallbackPath);
    }

    public static function getRoomsPageUrl(): string
    {
        return self::getPageUrl('page_rooms_id', '/rooms');
    }

    public static function getBookingPageUrl(): string
    {
        return self::getPageUrl('page_booking_id', '/booking');
    }

    public static function getBookingAccommodationPageUrl(): string
    {
        return self::getPageUrl('page_booking_accommodation_id', '/booking-accommodation');
    }

    public static function getCheckoutPageUrl(): string
    {
        return self::getPageUrl('page_checkout_id', '/checkout');
    }

    public static function getBookingConfirmationPageUrl(): string
    {
        return self::getPageUrl('page_booking_confirmation_id', '/booking-confirmation');
    }

    public static function isCurrentPage(string $settingKey, string $fallbackSlug): bool
    {
        if (\is_admin()) {
            return false;
        }

        $pageId = self::getAssignedPageId($settingKey);

        if ($pageId > 0 && \is_page($pageId)) {
            return true;
        }

        return \is_page($fallbackSlug);
    }
}
