<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\RoomCatalog;

final class AccommodationWorkbookSchema
{
    public const SHEET_TYPES = 'accommodation_types';
    public const SHEET_UNITS = 'accommodation_units';

    /**
     * @return array<string, string>
     */
    public static function getAmenityColumnMap(): array
    {
        $columns = [];

        foreach (RoomCatalog::getAvailableAmenities() as $amenityKey => $meta) {
            unset($meta);
            $columns['amenity_' . \str_replace('-', '_', (string) $amenityKey)] = (string) $amenityKey;
        }

        return $columns;
    }

    /**
     * @return array<int, string>
     */
    public static function getAccommodationTypeColumns(): array
    {
        return \array_merge(
            [
                'id',
                'name',
                'slug',
                'category',
                'description',
                'internal_code',
                'is_active',
                'is_bookable',
                'is_online_bookable',
                'is_calendar_visible',
                'sort_order',
                'max_adults',
                'max_children',
                'max_guests',
                'default_occupancy',
                'base_price',
                'extra_guest_price',
                'room_size',
                'beds',
                'room_rules',
                'amenities_intro',
                'admin_notes',
            ],
            \array_keys(self::getAmenityColumnMap())
        );
    }

    /**
     * @return array<int, string>
     */
    public static function getAccommodationUnitColumns(): array
    {
        return [
            'id',
            'room_type_id',
            'room_type_name',
            'title',
            'room_number',
            'floor',
            'status',
            'is_active',
            'is_bookable',
            'is_calendar_visible',
            'sort_order',
            'capacity_override',
            'building',
            'section',
            'admin_notes',
        ];
    }

    /**
     * @return array<string, array<int, string>>
     */
    public static function getWorkbookColumns(): array
    {
        return [
            self::SHEET_TYPES => self::getAccommodationTypeColumns(),
            self::SHEET_UNITS => self::getAccommodationUnitColumns(),
        ];
    }
}
