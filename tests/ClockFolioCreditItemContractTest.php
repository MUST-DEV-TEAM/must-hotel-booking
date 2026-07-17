<?php
declare(strict_types=1);

namespace {
    if (\PHP_SAPI !== 'cli') {
        exit(1);
    }

    function __(string $text, ?string $domain = null): string { unset($domain); return $text; }
    function sanitize_key($value): string { return \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value)); }
    function sanitize_text_field($value): string { return \trim((string) $value); }
}

namespace MustHotelBooking\Core {
    final class MustBookingConfig
    {
        public static function get_all_settings(): array { return []; }
    }
}

namespace MustHotelBooking\Provider\Clock {
    final class FakeCreditItemResponse
    {
        private bool $success;
        /** @var mixed */ private $data;
        private int $status;
        public function __construct(bool $success, $data, int $status = 200)
        {
            $this->success = $success;
            $this->data = $data;
            $this->status = $status;
        }
        public function isSuccess(): bool { return $this->success; }
        public function getData() { return $this->data; }
        public function getStatusCode(): int { return $this->status; }
        public function getErrorCode(): string { return $this->success ? '' : 'request_failed'; }
        public function getErrorMessage(): string { return $this->success ? '' : 'request failed'; }
        public function isRateLimited(): bool { return $this->status === 429; }
        public function isForbidden(): bool { return $this->status === 403; }
    }

    class ClockApiClient
    {
        /** @var array<int, FakeCreditItemResponse> */ public array $responses = [];
        /** @var array<int, array<string, mixed>> */ public array $calls = [];
        public function get(string $path, array $query = [], string $operation = '', array $options = []): FakeCreditItemResponse
        {
            $this->calls[] = \compact('path', 'query', 'operation', 'options');
            return \array_shift($this->responses) ?: new FakeCreditItemResponse(false, [], 500);
        }
        public function request(string $method, string $path, array $options = [], string $operation = ''): FakeCreditItemResponse
        {
            $this->calls[] = \compact('method', 'path', 'options', 'operation');
            return \array_shift($this->responses) ?: new FakeCreditItemResponse(false, [], 500);
        }
    }

    final class ClockEndpointRegistry
    {
        public static function resolvePath(string $name, array $tokens = []): string
        {
            $path = $name === 'folio_credit_items_list'
                ? '/folios/{folio_id}/credit_items'
                : '/folios/{folio_id}/credit_items';
            return \strtr($path, ['{folio_id}' => (string) ($tokens['folio_id'] ?? '')]);
        }
        public static function apiType(string $name): string { unset($name); return 'base_api'; }
    }
}

namespace {
    require __DIR__ . '/../src/Provider/Clock/ClockFolioService.php';

    $failures = [];
    $client = new \MustHotelBooking\Provider\Clock\ClockApiClient();
    $service = new \MustHotelBooking\Provider\Clock\ClockFolioService($client);

    $client->responses = [new \MustHotelBooking\Provider\Clock\FakeCreditItemResponse(true, [
        'items' => [
            ['id' => 'wrong', 'reference' => 're_123', 'value_cents' => -7000, 'currency' => 'EUR'],
            ['id' => 'credit_123', 'reference' => 're_123', 'value_cents' => -8000, 'currency' => 'EUR'],
        ],
    ])];
    $exact = $service->findCreditItemByReference('folio_1', 're_123', -80.0, 'eur', 7);
    if (empty($exact['found']) || (string) ($exact['credit_item_id'] ?? '') !== 'credit_123') {
        $failures[] = 'Credit-item recovery must require exact reference, signed amount, currency, and real item ID.';
    }
    if (($client->calls[0]['query']['reference.eq'] ?? '') !== 're_123' || empty($client->calls[0]['options']['bypass_cache'])) {
        $failures[] = 'Credit-item reconciliation must use the documented equality filter and bypass stale caches.';
    }

    $client->responses = [new \MustHotelBooking\Provider\Clock\FakeCreditItemResponse(true, [
        ['id' => 'credit_a', 'reference' => 're_123', 'value_cents' => -8000, 'currency' => 'EUR'],
        ['id' => 'credit_b', 'reference' => 're_123', 'value_cents' => -8000, 'currency' => 'EUR'],
    ])];
    $duplicate = $service->findCreditItemByReference('folio_1', 're_123', -80.0, 'EUR', 7);
    if (empty($duplicate['ambiguous']) || !empty($duplicate['found'])) {
        $failures[] = 'Multiple exact credit-item matches must stop in an ambiguous state.';
    }

    $client->responses = [
        new \MustHotelBooking\Provider\Clock\FakeCreditItemResponse(true, ['credit_item' => []]),
        new \MustHotelBooking\Provider\Clock\FakeCreditItemResponse(true, []),
    ];
    $missingId = $service->postCreditItem('folio_1', 'stripe', 'refund', -80.0, 'EUR', 're_123', 'local-key', 7);
    if (!empty($missingId['success']) || empty($missingId['ambiguous']) || (string) ($missingId['error_code'] ?? '') !== 'missing_credit_item_id') {
        $failures[] = 'A successful write without a reconcilable Clock item ID must require manual review.';
    }

    if ($failures) {
        echo "FAIL\n" . \implode("\n", $failures) . "\n";
        exit(1);
    }

    echo "Clock folio credit-item contract tests passed.\n";
}
