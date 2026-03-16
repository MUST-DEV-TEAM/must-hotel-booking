<?php

namespace MustHotelBooking\Core;

final class RoomCatalog
{
    /**
     * @return array<string, string>
     */
    public static function getCategories(): array
    {
        return [
            'standard-rooms' => \__('Standard Rooms', 'must-hotel-booking'),
            'suites' => \__('Suites', 'must-hotel-booking'),
            'duplex-suite' => \__('Duplex Suite', 'must-hotel-booking'),
        ];
    }

    public static function normalizeCategory(string $category): string
    {
        $normalized = \sanitize_key($category);
        $categories = self::getCategories();

        if (isset($categories[$normalized])) {
            return $normalized;
        }

        return 'standard-rooms';
    }

    public static function getCategoryLabel(string $category): string
    {
        $categories = self::getCategories();
        $slug = self::normalizeCategory($category);

        return isset($categories[$slug]) ? (string) $categories[$slug] : (string) $categories['standard-rooms'];
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
