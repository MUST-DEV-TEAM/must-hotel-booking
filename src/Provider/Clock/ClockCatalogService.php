<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderMappingRepository;

final class ClockCatalogService
{
    private const CACHE_OPTION = 'must_hotel_booking_clock_catalog_snapshot';

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
        $collections = [];

        foreach (ClockConfig::catalogEndpoints() as $catalogKey => $endpoint) {
            $collections[$catalogKey] = $this->fetchCollection($catalogKey, $endpoint);
        }

        $errors = $this->collectionErrors($collections);
        $successCount = 0;

        foreach ($collections as $collection) {
            if (!empty($collection['success'])) {
                $successCount++;
            }
        }

        $catalog = [
            'success' => empty($errors),
            'partial_success' => $successCount > 0 && !empty($errors),
            'status' => empty($errors) ? 'ok' : ($successCount > 0 ? 'partial' : 'failed'),
            'fetched_at' => $this->now(),
            'collections' => $collections,
            'errors' => $errors,
        ];

        foreach ($collections as $key => $collection) {
            $catalog[$key] = $collection;
        }

        return $catalog;
    }

    /** @return array<string, mixed> */
    public function refreshCatalog(): array
    {
        $catalog = $this->fetchCatalog();
        $snapshot = $this->snapshotForStorage($catalog);

        if (\function_exists('update_option')) {
            \update_option(self::CACHE_OPTION, $snapshot, false);
        }

        return $snapshot;
    }

    /** @return array<string, mixed> */
    public static function getCachedCatalogSnapshot(): array
    {
        if (!\function_exists('get_option')) {
            return [];
        }

        $snapshot = \get_option(self::CACHE_OPTION, []);

        return \is_array($snapshot) ? $snapshot : [];
    }

    /** @return array<string, mixed> */
    public static function getCachedCatalogSummary(): array
    {
        $snapshot = self::getCachedCatalogSnapshot();
        $collections = isset($snapshot['collections']) && \is_array($snapshot['collections'])
            ? $snapshot['collections']
            : [];
        $counts = [];
        $errors = [];

        foreach ($collections as $key => $collection) {
            if (!\is_array($collection)) {
                continue;
            }

            $items = isset($collection['items']) && \is_array($collection['items']) ? $collection['items'] : [];
            $counts[(string) $key] = isset($collection['raw_item_count']) ? (int) $collection['raw_item_count'] : \count($items);

            if (empty($collection['success'])) {
                $errors[(string) $key] = [
                    'status' => (string) ($collection['status'] ?? 'unknown'),
                    'message' => (string) ($collection['message'] ?? ''),
                    'http_status' => (int) ($collection['http_status'] ?? 0),
                ];
            }
        }

        return [
            'last_fetched_at' => (string) ($snapshot['fetched_at'] ?? ''),
            'status' => (string) ($snapshot['status'] ?? 'missing'),
            'success' => !empty($snapshot['success']),
            'partial_success' => !empty($snapshot['partial_success']),
            'counts' => $counts,
            'errors' => $errors,
        ];
    }

    /** @return array<string, mixed> */
    public function fetchRoomTypes(): array
    {
        $endpoints = ClockConfig::catalogEndpoints();

        return $this->fetchCollection('room_types', $endpoints['room_types']);
    }

    /** @return array<string, mixed> */
    public function fetchRooms(): array
    {
        $endpoints = ClockConfig::catalogEndpoints();

        return $this->fetchCollection('rooms', $endpoints['rooms']);
    }

    /** @return array<string, mixed> */
    public function fetchRates(): array
    {
        $endpoints = ClockConfig::catalogEndpoints();

        return $this->fetchCollection('rates', $endpoints['rates']);
    }

    /** @return array<string, mixed> */
    public function fetchWbeRoomTypeRates(): array
    {
        $endpoints = ClockConfig::catalogEndpoints();

        return $this->fetchCollection('wbe_room_type_rates', $endpoints['wbe_room_type_rates']);
    }

    /** @return array<string, mixed> */
    public function fetchRatePlans(): array
    {
        $endpoints = ClockConfig::catalogEndpoints();

        return $this->fetchCollection('rate_plans', $endpoints['rate_plans']);
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

    /**
     * @param array<string, mixed> $endpoint
     * @return array<string, mixed>
     */
    private function fetchCollection(string $catalogKey, array $endpoint): array
    {
        $path = ClockConfig::normalizeOptionalPath((string) ($endpoint['path'] ?? ''));

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
                'http_status' => 0,
                'response_preview' => [],
            ];
        }

        $query = isset($endpoint['query']) && \is_array($endpoint['query']) ? $endpoint['query'] : [];
        $operation = (string) ($endpoint['operation'] ?? 'clock.catalog.' . $catalogKey);
        $response = $this->client->get($path, $this->sanitizeQuery($query), $operation, [
            'api_type' => (string) ($endpoint['api_type'] ?? 'pms_api'),
            'endpoint_name' => (string) ($endpoint['endpoint_name'] ?? $catalogKey),
        ]);

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'status' => $this->statusForResponse($response),
                'items' => [],
                'message' => $response->getErrorMessage(),
                'http_status' => $response->getStatusCode(),
                'response_preview' => $this->previewData($response->getData(), $response->getBody()),
            ];
        }

        $rawItems = $this->extractItems($response->getData(), $catalogKey);

        return [
            'success' => true,
            'status' => 'ok',
            'items' => $this->normalizeItems($rawItems, $catalogKey),
            'raw_item_count' => \count($rawItems),
            'http_status' => $response->getStatusCode(),
            'response_preview' => $this->previewData($response->getData(), $response->getBody()),
        ];
    }

    /**
     * @param array<int|string, mixed> $query
     * @return array<string, scalar|array<int, scalar>>
     */
    private function sanitizeQuery(array $query): array
    {
        $out = [];

        foreach ($query as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            if (\is_array($value)) {
                $items = [];

                foreach ($value as $item) {
                    if (\is_scalar($item)) {
                        $items[] = $item;
                    }
                }

                $out[$key] = $items;
            } elseif (\is_scalar($value)) {
                $out[$key] = $value;
            }
        }

        return $out;
    }

    /** @param array<string, mixed> $collections @return array<string, string> */
    private function collectionErrors(array $collections): array
    {
        $errors = [];

        foreach ($collections as $key => $collection) {
            if (!\is_array($collection) || !empty($collection['success'])) {
                continue;
            }

            $errors[(string) $key] = (string) ($collection['message'] ?? $collection['status'] ?? 'Clock catalog fetch failed.');
        }

        return $errors;
    }

    /** @param array<string, mixed> $catalog @return array<string, mixed> */
    private function snapshotForStorage(array $catalog): array
    {
        $snapshot = [
            'success' => !empty($catalog['success']),
            'partial_success' => !empty($catalog['partial_success']),
            'status' => (string) ($catalog['status'] ?? 'unknown'),
            'fetched_at' => (string) ($catalog['fetched_at'] ?? $this->now()),
            'errors' => isset($catalog['errors']) && \is_array($catalog['errors']) ? $catalog['errors'] : [],
            'collections' => [],
        ];

        foreach (isset($catalog['collections']) && \is_array($catalog['collections']) ? $catalog['collections'] : [] as $key => $collection) {
            if (!\is_array($collection)) {
                continue;
            }

            $items = isset($collection['items']) && \is_array($collection['items']) ? $collection['items'] : [];
            $snapshot['collections'][(string) $key] = [
                'success' => !empty($collection['success']),
                'status' => (string) ($collection['status'] ?? 'unknown'),
                'items' => \array_slice($items, 0, 250),
                'raw_item_count' => (int) ($collection['raw_item_count'] ?? \count($items)),
                'http_status' => (int) ($collection['http_status'] ?? 0),
                'message' => (string) ($collection['message'] ?? ''),
                'response_preview' => isset($collection['response_preview']) && \is_array($collection['response_preview']) ? $collection['response_preview'] : [],
            ];
        }

        return $snapshot;
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

        foreach ([$catalogKey, 'items', 'data', 'results', 'records', 'collection'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->onlyArrays($data[$key]);
            }
        }

        return [];
    }

    /**
     * @param array<int, array<string, mixed>> $items
     * @return array<int, array<string, mixed>>
     */
    private function normalizeItems(array $items, string $catalogKey): array
    {
        $normalized = [];

        foreach ($items as $item) {
            $normalized[] = $this->normalizeItem($item, $catalogKey);
        }

        return $normalized;
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function normalizeItem(array $item, string $catalogKey): array
    {
        $parentKeys = $catalogKey === 'rooms'
            ? ['room_type_id', 'room_type', 'roomType', 'parent_id', 'accommodation_id']
            : ['rate_plan_id', 'rateplan_id', 'room_type_id', 'bookable_id', 'parent_id'];

        return [
            'id' => $this->firstScalar($item, ['id', 'external_id', 'uid', 'uuid']),
            'name' => $this->firstScalar($item, ['name', 'title', 'label', 'description', 'code', 'number']),
            'code' => $this->firstScalar($item, ['code', 'number', 'slug', 'short_name']),
            'status' => $this->firstScalar($item, ['status', 'state', 'active', 'enabled']),
            'parent_id' => $this->firstScalarOrNestedId($item, $parentKeys),
            'metadata' => $this->compactMetadata($item),
        ];
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
            'external_parent_id' => $this->firstScalar($providerItem, ['parent_id', 'room_type_id', 'accommodation_id', 'rate_plan_id']),
            'display_name' => $this->firstScalar($providerItem, ['name', 'title', 'label']),
            'status' => $this->firstScalar($providerItem, ['status', 'state']) ?: 'active',
            'metadata' => $providerItem,
            'last_synced_at' => $this->now(),
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

    /**
     * @param array<string, mixed> $source
     * @param array<int, string> $keys
     */
    private function firstScalarOrNestedId(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (!isset($source[$key])) {
                continue;
            }

            if (\is_scalar($source[$key])) {
                return (string) $source[$key];
            }

            if (\is_array($source[$key])) {
                foreach (['id', 'external_id', 'uid', 'uuid'] as $nestedKey) {
                    if (isset($source[$key][$nestedKey]) && \is_scalar($source[$key][$nestedKey])) {
                        return (string) $source[$key][$nestedKey];
                    }
                }
            }
        }

        return '';
    }

    /** @param array<string, mixed> $item @return array<string, mixed> */
    private function compactMetadata(array $item): array
    {
        $metadata = [];
        $count = 0;

        foreach ($item as $key => $value) {
            if (!\is_string($key)) {
                continue;
            }

            if (\is_scalar($value) || $value === null) {
                $metadata[$key] = $value;
            } elseif (\is_array($value)) {
                $metadata[$key] = $this->compactArray($value);
            }

            $count++;

            if ($count >= 18) {
                break;
            }
        }

        return $this->redact($metadata);
    }

    /** @param array<int|string, mixed> $value @return array<string, mixed> */
    private function compactArray(array $value): array
    {
        $out = ['type' => 'array', 'count' => \count($value)];

        if (!$this->isList($value)) {
            $out['keys'] = \array_slice(\array_map('strval', \array_keys($value)), 0, 10);
        }

        return $out;
    }

    /**
     * @param mixed $data
     * @return array<string, mixed>
     */
    private function previewData($data, string $body): array
    {
        if (\is_array($data)) {
            return [
                'decoded_type' => 'array',
                'keys' => \array_slice(\array_map('strval', \array_keys($data)), 0, 20),
                'sample' => $this->redact(\array_slice($data, 0, 3, true)),
            ];
        }

        $body = \trim($body);

        return [
            'decoded_type' => \gettype($data),
            'body_preview' => $body !== '' ? \substr((string) \preg_replace('/("(?:authorization|token|secret|password|api[_-]?key|key)"\s*:\s*")[^"]+(")/i', '$1[redacted]$2', $body), 0, 500) : '',
        ];
    }

    /** @param mixed $value @return mixed */
    private function redact($value)
    {
        if (!\is_array($value)) {
            return $value;
        }

        $redacted = [];

        foreach ($value as $key => $item) {
            $keyString = \is_string($key) ? \strtolower($key) : '';
            $redacted[$key] = \preg_match('/(authorization|token|secret|password|api[_-]?key|key)$/i', $keyString) === 1
                ? '[redacted]'
                : $this->redact($item);
        }

        return $redacted;
    }

    private function statusForResponse(ClockApiResponse $response): string
    {
        if ($response->isAuthFailure()) {
            return 'auth_failed';
        }

        if ($response->isForbidden()) {
            return 'forbidden';
        }

        if ($response->isRateLimited()) {
            return 'rate_limited';
        }

        if ($response->isConnectivityFailure()) {
            return 'endpoint_unreachable';
        }

        if ($response->getStatusCode() === 404) {
            return 'bad_endpoint';
        }

        if ($response->getStatusCode() === 422) {
            return 'validation_error';
        }

        if ($response->getStatusCode() >= 500) {
            return 'provider_unavailable';
        }

        return 'http_error';
    }

    private function now(): string
    {
        return \function_exists('current_time') ? \current_time('mysql') : \gmdate('Y-m-d H:i:s');
    }
}
