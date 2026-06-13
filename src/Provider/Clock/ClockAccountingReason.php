<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockAccountingReason
{
    public const FUTURE_BOOKING_REQUIRES_DEPOSIT_ENDPOINT = 'future_booking_requires_deposit_endpoint';
    public const DEPOSIT_ENDPOINT_NOT_VERIFIED = 'deposit_endpoint_not_verified';
    public const SAME_DAY_FOLIO_POSTING_DISABLED = 'same_day_folio_posting_disabled';
    public const CLOCK_CREDENTIALS_INVALID = 'clock_credentials_invalid';
    public const CLOCK_BOOKING_NOT_FOUND = 'clock_booking_not_found';
    public const CLOCK_FOLIO_NOT_FOUND = 'clock_folio_not_found';
    public const CLOCK_API_RIGHT_MISSING = 'clock_api_right_missing';
    public const CLOCK_ENDPOINT_INVALID = 'clock_endpoint_invalid';
    public const CLOCK_REQUEST_FAILED = 'clock_request_failed';
    public const CLOCK_RESPONSE_INVALID = 'clock_response_invalid';
    public const CLOCK_POSTING_REQUIRES_MANUAL_ACTION = 'clock_posting_requires_manual_action';
    public const REFUND_REQUIRES_MANUAL_CLOCK_ACTION = 'refund_requires_manual_clock_action';

    /** @return array<int, string> */
    public static function all(): array
    {
        return [
            self::FUTURE_BOOKING_REQUIRES_DEPOSIT_ENDPOINT,
            self::DEPOSIT_ENDPOINT_NOT_VERIFIED,
            self::SAME_DAY_FOLIO_POSTING_DISABLED,
            self::CLOCK_CREDENTIALS_INVALID,
            self::CLOCK_BOOKING_NOT_FOUND,
            self::CLOCK_FOLIO_NOT_FOUND,
            self::CLOCK_API_RIGHT_MISSING,
            self::CLOCK_ENDPOINT_INVALID,
            self::CLOCK_REQUEST_FAILED,
            self::CLOCK_RESPONSE_INVALID,
            self::CLOCK_POSTING_REQUIRES_MANUAL_ACTION,
            self::REFUND_REQUIRES_MANUAL_CLOCK_ACTION,
        ];
    }

    public static function normalize(string $code, string $fallback = self::CLOCK_REQUEST_FAILED): string
    {
        $code = \sanitize_key($code);

        return \in_array($code, self::all(), true) ? $code : $fallback;
    }

    public static function forFolioMessage(string $message, string $errorCode = ''): string
    {
        $errorCode = \sanitize_key($errorCode);
        $message = \strtolower($message);

        if ($errorCode === 'forbidden' || \strpos($message, 'forbidden') !== false || \strpos($message, 'permission') !== false || \strpos($message, 'right') !== false) {
            return self::CLOCK_API_RIGHT_MISSING;
        }

        if (\strpos($message, 'folio') !== false) {
            return self::CLOCK_FOLIO_NOT_FOUND;
        }

        if (\strpos($message, 'booking') !== false || \strpos($message, 'reservation') !== false) {
            return self::CLOCK_BOOKING_NOT_FOUND;
        }

        if (\strpos($message, 'json') !== false || \strpos($message, 'response') !== false || \strpos($message, 'malformed') !== false) {
            return self::CLOCK_RESPONSE_INVALID;
        }

        if (\strpos($message, 'endpoint') !== false || \strpos($message, 'path') !== false || \strpos($message, '404') !== false || $errorCode === 'not_found') {
            return self::CLOCK_ENDPOINT_INVALID;
        }

        return self::CLOCK_REQUEST_FAILED;
    }

    public static function label(string $code): string
    {
        $labels = [
            self::FUTURE_BOOKING_REQUIRES_DEPOSIT_ENDPOINT => \__('Future booking requires deposit endpoint', 'must-hotel-booking'),
            self::DEPOSIT_ENDPOINT_NOT_VERIFIED => \__('Deposit endpoint not verified', 'must-hotel-booking'),
            self::SAME_DAY_FOLIO_POSTING_DISABLED => \__('Same-day folio posting disabled', 'must-hotel-booking'),
            self::CLOCK_CREDENTIALS_INVALID => \__('Clock credentials invalid', 'must-hotel-booking'),
            self::CLOCK_BOOKING_NOT_FOUND => \__('Clock booking not found', 'must-hotel-booking'),
            self::CLOCK_FOLIO_NOT_FOUND => \__('Clock folio not found', 'must-hotel-booking'),
            self::CLOCK_API_RIGHT_MISSING => \__('Clock API right missing', 'must-hotel-booking'),
            self::CLOCK_ENDPOINT_INVALID => \__('Clock endpoint invalid', 'must-hotel-booking'),
            self::CLOCK_REQUEST_FAILED => \__('Clock request failed', 'must-hotel-booking'),
            self::CLOCK_RESPONSE_INVALID => \__('Clock response invalid', 'must-hotel-booking'),
            self::CLOCK_POSTING_REQUIRES_MANUAL_ACTION => \__('Manual Clock posting required', 'must-hotel-booking'),
            self::REFUND_REQUIRES_MANUAL_CLOCK_ACTION => \__('Manual Clock refund action required', 'must-hotel-booking'),
        ];

        $code = self::normalize($code, self::CLOCK_REQUEST_FAILED);

        return (string) ($labels[$code] ?? $code);
    }
}
