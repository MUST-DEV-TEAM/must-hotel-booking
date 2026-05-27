<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Engine\CancellationEngine;
use MustHotelBooking\Engine\InventoryEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Engine\ReservationEngine;
use MustHotelBooking\Provider\ProviderManager;

final class ClockMirrorReservationService
{
    /**
     * @param array<string, mixed> $context
     * @param array<string, string> $guestForm
     * @param array<string, mixed> $validatedRoom
     * @param array<string, mixed> $providerReservation
     * @param array<string, mixed> $options
     */
    public function createMirrorReservation(
        array $context,
        array $guestForm,
        int $guestId,
        array $validatedRoom,
        array $providerReservation,
        array $options
    ): int {
        $roomId = isset($validatedRoom['room_id']) ? (int) $validatedRoom['room_id'] : 0;
        $roomTypeId = isset($validatedRoom['room_type_id']) ? (int) $validatedRoom['room_type_id'] : $roomId;
        $assignedRoomId = isset($validatedRoom['physical_room_id']) ? (int) $validatedRoom['physical_room_id'] : 0;
        $noteRoomId = $roomTypeId > 0 ? $roomTypeId : $roomId;
        $ratePlanId = isset($validatedRoom['rate_plan_id']) ? (int) $validatedRoom['rate_plan_id'] : 0;
        $pricing = isset($validatedRoom['pricing']) && \is_array($validatedRoom['pricing']) ? $validatedRoom['pricing'] : [];
        $bookingId = $this->generateBookingId();
        $note = ReservationEngine::buildReservationNote($noteRoomId, $guestForm);
        $cancellationPolicy = $ratePlanId > 0 ? CancellationEngine::getCancellationPolicy($ratePlanId) : null;

        if (\is_array($cancellationPolicy) && (string) ($cancellationPolicy['name'] ?? '') !== '') {
            $note = \trim($note);
            $note .= ($note !== '' ? "\n\n" : '')
                . \sprintf(
                    /* translators: %s is cancellation policy name. */
                    \__('Cancellation Policy: %s', 'must-hotel-booking'),
                    (string) $cancellationPolicy['name']
                );
        }

        $providerBookingId = $this->firstString($providerReservation, ['booking_id', 'provider_booking_id', 'reservation_id', 'id', 'confirmation_number', 'reference']);
        $providerReservationId = $this->firstString($providerReservation, ['reservation_id', 'provider_reservation_id', 'id', 'booking_id']);
        $providerStatus = $this->firstString($providerReservation, ['status', 'state', 'reservation_status']);
        $providerPaymentStatus = $this->firstString($providerReservation, ['payment_status', 'payment_state']);
        $now = \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
        $couponId = isset($pricing['applied_coupon_id']) ? (int) $pricing['applied_coupon_id'] : 0;
        $couponCode = isset($pricing['applied_coupon']) ? (string) $pricing['applied_coupon'] : '';
        $couponDiscountTotal = isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0;
        $repository = \MustHotelBooking\Engine\get_reservation_repository();
        $reservationId = $repository->createProviderMirrorReservation([
            'booking_id' => $bookingId,
            'room_id' => $roomTypeId,
            'room_type_id' => $roomTypeId,
            'assigned_room_id' => $assignedRoomId,
            'rate_plan_id' => \max(0, $ratePlanId),
            'guest_id' => $guestId,
            'checkin' => (string) ($context['checkin'] ?? ''),
            'checkout' => (string) ($context['checkout'] ?? ''),
            'guests' => isset($validatedRoom['guests']) ? (int) $validatedRoom['guests'] : \max(1, (int) ($context['guests'] ?? 1)),
            'status' => isset($options['reservation_status']) ? \sanitize_key((string) $options['reservation_status']) : 'pending',
            'booking_source' => 'website',
            'notes' => $note,
            'total_price' => isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0,
            'coupon_id' => $couponId,
            'coupon_code' => $couponCode,
            'coupon_discount_total' => $couponDiscountTotal,
            'payment_status' => isset($options['payment_status']) ? \sanitize_key((string) $options['payment_status']) : 'pending',
            'provider' => ProviderManager::CLOCK_MODE,
            'provider_booking_id' => $providerBookingId,
            'provider_reservation_id' => $providerReservationId,
            'provider_status' => $providerStatus,
            'provider_payment_status' => $providerPaymentStatus,
            'provider_sync_status' => 'synced',
            'provider_synced_at' => $now,
            'provider_payload_ref' => $providerReservationId !== '' ? $providerReservationId : $providerBookingId,
            'provider_metadata' => [
                'source' => 'public_booking_mvp',
                'payment_strategy' => 'clock_pms',
                'provider_created_before_payment' => (string) ($options['payment_status'] ?? '') === 'pending',
                'payment_reconciliation_required' => false,
                'clock_payment_required' => (string) ($options['payment_status'] ?? '') === 'pending',
                'provider_response' => $this->responseSummary($providerReservation),
                'selected_room_id' => $roomId,
                'physical_room_id' => $assignedRoomId,
                'room_mapping' => $validatedRoom['room_mapping'] ?? null,
                'physical_mapping' => $validatedRoom['physical_mapping'] ?? null,
                'rate_plan_mapping' => $validatedRoom['rate_plan_mapping'] ?? null,
            ],
            'created_at' => $now,
        ]);

        if ($reservationId <= 0) {
            return 0;
        }

        $this->releaseSelectionLock(
            $assignedRoomId > 0 ? $assignedRoomId : $roomId,
            (string) ($context['checkin'] ?? ''),
            (string) ($context['checkout'] ?? '')
        );

        \do_action('must_hotel_booking/reservation_created', $reservationId);

        return $reservationId;
    }

    private function releaseSelectionLock(int $roomId, string $checkin, string $checkout): void
    {
        if ($roomId <= 0 || $checkin === '' || $checkout === '') {
            return;
        }

        if (InventoryEngine::hasInventoryForRoomType($roomId)) {
            InventoryEngine::releaseLocksForRoomType($roomId, $checkin, $checkout);
            return;
        }

        LockEngine::releaseExactLock($roomId, $checkin, $checkout);
    }

    private function generateBookingId(): string
    {
        $repository = \MustHotelBooking\Engine\get_reservation_repository();
        $attempt = 0;

        while ($attempt < 8) {
            $suffix = \strtoupper(\substr(\str_replace('-', '', \wp_generate_uuid4()), 0, 8));
            $bookingId = 'MHB-' . \gmdate('Ymd') . '-' . $suffix;

            if (!$repository->bookingIdExists($bookingId)) {
                return $bookingId;
            }

            $attempt++;
        }

        return 'MHB-' . \gmdate('YmdHis') . '-' . \wp_rand(1000, 9999);
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                return \sanitize_text_field((string) $source[$key]);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $response @return array<string, mixed> */
    private function responseSummary(array $response): array
    {
        $summary = [];

        foreach (['id', 'booking_id', 'reservation_id', 'confirmation_number', 'reference', 'status', 'state', 'payment_status'] as $key) {
            if (isset($response[$key]) && \is_scalar($response[$key])) {
                $summary[$key] = (string) $response[$key];
            }
        }

        return $summary;
    }
}
