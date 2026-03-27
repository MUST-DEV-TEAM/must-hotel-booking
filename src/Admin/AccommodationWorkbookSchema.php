<?php

namespace MustHotelBooking\Admin;

final class AccommodationWorkbookSchema
{
    public const SHEET_ACCOMMODATIONS = 'accommodations';

    /**
     * @return array<int, string>
     */
    public static function getAccommodationSheetColumns(): array
    {
        return [
            'id',
            'title',
            'accommodation_category',
            'description',
            'internal_code',
            'max_adults',
            'max_children',
            'max_guests',
            'default_occupancy',
            'base_price',
            'extra_guest_price',
            'size',
            'bed_type',
            'amenities',
            'amenities_intro',
            'room_rules',
            'sort_order',
            'active',
            'bookable',
            'online_bookable',
            'calendar_visible',
            'admin_notes',
        ];
    }

    public static function getAccommodationSheetName(): string
    {
        return self::SHEET_ACCOMMODATIONS;
    }

    public static function getWorkbookSheetName(): string
    {
        return self::getAccommodationSheetName();
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function getHeaderAliases(): array
    {
        return [
            'accommodation_category' => ['accommodation_category', 'accommodation_type'],
        ];
    }
}
