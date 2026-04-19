<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;

final class ClockCatalogService
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
    public function fetchCatalog(): array
    {
        return [
            'room_types' => $this->fetchRoomTypes(),
            'rooms' => $this->fetchRooms(),
            'rate_plans' => $this->fetchRatePlans(),
        ];
    }

    /** @return array<string, mixed> */
    public function fetchRoomTypes(): array
    {
        return $this->fetchCollection('room_types', 'clock.catalog.room_types');
    }

    /** @return array<string, mixed> */
    public function fetchRooms(): array
    {
        return $this->fetchCollection('rooms', 'clock.catalog.rooms');
    }

    /** @return array<string, mixed> */
    public function fetchRatePlans(): array
    {
        return $this->fetchCollection('rate_plans', 'clock.catalog.rate_plans');
    }

    /** @param array<string, mixed> $providerItem */
    public function saveAccommodationMapping(int $mustRoomId, array $providerItem): int
    {
        return $this->saveMapping('accommodation', 'must_rooms', $mustRoomId, $providerItem);
    }

    /** @param array<string, mixed> $providerItem */
    public function saveRoomTypeMapping(int $roomTypeId, array $providerItem): int
    {
        return $this->saveMapping('room_type', 'mhb_room_types', $roomTypeId, $providerItem);
    }

    /** @param array<string, mixed> $providerItem */
    public function savePhysicalRoomMapping(int $roomId, array $providerItem): int
    {
        return $this->saveMapping('physical_room', 'mhb_rooms', $roomId, $providerItem);
    }

    /** @param array<string, mixed> $providerItem */
    public function saveRatePlanMapping(int $ratePlanId, array $providerItem): int
    {
        return $this->saveMapping('rate_plan', 'mhb_rate_plans', $ratePlanId, $providerItem);
    }

    /** @return array<string, mixed>|null */
    public function findAccommodationMapping(int $mustRoomId): ?array
    {
        return $this->mappings->findByLocal(ProviderManager::CLOCK_MODE, 'accommodation', $mustRoomId, 'must_rooms');
    }

    /** @return array<string, mixed>|null */
    public function findRatePlanMapping(int $ratePlanId): ?array
    {
        return $this->mappings->findByLocal(ProviderManager::CLOCK_MODE, 'rate_plan', $ratePlanId, 'mhb_rate_plans');
    }

    /** @return array<string, mixed>|null */
    public function findPhysicalRoomMapping(int $roomId): ?array
    {
        return $this->mappings->findByLocal(ProviderManager::CLOCK_MODE, 'physical_room', $roomId, 'mhb_rooms');
    }

    /** @return array<string, mixed> */
    private function fetchCollection(string $catalogKey, string $operation): array
    {
        $paths = ClockConfig::catalogPaths();
        $path = (string) ($paths[$catalogKey] ?? '');

        if ($path === '') {
            return [
                'success' => false,
                'status' => 'path_missing',
                'items' => [],
                'message' => \sprintf(
                    /* translators: %s is a Clock catalog key. */
                    \__('Clock catalog path is not configured for %s.', 'must-hotel-booking'),
                    $catalogKey
                ),
            ];
        }

        $response = $this->client->get($path, $this->catalogQuery(), $operation);

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'status' => $response->isAuthFailure() ? 'auth_failed' : ($response->isConnectivityFailure() ? 'endpoint_unreachable' : 'http_error'),
                'items' => [],
                'message' => $response->getErrorMessage(),
                'http_status' => $response->getStatusCode(),
            ];
        }

        return [
            'success' => true,
            'status' => 'ok',
            'items' => $this->extractItems($response->getData(), $catalogKey),
            'http_status' => $response->getStatusCode(),
        ];
    }

    /** @return array<string, scalar> */
    private function catalogQuery(): array
    {
        $propertyId = ClockConfig::propertyId();

        return $propertyId !== '' ? ['property_id' => $propertyId] : [];
    }

    /**
     * @param mixed $data
     * @return array<int, array<string, mixed>>
     */
    private function extractItems($data, string $catalogKey): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if ($this->isList($data)) {
            return $this->onlyArrays($data);
        }

        foreach ([$catalogKey, 'items', 'data', 'results', 'records'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->onlyArrays($data[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<int|string, mixed> $items
     * @return array<int, array<string, mixed>>
     */
    private function onlyArrays(array $items): array
    {
        $out = [];

        foreach ($items as $item) {
            if (\is_array($item)) {
                $out[] = $item;
            }
        }

        return $out;
    }

    /** @param array<int|string, mixed> $items */
    private function isList(array $items): bool
    {
        return \array_values($items) === $items;
    }

    /** @param array<string, mixed> $providerItem */
    private function saveMapping(string $entityType, string $localTable, int $localId, array $providerItem): int
    {
        if ($localId <= 0) {
            return 0;
        }

        return $this->mappings->save([
            'provider' => ProviderManager::CLOCK_MODE,
            'entity_type' => $entityType,
            'local_table' => $localTable,
            'local_id' => $localId,
            'external_id' => $this->firstScalar($providerItem, ['id', 'external_id', 'uid', 'uuid']),
            'external_code' => $this->firstScalar($providerItem, ['code', 'number', 'slug']),
            'external_parent_id' => $this->firstScalar($providerItem, ['parent_id', 'room_type_id', 'accommodation_id']),
            'display_name' => $this->firstScalar($providerItem, ['name', 'title', 'label']),
            'status' => $this->firstScalar($providerItem, ['status', 'state']) ?: 'active',
            'metadata' => $providerItem,
            'last_synced_at' => \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s'),
        ]);
    }

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstScalar(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                return (string) $source[$key];
            }
        }

        return '';
    }
}
