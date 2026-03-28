<?php

namespace MustHotelBooking\Engine;

final class CouponService
{
    /**
     * @return array{type: string, code: string, message: string}
     */
    public static function buildCustomerCouponNotice(string $couponCode, float $bookingAmount, string $stayDate = '', float $discountAmount = 0.0): array
    {
        $normalized = self::normalizeCode($couponCode);

        if ($normalized === '') {
            return [
                'type' => 'error',
                'code' => '',
                'message' => \__('Enter a coupon code to check it.', 'must-hotel-booking'),
            ];
        }

        $validation = self::validateCouponForAmount($normalized, $bookingAmount, $stayDate);

        if (empty($validation['valid']) || !isset($validation['coupon']) || !\is_array($validation['coupon'])) {
            $errors = isset($validation['errors']) && \is_array($validation['errors']) ? $validation['errors'] : [];

            return [
                'type' => 'error',
                'code' => $normalized,
                'message' => isset($errors[0]) ? (string) $errors[0] : \__('This coupon cannot be used for the current booking.', 'must-hotel-booking'),
            ];
        }

        $coupon = $validation['coupon'];
        $resolvedCode = self::normalizeCode((string) ($coupon['code'] ?? $normalized));
        $resolvedDiscountAmount = $discountAmount > 0.0
            ? \round($discountAmount, 2)
            : self::calculateDiscountAmount($bookingAmount, $coupon);

        if ($resolvedDiscountAmount <= 0.0) {
            return [
                'type' => 'error',
                'code' => $resolvedCode,
                'message' => \__('This coupon does not reduce the current booking total.', 'must-hotel-booking'),
            ];
        }

        return [
            'type' => 'success',
            'code' => $resolvedCode,
            'message' => \sprintf(
                /* translators: %s is the applied coupon code. */
                \__('Coupon %s was applied successfully.', 'must-hotel-booking'),
                $resolvedCode
            ),
        ];
    }

    public static function normalizeCode(string $couponCode): string
    {
        $normalized = \strtoupper(\trim($couponCode));

        return (string) \preg_replace('/[^A-Z0-9_-]/', '', $normalized);
    }

    /**
     * @return array<string, mixed>|null
     */
    public static function getCouponByCode(string $couponCode): ?array
    {
        $normalized = self::normalizeCode($couponCode);

        if ($normalized === '') {
            return null;
        }

        return get_coupon_repository()->getCouponByCode($normalized);
    }

    /**
     * @return array<string, mixed>
     */
    public static function buildCouponState(array $coupon, string $today = ''): array
    {
        $today = $today !== '' ? $today : \current_time('Y-m-d');
        $usageLimit = isset($coupon['usage_limit']) ? (int) $coupon['usage_limit'] : 0;
        $usedCount = isset($coupon['used_count']) ? (int) $coupon['used_count'] : (isset($coupon['usage_count']) ? (int) $coupon['usage_count'] : 0);
        $remaining = $usageLimit > 0 ? \max(0, $usageLimit - $usedCount) : null;
        $validFrom = isset($coupon['valid_from']) ? (string) $coupon['valid_from'] : '';
        $validUntil = isset($coupon['valid_until']) ? (string) $coupon['valid_until'] : '';
        $isActive = !empty($coupon['is_active']);
        $status = 'inactive';

        if (!$isActive) {
            $status = 'inactive';
        } elseif ($validFrom !== '' && $validFrom > $today) {
            $status = 'scheduled';
        } elseif ($validUntil !== '' && $validUntil < $today) {
            $status = 'expired';
        } elseif ($usageLimit > 0 && $usedCount >= $usageLimit) {
            $status = 'fully_used';
        } else {
            $status = 'active';
        }

        return [
            'status' => $status,
            'usable_now' => $status === 'active',
            'used_count' => $usedCount,
            'remaining_usage' => $remaining,
        ];
    }

    /**
     * @return array<string, mixed>
     */
    public static function validateCouponForAmount(string $couponCode, float $bookingAmount, string $stayDate = ''): array
    {
        $coupon = self::getCouponByCode($couponCode);

        if (!\is_array($coupon)) {
            return [
                'valid' => false,
                'coupon' => null,
                'errors' => [\__('That coupon code could not be found.', 'must-hotel-booking')],
            ];
        }

        $today = $stayDate !== '' ? $stayDate : \current_time('Y-m-d');
        $state = self::buildCouponState($coupon, $today);
        $errors = [];

        if (empty($state['usable_now'])) {
            $messages = [
                'inactive' => \__('This coupon is not available right now.', 'must-hotel-booking'),
                'scheduled' => \__('This coupon is not active yet.', 'must-hotel-booking'),
                'expired' => \__('This coupon has expired.', 'must-hotel-booking'),
                'fully_used' => \__('This coupon is no longer available.', 'must-hotel-booking'),
            ];
            $errors[] = $messages[(string) $state['status']] ?? \__('This coupon is not currently valid.', 'must-hotel-booking');
        }

        $minimumBookingAmount = isset($coupon['minimum_booking_amount']) ? (float) $coupon['minimum_booking_amount'] : 0.0;

        if ($minimumBookingAmount > 0 && $bookingAmount < $minimumBookingAmount) {
            $errors[] = \sprintf(
                /* translators: %s is minimum amount. */
                \__('This coupon is available only for bookings of at least %s.', 'must-hotel-booking'),
                \number_format_i18n($minimumBookingAmount, 2)
            );
        }

        return [
            'valid' => empty($errors),
            'coupon' => $coupon,
            'state' => $state,
            'errors' => $errors,
        ];
    }

    public static function calculateDiscountAmount(float $discountBase, array $coupon): float
    {
        if ($discountBase <= 0) {
            return 0.0;
        }

        $type = isset($coupon['discount_type']) ? (string) $coupon['discount_type'] : (isset($coupon['type']) ? (string) $coupon['type'] : 'fixed');
        $value = isset($coupon['discount_value']) ? (float) $coupon['discount_value'] : (isset($coupon['value']) ? (float) $coupon['value'] : 0.0);

        if ($value <= 0) {
            return 0.0;
        }

        $discount = ($type === 'percent' || $type === 'percentage')
            ? $discountBase * ($value / 100)
            : $value;

        return \min(\round($discount, 2), \round($discountBase, 2));
    }

    /**
     * @return array<string, mixed>
     */
    public static function resolveCouponForBooking(string $couponCode, float $discountBase, string $stayDate = ''): array
    {
        $validation = self::validateCouponForAmount($couponCode, $discountBase, $stayDate);

        if (empty($validation['valid']) || !isset($validation['coupon']) || !\is_array($validation['coupon'])) {
            return [
                'valid' => false,
                'coupon' => null,
                'amount' => 0.0,
                'applied_code' => '',
                'errors' => isset($validation['errors']) && \is_array($validation['errors']) ? $validation['errors'] : [],
            ];
        }

        $coupon = $validation['coupon'];
        $amount = self::calculateDiscountAmount($discountBase, $coupon);

        return [
            'valid' => $amount > 0.0,
            'coupon' => $coupon,
            'amount' => $amount,
            'applied_code' => self::normalizeCode((string) ($coupon['code'] ?? $couponCode)),
            'errors' => $amount > 0.0 ? [] : [\__('This coupon does not reduce the current booking total.', 'must-hotel-booking')],
        ];
    }

    /**
     * @return array<int, string>
     */
    public static function buildAdminWarnings(array $coupon, string $today = ''): array
    {
        $today = $today !== '' ? $today : \current_time('Y-m-d');
        $warnings = [];
        $state = self::buildCouponState($coupon, $today);
        $discountType = isset($coupon['discount_type']) ? (string) $coupon['discount_type'] : '';
        $discountValue = isset($coupon['discount_value']) ? (float) $coupon['discount_value'] : 0.0;

        if (!empty($coupon['is_active']) && (string) $state['status'] === 'expired') {
            $warnings[] = \__('Coupon is still marked active but has expired.', 'must-hotel-booking');
        }

        if (!empty($coupon['is_active']) && (string) $state['status'] === 'fully_used') {
            $warnings[] = \__('Coupon is active but has no remaining usage.', 'must-hotel-booking');
        }

        if ($discountType === 'percentage' && ($discountValue <= 0 || $discountValue > 100)) {
            $warnings[] = \__('Percentage discount must be between 0 and 100.', 'must-hotel-booking');
        }

        if ($discountType === 'fixed' && $discountValue <= 0) {
            $warnings[] = \__('Fixed discount must be greater than zero.', 'must-hotel-booking');
        }

        if ((string) ($coupon['valid_from'] ?? '') > (string) ($coupon['valid_until'] ?? '')) {
            $warnings[] = \__('Coupon validity range is broken.', 'must-hotel-booking');
        }

        if ((int) ($coupon['future_reservation_count'] ?? 0) > 0 && empty($coupon['is_active'])) {
            $warnings[] = \__('Disabled coupon is still attached to future reservations.', 'must-hotel-booking');
        }

        return \array_values(\array_unique($warnings));
    }
}
