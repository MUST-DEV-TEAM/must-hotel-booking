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
        $bookingId = isset($options['booking_id']) ? \sanitize_text_field((string) $options['booking_id']) : '';
        if ($bookingId === '') {
            $bookingId = $this->generateBookingReference();
        }
        $bookingSource = isset($options['booking_source']) ? \sanitize_key((string) $options['booking_source']) : 'website';
        $extraNotes = isset($options['notes']) ? \trim(\sanitize_textarea_field((string) $options['notes'])) : '';
        $note = ReservationEngine::buildReservationNote($noteRoomId, $guestForm);

        if ($extraNotes !== '') {
            $note = \trim($note);
            $note .= ($note !== '' ? "\n\n" : '') . $extraNotes;
        }
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

        $clockIdentifiers = ClockBookingReferenceMapper::extractClockIdentifiers($providerReservation);
        $providerBookingId = (string) ($clockIdentifiers['clock_booking_id'] ?? '');
        $providerReservationId = $this->firstString($providerReservation, ['reservation_id', 'provider_reservation_id', 'id', 'booking_id']);
        if ($providerReservationId === '') {
            $providerReservationId = $providerBookingId;
        }
        $deferProviderCreation = !empty($options['defer_provider_creation']);
        $providerCreated = $providerBookingId !== '' || $providerReservationId !== '';
        $pendingGuestForm = $deferProviderCreation && isset($options['pending_guest_form']) && \is_array($options['pending_guest_form'])
            ? $this->sanitizePendingGuestForm($options['pending_guest_form'])
            : [];
        $providerStatus = $this->firstString($providerReservation, ['status', 'state', 'reservation_status']);
        $providerPaymentStatus = $this->firstString($providerReservation, ['payment_status', 'payment_state']);
        $referenceMapping = isset($providerReservation['_mhb_reference_mapping']) && \is_array($providerReservation['_mhb_reference_mapping'])
            ? $providerReservation['_mhb_reference_mapping']
            : [];
        $clockBookingReference = (string) ($clockIdentifiers['clock_booking_reference'] ?? '');
        $now = \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
        $couponId = isset($pricing['applied_coupon_id']) ? (int) $pricing['applied_coupon_id'] : 0;
        $couponCode = isset($options['coupon_code'])
            ? \sanitize_text_field((string) $options['coupon_code'])
            : (isset($pricing['applied_coupon']) ? (string) $pricing['applied_coupon'] : '');
        $couponDiscountTotal = isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0;
        $pricingSnapshot = \function_exists('MustHotelBooking\\Frontend\\build_price_breakdown_snapshot')
            ? \MustHotelBooking\Frontend\build_price_breakdown_snapshot($pricing)
            : [];
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
            'booking_source' => $bookingSource !== '' ? $bookingSource : 'website',
            'notes' => $note,
            'total_price' => isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0,
            'coupon_id' => $couponId,
            'coupon_code' => $couponCode,
            'coupon_discount_total' => $couponDiscountTotal,
            'payment_status' => isset($options['payment_status']) ? \sanitize_key((string) $options['payment_status']) : 'pending',
            'confirmation_flow' => isset($options['confirmation_flow']) ? \sanitize_key((string) $options['confirmation_flow']) : 'legacy',
            'provider' => ProviderManager::CLOCK_MODE,
            'provider_booking_id' => $providerBookingId,
            'provider_reservation_id' => $providerReservationId,
            'provider_status' => $providerStatus,
            'provider_payment_status' => $providerPaymentStatus,
            'provider_sync_status' => $providerCreated ? 'synced' : ($deferProviderCreation ? 'pending_payment' : 'synced'),
            'provider_synced_at' => $providerCreated ? $now : null,
            'provider_payload_ref' => $providerReservationId !== '' ? $providerReservationId : $providerBookingId,
            'provider_metadata' => [
                'source' => $bookingSource !== '' && $bookingSource !== 'website' ? $bookingSource : 'public_booking_mvp',
                'payment_strategy' => 'clock_pms',
                'provider_created_before_payment' => $providerCreated && (string) ($options['payment_status'] ?? '') === 'pending',
                'pending_clock_creation' => $deferProviderCreation,
                'payment_reconciliation_required' => false,
                'clock_payment_required' => $providerCreated && (string) ($options['payment_status'] ?? '') === 'pending',
                'pending_guest_form' => $pendingGuestForm,
                'clock_booking_id' => $providerBookingId,
                'clock_booking_reference' => $clockBookingReference,
                'website_booking_reference' => $bookingId,
                'pricing_snapshot' => $pricingSnapshot,
                'website_reference_sent_to_clock' => !empty($referenceMapping['website_reference_sent_to_clock']),
                'clock_reference_storage_field' => (string) ($referenceMapping['clock_reference_storage_field'] ?? ''),
                'clock_reference_fallback_fields' => isset($referenceMapping['clock_reference_fallback_fields']) && \is_array($referenceMapping['clock_reference_fallback_fields'])
                    ? $referenceMapping['clock_reference_fallback_fields']
                    : [],
                'clock_reference_text' => (string) ($referenceMapping['clock_reference_text'] ?? ''),
                'last_clock_reference_sync' => [
                    'success' => $providerCreated && !empty($referenceMapping['website_reference_sent_to_clock']),
                    'sync_status' => !$providerCreated
                        ? 'pending_payment'
                        : (!empty($referenceMapping['website_reference_sent_to_clock']) ? 'synced' : 'failed'),
                    'synced_at' => $providerCreated ? $now : null,
                    'storage_field' => (string) ($referenceMapping['clock_reference_storage_field'] ?? ''),
                    'fallback_fields' => isset($referenceMapping['clock_reference_fallback_fields']) && \is_array($referenceMapping['clock_reference_fallback_fields'])
                        ? $referenceMapping['clock_reference_fallback_fields']
                        : [],
                ],
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
        if ($providerCreated) {
            $this->logReferenceDiagnostics($reservationId, $bookingId, $providerBookingId, $clockBookingReference, $referenceMapping);
        }

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

    /** @param array<string, mixed> $guestForm @return array<string, string> */
    private function sanitizePendingGuestForm(array $guestForm): array
    {
        $sanitized = [];
        foreach (['first_name', 'last_name', 'email', 'phone_country_code', 'phone_number', 'country', 'address', 'city', 'zip_code'] as $key) {
            if (!isset($guestForm[$key]) || !\is_scalar($guestForm[$key])) {
                continue;
            }
            $value = (string) $guestForm[$key];
            $sanitized[$key] = $key === 'email'
                ? \sanitize_email($value)
                : \sanitize_text_field($value);
        }
        return $sanitized;
    }

    public function generateBookingReference(): string
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

    /** @param array<string, mixed> $referenceMapping */
    private function logReferenceDiagnostics(
        int $reservationId,
        string $websiteReference,
        string $clockBookingId,
        string $clockBookingReference,
        array $referenceMapping
    ): void {
        $repository = \MustHotelBooking\Engine\get_activity_repository();
        $context = [
            'reservation_id' => $reservationId,
            'booking_id' => $websiteReference,
            'clock_booking_id' => $clockBookingId,
            'clock_booking_reference' => $clockBookingReference,
            'website_reference_sent_to_clock' => !empty($referenceMapping['website_reference_sent_to_clock']),
            'clock_reference_storage_field' => (string) ($referenceMapping['clock_reference_storage_field'] ?? ''),
            'clock_reference_fallback_fields' => isset($referenceMapping['clock_reference_fallback_fields']) && \is_array($referenceMapping['clock_reference_fallback_fields'])
                ? $referenceMapping['clock_reference_fallback_fields']
                : [],
        ];

        $referenceEvent = !empty($referenceMapping['website_reference_sent_to_clock'])
            ? 'clock_website_reference_sent'
            : 'clock_website_reference_failed';
        $referenceMessage = !empty($referenceMapping['website_reference_sent_to_clock'])
            ? \sprintf(\__('Website booking reference sent to Clock: %s', 'must-hotel-booking'), $websiteReference)
            : \sprintf(\__('Website booking reference could not be sent to Clock: %s', 'must-hotel-booking'), $websiteReference);

        $messages = [
            'clock_booking_created' => \__('Clock booking created.', 'must-hotel-booking'),
            'clock_booking_id_received' => $clockBookingId !== ''
                ? \sprintf(\__('Clock booking ID received: %s', 'must-hotel-booking'), $clockBookingId)
                : \__('Clock booking ID was not returned.', 'must-hotel-booking'),
            'clock_booking_reference_received' => $clockBookingReference !== ''
                ? \sprintf(\__('Clock booking reference received: %s', 'must-hotel-booking'), $clockBookingReference)
                : \__('Clock booking reference was not returned.', 'must-hotel-booking'),
            $referenceEvent => $referenceMessage,
            'clock_reference_fallback_used' => \sprintf(
                \__('Clock-side website reference storage used %1$s with fallback %2$s.', 'must-hotel-booking'),
                (string) ($referenceMapping['clock_reference_storage_field'] ?? ''),
                \implode(', ', (array) ($referenceMapping['clock_reference_fallback_fields'] ?? []))
            ),
        ];

        foreach ($messages as $eventType => $message) {
            $repository->createActivity([
                'event_type' => (string) $eventType,
                'severity' => $eventType === 'clock_website_reference_failed' ? 'warning' : 'info',
                'entity_type' => 'reservation',
                'entity_id' => $reservationId,
                'reference' => $websiteReference,
                'message' => $message,
                'context_json' => \wp_json_encode($context),
            ]);
        }
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

        foreach (['id', 'booking_id', 'reservation_id', 'reference_number', 'confirmation_number', 'reference', 'status', 'state', 'payment_status'] as $key) {
            if (isset($response[$key]) && \is_scalar($response[$key])) {
                $summary[$key] = (string) $response[$key];
            }
        }

        return $summary;
    }
}
