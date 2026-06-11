<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Core\MustBookingConfig;

final class ClockFolioService
{
    /** @var ClockApiClient */
    private $client;

    public function __construct(?ClockApiClient $client = null)
    {
        $this->client = $client ?: new ClockApiClient();
    }

    /** @return array{success: bool, folio: array<string, mixed>, folio_id: string, message: string} */
    public function selectPaymentFolio(string $clockBookingId, float $amount, string $currency, int $reservationId = 0): array
    {
        $foliosResult = $this->listBookingFolios($clockBookingId, $reservationId);

        if (empty($foliosResult['success'])) {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => (string) ($foliosResult['message'] ?? \__('Unable to list Clock booking folios.', 'must-hotel-booking')),
            ];
        }

        $resolvedFolios = $this->resolveListedFolios((array) ($foliosResult['folios'] ?? []), $reservationId);

        if (empty($resolvedFolios['success'])) {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => (string) ($resolvedFolios['message'] ?? \__('Unable to resolve Clock booking folios.', 'must-hotel-booking')),
            ];
        }

        $folios = $this->postableFolios((array) ($resolvedFolios['folios'] ?? []));

        if (empty($folios)) {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => \__('Clock did not return an open, postable booking folio.', 'must-hotel-booking'),
            ];
        }

        if (\count($folios) === 1) {
            $folio = $folios[0];

            return [
                'success' => true,
                'folio' => $folio,
                'folio_id' => $this->folioId($folio),
                'message' => '',
            ];
        }

        $matches = [];

        foreach ($folios as $folio) {
            if (!$this->folioCurrencyMatches($folio, $currency)) {
                continue;
            }

            $balance = $this->firstMoneyValue($folio, ['balance', 'balance_due', 'due', 'open_balance', 'outstanding_balance']);

            if ($balance !== null && \abs(\abs($balance) - \round($amount, 2)) < 0.01) {
                $matches[] = $folio;
            }
        }

        if (\count($matches) === 1) {
            return [
                'success' => true,
                'folio' => $matches[0],
                'folio_id' => $this->folioId($matches[0]),
                'message' => '',
            ];
        }

        return [
            'success' => false,
            'folio' => [],
            'folio_id' => '',
            'message' => \__('Clock returned multiple open folios and no unambiguous payment target could be selected.', 'must-hotel-booking'),
        ];
    }

    /** @return array{success: bool, folio: array<string, mixed>, folio_id: string, message: string} */
    public function validateRefundFolio(string $clockBookingId, string $folioId, int $reservationId = 0): array
    {
        $folioId = \sanitize_text_field($folioId);

        if ($folioId === '') {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => \__('Refund accounting requires the original Clock payment folio ID.', 'must-hotel-booking'),
            ];
        }

        $foliosResult = $this->listBookingFolios($clockBookingId, $reservationId);

        if (empty($foliosResult['success'])) {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => (string) ($foliosResult['message'] ?? \__('Unable to list Clock booking folios before refund posting.', 'must-hotel-booking')),
            ];
        }

        foreach ((array) ($foliosResult['folios'] ?? []) as $folio) {
            if (!\is_array($folio) || $this->folioId($folio) !== $folioId) {
                continue;
            }

            if ($this->isScalarFolioReference($folio)) {
                $viewResult = $this->viewFolio($folioId, $reservationId);

                if (empty($viewResult['success'])) {
                    return [
                        'success' => false,
                        'folio' => [],
                        'folio_id' => '',
                        'message' => (string) ($viewResult['message'] ?? \__('Unable to verify the original Clock payment folio.', 'must-hotel-booking')),
                    ];
                }

                $folio = (array) ($viewResult['folio'] ?? []);
            }

            if (!$this->isPostableFolio($folio)) {
                return [
                    'success' => false,
                    'folio' => [],
                    'folio_id' => '',
                    'message' => \__('The original Clock folio is closed, voided, correction-only, or otherwise not postable.', 'must-hotel-booking'),
                ];
            }

            return [
                'success' => true,
                'folio' => $folio,
                'folio_id' => $folioId,
                'message' => '',
            ];
        }

        return [
            'success' => false,
            'folio' => [],
            'folio_id' => '',
            'message' => \__('The original Clock payment folio was not returned by booking_folios LIST VIEW.', 'must-hotel-booking'),
        ];
    }

    /** @return array{success: bool, folios: array<int, array<string, mixed>>, message: string} */
    public function listBookingFolios(string $clockBookingId, int $reservationId = 0): array
    {
        $clockBookingId = \sanitize_text_field($clockBookingId);

        if ($clockBookingId === '') {
            return [
                'success' => false,
                'folios' => [],
                'message' => \__('Clock booking ID is missing.', 'must-hotel-booking'),
            ];
        }

        $response = $this->client->request(
            'GET',
            '/bookings/' . \rawurlencode($clockBookingId) . '/folios',
            [
                'api_type' => 'pms_api',
                'reservation_id' => $reservationId,
                'external_id' => $clockBookingId,
            ],
            'clock.booking_folios_list'
        );

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'folios' => [],
                'message' => $response->getErrorMessage() !== ''
                    ? $response->getErrorMessage()
                    : \__('Clock booking folios list request failed.', 'must-hotel-booking'),
            ];
        }

        return [
            'success' => true,
            'folios' => $this->extractFolios($response->getData()),
            'message' => '',
        ];
    }

    /** @return array{success: bool, folio: array<string, mixed>, message: string} */
    private function viewFolio(string $folioId, int $reservationId = 0): array
    {
        $folioId = \sanitize_text_field($folioId);

        if ($folioId === '') {
            return [
                'success' => false,
                'folio' => [],
                'message' => \__('Clock folio ID is missing.', 'must-hotel-booking'),
            ];
        }

        $response = $this->client->request(
            'GET',
            '/folios/' . \rawurlencode($folioId),
            [
                'api_type' => 'base_api',
                'reservation_id' => $reservationId,
                'external_id' => $folioId,
            ],
            'clock.folio_view'
        );

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'folio' => [],
                'message' => $response->getErrorMessage() !== ''
                    ? $response->getErrorMessage()
                    : \__('Clock folio detail request failed.', 'must-hotel-booking'),
            ];
        }

        $folio = $this->extractFolio($response->getData());
        $folioIdFromResponse = $this->folioId($folio);

        if ($folioIdFromResponse === '') {
            $folio['id'] = $folioId;
        }

        return [
            'success' => true,
            'folio' => $folio,
            'message' => '',
        ];
    }

    /** @return array<int, array<string, mixed>> */
    public function paymentSubTypes(): array
    {
        $response = $this->client->request(
            'GET',
            '/payment_sub_types',
            [
                'api_type' => 'base_api',
            ],
            'clock.payment_sub_types_view'
        );

        if (!$response->isSuccess()) {
            return [];
        }

        return $this->extractList($response->getData());
    }

    /** @return array{success: bool, credit_item_id: string, status_code: int, error_code: string, message: string, retryable: bool, forbidden: bool} */
    public function postCreditItem(
        string $folioId,
        string $gateway,
        string $direction,
        float $amount,
        string $currency,
        string $reference,
        string $idempotencyKey,
        int $reservationId = 0
    ): array {
        $folioId = \sanitize_text_field($folioId);
        $gateway = \sanitize_key($gateway);
        $direction = \sanitize_key($direction);
        $gatewayLabel = $gateway === 'pokpay' ? 'PokPay' : ($gateway === 'stripe' ? 'Stripe' : 'Gateway');
        $paymentSubType = $this->paymentSubType($gateway);
        $this->paymentSubTypes();
        $description = $direction === 'refund'
            ? 'Website ' . $gatewayLabel . ' refund'
            : 'Website booking payment via ' . $gatewayLabel;

        $body = [
            'credit_item' => [
                'payment_type' => 'on-line',
                'payment_sub_type' => $paymentSubType,
                'text' => $description,
                'value' => $this->formatMoney($amount),
                'currency' => \strtoupper(\sanitize_text_field($currency)),
                'reference' => \sanitize_text_field($reference),
            ],
        ];

        $response = $this->client->request(
            'POST',
            '/folios/' . \rawurlencode($folioId) . '/credit_items',
            [
                'api_type' => 'base_api',
                'reservation_id' => $reservationId,
                'external_id' => $folioId,
                'idempotency_key' => $idempotencyKey,
                'body' => $body,
            ],
            $direction === 'refund' ? 'clock.refund_credit_item_create' : 'clock.folio_payment_create'
        );

        if (!$response->isSuccess()) {
            $message = $response->getErrorMessage() !== ''
                ? $response->getErrorMessage()
                : \__('Clock credit item create request failed.', 'must-hotel-booking');

            return [
                'success' => false,
                'credit_item_id' => '',
                'status_code' => $response->getStatusCode(),
                'error_code' => $response->getErrorCode(),
                'message' => $message,
                'retryable' => $response->isRetryable(),
                'forbidden' => $response->isForbidden(),
            ];
        }

        return [
            'success' => true,
            'credit_item_id' => $this->extractCreditItemId($response->getData()),
            'status_code' => $response->getStatusCode(),
            'error_code' => '',
            'message' => '',
            'retryable' => false,
            'forbidden' => false,
        ];
    }

    /** @return array{verification_status: string, message: string} */
    public function verifyFolioBalance(string $folioId): array
    {
        $folioId = \sanitize_text_field($folioId);

        if ($folioId === '') {
            return [
                'verification_status' => 'unknown',
                'message' => \__('Clock folio ID is missing.', 'must-hotel-booking'),
            ];
        }

        $viewResult = $this->viewFolio($folioId);

        if (empty($viewResult['success'])) {
            return [
                'verification_status' => 'unknown',
                'message' => (string) ($viewResult['message'] ?? \__('Clock folio verification failed.', 'must-hotel-booking')),
            ];
        }

        $folio = (array) ($viewResult['folio'] ?? []);
        $balance = $this->firstMoneyValue($folio, ['balance', 'balance_due', 'due', 'open_balance', 'outstanding_balance']);

        if ($balance === null) {
            return [
                'verification_status' => 'unknown',
                'message' => \__('Clock folio response did not include a recognizable balance field.', 'must-hotel-booking'),
            ];
        }

        return [
            'verification_status' => \abs($balance) < 0.01 ? 'verified_paid' : 'balance_remaining',
            'message' => '',
        ];
    }

    /** @param mixed $data @return array<int, array<string, mixed>> */
    private function extractFolios($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if ($this->isList($data)) {
            $folios = [];

            foreach ($data as $item) {
                if (\is_array($item)) {
                    $folio = $this->extractFolio($item);

                    if ($this->folioId($folio) !== '') {
                        $folios[] = $folio;
                    }

                    continue;
                }

                if (\is_scalar($item) && \trim((string) $item) !== '') {
                    $folios[] = [
                        'id' => \sanitize_text_field((string) $item),
                        '_scalar_id' => true,
                    ];
                }
            }

            return $folios;
        }

        foreach (['folios', 'booking_folios', 'data', 'items', 'records'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->extractFolios($data[$key]);
            }
        }

        return $this->folioId($data) !== '' ? [$data] : [];
    }

    /** @param mixed $data @return array<int, array<string, mixed>> */
    private function extractList($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if ($this->isList($data)) {
            return \array_values(\array_filter($data, 'is_array'));
        }

        foreach (['folios', 'booking_folios', 'payment_sub_types', 'data', 'items', 'records'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->extractList($data[$key]);
            }
        }

        return $this->folioId($data) !== '' ? [$data] : [];
    }

    /** @param array<string, mixed> $source @return array<int, array<string, mixed>> */
    private function postableFolios(array $source): array
    {
        $folios = [];

        foreach ($source as $folio) {
            if (!\is_array($folio) || !$this->isPostableFolio($folio) || $this->folioId($folio) === '') {
                continue;
            }

            $folios[] = $folio;
        }

        return $folios;
    }

    /** @param array<int, array<string, mixed>> $source @return array{success: bool, folios: array<int, array<string, mixed>>, message: string} */
    private function resolveListedFolios(array $source, int $reservationId): array
    {
        $scalarReferences = [];
        $objectFolios = [];

        foreach ($source as $folio) {
            if (!\is_array($folio)) {
                continue;
            }

            if ($this->isScalarFolioReference($folio)) {
                $folioId = $this->folioId($folio);

                if ($folioId !== '') {
                    $scalarReferences[] = $folio;
                }

                continue;
            }

            $objectFolios[] = $folio;
        }

        if (\count($scalarReferences) === 0) {
            return [
                'success' => true,
                'folios' => $objectFolios,
                'message' => '',
            ];
        }

        if (\count($scalarReferences) === 1 && \count($objectFolios) === 0) {
            return [
                'success' => true,
                'folios' => $scalarReferences,
                'message' => '',
            ];
        }

        $resolved = $objectFolios;
        $errors = [];

        foreach ($scalarReferences as $reference) {
            $folioId = $this->folioId($reference);
            $viewResult = $this->viewFolio($folioId, $reservationId);

            if (empty($viewResult['success'])) {
                $errors[] = (string) ($viewResult['message'] ?? '');
                continue;
            }

            $folio = (array) ($viewResult['folio'] ?? []);

            if ($this->folioId($folio) !== '') {
                $resolved[] = $folio;
            }
        }

        if (\count($resolved) === \count($objectFolios)) {
            return [
                'success' => false,
                'folios' => [],
                'message' => \__('Clock returned multiple folio IDs, but folio detail lookup did not return a usable payment folio.', 'must-hotel-booking') . ($errors ? ' ' . \implode(' ', \array_filter($errors)) : ''),
            ];
        }

        return [
            'success' => true,
            'folios' => $resolved,
            'message' => '',
        ];
    }

    /** @param array<string, mixed> $folio */
    private function isScalarFolioReference(array $folio): bool
    {
        return !empty($folio['_scalar_id']);
    }

    /** @param array<string, mixed> $folio */
    private function isPostableFolio(array $folio): bool
    {
        foreach (['closed', 'is_closed', 'voided', 'is_voided', 'is_correction', 'correction'] as $key) {
            if (!empty($folio[$key])) {
                return false;
            }
        }

        foreach (['status', 'state', 'folio_status', 'kind', 'type'] as $key) {
            if (!isset($folio[$key]) || !\is_scalar($folio[$key])) {
                continue;
            }

            $value = \strtolower(\trim((string) $folio[$key]));

            if (\in_array($value, ['closed', 'voided', 'cancelled', 'canceled', 'correction', 'correction_folio'], true)) {
                return false;
            }
        }

        foreach (['closed_at', 'voided_at', 'cancelled_at', 'canceled_at'] as $key) {
            if (isset($folio[$key]) && \trim((string) $folio[$key]) !== '') {
                return false;
            }
        }

        return true;
    }

    /** @param array<string, mixed> $folio */
    private function folioId(array $folio): string
    {
        foreach (['id', 'folio_id', 'booking_folio_id'] as $key) {
            if (isset($folio[$key]) && \trim((string) $folio[$key]) !== '') {
                return \sanitize_text_field((string) $folio[$key]);
            }
        }

        return '';
    }

    /** @param array<string, mixed> $folio */
    private function folioCurrencyMatches(array $folio, string $currency): bool
    {
        $currency = \strtoupper(\sanitize_text_field($currency));

        if ($currency === '') {
            return true;
        }

        foreach (['currency', 'currency_code'] as $key) {
            if (isset($folio[$key]) && \strtoupper(\trim((string) $folio[$key])) === $currency) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstMoneyValue(array $source, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (!isset($source[$key])) {
                continue;
            }

            if (\is_array($source[$key])) {
                foreach (['amount', 'value', 'cents'] as $moneyKey) {
                    if (!isset($source[$key][$moneyKey]) || !\is_scalar($source[$key][$moneyKey])) {
                        continue;
                    }

                    $normalized = \str_replace(',', '', (string) $source[$key][$moneyKey]);

                    if (\is_numeric($normalized)) {
                        $value = (float) $normalized;

                        return $moneyKey === 'cents' ? \round($value / 100, 2) : \round($value, 2);
                    }
                }

                continue;
            }

            if (!\is_scalar($source[$key])) {
                continue;
            }

            $normalized = \str_replace(',', '', (string) $source[$key]);

            if (\is_numeric($normalized)) {
                return \round((float) $normalized, 2);
            }
        }

        return null;
    }

    /** @param mixed $data @return array<string, mixed> */
    private function extractFolio($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if ($this->folioId($data) !== '' || $this->firstMoneyValue($data, ['balance', 'balance_due', 'due', 'open_balance', 'outstanding_balance']) !== null) {
            return $data;
        }

        foreach (['folio', 'data', 'record'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $this->extractFolio($data[$key]);
            }
        }

        return $data;
    }

    /** @param mixed $data */
    private function extractCreditItemId($data): string
    {
        if (!\is_array($data)) {
            return '';
        }

        foreach (['id', 'credit_item_id', 'payment_id'] as $key) {
            if (isset($data[$key]) && \trim((string) $data[$key]) !== '') {
                return \sanitize_text_field((string) $data[$key]);
            }
        }

        foreach (['credit_item', 'data', 'record'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                $value = $this->extractCreditItemId($data[$key]);

                if ($value !== '') {
                    return $value;
                }
            }
        }

        return '';
    }

    private function paymentSubType(string $gateway): string
    {
        $settings = MustBookingConfig::get_all_settings();
        $gateway = \sanitize_key($gateway);
        $settingKey = $gateway === 'pokpay' ? 'clock_pokpay_payment_sub_type' : 'clock_stripe_payment_sub_type';
        $value = isset($settings[$settingKey]) ? \trim((string) $settings[$settingKey]) : '';

        return $value !== '' ? \sanitize_text_field($value) : ($gateway === 'pokpay' ? 'PokPay' : 'Stripe');
    }

    private function formatMoney(float $amount): string
    {
        return \number_format(\round($amount, 2), 2, '.', '');
    }

    /** @param array<int|string, mixed> $value */
    private function isList(array $value): bool
    {
        if ($value === []) {
            return true;
        }

        return \array_keys($value) === \range(0, \count($value) - 1);
    }
}
