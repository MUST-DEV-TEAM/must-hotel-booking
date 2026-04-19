<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Core\RoomViewBuilder;
use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Engine\RatePlanEngine;
use MustHotelBooking\Engine\ReservationEngine;
use MustHotelBooking\Provider\Contracts\QuoteProviderInterface;
use MustHotelBooking\Provider\Dto\QuoteRequest;

final class ClockQuoteProvider implements QuoteProviderInterface
{
    /** @var ClockApiClient */
    private $client;

    /** @var ClockCatalogService */
    private $catalog;

    public function __construct(?ClockApiClient $client = null, ?ClockCatalogService $catalog = null)
    {
        $this->client = $client ?: new ClockApiClient();
        $this->catalog = $catalog ?: new ClockCatalogService($this->client);
    }

    public function calculateTotal(
        int $roomId,
        string $checkin,
        string $checkout,
        int $guests = 1,
        string $couponCode = '',
        int $ratePlanId = 0
    ): array {
        if (!ClockConfig::isPublicBookingConfigured() || !$this->isValidStay($checkin, $checkout) || $roomId <= 0) {
            return [
                'success' => false,
                'message' => \__('Clock quote is not configured for this stay.', 'must-hotel-booking'),
            ];
        }

        $room = \MustHotelBooking\Engine\get_room_repository()->getRoomById($roomId);
        $roomMapping = $this->catalog->findAccommodationMapping($roomId);

        if (!\is_array($room) || !$this->hasExternalId($roomMapping)) {
            return [
                'success' => false,
                'message' => \__('This accommodation is not mapped to Clock.', 'must-hotel-booking'),
            ];
        }

        $ratePlan = RatePlanEngine::getRoomRatePlan($roomId, $ratePlanId);
        $ratePlanMapping = null;

        if ($ratePlanId > 0) {
            $ratePlanMapping = $this->catalog->findRatePlanMapping($ratePlanId);

            if (!$this->hasExternalId($ratePlanMapping)) {
                return [
                    'success' => false,
                    'message' => \__('The selected rate plan is not mapped to Clock.', 'must-hotel-booking'),
                ];
            }
        }

        $response = $this->client->request(
            'POST',
            ClockConfig::quotePath(),
            [
                'body' => $this->quotePayload($roomId, $checkin, $checkout, $guests, $couponCode, $ratePlanId, $roomMapping, $ratePlanMapping),
            ],
            'clock.quote'
        );

        if (!$response->isSuccess()) {
            return [
                'success' => false,
                'message' => $response->getErrorMessage() !== '' ? $response->getErrorMessage() : \__('Clock quote request failed.', 'must-hotel-booking'),
            ];
        }

        return $this->normalizeQuote($response->getData(), $roomId, $checkin, $checkout, \max(1, $guests), $couponCode, $ratePlanId, \is_array($ratePlan) ? $ratePlan : []);
    }

    public function buildCheckoutRoomItems(QuoteRequest $request): array
    {
        $context = $request->getContext();
        $couponCode = $request->getCouponCode();
        $guestForm = $request->getGuestForm();
        $summary = [
            'room_subtotal' => 0.0,
            'fees_total' => 0.0,
            'discount_total' => 0.0,
            'taxes_total' => 0.0,
            'total_price' => 0.0,
            'nights' => 0,
            'applied_coupon' => '',
        ];
        $roomRows = [];
        $selectedRatePlans = [];

        foreach (\MustHotelBooking\Frontend\get_booking_selected_rooms() as $selectedRoom) {
            $roomId = isset($selectedRoom['room_id']) ? (int) $selectedRoom['room_id'] : 0;
            $ratePlanId = isset($selectedRoom['rate_plan_id']) ? (int) $selectedRoom['rate_plan_id'] : 0;
            $room = ReservationEngine::getCheckoutRoomData($roomId);

            if (!\is_array($room)) {
                continue;
            }

            $ratePlan = RatePlanEngine::getRoomRatePlan($roomId, $ratePlanId);

            if (\is_array($ratePlan)) {
                $room['selected_rate_plan_id'] = $ratePlanId;
                $room['selected_rate_plan_name'] = isset($ratePlan['name']) ? (string) $ratePlan['name'] : '';
                $room['selected_rate_plan_description'] = isset($ratePlan['description']) ? (string) $ratePlan['description'] : '';
                $room['selected_rate_plan_max_occupancy'] = isset($ratePlan['max_occupancy']) ? (int) $ratePlan['max_occupancy'] : 0;
                $room['effective_max_guests'] = isset($ratePlan['max_occupancy'])
                    ? \max(1, \min((int) ($room['max_guests'] ?? 1), (int) $ratePlan['max_occupancy']))
                    : \max(1, (int) ($room['max_guests'] ?? 1));
            }

            $roomRows[] = $room;
            $selectedRatePlans[$roomId] = $ratePlanId;
        }

        $allocation = ReservationEngine::getRoomGuestAllocations($roomRows, (int) ($context['guests'] ?? 1), $guestForm, $request->shouldUseStrictRoomGuests());
        $roomGuestCounts = isset($allocation['counts']) && \is_array($allocation['counts']) ? $allocation['counts'] : [];
        $allocationErrors = isset($allocation['errors']) && \is_array($allocation['errors'])
            ? \array_values(\array_filter(\array_map('strval', $allocation['errors'])))
            : [];

        if (!empty($allocationErrors)) {
            return [
                'items' => [],
                'summary' => $summary,
                'errors' => $allocationErrors,
                'room_guest_counts' => $roomGuestCounts,
            ];
        }

        $items = [];

        foreach ($roomRows as $room) {
            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $roomGuests = isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : \max(1, (int) ($context['guests'] ?? 1));
            $pricing = $this->calculateTotal(
                $roomId,
                (string) ($context['checkin'] ?? ''),
                (string) ($context['checkout'] ?? ''),
                $roomGuests,
                $couponCode,
                isset($selectedRatePlans[$roomId]) ? (int) $selectedRatePlans[$roomId] : 0
            );

            if (empty($pricing['success'])) {
                return [
                    'items' => [],
                    'summary' => $summary,
                    'errors' => [(string) ($pricing['message'] ?? \__('Unable to calculate Clock pricing for one of the selected rooms.', 'must-hotel-booking'))],
                    'room_guest_counts' => $roomGuestCounts,
                ];
            }

            $room['dynamic_total_price'] = isset($pricing['total_price']) ? (float) $pricing['total_price'] : null;
            $room['price_preview_total'] = isset($pricing['total_price']) ? (float) $pricing['total_price'] : null;
            $room['dynamic_room_subtotal'] = isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : null;
            $room['dynamic_nights'] = isset($pricing['nights']) ? (int) $pricing['nights'] : null;

            $summary['room_subtotal'] += isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : 0.0;
            $summary['fees_total'] += isset($pricing['fees_total']) ? (float) $pricing['fees_total'] : 0.0;
            $summary['discount_total'] += isset($pricing['discount_total']) ? (float) $pricing['discount_total'] : 0.0;
            $summary['taxes_total'] += isset($pricing['taxes_total']) ? (float) $pricing['taxes_total'] : 0.0;
            $summary['total_price'] += isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0;

            if ((int) $summary['nights'] === 0 && isset($pricing['nights'])) {
                $summary['nights'] = (int) $pricing['nights'];
            }

            if ((string) $summary['applied_coupon'] === '' && !empty($pricing['applied_coupon']) && \is_string($pricing['applied_coupon'])) {
                $summary['applied_coupon'] = (string) $pricing['applied_coupon'];
            }

            $roomView = RoomViewBuilder::buildBookingResultsRoomViewData($room);

            if (!\is_array($roomView)) {
                continue;
            }

            $items[] = [
                'room_id' => $roomId,
                'rate_plan_id' => isset($room['selected_rate_plan_id']) ? (int) $room['selected_rate_plan_id'] : 0,
                'rate_plan' => [
                    'id' => isset($room['selected_rate_plan_id']) ? (int) $room['selected_rate_plan_id'] : 0,
                    'name' => isset($room['selected_rate_plan_name']) ? (string) $room['selected_rate_plan_name'] : '',
                    'description' => isset($room['selected_rate_plan_description']) ? (string) $room['selected_rate_plan_description'] : '',
                    'max_occupancy' => isset($room['selected_rate_plan_max_occupancy']) ? (int) $room['selected_rate_plan_max_occupancy'] : 0,
                ],
                'assigned_guests' => $roomGuests,
                'room' => $roomView,
                'pricing' => $pricing,
            ];
        }

        return [
            'items' => $items,
            'summary' => $summary,
            'errors' => [],
            'room_guest_counts' => $roomGuestCounts,
        ];
    }

    public function getRoomRatePlansWithPricing(int $roomId, string $checkin, string $checkout, int $guests = 1): array
    {
        $plans = RatePlanEngine::getRatePlansForRoomType($roomId);
        $items = [];

        foreach ($plans as $plan) {
            if (!\is_array($plan)) {
                continue;
            }

            $planId = isset($plan['id']) ? (int) $plan['id'] : 0;
            $maxOccupancy = isset($plan['max_occupancy']) ? \max(1, (int) $plan['max_occupancy']) : 1;

            if ($guests > 0 && $maxOccupancy > 0 && $guests > $maxOccupancy) {
                continue;
            }

            if ($planId > 0 && !$this->hasExternalId($this->catalog->findRatePlanMapping($planId))) {
                continue;
            }

            $pricing = $this->calculateTotal($roomId, $checkin, $checkout, \max(1, $guests), '', $planId);

            if (empty($pricing['success'])) {
                continue;
            }

            $roomSubtotal = isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : 0.0;
            $nights = isset($pricing['nights']) ? \max(1, (int) $pricing['nights']) : 1;
            $nightlyPrice = $roomSubtotal > 0 ? \round($roomSubtotal / $nights, 2) : (float) ($plan['base_price'] ?? 0.0);

            $items[] = [
                'id' => $planId,
                'name' => isset($plan['name']) ? (string) $plan['name'] : '',
                'description' => isset($plan['description']) ? (string) $plan['description'] : '',
                'base_price' => isset($plan['base_price']) ? (float) $plan['base_price'] : 0.0,
                'nightly_price' => $nightlyPrice,
                'total_price' => isset($pricing['total_price']) ? (float) $pricing['total_price'] : 0.0,
                'max_occupancy' => $maxOccupancy,
                'is_fallback' => !empty($plan['is_fallback']),
            ];
        }

        return $items;
    }

    /**
     * @param array<string, mixed> $roomMapping
     * @param array<string, mixed>|null $ratePlanMapping
     * @return array<string, mixed>
     */
    private function quotePayload(int $roomId, string $checkin, string $checkout, int $guests, string $couponCode, int $ratePlanId, array $roomMapping, ?array $ratePlanMapping): array
    {
        return [
            'property_id' => ClockConfig::propertyId(),
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => \max(1, $guests),
            'coupon_code' => $couponCode,
            'local_room_id' => $roomId,
            'provider_room_id' => (string) ($roomMapping['external_id'] ?? ''),
            'provider_room_code' => (string) ($roomMapping['external_code'] ?? ''),
            'local_rate_plan_id' => $ratePlanId,
            'provider_rate_plan_id' => \is_array($ratePlanMapping) ? (string) ($ratePlanMapping['external_id'] ?? '') : '',
            'provider_rate_plan_code' => \is_array($ratePlanMapping) ? (string) ($ratePlanMapping['external_code'] ?? '') : '',
        ];
    }

    /**
     * @param mixed $data
     * @param array<string, mixed> $ratePlan
     * @return array<string, mixed>
     */
    private function normalizeQuote($data, int $roomId, string $checkin, string $checkout, int $guests, string $couponCode, int $ratePlanId, array $ratePlan): array
    {
        $source = $this->quoteSource($data);
        $total = $this->firstNumeric($source, ['total_price', 'total', 'gross_total', 'amount', 'price', 'grand_total']);

        if ($total === null) {
            return [
                'success' => false,
                'message' => \__('Clock quote response did not include a total.', 'must-hotel-booking'),
            ];
        }

        $nights = $this->nights($checkin, $checkout);
        $roomSubtotal = $this->firstNumeric($source, ['room_subtotal', 'subtotal', 'net_total', 'base_amount', 'room_total']);
        $feesTotal = $this->firstNumeric($source, ['fees_total', 'fees', 'fee_total']);
        $taxesTotal = $this->firstNumeric($source, ['taxes_total', 'tax_total', 'taxes']);
        $discountTotal = $this->firstNumeric($source, ['discount_total', 'discount', 'discount_amount']);
        $roomSubtotal = $roomSubtotal !== null ? $roomSubtotal : \max(0.0, $total - (float) ($feesTotal ?? 0.0) - (float) ($taxesTotal ?? 0.0) + (float) ($discountTotal ?? 0.0));
        $appliedCoupon = $this->firstString($source, ['applied_coupon', 'coupon_code', 'discount_code']);
        $nightlyRates = $this->nightlyRates($source, $checkin, $nights, $roomSubtotal);

        return [
            'success' => true,
            'room_id' => $roomId,
            'rate_plan_id' => $ratePlanId,
            'rate_plan_name' => (string) ($ratePlan['name'] ?? ''),
            'checkin' => $checkin,
            'checkout' => $checkout,
            'nights' => $nights,
            'guests' => \max(1, $guests),
            'room_base_price' => $nights > 0 ? \round($roomSubtotal / $nights, 2) : $roomSubtotal,
            'base_capacity' => isset($ratePlan['max_occupancy']) ? \max(1, (int) $ratePlan['max_occupancy']) : \max(1, $guests),
            'extra_guest_price' => 0.0,
            'extra_guest_count' => 0,
            'base_amount' => \round($roomSubtotal, 2),
            'nightly_rates' => $nightlyRates,
            'seasonal_rules_count' => 0,
            'seasonal_modifier_total' => 0.0,
            'extra_guest_amount' => 0.0,
            'room_subtotal' => \round($roomSubtotal, 2),
            'fees_total' => \round((float) ($feesTotal ?? 0.0), 2),
            'discount_total' => \round((float) ($discountTotal ?? 0.0), 2),
            'taxes_total' => \round((float) ($taxesTotal ?? 0.0), 2),
            'total_price' => \round($total, 2),
            'applied_coupon' => $appliedCoupon !== '' ? $appliedCoupon : ($couponCode !== '' && (float) ($discountTotal ?? 0.0) > 0.0 ? $couponCode : ''),
            'applied_coupon_id' => 0,
            'breakdown' => [
                'fees' => [],
                'taxes' => [],
            ],
            'provider' => 'clock',
            'provider_quote' => \is_array($source) ? $source : [],
        ];
    }

    /** @param mixed $data @return array<string, mixed> */
    private function quoteSource($data): array
    {
        if (!\is_array($data)) {
            return [];
        }

        if ($this->hasAnyKey($data, ['total_price', 'total', 'gross_total', 'amount', 'price', 'grand_total'])) {
            return $data;
        }

        foreach (['quote', 'pricing', 'rate', 'data', 'result'] as $key) {
            if (isset($data[$key]) && \is_array($data[$key])) {
                return $data[$key];
            }
        }

        return $data;
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function firstNumeric(array $source, array $keys): ?float
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_numeric($source[$key])) {
                return \round((float) $source[$key], 2);
            }
        }

        return null;
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

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    private function hasAnyKey(array $source, array $keys): bool
    {
        foreach ($keys as $key) {
            if (\array_key_exists($key, $source)) {
                return true;
            }
        }

        return false;
    }

    /** @param array<string, mixed> $source @return array<int, array<string, mixed>> */
    private function nightlyRates(array $source, string $checkin, int $nights, float $roomSubtotal): array
    {
        foreach (['nightly_rates', 'rates', 'nights'] as $key) {
            if (isset($source[$key]) && \is_array($source[$key])) {
                return \array_values(\array_filter($source[$key], 'is_array'));
            }
        }

        if ($nights <= 0) {
            return [];
        }

        $nightly = [];
        $average = \round($roomSubtotal / $nights, 2);

        try {
            $date = new \DateTimeImmutable($checkin);
        } catch (\Exception $exception) {
            return [];
        }

        for ($index = 0; $index < $nights; $index++) {
            $nightly[] = [
                'date' => $date->modify('+' . $index . ' days')->format('Y-m-d'),
                'base_price' => $average,
                'seasonal_modifier' => 0.0,
                'price' => $average,
                'source' => 'clock',
            ];
        }

        return $nightly;
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

    private function nights(string $checkin, string $checkout): int
    {
        if (!$this->isValidStay($checkin, $checkout)) {
            return 0;
        }

        try {
            return (int) (new \DateTimeImmutable($checkin))->diff(new \DateTimeImmutable($checkout))->days;
        } catch (\Exception $exception) {
            return 0;
        }
    }
}
