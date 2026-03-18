<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\BookingRules;
use MustHotelBooking\Core\RoomCatalog;

final class BookingValidationEngine
{
    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function parseRequestContext(array $source, bool $requireDates = false): array
    {
        $checkin = isset($source['checkin']) ? \sanitize_text_field((string) \wp_unslash($source['checkin'])) : '';
        $checkout = isset($source['checkout']) ? \sanitize_text_field((string) \wp_unslash($source['checkout'])) : '';
        $accommodationTypeRaw = isset($source['accommodation_type'])
            ? \sanitize_text_field((string) \wp_unslash($source['accommodation_type']))
            : 'standard-rooms';
        $accommodationType = RoomCatalog::normalizeBookingCategory($accommodationTypeRaw);
        $roomCount = BookingRules::normalizeRoomCount($source['room_count'] ?? 0);
        $guestsRaw = isset($source['guests']) ? \wp_unslash($source['guests']) : 1;
        $maxBookingGuests = BookingRules::getMaxBookingGuestsLimit();
        $guests = \max(1, \min($maxBookingGuests, \absint($guestsRaw)));
        $errors = [];

        if ($requireDates && ($checkin === '' || $checkout === '')) {
            $errors[] = \__('Please provide check-in and check-out dates.', 'must-hotel-booking');
        }

        if ($checkin !== '' && !AvailabilityEngine::isValidBookingDate($checkin)) {
            $errors[] = \__('Check-in date is invalid.', 'must-hotel-booking');
        }

        if ($checkout !== '' && !AvailabilityEngine::isValidBookingDate($checkout)) {
            $errors[] = \__('Check-out date is invalid.', 'must-hotel-booking');
        }

        if ($checkin !== '' && $checkout !== '' && AvailabilityEngine::isValidBookingDate($checkin) && AvailabilityEngine::isValidBookingDate($checkout) && $checkin >= $checkout) {
            $errors[] = \__('Check-out date must be after check-in date.', 'must-hotel-booking');
        }

        return [
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'room_count' => $roomCount,
            'accommodation_type' => $accommodationType,
            'is_valid' => empty($errors),
            'errors' => $errors,
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $room
     * @return array<string, mixed>
     */
    public static function applyFixedRoomContext(array $context, array $room): array
    {
        $roomCategory = isset($room['category'])
            ? RoomCatalog::normalizeCategory((string) $room['category'])
            : 'standard-rooms';
        $roomMaxGuests = isset($room['max_guests']) ? \max(1, (int) $room['max_guests']) : 1;

        $context['accommodation_type'] = $roomCategory;
        $context['guests'] = \max(1, \min($roomMaxGuests, (int) ($context['guests'] ?? 1)));
        $context['room_count'] = 1;

        return $context;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, string>
     */
    public static function getCheckoutGuestFormValues(array $source): array
    {
        $storedPhone = isset($source['phone']) ? \sanitize_text_field((string) \wp_unslash($source['phone'])) : '';
        $phoneParts = \MustHotelBooking\Frontend\split_checkout_phone_value($storedPhone);
        $phoneCountryCode = isset($source['phone_country_code'])
            ? \MustHotelBooking\Frontend\normalize_checkout_phone_option_value(\sanitize_text_field((string) \wp_unslash($source['phone_country_code'])))
            : (string) $phoneParts['phone_country_code'];
        $phoneNumber = isset($source['phone_number'])
            ? \MustHotelBooking\Frontend\sanitize_checkout_phone_number(\sanitize_text_field((string) \wp_unslash($source['phone_number'])))
            : (string) $phoneParts['phone_number'];
        $countryCode = isset($source['country'])
            ? \MustHotelBooking\Frontend\resolve_checkout_country_code(\sanitize_text_field((string) \wp_unslash($source['country'])))
            : '';
        $roomGuestCounts = isset($source['room_guest_count']) && \is_array($source['room_guest_count']) ? $source['room_guest_count'] : [];
        $roomGuestFirstNames = isset($source['room_guest_first_name']) && \is_array($source['room_guest_first_name']) ? $source['room_guest_first_name'] : [];
        $roomGuestLastNames = isset($source['room_guest_last_name']) && \is_array($source['room_guest_last_name']) ? $source['room_guest_last_name'] : [];
        $roomGuests = [];

        foreach (\array_keys($roomGuestCounts + $roomGuestFirstNames + $roomGuestLastNames) as $roomId) {
            $normalizedRoomId = \absint($roomId);

            if ($normalizedRoomId <= 0) {
                continue;
            }

            $roomGuests[$normalizedRoomId] = [
                'guest_count' => isset($roomGuestCounts[$roomId]) ? \sanitize_text_field((string) \wp_unslash($roomGuestCounts[$roomId])) : '',
                'first_name' => isset($roomGuestFirstNames[$roomId]) ? \sanitize_text_field((string) \wp_unslash($roomGuestFirstNames[$roomId])) : '',
                'last_name' => isset($roomGuestLastNames[$roomId]) ? \sanitize_text_field((string) \wp_unslash($roomGuestLastNames[$roomId])) : '',
            ];
        }

        return [
            'first_name' => isset($source['first_name']) ? \sanitize_text_field((string) \wp_unslash($source['first_name'])) : '',
            'last_name' => isset($source['last_name']) ? \sanitize_text_field((string) \wp_unslash($source['last_name'])) : '',
            'email' => isset($source['email']) ? \sanitize_email((string) \wp_unslash($source['email'])) : '',
            'phone' => \MustHotelBooking\Frontend\combine_checkout_phone_value([
                'phone_country_code' => $phoneCountryCode,
                'phone_number' => $phoneNumber,
            ]),
            'phone_country_code' => $phoneCountryCode,
            'phone_number' => $phoneNumber,
            'country' => $countryCode,
            'special_requests' => isset($source['special_requests']) ? \sanitize_textarea_field((string) \wp_unslash($source['special_requests'])) : '',
            'marketing_opt_in' => isset($source['marketing_opt_in']) ? '1' : '',
            'room_guests' => $roomGuests,
        ];
    }

    /**
     * @param array<string, string> $guestForm
     * @return array<int, string>
     */
    public static function validateGuestForm(array $guestForm): array
    {
        $errors = [];

        if ((string) ($guestForm['first_name'] ?? '') === '') {
            $errors[] = \__('First name is required.', 'must-hotel-booking');
        }

        if ((string) ($guestForm['last_name'] ?? '') === '') {
            $errors[] = \__('Last name is required.', 'must-hotel-booking');
        }

        if ((string) ($guestForm['email'] ?? '') === '' || !\is_email((string) ($guestForm['email'] ?? ''))) {
            $errors[] = \__('A valid email address is required.', 'must-hotel-booking');
        }

        if ((string) ($guestForm['phone_number'] ?? '') === '') {
            $errors[] = \__('Phone number is required.', 'must-hotel-booking');
        }

        if ((string) ($guestForm['country'] ?? '') === '') {
            $errors[] = \__('Country of residence is required.', 'must-hotel-booking');
        }

        return $errors;
    }

    /**
     * @param array<string, string> $billingForm
     */
    public static function getConfirmationPrimaryCountryCode(array $billingForm): string
    {
        $billingCountry = isset($billingForm['billing_country']) ? (string) $billingForm['billing_country'] : '';

        if ($billingCountry !== '') {
            return $billingCountry;
        }

        return isset($billingForm['country']) ? (string) $billingForm['country'] : '';
    }

    /**
     * @param array<string, string> $guestForm
     * @param array<string, mixed>  $storedBillingForm
     * @return array<string, string>
     */
    public static function getConfirmationBillingFormSeed(array $guestForm, array $storedBillingForm = []): array
    {
        $guestCountry = isset($guestForm['country'])
            ? \MustHotelBooking\Frontend\resolve_checkout_country_code((string) $guestForm['country'])
            : '';
        $guestPhoneCountryCode = isset($guestForm['phone_country_code'])
            ? \MustHotelBooking\Frontend\normalize_checkout_phone_option_value((string) $guestForm['phone_country_code'])
            : \MustHotelBooking\Frontend\get_checkout_default_phone_option_value();
        $guestPhoneNumber = isset($guestForm['phone_number']) ? (string) $guestForm['phone_number'] : '';

        return [
            'first_name' => isset($storedBillingForm['first_name']) ? (string) $storedBillingForm['first_name'] : (string) ($guestForm['first_name'] ?? ''),
            'last_name' => isset($storedBillingForm['last_name']) ? (string) $storedBillingForm['last_name'] : (string) ($guestForm['last_name'] ?? ''),
            'company' => isset($storedBillingForm['company']) ? (string) $storedBillingForm['company'] : '',
            'country' => isset($storedBillingForm['country']) && (string) $storedBillingForm['country'] !== ''
                ? (string) $storedBillingForm['country']
                : $guestCountry,
            'street_address' => isset($storedBillingForm['street_address']) ? (string) $storedBillingForm['street_address'] : '',
            'address_line_2' => isset($storedBillingForm['address_line_2']) ? (string) $storedBillingForm['address_line_2'] : '',
            'city' => isset($storedBillingForm['city']) ? (string) $storedBillingForm['city'] : '',
            'county' => isset($storedBillingForm['county']) ? (string) $storedBillingForm['county'] : '',
            'postcode' => isset($storedBillingForm['postcode']) ? (string) $storedBillingForm['postcode'] : '',
            'phone_country_code' => isset($storedBillingForm['phone_country_code']) && (string) $storedBillingForm['phone_country_code'] !== ''
                ? (string) $storedBillingForm['phone_country_code']
                : $guestPhoneCountryCode,
            'phone_number' => isset($storedBillingForm['phone_number']) ? (string) $storedBillingForm['phone_number'] : $guestPhoneNumber,
            'email' => isset($storedBillingForm['email']) ? (string) $storedBillingForm['email'] : (string) ($guestForm['email'] ?? ''),
            'billing_country' => isset($storedBillingForm['billing_country']) && (string) $storedBillingForm['billing_country'] !== ''
                ? (string) $storedBillingForm['billing_country']
                : $guestCountry,
            'special_requests' => isset($storedBillingForm['special_requests'])
                ? (string) $storedBillingForm['special_requests']
                : (string) ($guestForm['special_requests'] ?? ''),
        ];
    }

    /**
     * @param array<string, mixed>  $source
     * @param array<string, string> $fallback
     * @return array<string, string>
     */
    public static function getConfirmationBillingFormValues(array $source, array $fallback = []): array
    {
        $defaultPhoneCountryCode = isset($fallback['phone_country_code']) && (string) $fallback['phone_country_code'] !== ''
            ? \MustHotelBooking\Frontend\normalize_checkout_phone_option_value((string) $fallback['phone_country_code'])
            : \MustHotelBooking\Frontend\get_checkout_default_phone_option_value();

        $country = isset($source['country'])
            ? \MustHotelBooking\Frontend\resolve_checkout_country_code(\sanitize_text_field((string) \wp_unslash($source['country'])))
            : (string) ($fallback['country'] ?? '');

        return [
            'first_name' => isset($source['first_name']) ? \sanitize_text_field((string) \wp_unslash($source['first_name'])) : (string) ($fallback['first_name'] ?? ''),
            'last_name' => isset($source['last_name']) ? \sanitize_text_field((string) \wp_unslash($source['last_name'])) : (string) ($fallback['last_name'] ?? ''),
            'company' => isset($source['company']) ? \sanitize_text_field((string) \wp_unslash($source['company'])) : (string) ($fallback['company'] ?? ''),
            'country' => $country,
            'street_address' => isset($source['street_address']) ? \sanitize_text_field((string) \wp_unslash($source['street_address'])) : (string) ($fallback['street_address'] ?? ''),
            'address_line_2' => isset($source['address_line_2']) ? \sanitize_text_field((string) \wp_unslash($source['address_line_2'])) : (string) ($fallback['address_line_2'] ?? ''),
            'city' => isset($source['city']) ? \sanitize_text_field((string) \wp_unslash($source['city'])) : (string) ($fallback['city'] ?? ''),
            'county' => isset($source['county']) ? \sanitize_text_field((string) \wp_unslash($source['county'])) : (string) ($fallback['county'] ?? ''),
            'postcode' => isset($source['postcode']) ? \sanitize_text_field((string) \wp_unslash($source['postcode'])) : (string) ($fallback['postcode'] ?? ''),
            'phone_country_code' => isset($source['phone_country_code'])
                ? \MustHotelBooking\Frontend\normalize_checkout_phone_option_value(\sanitize_text_field((string) \wp_unslash($source['phone_country_code'])))
                : $defaultPhoneCountryCode,
            'phone_number' => isset($source['phone_number'])
                ? \MustHotelBooking\Frontend\sanitize_checkout_phone_number(\sanitize_text_field((string) \wp_unslash($source['phone_number'])))
                : \MustHotelBooking\Frontend\sanitize_checkout_phone_number((string) ($fallback['phone_number'] ?? '')),
            'email' => isset($source['email']) ? \sanitize_email((string) \wp_unslash($source['email'])) : (string) ($fallback['email'] ?? ''),
            'billing_country' => isset($source['billing_country'])
                ? \MustHotelBooking\Frontend\resolve_checkout_country_code(\sanitize_text_field((string) \wp_unslash($source['billing_country'])))
                : ($country !== ''
                    ? $country
                    : ((string) ($fallback['billing_country'] ?? '') !== ''
                        ? (string) $fallback['billing_country']
                        : (string) ($fallback['country'] ?? ''))),
            'special_requests' => isset($source['special_requests']) ? \sanitize_textarea_field((string) \wp_unslash($source['special_requests'])) : (string) ($fallback['special_requests'] ?? ''),
        ];
    }

    /**
     * @param array<string, string> $billingForm
     * @return array<int, string>
     */
    public static function validateBillingForm(array $billingForm): array
    {
        $errors = [];

        if ((string) ($billingForm['first_name'] ?? '') === '') {
            $errors[] = \__('First name is required.', 'must-hotel-booking');
        }

        if ((string) ($billingForm['last_name'] ?? '') === '') {
            $errors[] = \__('Last name is required.', 'must-hotel-booking');
        }

        if ((string) ($billingForm['street_address'] ?? '') === '') {
            $errors[] = \__('Street address is required.', 'must-hotel-booking');
        }

        if ((string) ($billingForm['city'] ?? '') === '') {
            $errors[] = \__('Town / City is required.', 'must-hotel-booking');
        }

        if ((string) ($billingForm['county'] ?? '') === '') {
            $errors[] = \__('County is required.', 'must-hotel-booking');
        }

        if ((string) ($billingForm['postcode'] ?? '') === '') {
            $errors[] = \__('Postcode / ZIP is required.', 'must-hotel-booking');
        }

        if ((string) ($billingForm['email'] ?? '') === '' || !\is_email((string) ($billingForm['email'] ?? ''))) {
            $errors[] = \__('A valid email address is required.', 'must-hotel-booking');
        }

        if (self::getConfirmationPrimaryCountryCode($billingForm) === '') {
            $errors[] = \__('Country of residence is required.', 'must-hotel-booking');
        }

        if ((string) ($billingForm['phone_number'] ?? '') === '') {
            $errors[] = \__('Phone number is required.', 'must-hotel-booking');
        }

        return $errors;
    }

    /**
     * @param array<string, string> $guestForm
     * @param array<string, string> $billingForm
     * @return array<string, string>
     */
    public static function buildConfirmationGuestForm(array $guestForm, array $billingForm): array
    {
        return \array_merge(
            $guestForm,
            [
                'first_name' => (string) ($billingForm['first_name'] ?? ''),
                'last_name' => (string) ($billingForm['last_name'] ?? ''),
                'email' => (string) ($billingForm['email'] ?? ''),
                'phone_country_code' => (string) ($billingForm['phone_country_code'] ?? ''),
                'phone_number' => (string) ($billingForm['phone_number'] ?? ''),
                'country' => self::getConfirmationPrimaryCountryCode($billingForm),
                'company' => (string) ($billingForm['company'] ?? ''),
                'street_address' => (string) ($billingForm['street_address'] ?? ''),
                'address_line_2' => (string) ($billingForm['address_line_2'] ?? ''),
                'city' => (string) ($billingForm['city'] ?? ''),
                'county' => (string) ($billingForm['county'] ?? ''),
                'postcode' => (string) ($billingForm['postcode'] ?? ''),
                'billing_country' => (string) (($billingForm['billing_country'] ?? '') !== ''
                    ? $billingForm['billing_country']
                    : self::getConfirmationPrimaryCountryCode($billingForm)),
                'special_requests' => (string) ($billingForm['special_requests'] ?? '') !== ''
                    ? (string) $billingForm['special_requests']
                    : (string) ($guestForm['special_requests'] ?? ''),
            ]
        );
    }
}
