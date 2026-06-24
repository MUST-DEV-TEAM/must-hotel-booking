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
    public function validateRefundFolio(
        string $clockBookingId,
        string $folioId,
        int $reservationId = 0,
        bool $requireUnusedDeposit = false
    ): array
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
            if ($requireUnusedDeposit && !$this->isDepositFolio($folio)) {
                return [
                    'success' => false,
                    'folio' => [],
                    'folio_id' => '',
                    'message' => \__('Automatic refund accounting is allowed only while the original website payment remains on an open Clock deposit folio.', 'must-hotel-booking'),
                ];
            }
            if ($requireUnusedDeposit && $this->hasDepositApplicationMarker($folio)) {
                return [
                    'success' => false,
                    'folio' => [],
                    'folio_id' => '',
                    'message' => \__('The Clock deposit appears transferred, deducted, or applied. Automatic refund accounting stopped for manual review.', 'must-hotel-booking'),
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

    /** @return array{success: bool, balances: array<string, float|null>, message: string} */
    public function readStandardFolioBalances(
        string $clockBookingId,
        int $reservationId = 0,
        string $excludeFolioId = ''
    ): array {
        $foliosResult = $this->listBookingFolios($clockBookingId, $reservationId);

        if (empty($foliosResult['success'])) {
            return [
                'success' => false,
                'balances' => [],
                'message' => (string) ($foliosResult['message'] ?? \__('Unable to list Clock booking folios.', 'must-hotel-booking')),
            ];
        }

        $resolved = $this->resolveListedFolios((array) ($foliosResult['folios'] ?? []), $reservationId);

        if (empty($resolved['success'])) {
            return [
                'success' => false,
                'balances' => [],
                'message' => (string) ($resolved['message'] ?? \__('Unable to resolve Clock booking folios.', 'must-hotel-booking')),
            ];
        }

        $balances = [];

        foreach ((array) ($resolved['folios'] ?? []) as $folio) {
            if (!\is_array($folio) || $this->isDepositFolio($folio)) {
                continue;
            }

            $folioId = $this->folioId($folio);

            if ($folioId === '' || $folioId === $excludeFolioId) {
                continue;
            }

            $balances[$folioId] = $this->firstMoneyValue(
                $folio,
                ['balance', 'balance_due', 'due', 'open_balance', 'outstanding_balance']
            );
        }

        return [
            'success' => true,
            'balances' => $balances,
            'message' => '',
        ];
    }
    /** @return array{success: bool, folio: array<string, mixed>, folio_id: string, message: string} */
    public function selectOrCreateDepositFolio(string $clockBookingId, string $currency, int $reservationId = 0, string $preferredFolioId = ''): array
    {
        $clockBookingId = \sanitize_text_field($clockBookingId);
        $preferredFolioId = \sanitize_text_field($preferredFolioId);
        if ($clockBookingId === '') {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => \__('Clock booking ID is missing.', 'must-hotel-booking'),
            ];
        }
        if ($preferredFolioId !== '') {
            $preferred = $this->viewFolio($preferredFolioId, $reservationId);
            if (!empty($preferred['success'])) {
                $folio = (array) ($preferred['folio'] ?? []);
                if ($this->isPostableFolio($folio) && $this->isDepositFolio($folio)) {
                    return [
                        'success' => true,
                        'folio' => $folio,
                        'folio_id' => $preferredFolioId,
                        'message' => '',
                    ];
                }
            }
        }
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
        $depositFolios = [];
        if (!empty($resolvedFolios['success'])) {
            foreach ((array) ($resolvedFolios['folios'] ?? []) as $folio) {
                if (
                    \is_array($folio)
                    && $this->isPostableFolio($folio)
                    && $this->isDepositFolio($folio)
                    && $this->folioCurrencyMatches($folio, $currency)
                    && $this->folioId($folio) !== ''
                ) {
                    $depositFolios[] = $folio;
                }
            }
        }
        if (\count($depositFolios) === 1) {
            return [
                'success' => true,
                'folio' => $depositFolios[0],
                'folio_id' => $this->folioId($depositFolios[0]),
                'message' => '',
            ];
        }
        return $this->createDepositFolio($clockBookingId, $reservationId);
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
        $path = ClockEndpointRegistry::resolvePath('booking_folios_list', ['booking_id' => $clockBookingId]);
        $response = $this->client->request(
            'GET',
            $path,
            [
                'api_type' => ClockEndpointRegistry::apiType('booking_folios_list'),
                'reservation_id' => $reservationId,
                'external_id' => $clockBookingId,
                'endpoint_name' => 'booking_folios_list',
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
    /** @return array{success: bool, folio: array<string, mixed>, folio_id: string, message: string} */
    private function createDepositFolio(string $clockBookingId, int $reservationId = 0): array
    {
        $path = ClockEndpointRegistry::resolvePath('booking_deposit_folio_create', ['booking_id' => $clockBookingId]);
        $body = [
            'booking_folio' => [
                'deposit' => true,
            ],
        ];
        $response = $this->client->request(
            'POST',
            $path,
            [
                'api_type' => ClockEndpointRegistry::apiType('booking_deposit_folio_create'),
                'reservation_id' => $reservationId,
                'external_id' => $clockBookingId,
                'body' => $body,
                'endpoint_name' => 'booking_deposit_folio_create',
            ],
            'clock.booking_deposit_folio_create'
        );
        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => $response->getErrorMessage() !== ''
                    ? $response->getErrorMessage()
                    : \__('Clock deposit folio create request failed.', 'must-hotel-booking'),
            ];
        }
        $folio = $this->extractFolio($response->getData());
        $folioId = $this->folioId($folio);
        if ($folioId === '') {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => \__(
                    'Clock created a deposit folio but did not return its ID.',
                    'must-hotel-booking'
                ),
            ];
        }
        /*
         * Do not trust only the create response.
         * Read the folio back and verify that Clock actually marked it as a deposit.
         */
        $verification = $this->viewFolio($folioId, $reservationId);
        if (empty($verification['success'])) {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => \__(
                    'Clock created a folio, but the plugin could not verify that it is a deposit folio. No payment was posted.',
                    'must-hotel-booking'
                ),
            ];
        }
        $verifiedFolio = isset($verification['folio']) && \is_array($verification['folio'])
            ? $verification['folio']
            : [];
        if (
            !$this->isPostableFolio($verifiedFolio)
            || !$this->isDepositFolio($verifiedFolio)
        ) {
            return [
                'success' => false,
                'folio' => [],
                'folio_id' => '',
                'message' => \__(
                    'Clock did not confirm the newly created folio as an open deposit folio. The normal booking folio was left untouched.',
                    'must-hotel-booking'
                ),
            ];
        }
        return [
            'success' => true,
            'folio' => $verifiedFolio,
            'folio_id' => $folioId,
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
        $path = ClockEndpointRegistry::resolvePath('folio_view', ['folio_id' => $folioId]);
        $response = $this->client->request(
            'GET',
            $path,
            [
                'api_type' => ClockEndpointRegistry::apiType('folio_view'),
                'reservation_id' => $reservationId,
                'external_id' => $folioId,
                'endpoint_name' => 'folio_view',
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
            ClockEndpointRegistry::resolvePath('payment_sub_types'),
            [
                'api_type' => ClockEndpointRegistry::apiType('payment_sub_types'),
                'endpoint_name' => 'payment_sub_types',
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
        $description = $direction === 'refund'
            ? 'Website ' . $gatewayLabel . ' refund'
            : ($direction === 'deposit' ? 'Website booking deposit via ' . $gatewayLabel : 'Website booking payment via ' . $gatewayLabel);
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
        $endpointName = $direction === 'deposit' ? 'booking_deposit_payment_create' : 'folio_credit_item_create';
        $path = ClockEndpointRegistry::resolvePath($endpointName, ['folio_id' => $folioId]);
        $response = $this->client->request(
            'POST',
            $path,
            [
                'api_type' => ClockEndpointRegistry::apiType($endpointName),
                'reservation_id' => $reservationId,
                'external_id' => $folioId,
                'idempotency_key' => $idempotencyKey,
                'body' => $body,
                'endpoint_name' => $endpointName,
            ],
            $direction === 'refund' ? 'clock.refund_credit_item_create' : ($direction === 'deposit' ? 'clock.deposit_payment_create' : 'clock.folio_payment_create')
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

    /** @return array{success: bool, balance: float|null, raw_balance: float|null, deposit: bool, postable: bool, currency: string, message: string} */
    public function readFolioBalance(string $folioId, int $reservationId = 0): array
    {
        $viewResult = $this->viewFolio($folioId, $reservationId);

        if (empty($viewResult['success'])) {
            return [
                'success' => false,
                'balance' => null,
                'raw_balance' => null,
                'deposit' => false,
                'postable' => false,
                'currency' => '',
                'message' => (string) ($viewResult['message'] ?? \__('Clock folio verification failed.', 'must-hotel-booking')),
            ];
        }

        $folio = (array) ($viewResult['folio'] ?? []);
        $balance = $this->firstMoneyValue(
            $folio,
            ['balance', 'balance_due', 'due', 'open_balance', 'outstanding_balance']
        );

        return [
            'success' => $balance !== null,
            'balance' => $balance,
            'raw_balance' => $balance,
            'deposit' => $this->isDepositFolio($folio),
            'postable' => $this->isPostableFolio($folio),
            'currency' => $this->folioCurrency($folio),
            'message' => $balance === null
                ? \__('Clock folio response did not include a recognizable balance field.', 'must-hotel-booking')
                : '',
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
    private function isDepositFolio(array $folio): bool
    {
        if (isset($folio['deposit'])) {
            return \filter_var($folio['deposit'], \FILTER_VALIDATE_BOOLEAN);
        }
        foreach (['folio_type', 'type', 'kind', 'purpose'] as $key) {
            if (!isset($folio[$key]) || !\is_scalar($folio[$key])) {
                continue;
            }
            $value = \strtolower(\trim((string) $folio[$key]));
            if (\in_array($value, ['deposit', 'deposit_folio', 'booking_deposit'], true)) {
                return true;
            }
        }
        return false;
    }

    /** @param array<string, mixed> $folio */
    private function hasDepositApplicationMarker(array $folio): bool
    {
        foreach ($folio as $key => $value) {
            $normalizedKey = \strtolower((string) $key);

            if (
                \preg_match('/(transfer(_to)?_id|credit_amount_transfer_id|advance_deduction|deposit_applied|applied_at|consumed_at)/', $normalizedKey) === 1
                && $value !== null
                && $value !== ''
                && $value !== false
                && $value !== 0
                && $value !== '0'
            ) {
                return true;
            }

            if (\is_array($value) && $this->hasDepositApplicationMarker($value)) {
                return true;
            }
        }

        return false;
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
    /** @param array<string, mixed> $folio */
    private function folioCurrency(array $folio): string
    {
        foreach (['currency', 'currency_code'] as $key) {
            if (isset($folio[$key]) && \is_scalar($folio[$key])) {
                return \strtoupper(\sanitize_text_field((string) $folio[$key]));
            }
        }
        return '';
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
