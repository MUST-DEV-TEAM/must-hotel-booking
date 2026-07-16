<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Engine\InventoryEngine;
use MustHotelBooking\Engine\LockEngine;
use MustHotelBooking\Core\RoomCatalog;
use MustHotelBooking\Core\RoomData;
use MustHotelBooking\Provider\Contracts\AvailabilityProviderInterface;
use MustHotelBooking\Provider\Dto\AvailabilitySearchRequest;
use MustHotelBooking\Provider\Dto\DisabledDatesRequest;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;

final class ClockAvailabilityProvider implements AvailabilityProviderInterface
{
    private const AVAILABILITY_SELECTOR_ROOM_TYPES = 'room_types';

    /** @var ClockApiClient */
    private $client;

    /** @var ClockCatalogService */
    private $catalog;

    /** @var ProviderMappingRepository */
    private $mappings;

    /** @var ClockRoomSelection */
    private $roomSelection;

    /** @var ClockRoomStatusService */
    private $roomStatuses;

    /** @var string */
    private $lastAvailabilityFailureReason = '';

    public function __construct(
        ?ClockApiClient $client = null,
        ?ClockCatalogService $catalog = null,
        ?ProviderMappingRepository $mappings = null,
        ?ClockRoomSelection $roomSelection = null,
        ?ClockRoomStatusService $roomStatuses = null
    )
    {
        $this->client = $client ?: new ClockApiClient();
        $this->catalog = $catalog ?: new ClockCatalogService($this->client);
        $this->mappings = $mappings ?: new ProviderMappingRepository();
        $this->roomSelection = $roomSelection ?: new ClockRoomSelection($this->catalog, $this->mappings);
        $this->roomStatuses = $roomStatuses ?: new ClockRoomStatusService($this->client);
    }

    public function getAvailableRooms(AvailabilitySearchRequest $request): array
    {
        $this->lastAvailabilityFailureReason = '';

        if (!$this->isConfiguredForExactAvailability() || !$this->isValidStay($request->getCheckin(), $request->getCheckout())) {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return [];
        }

        $mapped = $this->mappedSelectionsForSearch($request);
        $roomTypeIds = $this->mappedExternalIds($mapped);
        $rateMappings = $this->mappedRateMappings($roomTypeIds);
        $rateIds = $this->externalIdsFromMappings($rateMappings);

        if (empty($mapped)) {
            if ($this->lastAvailabilityFailureReason === '') {
                $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            }
            return [];
        }

        if (empty($roomTypeIds) || empty($rateIds)) {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return [];
        }

        $response = $this->ratesAvailabilityRequest(
            $request->getCheckin(),
            $request->getCheckout(),
            $rateIds,
            $roomTypeIds,
            self::AVAILABILITY_SELECTOR_ROOM_TYPES,
            'clock.rates_availability.search',
            false,
            $request->getGuests()
        );

        if (!$response->isSuccess()) {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return [];
        }

        $statusFilter = \count($roomTypeIds) === 1 ? $roomTypeIds[0] : '';
        $statusResult = $this->roomStatuses->fetch(
            $request->getCheckin(),
            $this->inclusiveCheckout($request->getCheckout()),
            $statusFilter,
            false,
            'clock.room_statuses.search'
        );

        if (($statusResult['status'] ?? '') !== 'confirmed') {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return [];
        }

        $available = [];
        $hasUnconfirmed = false;

        foreach ($mapped as $row) {
            $room = isset($row['room']) && \is_array($row['room']) ? $row['room'] : [];
            $mapping = isset($row['mapping']) && \is_array($row['mapping']) ? $row['mapping'] : [];
            $availabilityMapping = isset($row['availability_mapping']) && \is_array($row['availability_mapping']) ? $row['availability_mapping'] : [];
            $selection = isset($row['selection']) && \is_array($row['selection']) ? $row['selection'] : [];
            $physicalMapping = isset($selection['physical_mapping']) && \is_array($selection['physical_mapping']) ? $selection['physical_mapping'] : [];
            $typeId = (string) ($mapping['external_id'] ?? '');
            $candidateRateMappings = $this->rateMappingsForType($rateMappings, $typeId);
            $rateResult = $this->rateAvailabilityResult(
                $response->getData(),
                $typeId,
                $candidateRateMappings,
                $request->getCheckin(),
                $request->getCheckout()
            );

            if (($rateResult['status'] ?? '') === 'provider_unconfirmed') {
                $hasUnconfirmed = true;
                continue;
            }

            if (($rateResult['status'] ?? '') !== 'available') {
                continue;
            }

            $physicalResult = $this->physicalRoomStatusResult($statusResult, $typeId, (string) ($availabilityMapping['external_id'] ?? ''));

            if (($physicalResult['status'] ?? '') === 'provider_unconfirmed') {
                $hasUnconfirmed = true;
                continue;
            }

            if (($physicalResult['status'] ?? '') !== 'available') {
                continue;
            }

            if (!$this->isSelectionLocallyAvailable($selection, $request->getCheckin(), $request->getCheckout(), LockEngine::getOrCreateSessionId())) {
                continue;
            }

            $room['available_count'] = 1;
            $room['provider'] = 'clock';
            $room['provider_mapping_id'] = isset($mapping['id']) ? (int) $mapping['id'] : 0;
            $room['provider_room_type_id'] = (string) ($mapping['external_id'] ?? '');
            $room['provider_physical_room_id'] = (string) ($physicalMapping['external_id'] ?? '');
            $room['provider_room_id'] = (string) ($physicalMapping['external_id'] ?? '') !== ''
                ? (string) ($physicalMapping['external_id'] ?? '')
                : (string) ($mapping['external_id'] ?? '');
            $available[] = $room;
        }

        if (empty($available)) {
            $this->lastAvailabilityFailureReason = $hasUnconfirmed ? 'provider_unconfirmed' : 'unavailable';
        }

        return $available;
    }

    public function getAvailableRoomById(int $roomId, string $checkin, string $checkout, int $guests = 1): ?array
    {
        $this->lastAvailabilityFailureReason = '';
        $selection = $this->roomSelection->resolve($roomId);
        $room = \is_array($selection) && isset($selection['room']) && \is_array($selection['room']) ? $selection['room'] : null;

        if (!\is_array($room)) {
            $this->lastAvailabilityFailureReason = 'unavailable';
            return null;
        }

        $maxGuests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

        if ($maxGuests > 0 && \max(1, $guests) > $maxGuests) {
            $this->lastAvailabilityFailureReason = 'unavailable';
            return null;
        }

        if (!$this->checkAvailabilityWithCacheMode($roomId, $checkin, $checkout, '', false, $guests, 0, 'check')) {
            return null;
        }

        $mapping = isset($selection['room_mapping']) && \is_array($selection['room_mapping']) ? $selection['room_mapping'] : null;
        $physicalMapping = isset($selection['physical_mapping']) && \is_array($selection['physical_mapping']) ? $selection['physical_mapping'] : [];
        $room['available_count'] = !empty($selection['is_physical']) ? 1 : $this->availableCountForSelection($selection);
        $room['provider'] = 'clock';
        $room['provider_mapping_id'] = \is_array($mapping) && isset($mapping['id']) ? (int) $mapping['id'] : 0;
        $room['provider_room_type_id'] = \is_array($mapping) ? (string) ($mapping['external_id'] ?? '') : '';
        $room['provider_physical_room_id'] = (string) ($physicalMapping['external_id'] ?? '');
        $room['provider_room_id'] = (string) ($physicalMapping['external_id'] ?? '') !== ''
            ? (string) ($physicalMapping['external_id'] ?? '')
            : (\is_array($mapping) ? (string) ($mapping['external_id'] ?? '') : '');

        return $room;
    }

    public function getLastAvailabilityFailureReason(): string
    {
        return $this->lastAvailabilityFailureReason;
    }

public function getDisabledDates(DisabledDatesRequest $request): array
{
    $this->lastAvailabilityFailureReason = '';
    $windowDays = \max(1, \min(365, $request->getWindowDays()));
    $today = \function_exists('current_time') ? \current_time('Y-m-d') : \gmdate('Y-m-d');

    // The check-in disabled-date window must always start from today.
    // Do not start it from the selected check-in date, or earlier unavailable days disappear.
    $startDate = $today;

    // This is only used for calculating the second calendar / checkout dates.
    $checkoutCheckin = $request->getCheckin();

    if ($checkoutCheckin !== '' && !AvailabilityEngine::isValidBookingDate($checkoutCheckin)) {
        $checkoutCheckin = '';
    }

    if (!$this->isConfiguredForRatesAvailability()) {
        return $this->disabledDatesFailOpen('clock_rates_availability_not_configured');
    }

    $availabilityTarget = $this->availabilityTargetForDisabledDates($request);
    $availabilityIds = isset($availabilityTarget['ids']) && \is_array($availabilityTarget['ids'])
        ? $availabilityTarget['ids']
        : [];
    $availabilitySelector = isset($availabilityTarget['selector']) ? (string) $availabilityTarget['selector'] : '';
    $rateIds = $this->externalIdsFromMappings($this->mappedRateMappings($availabilityIds));

    if (empty($availabilityIds) || empty($rateIds)) {
        return $this->disabledDatesFailOpen(empty($availabilityIds) ? 'clock_room_mapping_missing' : 'clock_rate_mapping_missing');
    }

    try {
        $endDate = (new \DateTimeImmutable($startDate))->modify('+' . $windowDays . ' days')->format('Y-m-d');
    } catch (\Exception $exception) {
        return $this->disabledDatesFailOpen('invalid_disabled_dates_window');
    }

    $response = $this->ratesAvailabilityRequest(
        $startDate,
        $endDate,
        $rateIds,
        $availabilityIds,
        $availabilitySelector,
        'clock.rates_availability.disabled_dates',
        false,
        $request->getGuests()
    );

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

    $selection = $request->getRoomId() > 0 ? $this->roomSelection->resolve($request->getRoomId()) : null;

    if (\is_array($selection) && !empty($selection['is_physical'])) {
        $disabledCheckinDates = \array_values(\array_unique(\array_merge(
            $disabledCheckinDates,
            $this->disabledCheckinDatesForPhysicalSelection($selection, $startDate, $windowDays)
        )));

        \sort($disabledCheckinDates);
    }

    // If no check-in was selected, or the selected check-in is unavailable,
    // calculate checkout availability from the first available check-in date.
    if ($checkoutCheckin === '' || \in_array($checkoutCheckin, $disabledCheckinDates, true)) {
        $checkoutCheckin = $this->firstAvailableDateFromDailyAvailability($dateAvailability, $startDate, $windowDays);
    }

    $disabledCheckoutDates = $checkoutCheckin !== ''
        ? $this->disabledCheckoutDatesFromDailyAvailability(
            $checkoutCheckin,
            $dateAvailability,
            $windowDays,
            \is_array($selection) ? $selection : null
        )
        : [];

    return $this->disabledDatesResponse(
        $disabledCheckinDates,
        $disabledCheckoutDates,
        'clock_rates_availability',
        'ok',
        ''
    );
}
    public function checkAvailability(int $roomId, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        return $this->checkAvailabilityWithCacheMode($roomId, $checkin, $checkout, $excludeSessionId, false, 1, 0, 'check');
    }

    public function checkAvailabilityFresh(
        int $roomId,
        string $checkin,
        string $checkout,
        string $excludeSessionId = '',
        int $guests = 1,
        int $ratePlanId = 0,
        string $operationContext = 'final_revalidation'
    ): bool
    {
        $operationContext = \function_exists('sanitize_key')
            ? \sanitize_key($operationContext)
            : \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', $operationContext));

        if (!\in_array($operationContext, ['final_revalidation', 'paid_fulfilment'], true)) {
            $operationContext = 'final_revalidation';
        }

        if ($ratePlanId <= 0) {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return false;
        }

        return $this->checkAvailabilityWithCacheMode(
            $roomId,
            $checkin,
            $checkout,
            $excludeSessionId,
            true,
            \max(1, $guests),
            \max(0, $ratePlanId),
            $operationContext
        );
    }

    private function checkAvailabilityWithCacheMode(
        int $roomId,
        string $checkin,
        string $checkout,
        string $excludeSessionId,
        bool $bypassCache,
        int $guests,
        int $ratePlanId,
        string $operationContext
    ): bool
    {
        $this->lastAvailabilityFailureReason = '';

        if (!$this->isConfiguredForExactAvailability() || !$this->isValidStay($checkin, $checkout)) {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return false;
        }

        $selection = $this->roomSelection->resolve($roomId);
        $isPhysical = \is_array($selection) && !empty($selection['is_physical']);
        $typeMapping = \is_array($selection) && isset($selection['room_mapping']) && \is_array($selection['room_mapping'])
            ? $selection['room_mapping']
            : null;
        $physicalMapping = $isPhysical && isset($selection['physical_mapping']) && \is_array($selection['physical_mapping'])
            ? $selection['physical_mapping']
            : null;
        $typeId = \is_array($typeMapping) ? (string) ($typeMapping['external_id'] ?? '') : '';
        $rateMappings = $this->mappedRateMappings($typeId !== '' ? [$typeId] : [], $ratePlanId);
        $rateIds = $this->externalIdsFromMappings($rateMappings);
        $room = \is_array($selection) && isset($selection['room']) && \is_array($selection['room']) ? $selection['room'] : [];
        $maxGuests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

        if (!\is_array($selection)
            || !$isPhysical
            || !$this->hasExternalId($typeMapping)
            || !$this->hasExternalId($physicalMapping)
            || empty($rateIds)) {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return false;
        }

        if ($maxGuests > 0 && $guests > $maxGuests) {
            $this->lastAvailabilityFailureReason = 'unavailable';
            return false;
        }

        $operationSuffix = $bypassCache ? $operationContext : 'check';

        $response = $this->ratesAvailabilityRequest(
            $checkin,
            $checkout,
            $rateIds,
            [$typeId],
            self::AVAILABILITY_SELECTOR_ROOM_TYPES,
            'clock.rates_availability.' . $operationSuffix,
            $bypassCache,
            $guests
        );

        if (!$response->isSuccess()) {
            $this->lastAvailabilityFailureReason = 'provider_unconfirmed';
            return false;
        }

        $rateResult = $this->rateAvailabilityResult($response->getData(), $typeId, $rateMappings, $checkin, $checkout);

        if (($rateResult['status'] ?? '') !== 'available') {
            $this->lastAvailabilityFailureReason = ($rateResult['status'] ?? '') === 'unavailable'
                ? 'unavailable'
                : 'provider_unconfirmed';
            return false;
        }

        $statusResult = $this->roomStatuses->fetch(
            $checkin,
            $this->inclusiveCheckout($checkout),
            $typeId,
            $bypassCache,
            'clock.room_statuses.' . $operationSuffix
        );
        $physicalResult = $this->physicalRoomStatusResult(
            $statusResult,
            $typeId,
            (string) ($physicalMapping['external_id'] ?? '')
        );

        if (($physicalResult['status'] ?? '') !== 'available') {
            $this->lastAvailabilityFailureReason = ($physicalResult['status'] ?? '') === 'unavailable'
                ? 'unavailable'
                : 'provider_unconfirmed';
            return false;
        }

        if (!$this->isSelectionLocallyAvailable($selection, $checkin, $checkout, $excludeSessionId)) {
            $this->lastAvailabilityFailureReason = 'unavailable';
            return false;
        }

        return true;
    }

    public function checkBookingRestrictions(int $roomId, string $checkin, string $checkout): bool
    {
        if (!$this->isValidStay($checkin, $checkout)) {
            return false;
        }

        $selection = $this->roomSelection->resolve($roomId);

        return \is_array($selection) && $this->hasExternalId($selection['room_mapping'] ?? null);
    }

    /**
     * @return array<int, array{room: array<string, mixed>, mapping: array<string, mixed>, availability_mapping: array<string, mixed>, selection: array<string, mixed>}>
     */
    private function mappedSelectionsForSearch(AvailabilitySearchRequest $request): array
    {
        $category = RoomCatalog::normalizeBookingCategory($request->getCategory());
        $selections = [];
        $mapped = [];
        $hasSelection = false;
        $hasCapacityEligibleSelection = false;

        if (RoomCatalog::isRoomTypeBookingValue($category)) {
            $roomTypeId = RoomCatalog::resolveBookingRoomTypeId($category);
            $selections = $this->roomSelection->physicalRoomSelectionsForType($roomTypeId);

            if (empty($selections)) {
                $selection = $this->roomSelection->resolve($roomTypeId);
                $selections = \is_array($selection) ? [$selection] : [];
            }
        } elseif (RoomCatalog::isBookingAllCategory($category) && RoomCatalog::isClockBackendMode()) {
            foreach (\array_keys(RoomCatalog::getClockBookingRoomTypes()) as $bookingCategory) {
                $roomTypeId = RoomCatalog::resolveBookingRoomTypeId((string) $bookingCategory);

                if ($roomTypeId <= 0) {
                    continue;
                }

                $typeSelections = $this->roomSelection->physicalRoomSelectionsForType($roomTypeId);

                if (!empty($typeSelections)) {
                    $selections = \array_merge($selections, $typeSelections);
                    continue;
                }

                $selection = $this->roomSelection->resolve($roomTypeId);

                if (\is_array($selection)) {
                    $selections[] = $selection;
                }
            }
        } else {
            foreach (\MustHotelBooking\Engine\get_room_repository()->getRoomsByType($category, $request->getGuests()) as $room) {
                if (!\is_array($room)) {
                    continue;
                }

                $roomId = isset($room['id']) ? (int) $room['id'] : 0;
                $typeSelections = $this->roomSelection->physicalRoomSelectionsForType($roomId);

                if (!empty($typeSelections)) {
                    $selections = \array_merge($selections, $typeSelections);
                    continue;
                }

                $selection = $this->roomSelection->resolve($roomId);

                if (\is_array($selection)) {
                    $selections[] = $selection;
                }
            }
        }

        foreach ($selections as $selection) {
            $hasSelection = true;
            $room = isset($selection['room']) && \is_array($selection['room']) ? $selection['room'] : [];
            $mapping = isset($selection['room_mapping']) && \is_array($selection['room_mapping']) ? $selection['room_mapping'] : [];
            $availabilityMapping = isset($selection['physical_mapping']) && \is_array($selection['physical_mapping']) ? $selection['physical_mapping'] : [];
            $maxGuests = isset($room['max_guests']) ? (int) $room['max_guests'] : 0;

            if ($maxGuests > 0 && $request->getGuests() > $maxGuests) {
                continue;
            }

            $hasCapacityEligibleSelection = true;

            if (empty($selection['is_physical']) || !$this->hasExternalId($mapping) || !$this->hasExternalId($availabilityMapping)) {
                continue;
            }

            $mapped[] = [
                'room' => $room,
                'mapping' => $mapping,
                'availability_mapping' => $availabilityMapping,
                'selection' => $selection,
            ];
        }

        if (empty($mapped) && $hasSelection && !$hasCapacityEligibleSelection) {
            $this->lastAvailabilityFailureReason = 'unavailable';
        }

        return $mapped;
    }

    /**
     * @param array<int, array{room: array<string, mixed>, mapping: array<string, mixed>, availability_mapping: array<string, mixed>, selection: array<string, mixed>}> $mapped
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

    /**
     * @param mixed $data
     * @param array<int, array<string, mixed>> $rateMappings
     * @return array{status:string,reason:string,rate_id:string}
     */
    private function rateAvailabilityResult(
        $data,
        string $roomTypeId,
        array $rateMappings,
        string $checkin,
        string $checkout
    ): array {
        $unconfirmed = ['status' => 'provider_unconfirmed', 'reason' => 'rate_evidence_missing', 'rate_id' => ''];
        if (!\is_array($data) || \array_values($data) !== $data || empty($rateMappings)) {
            return $unconfirmed;
        }

        $matches = [];
        foreach ($data as $item) {
            if (!\is_array($item)
                || !isset($item['id'])
                || !\is_scalar($item['id'])
                || (string) $item['id'] !== $roomTypeId) {
                continue;
            }

            if (!isset($item['type']) || !\is_scalar($item['type']) || (string) $item['type'] !== 'Pms::RoomType') {
                return ['status' => 'provider_unconfirmed', 'reason' => 'rate_resource_malformed', 'rate_id' => ''];
            }

            $matches[] = $item;
        }

        if (\count($matches) !== 1 || !isset($matches[0]['rates']) || !\is_array($matches[0]['rates'])) {
            return $unconfirmed;
        }

        $stayDates = $this->stayDates($checkin, $checkout);
        if (empty($stayDates)) {
            return $unconfirmed;
        }

        $rates = $matches[0]['rates'];
        $hasUnconfirmed = false;

        foreach ($rateMappings as $mapping) {
            $rateId = (string) ($mapping['external_id'] ?? '');
            if ($rateId === '' || !\array_key_exists($rateId, $rates) || !\is_array($rates[$rateId])) {
                $hasUnconfirmed = true;
                continue;
            }

            $rateRows = $rates[$rateId];
            $rateUnconfirmed = false;
            $rateUnavailable = $this->hasContradictoryRateRestriction($rateRows);

            if (!$rateUnavailable) {
                foreach ($stayDates as $date) {
                    if (!isset($rateRows[$date]) || !\is_array($rateRows[$date]) || !\array_key_exists('free', $rateRows[$date]) || !\is_bool($rateRows[$date]['free'])) {
                        $rateUnconfirmed = true;
                        break;
                    }

                    if ($rateRows[$date]['free'] !== true || $this->hasContradictoryRateRestriction($rateRows[$date])) {
                        $rateUnavailable = true;
                        break;
                    }
                }
            }

            if (!$rateUnconfirmed
                && !$rateUnavailable
                && isset($rateRows[$checkout])
                && \is_array($rateRows[$checkout])
                && $this->hasClosedDepartureRestriction($rateRows[$checkout])) {
                $rateUnavailable = true;
            }

            if (!$rateUnconfirmed && !$rateUnavailable) {
                return ['status' => 'available', 'reason' => 'confirmed', 'rate_id' => $rateId];
            }

            if ($rateUnconfirmed) {
                $hasUnconfirmed = true;
            }
        }

        return $hasUnconfirmed
            ? $unconfirmed
            : ['status' => 'unavailable', 'reason' => 'rate_unavailable', 'rate_id' => ''];
    }

    /** @param array<string, mixed> $entry */
    private function hasContradictoryRateRestriction(array $entry): bool
    {
        foreach (['error', 'errors'] as $key) {
            if (!\array_key_exists($key, $entry)) {
                continue;
            }

            $error = $entry[$key];
            if ((\is_array($error) && !empty($error)) || (\is_scalar($error) && \trim((string) $error) !== '')) {
                return true;
            }
        }

        foreach (['stop_sale', 'stop_sell', 'stop_sales', 'closed', 'closed_arrival', 'closed_departure', 'closed_for_arrival', 'closed_for_departure', 'closed_to_arrival', 'closed_to_departure', 'cta', 'ctd'] as $key) {
            if (\array_key_exists($key, $entry) && $this->truthy($entry[$key])) {
                return true;
            }
        }

        foreach (['restrictions', 'restriction'] as $key) {
            if (isset($entry[$key]) && \is_array($entry[$key]) && $this->hasContradictoryRateRestriction($entry[$key])) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $entry */
    private function hasClosedDepartureRestriction(array $entry): bool
    {
        foreach (['closed_departure', 'closed_for_departure', 'closed_to_departure', 'ctd'] as $key) {
            if (\array_key_exists($key, $entry) && $this->truthy($entry[$key])) {
                return true;
            }
        }

        foreach (['restrictions', 'restriction'] as $key) {
            if (isset($entry[$key]) && \is_array($entry[$key]) && $this->hasClosedDepartureRestriction($entry[$key])) {
                return true;
            }
        }

        return false;
    }

    /**
     * @param array<string, mixed> $statusResult
     * @return array{status:string,reason:string,rate_id:string}
     */
    private function physicalRoomStatusResult(array $statusResult, string $roomTypeId, string $physicalRoomId): array
    {
        if (($statusResult['status'] ?? '') !== 'confirmed') {
            return ['status' => 'provider_unconfirmed', 'reason' => (string) ($statusResult['reason'] ?? 'status_unconfirmed'), 'rate_id' => ''];
        }

        $rooms = isset($statusResult['rooms']) && \is_array($statusResult['rooms']) ? $statusResult['rooms'] : [];
        if ($physicalRoomId === '' || !isset($rooms[$physicalRoomId]) || !\is_array($rooms[$physicalRoomId])) {
            return ['status' => 'provider_unconfirmed', 'reason' => 'physical_room_missing', 'rate_id' => ''];
        }

        $room = $rooms[$physicalRoomId];
        if ((string) ($room['room_type_id'] ?? '') !== $roomTypeId || !\array_key_exists('available', $room) || !\is_bool($room['available'])) {
            return ['status' => 'provider_unconfirmed', 'reason' => 'physical_room_mismatch', 'rate_id' => ''];
        }

        return $room['available']
            ? ['status' => 'available', 'reason' => 'confirmed', 'rate_id' => '']
            : ['status' => 'unavailable', 'reason' => 'physical_room_unavailable', 'rate_id' => ''];
    }

    private function inclusiveCheckout(string $checkout): string
    {
        try {
            return (new \DateTimeImmutable($checkout))->modify('-1 day')->format('Y-m-d');
        } catch (\Exception $exception) {
            return '';
        }
    }

    private function isConfiguredForRatesAvailability(): bool
    {
        return ClockConfig::isConfigured() && ClockConfig::ratesAvailabilityPath() !== '';
    }

    private function isConfiguredForExactAvailability(): bool
    {
        return $this->isConfiguredForRatesAvailability() && ClockConfig::roomStatusesPath() !== '';
    }

    /**
     * @param array<int, string> $rateIds
     * @param array<int, string> $availabilityIds
     */
    private function ratesAvailabilityRequest(
        string $from,
        string $to,
        array $rateIds,
        array $availabilityIds,
        string $selector,
        string $operation,
        bool $bypassCache = false,
        int $adults = 1
    ): ClockApiResponse
    {
        if (!$this->isAvailabilitySelector($selector) || empty($this->numericIds($availabilityIds))) {
            return new ClockApiResponse(
                0,
                '',
                null,
                'clock_availability_selector_invalid',
                'Clock rate availability requires one or more explicit room-type selectors.'
            );
        }

        return $this->client->get(
            $this->ratesAvailabilityPathWithQuery($from, $to, $rateIds, $availabilityIds, $selector, $adults),
            [],
            $operation,
            [
                'api_type' => 'pms_api',
                'endpoint_name' => 'rates_availability',
                'cache_ttl' => 45,
                'timeout' => 8,
                'bypass_cache' => $bypassCache,
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

        \ksort($out, \SORT_NUMERIC);

        return \array_values($out);
    }

    /**
     * Clock documents rates_availability as GET with rates[]=ID and either
     * rooms[]=ID or room_types[]=ID.
     * WordPress add_query_arg serializes arrays as rates[0]=ID, which Clock rejects.
     *
     * @param array<int, string> $rateIds
     * @param array<int, string> $availabilityIds
     */
    private function ratesAvailabilityPathWithQuery(
        string $from,
        string $to,
        array $rateIds,
        array $availabilityIds,
        string $selector,
        int $adults = 1
    ): string
    {
        if (!$this->isAvailabilitySelector($selector)) {
            return '';
        }

        $parts = [
            $this->queryPair('from', $from),
            $this->queryPair('to', $to),
        ];

        foreach ($this->numericIds($rateIds) as $rateId) {
            $parts[] = $this->queryPair('rates[]', $rateId);
        }

        foreach ($this->numericIds($availabilityIds) as $availabilityId) {
            $parts[] = $this->queryPair($selector . '[]', $availabilityId);
        }

        $parts[] = $this->queryPair('adults', (string) \max(1, $adults));

        return $this->appendQuery(ClockConfig::ratesAvailabilityPath(), $parts);
    }

    private function isAvailabilitySelector(string $selector): bool
    {
        return $selector === self::AVAILABILITY_SELECTOR_ROOM_TYPES;
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

    /**
     * @param array<int, string> $roomTypeIds
     * @return array<int, array<string, mixed>>
     */
    private function mappedRateMappings(array $roomTypeIds, int $selectedRatePlanId = 0): array
    {
        $typeSet = [];

        foreach ($this->numericIds($roomTypeIds) as $roomTypeId) {
            $typeSet[$roomTypeId] = true;
        }

        if (empty($typeSet)) {
            return [];
        }

        $mappings = $selectedRatePlanId > 0
            ? [$this->catalog->findRatePlanMapping($selectedRatePlanId)]
            : $this->mappings->listForProvider(ProviderManager::CLOCK_MODE, 'rate_plan');
        $out = [];

        foreach ($mappings as $mapping) {
            if (!\is_array($mapping) || !$this->isApplicablePublicRateMapping($mapping)) {
                continue;
            }

            $parentId = \trim((string) ($mapping['external_parent_id'] ?? ''));
            $externalId = \trim((string) ($mapping['external_id'] ?? ''));

            if ($externalId !== '' && isset($typeSet[$parentId])) {
                $out[$externalId] = $mapping;
            }
        }

        \ksort($out, \SORT_NUMERIC);

        return \array_values($out);
    }

    /** @param array<string, mixed> $mapping */
    private function isApplicablePublicRateMapping(array $mapping): bool
    {
        $status = \strtolower(\trim((string) ($mapping['status'] ?? '')));
        if (!\in_array($status, ['1', 'true', 'active', 'enabled', 'open', 'published'], true)) {
            return false;
        }

        $metadata = $this->mappingMetadata($mapping);
        if ($this->hasInactiveProviderRateSignal($metadata)) {
            return false;
        }

        $visibility = $this->nestedMappingMetadataScalar($metadata, ['public_visible']);
        if ($visibility === '' || !$this->truthy($visibility)) {
            return false;
        }

        $bookableType = $this->nestedMappingMetadataScalar($metadata, ['bookable_type']);

        return $bookableType === 'Pms::RoomType';
    }

    /**
     * @param array<string, mixed> $metadata
     * @param array<int, string> $keys
     */
    private function nestedMappingMetadataScalar(array $metadata, array $keys): string
    {
        foreach ($this->mappingMetadataSources($metadata) as $source) {
            foreach ($keys as $key) {
                if (\array_key_exists($key, $source) && \is_scalar($source[$key])) {
                    return \trim((string) $source[$key]);
                }
            }
        }

        return '';
    }

    /** @param array<string, mixed> $metadata */
    private function hasInactiveProviderRateSignal(array $metadata): bool
    {
        foreach ($this->mappingMetadataSources($metadata) as $source) {
            foreach (['active', 'enabled'] as $key) {
                if (\array_key_exists($key, $source) && \is_scalar($source[$key]) && !$this->truthy($source[$key])) {
                    return true;
                }
            }

            foreach (['status', 'state'] as $key) {
                if (!\array_key_exists($key, $source) || !\is_scalar($source[$key])) {
                    continue;
                }

                $status = \strtolower(\trim((string) $source[$key]));
                if (!\in_array($status, ['1', 'true', 'active', 'enabled', 'open', 'published'], true)) {
                    return true;
                }
            }
        }

        return false;
    }

    /** @param array<string, mixed> $metadata @return array<int, array<string, mixed>> */
    private function mappingMetadataSources(array $metadata): array
    {
        $sources = [$metadata];

        if (isset($metadata['metadata']) && \is_array($metadata['metadata'])) {
            $sources[] = $metadata['metadata'];
        }

        if (isset($metadata['clock_catalog_item']) && \is_array($metadata['clock_catalog_item'])) {
            $sources[] = $metadata['clock_catalog_item'];

            if (isset($metadata['clock_catalog_item']['metadata']) && \is_array($metadata['clock_catalog_item']['metadata'])) {
                $sources[] = $metadata['clock_catalog_item']['metadata'];
            }
        }

        return $sources;
    }

    /** @param array<string, mixed> $mapping @return array<string, mixed> */
    private function mappingMetadata(array $mapping): array
    {
        $metadata = $mapping['metadata'] ?? [];

        if (\is_array($metadata)) {
            return $metadata;
        }

        if (\is_string($metadata) && $metadata !== '') {
            $decoded = \json_decode($metadata, true);

            return \is_array($decoded) ? $decoded : [];
        }

        return [];
    }

    /** @param array<int, array<string, mixed>> $mappings @return array<int, string> */
    private function externalIdsFromMappings(array $mappings): array
    {
        $ids = [];

        foreach ($mappings as $mapping) {
            $externalId = \trim((string) ($mapping['external_id'] ?? ''));
            if ($externalId !== '') {
                $ids[$externalId] = $externalId;
            }
        }

        return $this->numericIds(\array_values($ids));
    }

    /**
     * @param array<int, array<string, mixed>> $mappings
     * @return array<int, array<string, mixed>>
     */
    private function rateMappingsForType(array $mappings, string $roomTypeId): array
    {
        return \array_values(\array_filter(
            $mappings,
            static function (array $mapping) use ($roomTypeId): bool {
                return (string) ($mapping['external_parent_id'] ?? '') === $roomTypeId;
            }
        ));
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

    /** @return array{selector:string,ids:array<int, string>} */
    private function availabilityTargetForDisabledDates(DisabledDatesRequest $request): array
    {
        $roomId = $request->getRoomId();

        if ($roomId > 0) {
            $selection = $this->roomSelection->resolve($roomId);
            $mapping = \is_array($selection) && isset($selection['room_mapping']) && \is_array($selection['room_mapping'])
                ? $selection['room_mapping']
                : null;

            return [
                'selector' => self::AVAILABILITY_SELECTOR_ROOM_TYPES,
                'ids' => $this->hasExternalId($mapping) ? [(string) ($mapping['external_id'] ?? '')] : [],
            ];
        }

        $category = RoomCatalog::normalizeBookingCategory($request->getCategory());

        if (RoomCatalog::isRoomTypeBookingValue($category)) {
            $mapping = $this->catalog->findAccommodationMapping(RoomCatalog::resolveBookingRoomTypeId($category));

            return [
                'selector' => self::AVAILABILITY_SELECTOR_ROOM_TYPES,
                'ids' => $this->hasExternalId($mapping) ? [(string) ($mapping['external_id'] ?? '')] : [],
            ];
        }

        return [
            'selector' => self::AVAILABILITY_SELECTOR_ROOM_TYPES,
            'ids' => $this->mappedAccommodationIds(),
        ];
    }

    /** @param array<string, mixed> $selection */
    private function isSelectionLocallyAvailable(array $selection, string $checkin, string $checkout, string $excludeSessionId = ''): bool
    {
        if (empty($selection['is_physical'])) {
            return true;
        }

        $physicalRoomId = isset($selection['physical_room_id']) ? (int) $selection['physical_room_id'] : 0;
        $roomTypeId = isset($selection['room_type_id']) ? (int) $selection['room_type_id'] : 0;

        if ($physicalRoomId <= 0 || $roomTypeId <= 0) {
            return false;
        }

        $availableRooms = InventoryEngine::getAvailableRooms($roomTypeId, $checkin, $checkout, $excludeSessionId);

        foreach ($availableRooms as $room) {
            if (\is_array($room) && (int) ($room['id'] ?? 0) === $physicalRoomId) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed>|null $selection */
    private function availableCountForSelection(?array $selection): int
    {
        if (\is_array($selection) && !empty($selection['is_physical'])) {
            return 1;
        }

        return 1;
    }

    /**
     * @param array<string, mixed> $selection
     * @return array<int, string>
     */
    private function disabledCheckinDatesForPhysicalSelection(array $selection, string $startDate, int $windowDays): array
    {
        $disabled = [];
        $roomTypeId = isset($selection['room_type_id']) ? (int) $selection['room_type_id'] : 0;
        $physicalRoomId = isset($selection['physical_room_id']) ? (int) $selection['physical_room_id'] : 0;

        if ($roomTypeId <= 0 || $physicalRoomId <= 0) {
            return $disabled;
        }

        foreach ($this->allDatesFrom($startDate, $windowDays) as $date) {
            $checkout = $this->nextDate($date);

            if ($checkout === '' || $this->isSelectionLocallyAvailable($selection, $date, $checkout, LockEngine::getOrCreateSessionId())) {
                continue;
            }

            $disabled[] = $date;
        }

        return $disabled;
    }

    /**
     * @param array<string, bool> $dateAvailability
     */
    private function firstAvailableDateFromDailyAvailability(array $dateAvailability, string $startDate, int $windowDays): string
    {
        foreach ($this->allDatesFrom($startDate, $windowDays) as $date) {
            if (!empty($dateAvailability[$date])) {
                return $date;
            }
        }

        return '';
    }

    /**
     * @param array<string, bool> $dateAvailability
     * @param array<string, mixed>|null $selection
     * @return array<int, string>
     */
    private function disabledCheckoutDatesFromDailyAvailability(string $checkin, array $dateAvailability, int $windowDays, ?array $selection = null): array
    {
        if (!AvailabilityEngine::isValidBookingDate($checkin)) {
            return [];
        }

        $disabled = [];

        try {
            $checkinDate = new \DateTimeImmutable($checkin);
        } catch (\Exception $exception) {
            return $disabled;
        }

        for ($nights = 1; $nights <= $windowDays; $nights++) {
            $checkout = $checkinDate->modify('+' . $nights . ' days')->format('Y-m-d');
            $stayDates = $this->stayDates($checkin, $checkout);
            $available = !empty($stayDates);

            foreach ($stayDates as $stayDate) {
                if (empty($dateAvailability[$stayDate])) {
                    $available = false;
                    break;
                }
            }

            if (
                $available &&
                \is_array($selection) &&
                !empty($selection['is_physical']) &&
                !$this->isSelectionLocallyAvailable($selection, $checkin, $checkout, LockEngine::getOrCreateSessionId())
            ) {
                $available = false;
            }

            if (!$available) {
                $disabled[] = $checkout;
            }
        }

        return $disabled;
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

        if ($this->hasContradictoryRateRestriction($entry)) {
            $signal = false;
        } elseif (\array_key_exists('free', $entry)) {
            $signal = \is_bool($entry['free']) ? $entry['free'] : false;
        } else {
            $signal = $this->availabilitySignal($entry);
        }

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

    private function nextDate(string $date): string
    {
        if (!AvailabilityEngine::isValidBookingDate($date)) {
            return '';
        }

        try {
            return (new \DateTimeImmutable($date))->modify('+1 day')->format('Y-m-d');
        } catch (\Exception $exception) {
            return '';
        }
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
