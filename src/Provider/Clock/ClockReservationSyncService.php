<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;

final class ClockReservationSyncService
{
    /** @var ClockApiClient */
    private $client;

    /** @var ProviderMappingRepository */
    private $mappings;

    public function __construct(?ClockApiClient $client = null, ?ProviderMappingRepository $mappings = null)
    {
        $this->client = $client ?: new ClockApiClient();
        $this->mappings = $mappings ?: new ProviderMappingRepository();
    }

    /** @return array<string, mixed> */
    public function syncBookingsWindow(int $pastDays = 14, int $futureDays = 365, int $limit = 150): array
    {
        $summary = [
            'success' => true,
            'created' => 0,
            'updated' => 0,
            'skipped' => 0,
            'fetched' => 0,
            'errors' => [],
        ];

        if (!ClockConfig::isConfigured()) {
            $summary['success'] = false;
            $summary['errors'] = ClockConfig::configurationErrors();
            return $summary;
        }

        $window = $this->dateWindow($pastDays, $futureDays);
        $response = $this->client->get(
            '/bookings',
            [
                'arrival.gteq' => $window['from'],
                'arrival.lt' => $window['to'],
            ],
            'clock.bookings.index',
            [
                'api_type' => 'pms_api',
                'endpoint_name' => 'bookings',
            ]
        );

        if (!$response->isSuccess()) {
            $summary['success'] = false;
            $summary['errors'][] = $response->getErrorMessage() !== ''
                ? $response->getErrorMessage()
                : \__('Clock booking list request failed.', 'must-hotel-booking');
            return $summary;
        }

        $items = $this->bookingIndexItems($response->getData());
        $limit = \max(1, \min(500, $limit));
        $processed = 0;

        foreach ($items as $item) {
            if ($processed >= $limit) {
                break;
            }

            $processed++;
            $booking = $this->bookingDataFromIndexItem($item);

            if (empty($booking)) {
                $bookingId = $this->bookingIdFromIndexItem($item);
                $booking = $bookingId !== '' ? $this->fetchBooking($bookingId) : [];
            }

            if (empty($booking)) {
                $summary['skipped']++;
                continue;
            }

            $summary['fetched']++;
            $result = $this->upsertBooking($booking, 'bulk_sync');

            if (!empty($result['created'])) {
                $summary['created']++;
            } elseif (!empty($result['updated'])) {
                $summary['updated']++;
            } else {
                $summary['skipped']++;
            }

            if (empty($result['success']) && !empty($result['message'])) {
                $summary['errors'][] = (string) $result['message'];
            }
        }

        if (!empty($summary['errors']) && ($summary['created'] + $summary['updated']) === 0) {
            $summary['success'] = false;
        }

        $summary['errors'] = \array_slice(\array_values(\array_unique(\array_map('strval', $summary['errors']))), 0, 8);

        return $summary;
    }

    /** @return array<string, mixed> */
    public function refreshBookingById(string $bookingId, string $source = 'manual'): array
    {
        $bookingId = \sanitize_text_field($bookingId);

        if ($bookingId === '') {
            return [
                'success' => false,
                'message' => \__('Clock booking ID is missing.', 'must-hotel-booking'),
            ];
        }

        $booking = $this->fetchBooking($bookingId);

        if (empty($booking)) {
            return [
                'success' => false,
                'message' => \__('Clock booking details could not be fetched.', 'must-hotel-booking'),
            ];
        }

        return $this->upsertBooking($booking, $source);
    }

    /** @param array<string, mixed> $booking @return array<string, mixed> */
    public function upsertBooking(array $booking, string $source = 'sync'): array
    {
        $booking = $this->reservationSource($booking);
        $providerBookingId = $this->firstString($booking, ['id', 'booking_id', 'reservation_id']);
        $arrival = $this->firstString($booking, ['arrival', 'checkin', 'check_in']);
        $departure = $this->firstString($booking, ['departure', 'checkout', 'check_out']);

        if ($providerBookingId === '' || !$this->isDate($arrival) || !$this->isDate($departure)) {
            return [
                'success' => false,
                'message' => \__('Clock booking payload is missing an ID, arrival, or departure date.', 'must-hotel-booking'),
            ];
        }

        $roomTypeMapping = $this->mappingForExternal('accommodation', $this->firstString($booking, ['arrival_room_type_id', 'room_type_id']));

        if (!\is_array($roomTypeMapping)) {
            return [
                'success' => false,
                'message' => \sprintf(
                    /* translators: %s is Clock booking ID. */
                    \__('Clock booking %s was skipped because its room type is not mapped locally.', 'must-hotel-booking'),
                    $providerBookingId
                ),
            ];
        }

        $localRoomTypeId = (int) ($roomTypeMapping['local_id'] ?? 0);

        if ($localRoomTypeId <= 0) {
            return [
                'success' => false,
                'message' => \__('Mapped Clock room type does not point to a local accommodation.', 'must-hotel-booking'),
            ];
        }

        $rows = \MustHotelBooking\Engine\get_reservation_repository()->getProviderReservationRowsByExternalIds(
            ProviderManager::CLOCK_MODE,
            $providerBookingId,
            $providerBookingId
        );
        $existingGuestId = !empty($rows) ? (int) ($rows[0]['guest_id'] ?? 0) : 0;
        $guestId = $this->upsertGuest($booking, $existingGuestId);

        if ($guestId <= 0) {
            return [
                'success' => false,
                'message' => \__('Unable to create or update the local guest mirror for a Clock booking.', 'must-hotel-booking'),
            ];
        }

        $assignedRoomId = $this->localPhysicalRoomId($booking);
        $ratePlanId = $this->localRatePlanId($booking);
        $providerStatus = $this->firstString($booking, ['status', 'state']);
        $localStatus = $this->mapReservationStatus($providerStatus);
        $paymentStatus = $this->mapPaymentStatus($booking);
        $totalPrice = $this->bookingTotal($booking);
        $guestCount = $this->guestCount($booking);
        $now = $this->now();
        $metadata = [
            'source' => $source,
            'provider_response' => $this->responseSummary($booking),
            'clock_balance' => isset($booking['balance']) && \is_array($booking['balance']) ? $booking['balance'] : null,
            'clock_total_booking_value' => isset($booking['total_booking_value']) && \is_array($booking['total_booking_value']) ? $booking['total_booking_value'] : null,
            'synced_at' => $now,
        ];
        if (!empty($rows)) {
            $reservationId = (int) ($rows[0]['id'] ?? 0);

            if ($reservationId <= 0) {
                return ['success' => false, 'message' => \__('Matched Clock mirror reservation row is invalid.', 'must-hotel-booking')];
            }

            \MustHotelBooking\Engine\get_reservation_repository()->updateReservation($reservationId, [
                'room_id' => $localRoomTypeId,
                'room_type_id' => $localRoomTypeId,
                'assigned_room_id' => $assignedRoomId,
                'rate_plan_id' => $ratePlanId,
                'guest_id' => $guestId,
                'checkin' => $arrival,
                'checkout' => $departure,
                'guests' => $guestCount,
                'total_price' => $totalPrice,
            ]);
            \MustHotelBooking\Engine\get_reservation_repository()->updateProviderMetadata($reservationId, [
                'provider_status' => $providerStatus,
                'provider_payment_status' => $paymentStatus,
                'provider_sync_status' => 'synced',
                'provider_synced_at' => $now,
                'provider_sync_error' => '',
                'provider_payload_ref' => $providerBookingId,
                'provider_metadata' => $metadata,
            ]);
            (new \MustHotelBooking\Engine\BookingLifecycleSyncService())->applyReservationStatusTransition(
                $reservationId,
                $localStatus,
                $paymentStatus,
                [
                    'source' => $source === 'bulk_sync' ? 'clock_refresh' : 'clock_sync',
                    'operation' => $localStatus === 'cancelled' ? 'cancel_only' : 'status_transition',
                    'idempotency_key' => 'clock-booking-upsert-' . $providerBookingId . '-' . $localStatus . '-' . $paymentStatus,
                ]
            );

            return [
                'success' => true,
                'updated' => true,
                'reservation_id' => $reservationId,
            ];
        }

        $reservationId = \MustHotelBooking\Engine\get_reservation_repository()->createProviderMirrorReservation([
            'booking_id' => 'CLK-' . $providerBookingId,
            'room_id' => $localRoomTypeId,
            'room_type_id' => $localRoomTypeId,
            'assigned_room_id' => $assignedRoomId,
            'rate_plan_id' => $ratePlanId,
            'guest_id' => $guestId,
            'checkin' => $arrival,
            'checkout' => $departure,
            'guests' => $guestCount,
            'status' => \MustHotelBooking\Core\ReservationStatus::isConfirmed($localStatus) ? 'pending' : $localStatus,
            'booking_source' => 'clock_pms',
            'notes' => $this->firstString($booking, ['note', 'notes']),
            'total_price' => $totalPrice,
            'payment_status' => $paymentStatus,
            'confirmation_flow' => 'clock_import',
            'provider' => ProviderManager::CLOCK_MODE,
            'provider_booking_id' => $providerBookingId,
            'provider_reservation_id' => $providerBookingId,
            'provider_status' => $providerStatus,
            'provider_payment_status' => $paymentStatus,
            'provider_sync_status' => 'synced',
            'provider_synced_at' => $now,
            'provider_payload_ref' => $providerBookingId,
            'provider_metadata' => $metadata,
            'created_at' => $this->firstString($booking, ['created_at']) ?: $now,
        ]);

        if ($reservationId <= 0) {
            return [
                'success' => false,
                'message' => \__('Clock booking was fetched, but the local mirror reservation could not be created.', 'must-hotel-booking'),
            ];
        }

        if (\MustHotelBooking\Core\ReservationStatus::isConfirmed($localStatus)) {
            $transition = (new \MustHotelBooking\Engine\BookingLifecycleSyncService())->applyReservationStatusTransition(
                $reservationId,
                $localStatus,
                $paymentStatus,
                [
                    'source' => $source === 'bulk_sync' ? 'clock_refresh' : 'clock_sync',
                    'operation' => 'status_transition',
                    'idempotency_key' => 'clock-booking-create-' . $providerBookingId . '-' . $localStatus . '-' . $paymentStatus,
                ]
            );
            if (empty($transition['success'])) {
                return ['success' => false, 'message' => \__('Clock mirror was created, but its confirmed status was blocked for integrity review.', 'must-hotel-booking'), 'reservation_id' => $reservationId];
            }
        }

        return [
            'success' => true,
            'created' => true,
            'reservation_id' => $reservationId,
        ];
    }

    /** @return array<string, mixed> */
    private function fetchBooking(string $bookingId): array
    {
        $path = ClockConfig::reservationFetchPath() !== '' ? ClockConfig::reservationFetchPath() : '/bookings/{booking_id}';
        $path = \strtr($path, [
            '{booking_id}' => \rawurlencode($bookingId),
            '{reservation_id}' => \rawurlencode($bookingId),
        ]);

        if (\preg_match('/\{[a-z_]+\}/i', $path) === 1) {
            return [];
        }

        $response = $this->client->get(
            $path,
            [],
            'clock.booking.view',
            [
                'api_type' => 'pms_api',
                'endpoint_name' => 'booking',
                'external_id' => $bookingId,
            ]
        );

        if (!$response->isSuccess()) {
            return [];
        }

        return $this->reservationSource($response->getData());
    }

    /** @param mixed $data @return array<int, mixed> */
    private function bookingIndexItems($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if ($this->isList($data)) {
            return \array_values($data);
        }

        foreach (['bookings', 'items', 'data', 'results', 'records'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->isList($data[$key]) ? \array_values($data[$key]) : \array_values($data[$key]);
            }
        }

        return \array_values($data);
    }

    /** @param mixed $item @return array<string, mixed> */
    private function bookingDataFromIndexItem($item): array
    {
        if (!\is_array($item)) {
            return [];
        }

        $candidate = $this->reservationSource($item);

        return $this->isDate($this->firstString($candidate, ['arrival', 'checkin', 'check_in'])) ? $candidate : [];
    }

    /** @param mixed $item */
    private function bookingIdFromIndexItem($item): string
    {
        if (\is_scalar($item)) {
            return \sanitize_text_field((string) $item);
        }

        if (\is_array($item)) {
            return $this->firstString($item, ['id', 'booking_id', 'reservation_id']);
        }

        return '';
    }

    /** @param array<string, mixed> $source @return array<string, mixed> */
    private function reservationSource(array $source): array
    {
        foreach (['booking', 'reservation', 'data', 'result', 'object'] as $key) {
            if (isset($source[$key]) && \is_array($source[$key])) {
                return $this->reservationSource($source[$key]);
            }
        }

        return $source;
    }

    /** @param array<string, mixed> $booking */
    private function upsertGuest(array $booking, int $existingGuestId = 0): int
    {
        $firstName = $this->firstString($booking, ['guest_first_name', 'first_name']);
        $lastName = $this->firstString($booking, ['guest_last_name', 'last_name']);
        $email = $this->firstString($booking, ['guest_e_mail', 'guest_email', 'email']);
        $phone = $this->firstString($booking, ['guest_phone_number', 'phone_number', 'phone']);
        $country = $this->firstString($booking, ['guest_country', 'country']);

        if ($firstName === '' && $lastName === '') {
            $guest = isset($booking['main_booking_guest']) && \is_array($booking['main_booking_guest']) ? $booking['main_booking_guest'] : [];
            $firstName = $this->firstString($guest, ['first_name', 'name']);
            $lastName = $this->firstString($guest, ['last_name']);
            $email = $email !== '' ? $email : $this->firstString($guest, ['email', 'e_mail']);
        }

        return \MustHotelBooking\Engine\get_guest_repository()->saveAdminGuestProfile($existingGuestId, $firstName, $lastName, $email, $phone, $country);
    }

    /** @param array<string, mixed> $booking */
    private function localPhysicalRoomId(array $booking): int
    {
        $clockRoomId = $this->firstString($booking, ['current_room_id', 'arrival_room_id', 'room_id']);
        $mapping = $this->mappingForExternal('physical_room', $clockRoomId);

        return \is_array($mapping) ? (int) ($mapping['local_id'] ?? 0) : 0;
    }

    /** @param array<string, mixed> $booking */
    private function localRatePlanId(array $booking): int
    {
        $clockRateId = $this->firstString($booking, ['rate_id']);
        $mapping = $this->mappingForExternal('rate_plan', $clockRateId);

        return \is_array($mapping) ? (int) ($mapping['local_id'] ?? 0) : 0;
    }

    private function mappingForExternal(string $entityType, string $externalId): ?array
    {
        $externalId = \trim($externalId);

        return $externalId !== ''
            ? $this->mappings->findByExternal(ProviderManager::CLOCK_MODE, $entityType, $externalId)
            : null;
    }

    /** @param array<string, mixed> $booking */
    private function guestCount(array $booking): int
    {
        $adults = isset($booking['adults']) && \is_numeric($booking['adults']) ? (int) $booking['adults'] : 0;
        $children = isset($booking['children']) && \is_numeric($booking['children']) ? (int) $booking['children'] : 0;

        return \max(1, $adults + $children);
    }

    /** @param array<string, mixed> $booking */
    private function bookingTotal(array $booking): float
    {
        $total = $this->moneyAmount($booking['total_booking_value'] ?? null);

        if ($total !== null) {
            return $total;
        }

        if (isset($booking['rate_calculation']) && \is_array($booking['rate_calculation'])) {
            $cents = 0;

            foreach ($booking['rate_calculation'] as $row) {
                if (\is_array($row) && isset($row['cents']) && \is_numeric($row['cents'])) {
                    $cents += (int) $row['cents'];
                }
            }

            if ($cents > 0) {
                return \round($cents / 100, 2);
            }
        }

        return 0.0;
    }

    /** @param array<string, mixed> $booking */
    private function mapPaymentStatus(array $booking): string
    {
        $total = $this->bookingTotal($booking);
        $balance = $this->moneyAmount($booking['balance'] ?? null);

        if ($balance === null) {
            return $total > 0 ? 'unpaid' : 'paid';
        }

        if ($balance <= 0.0) {
            return 'paid';
        }

        if ($total > 0.0 && $balance < $total) {
            return 'partially_paid';
        }

        return 'unpaid';
    }

    /** @param mixed $money */
    private function moneyAmount($money): ?float
    {
        if (!\is_array($money)) {
            return null;
        }

        if (isset($money['cents']) && \is_numeric($money['cents'])) {
            return \round(((int) $money['cents']) / 100, 2);
        }

        if (isset($money['amount']) && \is_numeric($money['amount'])) {
            return \round((float) $money['amount'], 2);
        }

        return null;
    }

    private function mapReservationStatus(string $providerStatus): string
    {
        $status = \sanitize_key(\str_replace([' ', '-'], '_', \strtolower(\trim($providerStatus))));
        $map = [
            'expected' => 'confirmed',
            'checked_in' => 'confirmed',
            'checked_out' => 'completed',
            'canceled' => 'cancelled',
            'cancelled' => 'cancelled',
            'no_show' => 'cancelled',
        ];

        return $map[$status] ?? ($status !== '' ? $status : 'confirmed');
    }

    /** @param array<string, mixed> $booking @return array<string, mixed> */
    private function responseSummary(array $booking): array
    {
        $summary = [];

        foreach (['id', 'number', 'arrival', 'departure', 'status', 'rate_id', 'arrival_room_type_id', 'arrival_room_id', 'current_room_id'] as $key) {
            if (isset($booking[$key]) && \is_scalar($booking[$key])) {
                $summary[$key] = (string) $booking[$key];
            }
        }

        return $summary;
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

    private function isDate(string $date): bool
    {
        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) === 1;
    }

    /** @return array{from: string, to: string} */
    private function dateWindow(int $pastDays, int $futureDays): array
    {
        $today = \function_exists('current_time') ? \current_time('Y-m-d') : \gmdate('Y-m-d');

        try {
            $todayDate = new \DateTimeImmutable($today);
        } catch (\Exception $exception) {
            $todayDate = new \DateTimeImmutable('today');
        }

        return [
            'from' => $todayDate->modify('-' . \max(0, $pastDays) . ' days')->format('Y-m-d'),
            'to' => $todayDate->modify('+' . \max(1, $futureDays) . ' days')->format('Y-m-d'),
        ];
    }

    /** @param array<int|string, mixed> $value */
    private function isList(array $value): bool
    {
        return $value === [] || \array_keys($value) === \range(0, \count($value) - 1);
    }

    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
