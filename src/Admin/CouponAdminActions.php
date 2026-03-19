<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Engine\CouponService;

final class CouponAdminActions
{
    /**
     * @return array<string, mixed>
     */
    public function handleSaveRequest(CouponAdminQuery $query): array
    {
        $action = isset($_POST['must_coupon_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_coupon_action'])) : '';
        $state = [
            'errors' => [],
            'form' => null,
            'selected_coupon_id' => $query->getCouponId(),
        ];

        if ($action !== 'save_coupon') {
            return $state;
        }

        if (!isset($_POST['must_coupon_nonce']) || !\wp_verify_nonce((string) \wp_unslash($_POST['must_coupon_nonce']), 'must_coupon_save')) {
            return [
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'form' => null,
                'selected_coupon_id' => $query->getCouponId(),
            ];
        }

        $form = $this->buildForm($_POST);
        $state['form'] = $form;
        $state['selected_coupon_id'] = (int) $form['coupon_id'];
        $repository = \MustHotelBooking\Engine\get_coupon_repository();

        if ($form['code'] === '') {
            $state['errors'][] = \__('Coupon code is required.', 'must-hotel-booking');
        } elseif ($repository->couponCodeExists($form['code'], (int) $form['coupon_id'])) {
            $state['errors'][] = \__('Coupon code already exists.', 'must-hotel-booking');
        }

        if ($form['discount_type'] !== 'percentage' && $form['discount_type'] !== 'fixed') {
            $state['errors'][] = \__('Coupon type must be percentage or fixed.', 'must-hotel-booking');
        }

        if ($form['discount_value'] <= 0) {
            $state['errors'][] = \__('Discount value must be greater than zero.', 'must-hotel-booking');
        }

        if ($form['discount_type'] === 'percentage' && $form['discount_value'] > 100) {
            $state['errors'][] = \__('Percentage discount cannot be greater than 100.', 'must-hotel-booking');
        }

        if ($form['minimum_booking_amount'] < 0) {
            $state['errors'][] = \__('Minimum booking amount cannot be negative.', 'must-hotel-booking');
        }

        if ($form['usage_limit'] < 0) {
            $state['errors'][] = \__('Usage limit cannot be negative.', 'must-hotel-booking');
        }

        if ($form['valid_from'] === '' || $form['valid_until'] === '') {
            $state['errors'][] = \__('Valid from and valid until are required.', 'must-hotel-booking');
        } elseif ($form['valid_from'] > $form['valid_until']) {
            $state['errors'][] = \__('Valid until must be on or after valid from.', 'must-hotel-booking');
        }

        if (!empty($state['errors'])) {
            return $state;
        }

        $savedCouponId = $repository->saveCoupon(
            (int) $form['coupon_id'],
            [
                'code' => $form['code'],
                'name' => $form['name'],
                'is_active' => $form['is_active'],
                'discount_type' => $form['discount_type'],
                'discount_value' => $form['discount_value'],
                'minimum_booking_amount' => $form['minimum_booking_amount'],
                'valid_from' => $form['valid_from'],
                'valid_until' => $form['valid_until'],
                'usage_limit' => $form['usage_limit'],
            ]
        );

        if ($savedCouponId <= 0) {
            $state['errors'][] = \__('Unable to save coupon.', 'must-hotel-booking');

            return $state;
        }

        \wp_safe_redirect(
            get_admin_coupons_page_url(
                $query->buildUrlArgs(
                    [
                        'coupon_id' => $savedCouponId,
                        'action' => 'edit',
                        'notice' => ((int) $form['coupon_id'] > 0) ? 'coupon_updated' : 'coupon_created',
                    ]
                )
            )
        );
        exit;
    }

    public function handleGetAction(CouponAdminQuery $query): void
    {
        $action = $query->getAction();
        $couponId = $query->getCouponId();
        $repository = \MustHotelBooking\Engine\get_coupon_repository();

        if ($couponId <= 0 || !\in_array($action, ['toggle_coupon', 'delete_coupon'], true)) {
            return;
        }

        if (!isset($_GET['_wpnonce']) || !\wp_verify_nonce((string) \wp_unslash($_GET['_wpnonce']), 'must_coupon_' . $action . '_' . $couponId)) {
            \wp_safe_redirect(get_admin_coupons_page_url($query->buildUrlArgs(['notice' => 'invalid_nonce', 'action' => '', 'coupon_id' => 0])));
            exit;
        }

        $coupon = $repository->getCouponById($couponId);

        if (!\is_array($coupon)) {
            \wp_safe_redirect(get_admin_coupons_page_url($query->buildUrlArgs(['notice' => 'coupon_not_found', 'action' => '', 'coupon_id' => 0])));
            exit;
        }

        if ($action === 'toggle_coupon') {
            $saved = $repository->saveCoupon(
                $couponId,
                [
                    'code' => (string) ($coupon['code'] ?? ''),
                    'name' => (string) ($coupon['name'] ?? ''),
                    'is_active' => empty($coupon['is_active']) ? 1 : 0,
                    'discount_type' => (string) ($coupon['discount_type'] ?? 'percentage'),
                    'discount_value' => (float) ($coupon['discount_value'] ?? 0.0),
                    'minimum_booking_amount' => (float) ($coupon['minimum_booking_amount'] ?? 0.0),
                    'valid_from' => (string) ($coupon['valid_from'] ?? ''),
                    'valid_until' => (string) ($coupon['valid_until'] ?? ''),
                    'usage_limit' => (int) ($coupon['usage_limit'] ?? 0),
                ]
            );

            \wp_safe_redirect(get_admin_coupons_page_url($query->buildUrlArgs([
                'notice' => $saved > 0 ? (!empty($coupon['is_active']) ? 'coupon_disabled' : 'coupon_enabled') : 'action_failed',
                'action' => '',
                'coupon_id' => $couponId,
            ])));
            exit;
        }

        if ($repository->couponHasUsage($couponId)) {
            \wp_safe_redirect(get_admin_coupons_page_url($query->buildUrlArgs([
                'notice' => 'coupon_delete_blocked',
                'action' => '',
                'coupon_id' => $couponId,
            ])));
            exit;
        }

        $deleted = $repository->deleteCoupon($couponId);
        \wp_safe_redirect(get_admin_coupons_page_url($query->buildUrlArgs([
            'notice' => $deleted ? 'coupon_deleted' : 'action_failed',
            'action' => '',
            'coupon_id' => 0,
        ])));
        exit;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function buildForm(array $source): array
    {
        return [
            'coupon_id' => isset($source['coupon_id']) ? \absint(\wp_unslash($source['coupon_id'])) : 0,
            'code' => CouponService::normalizeCode(isset($source['code']) ? (string) \wp_unslash($source['code']) : ''),
            'name' => isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '',
            'is_active' => !empty($source['is_active']) ? 1 : 0,
            'discount_type' => isset($source['discount_type']) ? \sanitize_key((string) \wp_unslash($source['discount_type'])) : 'percentage',
            'discount_value' => isset($source['discount_value']) ? \round((float) \wp_unslash($source['discount_value']), 2) : 0.0,
            'minimum_booking_amount' => isset($source['minimum_booking_amount']) ? \round((float) \wp_unslash($source['minimum_booking_amount']), 2) : 0.0,
            'valid_from' => isset($source['valid_from']) ? \sanitize_text_field((string) \wp_unslash($source['valid_from'])) : '',
            'valid_until' => isset($source['valid_until']) ? \sanitize_text_field((string) \wp_unslash($source['valid_until'])) : '',
            'usage_limit' => isset($source['usage_limit']) ? \max(0, (int) \wp_unslash($source['usage_limit'])) : 0,
        ];
    }
}
