<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;
use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\DisabledDatesRequest;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;

final class ClockAvailabilityProvider implements AvailabilityProviderInterface
{
    /** @var ClockApiClient */
    private $client;

    /** @var ClockCatalogService */
    private $catalog;

    /** @var ProviderMappingRepository */
    private $mappings;

    public function __construct(?ClockApiClient $client = null, ?ClockCatalogService $catalog = null, ?ProviderMappingRepository $mappings = null)
    {
        $this->client = $client ?: new ClockApiClient();
        $this->catalog = $catalog ?: new ClockCatalogService($this->client);
        $this->mappings = $mappings ?: new ProviderMappingRepository();
    }

    public function getAvailableRooms(AvailabilitySearchRequest $request): array
    {
        if (!$this->isConfiguredForRatesAvailability() || !$this->isValidStay($request->getCheckin(), $request->getCheckout())) {
            return [];
        }

        $category = RoomCatalog::normalizeBookingCategory($request->getCategory());
        $candidates = [];

        if (RoomCatalog::isRoomTypeBookingValue($category)) {
            $room = RoomData::getRoom(RoomCatalog::resolveBookingRoomTypeId($category));
            $maxGuests = \is_array($room) && isset($room['max_guests']) ? (int) $room['max_guests'] : 0;
            $candidates = \is_array($room) && $maxGuests >= $request->getGuests() ? [$room] : [];
        } else {
            $candidates = \MustHotelBooking\Engine\get_room_repository()->getRoomsByType($category, $request->getGuests());
        }
        $mapped = $this->mappedRooms($candidates);
        $rateIds = $this->mappedRateIds();

        if (empty($mapped) || empty($rateIds)) {
            return [];
        }

        $response = $this->ratesAvailabilityRequest(
            $request->getCheckin(),
            $request->getCheckout(),
            $rateIds,
            $this->mappedExternalIds($mapped),
            'clock.rates_availability.search'
        );

        if (!$response->isSuccess()) {
            return [];
        }

        $items = $this->extractItems($response->getData());
        $available = [];

        foreach ($mapped as $row) {
            $room = isset($row['room']) && \is_array($row['room']) ? $row['room'] : [];
            $mapping = isset($row['mapping']) && \is_array($row['mapping']) ? $row['mapping'] : [];
            $availability = $this->findAvailabilityForMapping($items, $mapping, \count($mapped) === 1 ? $response->getData() : null);

            if (!$this->isAvailableForStay($availability, $request->getCheckin(), $request->getCheckout())) {
                continue;
            }

            $room['available_count'] = $this->availableCount($availability);
            $room['provider'] = 'clock';
            $room['provider_mapping_id'] = isset($mapping['id']) ? (int) $mapping['id'] : 0;
            $room['provider_room_id'] = (string) ($mapping['external_id'] ?? '');
            $available[] = $room;
        }

        return $available;
    }

    public function getAvailableRoomById(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array
    {
        $room = \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId);

        if (!\is_array($room)) {
            return null;
        }

        $maxGuests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

        if ($maxGuests > 0 && \max(1, $guests) > $maxGuests) {
            return null;
        }

        if (!$this->checkAvailability($roomId, $checkin, $checkout)) {
            return null;
        }

        $mapping = $this->catalog->findAccommodationMapping($roomId);
        $room['available_count'] = 1;
        $room['provider'] = 'clock';
        $room['provider_mapping_id'] = \is_array($mapping) && isset($mapping['id']) ? (int) $mapping['id'] : 0;
        $room['provider_room_id'] = \is_array($mapping) ? (string) ($mapping['external_id'] ?? '') : '';

        return $room;
    }

    public function getDisabledDates(DisabledDatesRequest $request): array
    {
        $windowDays = \max(1, \min(365, $request->getWindowDays()));
        $startDate = $request->getCheckin() !== '' && AvailabilityEngine::isValidBookingDate($request->getCheckin())
            ? $request->getCheckin()
            : (\function_exists('current_time') ? \current_time('Y-m-d') : \gmdate('Y-m-d'));

        if (!$this->isConfiguredForRatesAvailability()) {
            return $this->disabledDatesFailOpen('clock_rates_availability_not_configured');
        }

        $roomTypeIds = $this->roomTypeIdsForDisabledDates($request);
        $rateIds = $this->mappedRateIds();

        if (empty($roomTypeIds) || empty($rateIds)) {
            return $this->disabledDatesFailOpen(empty($roomTypeIds) ? 'clock_room_mapping_missing' : 'clock_rate_mapping_missing');
        }

        try {
            $endDate = (new \DateTimeImmutable($startDate))->modify('+' . $windowDays . ' days')->format('Y-m-d');
        } catch (\Exception $exception) {
            return $this->disabledDatesFailOpen('invalid_disabled_dates_window');
        }

        $response = $this->ratesAvailabilityRequest($startDate, $endDate, $rateIds, $roomTypeIds, 'clock.rates_availability.disabled_dates');

        if (!$response->isSuccess()) {
            return $this->disabledDatesFailOpen(
                'clock_rates_availability_request_failed',
                $response->getErrorMessage()
            );
        }

        $dateAvailability = $this->dateAvailabilityFromRatesAvailability($response->getData());

        if (empty($dateAvailability)) {
            return $this->disabledDatesFailOpen('clock_rates_availability_response_unparseable');
        }

        $disabledCheckinDates = [];
        foreach ($this->allDatesFrom($startDate, $windowDays) as $date) {
            if (empty($dateAvailability[$date])) {
                $disabledCheckinDates[] = $date;
            }
        }

        return $this->disabledDatesResponse(
            $disabledCheckinDates,
            [],
            'clock_rates_availability',
            'ok',
            ''
        );
    }

    public function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        unset($excludeSessionId);

        if (!$this->isConfiguredForRatesAvailability() || !$this->isValidStay($checkin, $checkout)) {
            return false;
        }

        $room = \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId);
        $mapping = $this->catalog->findAccommodationMapping($roomId);
        $rateIds = $this->mappedRateIds();

        if (!\is_array($room) || !$this->hasExternalId($mapping) || empty($rateIds)) {
            return false;
        }

        $response = $this->ratesAvailabilityRequest(
            $checkin,
            $checkout,
            $rateIds,
            [(string) ($mapping['external_id'] ?? '')],
            'clock.rates_availability.check'
        );

        if (!$response->isSuccess()) {
            return false;
        }

        $availability = $this->findAvailabilityForMapping($this->extractItems($response->getData()), $mapping, $response->getData());

        return $this->isAvailableForStay($availability, $checkin, $checkout);
    }

    public function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool
    {
        if (!$this->isValidStay($checkin, $checkout)) {
            return false;
        }

        return $roomId > 0 && $this->hasExternalId($this->catalog->findAccommodationMapping($roomId));
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<int, array{room: array<string, mixed>, mapping: array<string, mixed>}>
     */
    private function mappedRooms(array $rooms): array
    {
        $mapped = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $mapping = $this->catalog->findAccommodationMapping($roomId);

            if (!$this->hasExternalId($mapping)) {
                continue;
            }

            $mapped[] = [
                'room' => $room,
                'mapping' => $mapping,
            ];
        }

        return $mapped;
    }

    /**
     * @param array<int, array{room: array<string, mixed>, mapping: array<string, mixed>}> $mapped
     * @return array<int, string>
     */
    private function mappedExternalIds(array $mapped): array
    {
        $ids = [];

        foreach ($mapped as $row) {
            $mapping = $row['mapping'];
            $externalId = (string) ($mapping['external_id'] ?? '');

            if ($externalId !== '') {
                $ids[$externalId] = $externalId;
            }
        }

        return \array_values($ids);
    }

    /** @param mixed $data @return array<int, array<string, mixed>> */
    private function extractItems($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if (\array_values($data) === $data) {
            return $this->onlyArrayItems($data);
        }

        foreach (['available_rooms', 'rooms', 'accommodations', 'availability', 'items', 'data', 'results', 'room_types'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->onlyArrayItems($data[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function onlyArrayItems(array $items): array
    {
        $out = [];

        foreach ($items as $key => $item) {
            if (!\is_array($item)) {
                continue;
            }

            if (\is_scalar($key) && !isset($item['id'])) {
                $item['_response_key'] = (string) $key;
            }

            $out[] = $item;
        }

        return $out;
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @param array<string, mixed> $mapping
     * @param mixed $singleResponse
     * @return array<string, mixed>|null
     */
    private function findAvailabilityForMapping(array $items, array $mapping, $singleResponse): ?array
    {
        foreach ($items as $item) {
            if ($this->matchesMapping($item, $mapping)) {
                return $item;
            }
        }

        return \is_array($singleResponse) && $this->availabilitySignal($singleResponse) !== null
            ? $singleResponse
            : null;
    }

    /** @param array<string, mixed>|null $availability */
    private function isAvailable(?array $availability): bool
    {
        return $availability !== null && $this->availabilitySignal($availability) === true;
    }

    /** @param array<string, mixed>|null $availability */
    private function isAvailableForStay(?array $availability, string $checkin, string $checkout): bool
    {
        if ($availability === null || !$this->isValidStay($checkin, $checkout)) {
            return false;
        }

        $stayDates = $this->stayDates($checkin, $checkout);

        if (empty($stayDates)) {
            return false;
        }

        $rateEntries = $this->rateAvailabilityEntries($availability);

        foreach ($rateEntries as $entries) {
            if ($this->entriesAvailableForStay($entries, $stayDates)) {
                return true;
            }
        }

        if (!empty($rateEntries)) {
            return false;
        }

        $entries = $this->availabilityEntries($availability);

        if (!empty($entries)) {
            return $this->entriesAvailableForStay($entries, $stayDates);
        }

        return $this->isAvailable($availability);
    }

    /** @param array<string, mixed> $availability */
    private function availableCount(array $availability): int
    {
        foreach (['available_count', 'rooms_available', 'units_available', 'room_type_free_rooms', 'count', 'quantity'] as $key) {
            if (isset($availability[$key]) && \is_numeric($availability[$key])) {
                return \max(1, (int) $availability[$key]);
            }
        }

        foreach ($this->availabilityEntries($availability) as $entry) {
            if (!$this->isAvailable($entry)) {
                continue;
            }

            $count = $this->availableCount($entry);

            if ($count > 0) {
                return $count;
            }
        }

        return 1;
    }

    /** @param array<string, mixed> $availability */
    private function availabilitySignal(array $availability): ?bool
    {
        foreach (['available', 'is_available', 'bookable', 'free'] as $key) {
            if (\array_key_exists($key, $availability)) {
                return $this->truthy($availability[$key]);
            }
        }

        foreach (['available_count', 'rooms_available', 'units_available', 'room_type_free_rooms', 'free_rooms', 'available_units', 'available_rooms_count', 'availability', 'count', 'quantity'] as $key) {
            if (isset($availability[$key]) && \is_numeric($availability[$key])) {
                return (int) $availability[$key] > 0;
            }
        }

        foreach (['closed', 'stop_sell', 'stop_sales', 'sold_out'] as $key) {
            if (\array_key_exists($key, $availability) && $this->truthy($availability[$key])) {
                return false;
            }
        }

        foreach (['status', 'state'] as $key) {
            if (isset($availability[$key]) && \is_scalar($availability[$key])) {
                $status = \sanitize_key((string) $availability[$key]);

                if (\in_array($status, ['available', 'bookable', 'open', 'ok'], true)) {
                    return true;
                }

                if (\in_array($status, ['unavailable', 'closed', 'sold_out', 'sold-out', 'not_available', 'no_availability', 'fully_booked'], true)) {
                    return false;
                }
            }
        }

        foreach ($this->availabilityEntries($availability) as $entry) {
            if ($this->isAvailable($entry)) {
                return true;
            }
        }

        return null;
    }

    /** @param array<string, mixed> $item @param array<string, mixed> $mapping */
    private function matchesMapping(array $item, array $mapping): bool
    {
        $externalId = (string) ($mapping['external_id'] ?? '');
        $externalCode = (string) ($mapping['external_code'] ?? '');

        foreach (['id', 'external_id', 'room_type_id', 'room_id', 'accommodation_id', 'resource_id', 'unit_group_id', '_response_key'] as $key) {
            if (isset($item[$key]) && \is_scalar($item[$key]) && $externalId !== '' && (string) $item[$key] === $externalId) {
                return true;
            }
        }

        foreach (['code', 'room_code', 'external_code'] as $key) {
            if (isset($item[$key]) && \is_scalar($item[$key]) && $externalCode !== '' && (string) $item[$key] === $externalCode) {
                return true;
            }
        }

        return false;
    }

    private function isConfiguredForRatesAvailability(): bool
    {
        return ClockConfig::isConfigured() && ClockConfig::ratesAvailabilityPath() !== '';
    }

    /**
     * @param array<int, string> $rateIds
     * @param array<int, string> $roomTypeIds
     */
    private function ratesAvailabilityRequest(string $from, string $to, array $rateIds, array $roomTypeIds, string $operation): ClockApiResponse
    {
        return $this->client->get(
            $this->ratesAvailabilityPathWithQuery($from, $to, $rateIds, $roomTypeIds),
            [],
            $operation,
            [
                'api_type' => 'pms_api',
                'endpoint_name' => 'rates_availability',
            ]
        );
    }

    /**
     * @param array<int, string> $ids
     * @return array<int, string>
     */
    private function numericIds(array $ids): array
    {
        $out = [];

        foreach ($ids as $id) {
            $id = \trim((string) $id);

            if ($id !== '' && \ctype_digit($id)) {
                $out[(int) $id] = $id;
            }
        }

        return \array_values($out);
    }

    /**
     * Clock documents rates_availability as GET with rates[]=ID and room_types[]=ID.
     * WordPress add_query_arg serializes arrays as rates[0]=ID, which Clock rejects.
     *
     * @param array<int, string> $rateIds
     * @param array<int, string> $roomTypeIds
     */
    private function ratesAvailabilityPathWithQuery(string $from, string $to, array $rateIds, array $roomTypeIds): string
    {
        $parts = [
            $this->queryPair('from', $from),
            $this->queryPair('to', $to),
        ];

        foreach ($this->numericIds($rateIds) as $rateId) {
            $parts[] = $this->queryPair('rates[]', $rateId);
        }

        foreach ($this->numericIds($roomTypeIds) as $roomTypeId) {
            $parts[] = $this->queryPair('room_types[]', $roomTypeId);
        }

        return $this->appendQuery(ClockConfig::ratesAvailabilityPath(), $parts);
    }

    /** @param array<int, string> $parts */
    private function appendQuery(string $path, array $parts): string
    {
        $separator = \strpos($path, '?') === false ? '?' : '&';

        return $path . $separator . \implode('&', $parts);
    }

    private function queryPair(string $key, string $value): string
    {
        return \rawurlencode($key) . '=' . \rawurlencode($value);
    }

    /** @return array<int, string> */
    private function mappedRateIds(): array
    {
        $ids = [];

        foreach ($this->mappings->listForProvider(ProviderManager::CLOCK_MODE, 'rate_plan') as $mapping) {
            $externalId = \trim((string) ($mapping['external_id'] ?? ''));

            if ($externalId !== '') {
                $ids[$externalId] = $externalId;
            }
        }

        return \array_values($ids);
    }

    /** @return array<int, string> */
    private function mappedAccommodationIds(): array
    {
        $ids = [];

        foreach ($this->mappings->listForProvider(ProviderManager::CLOCK_MODE, 'accommodation') as $mapping) {
            $externalId = \trim((string) ($mapping['external_id'] ?? ''));

            if ($externalId !== '') {
                $ids[$externalId] = $externalId;
            }
        }

        return \array_values($ids);
    }

    /** @return array<int, string> */
    private function roomTypeIdsForDisabledDates(DisabledDatesRequest $request): array
    {
        $roomId = $request->getRoomId();

        if ($roomId > 0) {
            $mapping = $this->catalog->findAccommodationMapping($roomId);

            return $this->hasExternalId($mapping) ? [(string) ($mapping['external_id'] ?? '')] : [];
        }

        $category = RoomCatalog::normalizeBookingCategory($request->getCategory());

        if (RoomCatalog::isRoomTypeBookingValue($category)) {
            $mapping = $this->catalog->findAccommodationMapping(RoomCatalog::resolveBookingRoomTypeId($category));

            return $this->hasExternalId($mapping) ? [(string) ($mapping['external_id'] ?? '')] : [];
        }

        return $this->mappedAccommodationIds();
    }

    /**
     * @return array<string, bool>
     */
    private function dateAvailabilityFromRatesAvailability($data): array
    {
        $dates = [];

        foreach ($this->extractItems($data) as $item) {
            $this->collectDateAvailability($item, $dates);
        }

        if (\is_array($data)) {
            $this->collectDateAvailability($data, $dates);
        }

        return $dates;
    }

    /**
     * @param mixed $value
     * @param array<string, bool> $dates
     */
    private function collectDateAvailability($value, array &$dates): void
    {
        if (!\is_array($value)) {
            return;
        }

        $rowDate = $this->dateFromAvailabilityRow($value);

        if ($rowDate !== '') {
            $this->recordDateAvailability($rowDate, $value, $dates);
        }

        foreach ($value as $key => $entry) {
            if (!\is_array($entry)) {
                continue;
            }

            if (\is_string($key) && $this->isIsoDate($key)) {
                $this->recordDateAvailability($key, $entry, $dates);
            }

            $this->collectDateAvailability($entry, $dates);
        }
    }

    /**
     * @param array<string, mixed> $entry
     * @param array<string, bool> $dates
     */
    private function recordDateAvailability(string $date, array $entry, array &$dates): void
    {
        if (!$this->isIsoDate($date)) {
            return;
        }

        $signal = $this->availabilitySignal($entry);

        if ($signal === null) {
            foreach ($entry as $child) {
                if (\is_array($child)) {
                    $this->recordDateAvailability($date, $child, $dates);
                }
            }

            return;
        }

        if (!isset($dates[$date])) {
            $dates[$date] = false;
        }

        if ($signal) {
            $dates[$date] = true;
        }
    }

    /** @param array<string, mixed> $row */
    private function dateFromAvailabilityRow(array $row): string
    {
        foreach (['date', 'day', 'business_date', 'arrival', 'from', 'start_date'] as $key) {
            if (isset($row[$key]) && \is_scalar($row[$key])) {
                $date = \substr((string) $row[$key], 0, 10);

                if ($this->isIsoDate($date)) {
                    return $date;
                }
            }
        }

        return '';
    }

    /**
     * @param array<string, mixed> $item
     * @return array<string, array<string, mixed>>
     */
    private function availabilityEntries(array $item): array
    {
        $entries = [];
        $rates = isset($item['rates']) && \is_array($item['rates']) ? $item['rates'] : [];

        foreach ($rates as $rateRows) {
            if (!\is_array($rateRows)) {
                continue;
            }

            foreach ($rateRows as $date => $entry) {
                if (\is_string($date) && $this->isIsoDate($date) && \is_array($entry)) {
                    $entries[$date] = $entry;
                }
            }
        }

        if (empty($entries)) {
            foreach ($item as $date => $entry) {
                if (\is_string($date) && $this->isIsoDate($date) && \is_array($entry)) {
                    $entries[$date] = $entry;
                }
            }
        }

        return $entries;
    }

    /**
     * @param array<string, mixed> $item
     * @return array<int, array<string, array<string, mixed>>>
     */
    private function rateAvailabilityEntries(array $item): array
    {
        $rates = isset($item['rates']) && \is_array($item['rates']) ? $item['rates'] : [];
        $out = [];

        foreach ($rates as $rateRows) {
            if (!\is_array($rateRows)) {
                continue;
            }

            $entries = [];

            foreach ($rateRows as $date => $entry) {
                if (\is_string($date) && $this->isIsoDate($date) && \is_array($entry)) {
                    $entries[$date] = $entry;
                    continue;
                }

                if (\is_array($entry)) {
                    $rowDate = $this->dateFromAvailabilityRow($entry);

                    if ($rowDate !== '') {
                        $entries[$rowDate] = $entry;
                    }
                }
            }

            if (!empty($entries)) {
                $out[] = $entries;
            }
        }

        return $out;
    }

    /**
     * @param array<string, array<string, mixed>> $entries
     * @param array<int, string> $stayDates
     */
    private function entriesAvailableForStay(array $entries, array $stayDates): bool
    {
        foreach ($stayDates as $date) {
            if (!isset($entries[$date]) || $this->availabilitySignal($entries[$date]) !== true) {
                return false;
            }
        }

        return true;
    }

    private function isIsoDate(string $value): bool
    {
        return \preg_match('/^\d{4}-\d{2}-\d{2}$/', $value) === 1;
    }

    /** @return array<int, string> */
    private function allDatesFrom(string $startDate, int $days): array
    {
        $dates = [];

        try {
            $start = new \DateTimeImmutable($startDate);
        } catch (\Exception $exception) {
            return $dates;
        }

        for ($index = 0; $index < $days; $index++) {
            $dates[] = $start->modify('+' . $index . ' days')->format('Y-m-d');
        }

        return $dates;
    }

    /** @return array<int, string> */
    private function stayDates(string $checkin, string $checkout): array
    {
        if (!$this->isValidStay($checkin, $checkout)) {
            return [];
        }

        try {
            $start = new \DateTimeImmutable($checkin);
            $end = new \DateTimeImmutable($checkout);
        } catch (\Exception $exception) {
            return [];
        }

        $nights = (int) $start->diff($end)->days;
        $dates = [];

        for ($index = 0; $index < $nights; $index++) {
            $dates[] = $start->modify('+' . $index . ' days')->format('Y-m-d');
        }

        return $dates;
    }

    /** @param mixed $value */
    private function truthy($value): bool
    {
        if (\is_bool($value)) {
            return $value;
        }

        if (\is_numeric($value)) {
            return (int) $value > 0;
        }

        return \in_array(\strtolower(\trim((string) $value)), ['1', 'true', 'yes', 'available', 'bookable', 'ok'], true);
    }

    /** @param array<string, mixed>|null $mapping */
    private function hasExternalId(?array $mapping): bool
    {
        return \is_array($mapping) && (string) ($mapping['external_id'] ?? '') !== '';
    }

    private function isValidStay(string $checkin, string $checkout): bool
    {
        return AvailabilityEngine::isValidBookingDate($checkin)
            && AvailabilityEngine::isValidBookingDate($checkout)
            && $checkin < $checkout;
    }

    /**
     * Disabled-date calendars are only a frontend hint. If Clock cannot return
     * a confidently parsed window, fail open here and let search/checkout keep
     * enforcing provider availability strictly.
     *
     * @return array<string, mixed>
     */
    private function disabledDatesFailOpen(string $reason, string $message = ''): array
    {
        return $this->disabledDatesResponse([], [], 'clock_rates_availability', $reason, $message);
    }

    /**
     * @param array<int, string> $checkinDates
     * @param array<int, string> $checkoutDates
     * @return array<string, mixed>
     */
    private function disabledDatesResponse(array $checkinDates, array $checkoutDates, string $source, string $status, string $message): array
    {
        return [
            'disabled_checkin_dates' => \array_values($checkinDates),
            'disabled_checkout_dates' => \array_values($checkoutDates),
            'disabled_dates_source' => $source,
            'disabled_dates_status' => $status,
            'disabled_dates_message' => $message,
        ];
    }
}
