<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\CouponService;

final class CouponAdminDataProvider
{
    private \MustHotelBooking\Database\CouponRepository $couponRepository;

    public function __construct()
    {
        $this->couponRepository = \MustHotelBooking\Engine\get_coupon_repository();
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function getPageData(CouponAdminQuery $query, array $state = []): array
    {
        $filters = $query->getFilters();
        $totalItems = $this->couponRepository->countAdminCouponRows($filters);
        $totalPages = (int) \max(1, \ceil($totalItems / \max(1, (int) ($filters['per_page'] ?? 20))));
        $filters['paged'] = \max(1, \min((int) ($filters['paged'] ?? 1), $totalPages));
        $rows = $this->couponRepository->getAdminCouponRows($filters);
        $selectedCouponId = isset($state['selected_coupon_id']) && (int) $state['selected_coupon_id'] > 0
            ? (int) $state['selected_coupon_id']
            : $query->getCouponId();

        return [
            'filters' => $filters,
            'summary_cards' => $this->buildSummaryCards($rows, $totalItems),
            'rows' => $this->formatRows($rows, $query),
            'pagination' => [
                'total_items' => $totalItems,
                'total_pages' => $totalPages,
                'current_page' => (int) $filters['paged'],
                'per_page' => (int) $filters['per_page'],
            ],
            'form' => $this->buildForm($query, isset($state['form']) && \is_array($state['form']) ? $state['form'] : null),
            'detail' => $selectedCouponId > 0 ? $this->buildDetail($selectedCouponId) : null,
            'errors' => isset($state['errors']) && \is_array($state['errors']) ? $state['errors'] : [],
            'calculation_note' => \__('Coupon discounts currently apply after nightly pricing and fees, but before taxes. Payment totals and reservation totals follow that same order.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $rows, int $totalItems): array
    {
        $active = 0;
        $expired = 0;
        $usedUp = 0;

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $state = CouponService::buildCouponState($row);

            if ((string) $state['status'] === 'active') {
                $active++;
            } elseif ((string) $state['status'] === 'expired') {
                $expired++;
            } elseif ((string) $state['status'] === 'fully_used') {
                $usedUp++;
            }
        }

        return [
            [
                'label' => \__('Coupons', 'must-hotel-booking'),
                'value' => (string) $totalItems,
                'meta' => \__('Coupon definitions currently available in the booking system.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Usable Now', 'must-hotel-booking'),
                'value' => (string) $active,
                'meta' => \__('Coupons currently valid for checkout.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Expired', 'must-hotel-booking'),
                'value' => (string) $expired,
                'meta' => \__('Coupons outside their validity window.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Fully Used', 'must-hotel-booking'),
                'value' => (string) $usedUp,
                'meta' => \__('Coupons with no remaining usage.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatRows(array $rows, CouponAdminQuery $query): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $couponId = isset($row['id']) ? (int) $row['id'] : 0;
            $state = CouponService::buildCouponState($row);
            $warnings = CouponService::buildAdminWarnings($row);
            $remainingUsage = isset($state['remaining_usage']) && $state['remaining_usage'] !== null
                ? (int) $state['remaining_usage']
                : null;

            $formatted[] = [
                'id' => $couponId,
                'code' => (string) ($row['code'] ?? ''),
                'name' => (string) ($row['name'] ?? ''),
                'discount_type' => (string) ($row['discount_type'] ?? ''),
                'discount_value' => $this->formatDiscount($row),
                'status' => (string) ($state['status'] ?? 'inactive'),
                'valid_from' => (string) ($row['valid_from'] ?? ''),
                'valid_until' => (string) ($row['valid_until'] ?? ''),
                'usage' => (int) ($row['used_count'] ?? 0),
                'remaining' => $remainingUsage === null ? \__('Unlimited', 'must-hotel-booking') : (string) $remainingUsage,
                'last_used' => (string) (($row['last_used_at'] ?? '') !== '' ? $row['last_used_at'] : '—'),
                'warnings' => $warnings,
                'edit_url' => get_admin_coupons_page_url($query->buildUrlArgs(['coupon_id' => $couponId, 'action' => 'edit'])),
                'reservations_url' => get_admin_reservations_page_url(['search' => (string) ($row['code'] ?? '')]),
                'toggle_url' => \wp_nonce_url(
                    get_admin_coupons_page_url($query->buildUrlArgs(['coupon_id' => $couponId, 'action' => 'toggle_coupon'])),
                    'must_coupon_toggle_coupon_' . $couponId
                ),
                'delete_url' => \wp_nonce_url(
                    get_admin_coupons_page_url($query->buildUrlArgs(['coupon_id' => $couponId, 'action' => 'delete_coupon'])),
                    'must_coupon_delete_coupon_' . $couponId
                ),
                'is_active' => !empty($row['is_active']),
            ];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed>|null $submittedForm
     * @return array<string, mixed>
     */
    private function buildForm(CouponAdminQuery $query, ?array $submittedForm): array
    {
        $defaults = [
            'coupon_id' => 0,
            'code' => '',
            'name' => '',
            'is_active' => 1,
            'discount_type' => 'percentage',
            'discount_value' => 0.0,
            'minimum_booking_amount' => 0.0,
            'valid_from' => \current_time('Y-m-d'),
            'valid_until' => (new \DateTimeImmutable(\current_time('Y-m-d')))->modify('+30 days')->format('Y-m-d'),
            'usage_limit' => 0,
            'used_count' => 0,
            'remaining_usage' => null,
            'warnings' => [],
        ];

        if (\is_array($submittedForm)) {
            return \array_merge($defaults, $submittedForm);
        }

        if ($query->getAction() !== 'edit' || $query->getCouponId() <= 0) {
            return $defaults;
        }

        $coupon = $this->couponRepository->getCouponById($query->getCouponId());

        if (!\is_array($coupon)) {
            return $defaults;
        }

        $state = CouponService::buildCouponState($coupon);

        return [
            'coupon_id' => (int) ($coupon['id'] ?? 0),
            'code' => (string) ($coupon['code'] ?? ''),
            'name' => (string) ($coupon['name'] ?? ''),
            'is_active' => !empty($coupon['is_active']) ? 1 : 0,
            'discount_type' => (string) ($coupon['discount_type'] ?? 'percentage'),
            'discount_value' => (float) ($coupon['discount_value'] ?? 0.0),
            'minimum_booking_amount' => (float) ($coupon['minimum_booking_amount'] ?? 0.0),
            'valid_from' => (string) ($coupon['valid_from'] ?? $defaults['valid_from']),
            'valid_until' => (string) ($coupon['valid_until'] ?? $defaults['valid_until']),
            'usage_limit' => (int) ($coupon['usage_limit'] ?? 0),
            'used_count' => (int) ($coupon['used_count'] ?? 0),
            'remaining_usage' => $state['remaining_usage'],
            'warnings' => CouponService::buildAdminWarnings($coupon),
        ];
    }

    /**
     * @return array<string, mixed>|null
     */
    private function buildDetail(int $couponId): ?array
    {
        $coupon = $this->couponRepository->getCouponById($couponId);

        if (!\is_array($coupon)) {
            return null;
        }

        $state = CouponService::buildCouponState($coupon);
        $usageRows = $this->couponRepository->getReservationsUsingCoupon($couponId, 50);

        return [
            'coupon' => $coupon,
            'state' => $state,
            'warnings' => CouponService::buildAdminWarnings($coupon),
            'usage_rows' => $this->formatUsageRows($usageRows),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function formatUsageRows(array $rows): array
    {
        $formatted = [];

        foreach ($rows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $formatted[] = [
                'booking_id' => format_reservation_booking_id($row),
                'guest_name' => (string) ($row['guest_name'] ?? ''),
                'guest_email' => (string) ($row['guest_email'] ?? ''),
                'room_name' => (string) ($row['room_name'] ?? ''),
                'checkin' => (string) ($row['checkin'] ?? ''),
                'checkout' => (string) ($row['checkout'] ?? ''),
                'status' => get_reservation_status_options()[(string) ($row['status'] ?? '')] ?? (string) ($row['status'] ?? ''),
                'payment_status' => get_reservation_payment_status_options()[(string) ($row['payment_status'] ?? '')] ?? (string) ($row['payment_status'] ?? ''),
                'discount_total' => \number_format_i18n((float) ($row['coupon_discount_total'] ?? 0.0), 2) . ' ' . MustBookingConfig::get_currency(),
                'detail_url' => isset($row['id']) ? get_admin_reservation_detail_page_url((int) $row['id']) : '',
            ];
        }

        return $formatted;
    }

    /**
     * @param array<string, mixed> $coupon
     */
    private function formatDiscount(array $coupon): string
    {
        $value = isset($coupon['discount_value']) ? (float) $coupon['discount_value'] : 0.0;

        if ((string) ($coupon['discount_type'] ?? '') === 'fixed') {
            return \number_format_i18n($value, 2) . ' ' . MustBookingConfig::get_currency();
        }

        return \number_format_i18n($value, 2) . '%';
    }
}
