<?php

namespace MustHotelBooking\Core;

use MustHotelBooking\Database\AccommodationCategoryUpgradeService;

final class RoomCatalog
{
    public const BOOKING_ALL_CATEGORY = 'all';

    /** @var array<string, string>|null */
    private static ?array $categories = null;

    /**
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        if (\is_array(self::$categories)) {
            return self::$categories;
        }

        $categories = \MustHotelBooking\Engine\get_room_category_repository()->getCategoryOptions();

        if (empty($categories)) {
            $categories = AccommodationCategoryUpgradeService::getLegacyDefaultCategories();
        }

        self::$categories = $categories;

        return self::$categories;
    }

    public static function getDefaultCategory(): string
    {
        $categories = self::getCategories();

        if (isset($categories['standard-rooms'])) {
            return 'standard-rooms';
        }

        if (!empty($categories)) {
            return (string) \array_key_first($categories);
        }

        return 'standard-rooms';
    }

    /**
     * @return array<string, string>
     */
    public static function getBookingCategories(): array
    {
        return [
            self::BOOKING_ALL_CATEGORY => \__('ALL', 'must-hotel-booking'),
        ] + self::getCategories();
    }

    public static function normalizeCategory(string $category): string
    {
        $normalized = \sanitize_key($category);
        $categories = self::getCategories();

        if (isset($categories[$normalized])) {
            return $normalized;
        }

        return self::getDefaultCategory();
    }

    public static function normalizeBookingCategory(string $category): string
    {
        $normalized = \sanitize_key($category);

        if ($normalized === self::BOOKING_ALL_CATEGORY) {
            return self::BOOKING_ALL_CATEGORY;
        }

        return self::normalizeCategory($normalized);
    }

    public static function getCategoryLabel(string $category): string
    {
        $categories = self::getCategories();
        $slug = self::normalizeCategory($category);

        if (isset($categories[$slug])) {
            return (string) $categories[$slug];
        }

        $defaultCategory = self::getDefaultCategory();

        return isset($categories[$defaultCategory])
            ? (string) $categories[$defaultCategory]
            : (string) \ucwords(\str_replace(['-', '_'], ' ', $slug));
    }

    public static function getBookingCategoryLabel(string $category): string
    {
        $categories = self::getBookingCategories();
        $slug = self::normalizeBookingCategory($category);

        if (isset($categories[$slug])) {
            return (string) $categories[$slug];
        }

        $defaultCategory = self::getDefaultCategory();

        return isset($categories[$defaultCategory])
            ? (string) $categories[$defaultCategory]
            : (string) \ucwords(\str_replace(['-', '_'], ' ', $slug));
    }

    public static function isBookingAllCategory(string $category): bool
    {
        return self::normalizeBookingCategory($category) === self::BOOKING_ALL_CATEGORY;
    }

    /**
     * @return array<string, array<string, string>>
     */
    public static function getAvailableAmenities(): array
    {
        return [
            'air-conditioning' => [
                'label' => \__('Air conditioning', 'must-hotel-booking'),
                'icon' => 'airconditioning.svg',
            ],
            'cable-channels' => [
                'label' => \__('Cable channels', 'must-hotel-booking'),
                'icon' => 'cablechannels.svg',
            ],
            'refrigerator' => [
                'label' => \__('Refrigerator', 'must-hotel-booking'),
                'icon' => 'refrigerator.svg',
            ],
            'flat-screen-tv' => [
                'label' => \__('Flat-screen TV', 'must-hotel-booking'),
                'icon' => 'flatscreentv.svg',
            ],
            'linen' => [
                'label' => \__('Linen', 'must-hotel-booking'),
                'icon' => 'linen.svg',
            ],
            'telephone' => [
                'label' => \__('Telephone', 'must-hotel-booking'),
                'icon' => 'telephone.svg',
            ],
            'dryer' => [
                'label' => \__('Dryer', 'must-hotel-booking'),
                'icon' => 'dryer.svg',
            ],
            'streaming' => [
                'label' => \__('Streaming', 'must-hotel-booking'),
                'icon' => 'streaming.svg',
            ],
            'safety-deposit-box' => [
                'label' => \__('Safety deposit box', 'must-hotel-booking'),
                'icon' => 'safetydepositbox.svg',
            ],
        ];
    }

    public static function normalizeAmenityKey(string $value): string
    {
        $rawValue = \sanitize_text_field($value);

        if ($rawValue === '') {
            return '';
        }

        $available = self::getAvailableAmenities();
        $candidate = \sanitize_key(\str_replace('_', '-', $rawValue));

        if (isset($available[$candidate])) {
            return $candidate;
        }

        $labelMap = [];

        foreach ($available as $key => $option) {
            $label = isset($option['label']) ? (string) $option['label'] : '';
            $labelKey = \sanitize_key(\str_replace([' ', '_'], '-', $label));

            if ($labelKey !== '') {
                $labelMap[$labelKey] = $key;
            }
        }

        return isset($labelMap[$candidate]) ? (string) $labelMap[$candidate] : '';
    }
}
