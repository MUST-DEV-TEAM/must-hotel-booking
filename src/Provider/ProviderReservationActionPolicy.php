<?php

namespace MustHotelBooking\Provider;

final class ProviderReservationActionPolicy
{
    public const SURFACE_ADMIN_POST = 'admin_post';
    public const SURFACE_ADMIN_GET = 'admin_get';
    public const SURFACE_PORTAL = 'portal';

    /**
     * @param array<string, mixed> $reservation
     */
    public static function shouldBlockProviderBackedAction(array $reservation, string $action, string $surface): bool
    {
        if (!ProviderReservationView::isProviderBacked($reservation)) {
            return false;
        }

        if (!self::isKnownProviderSensitiveAction($action, $surface)) {
            return false;
        }

        return !self::supportsProviderAction($reservation, $action, $surface);
    }

    /**
     * @param array<string, mixed> $reservation
     */
    public static function supportsProviderAction(array $reservation, string $action, string $surface): bool
    {
        $provider = \sanitize_key((string) ($reservation['provider'] ?? ''));

        if ($provider !== ProviderManager::CLOCK_MODE) {
            return false;
        }

        $action = \sanitize_key($action);
        $surface = \sanitize_key($surface);

        if ($surface === self::SURFACE_ADMIN_POST) {
            return \in_array(
                $action,
                [
                    'save_guest',
                    'cancel',
                    'checkin',
                    'checkout',
                    'assign_room',
                    'update_stay',
                ],
                true
            );
        }

        if ($surface === self::SURFACE_ADMIN_GET) {
            return \in_array(
                $action,
                [
                    'cancel',
                ],
                true
            );
        }

        if ($surface === self::SURFACE_PORTAL) {
            return \in_array(
                $action,
                [
                    'reservation_save_guest',
                    'reservation_cancel',
                    'reservation_checkin',
                    'reservation_checkout',
                    'reservation_assign_room',
                    'reservation_update_stay',
                ],
                true
            );
        }

        return false;
    }

    public static function isKnownProviderSensitiveAction(string $action, string $surface): bool
    {
        $action = \sanitize_key($action);
        $surface = \sanitize_key($surface);

        if ($surface === self::SURFACE_ADMIN_POST || $surface === self::SURFACE_ADMIN_GET) {
            return \in_array(
                $action,
                [
                    'save_guest',
                    'save_admin_details',
                    'confirm',
                    'mark_pending',
                    'mark_paid',
                    'mark_unpaid',
                    'cancel',
                    'checkin',
                    'checkout',
                    'assign_room',
                    'update_stay',
                    'resend_guest_email',
                    'resend_admin_email',
                ],
                true
            );
        }

        if ($surface === self::SURFACE_PORTAL) {
            return \in_array(
                $action,
                [
                    'reservation_confirm',
                    'reservation_cancel',
                    'reservation_checkin',
                    'reservation_checkout',
                    'reservation_assign_room',
                    'reservation_update_stay',
                    'reservation_mark_no_show',
                    'reservation_add_note',
                    'reservation_request_cancel',
                    'reservation_reject_cancel',
                    'reservation_approve_cancel',
                    'reservation_mark_pending',
                    'reservation_mark_paid',
                    'reservation_mark_unpaid',
                    'reservation_resend_guest_email',
                    'reservation_resend_admin_email',
                    'reservation_save_guest',
                    'reservation_save_admin_details',
                    'payment_post',
                    'payment_post_partial',
                    'payment_mark_paid',
                    'payment_mark_unpaid',
                    'payment_mark_pending',
                    'payment_mark_pay_at_hotel',
                    'payment_mark_failed',
                    'payment_refund',
                    'payment_issue_receipt',
                    'payment_issue_invoice',
                ],
                true
            );
        }

        return false;
    }
}
