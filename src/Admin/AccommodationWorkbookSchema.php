<?php

namespace MustHotelBooking\Admin;

final class AccommodationWorkbookSchema
{
    public const SHEET_UNITS = 'rooms';
    public const SHEET_TYPES_REFERENCE = 'room_types_reference';

    public const REFERENCE_WARNING = 'DO NOT EDIT - REFERENCE ONLY. Manage room types in WordPress admin.';

    /**
     * @return array<int, string>
     */
    public static function getUnitSheetColumns(): array
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
     * @return array<int, string>
     */
    public static function getReferenceSheetColumns(): array
    {
        return [
            'id',
            'name',
            'slug',
            'status',
            'max_guests',
            'base_price',
            'beds',
            'room_size',
            'description',
        ];
    }

    public static function getUnitSheetName(): string
    {
        return self::SHEET_UNITS;
    }

    public static function getReferenceSheetName(): string
    {
        return self::SHEET_TYPES_REFERENCE;
    }

    public static function getReferenceSheetWarning(): string
    {
        return self::REFERENCE_WARNING;
    }

    public static function getWorkbookSheetName(): string
    {
        return self::getUnitSheetName();
    }
}
