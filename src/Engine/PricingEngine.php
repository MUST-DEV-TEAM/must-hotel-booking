<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\RoomViewBuilder;

final class PricingEngine
{
    /**
     * @return array<string, mixed>|null
     */
    public static function getSeasonForDate(string $date): ?array
    {
        if (!AvailabilityEngine::isValidBookingDate($date)) {
            return null;
        }

        return get_rate_plan_repository()->getSeasonForDate($date);
    }

    public static function calculateSeasonModifier(int $ratePlanId, string $date): float
    {
        $baseRatePlanPrice = self::resolveGenericRatePlanBasePrice($ratePlanId, $date);
        $modifier = self::resolveRatePlanSeasonModifierDetails($ratePlanId, $date, $baseRatePlanPrice);

        return isset($modifier['amount']) ? (float) $modifier['amount'] : 0.0;
    }

    public static function calculateSeasonalPrice(int $ratePlanId, string $date): float
    {
        $baseRatePlanPrice = self::resolveGenericRatePlanBasePrice($ratePlanId, $date);

        if ($baseRatePlanPrice <= 0) {
            return 0.0;
        }

        return self::roundPrice($baseRatePlanPrice + self::calculateSeasonModifier($ratePlanId, $date));
    }

    /**
     * @return array<string, mixed>
     */
    public static function calculateTotal(int $roomId, string $checkin, string $checkout, int $guests = 1, string $couponCode = '', int $ratePlanId = 0): array
    {
        $nights = self::calculateBookingNights($checkin, $checkout);

        if ($nights <= 0 || $roomId <= 0) {
            return [
                'success' => false,
                'message' => 'Invalid booking dates or room.',
            ];
        }

        $guests = \max(1, $guests);
        $roomContext = self::getRoomPricingContext($roomId);
        $ratePlanContext = self::getRoomRatePlanContext($roomId, $ratePlanId);

        if ($ratePlanId > 0 && !\is_array($ratePlanContext)) {
            return [
                'success' => false,
                'message' => 'Selected rate plan is not available.',
            ];
        }

        if (!\is_array($roomContext)) {
            return [
                'success' => false,
                'message' => 'Room pricing data not found.',
            ];
        }

        $roomBasePrice = isset($roomContext['room_base_price']) ? (float) $roomContext['room_base_price'] : 0.0;
        $baseCapacity = isset($roomContext['base_capacity']) ? (int) $roomContext['base_capacity'] : 1;

        if (\is_array($ratePlanContext)) {
            $ratePlanBasePrice = isset($ratePlanContext['base_price']) ? (float) $ratePlanContext['base_price'] : 0.0;

            if ($ratePlanBasePrice > 0) {
                $roomBasePrice = $ratePlanBasePrice;
            }

            if (!empty($ratePlanContext['max_occupancy'])) {
                $baseCapacity = \min($baseCapacity, \max(1, (int) $ratePlanContext['max_occupancy']));
            }
        }

        if ($baseCapacity <= 0) {
            $baseCapacity = 1;
        }

        if ($guests > $baseCapacity) {
            return [
                'success' => false,
                'message' => 'Selected rate plan cannot host the requested guest count.',
            ];
        }

        $seasonalRules = self::getApplicableSeasonalPricingRules($roomId, $checkin, $checkout, $nights);
        $ratePlanPrices = \is_array($ratePlanContext) && $ratePlanId > 0
            ? get_rate_plan_repository()->getRatePlanPricesInRange($ratePlanId, $checkin, $checkout)
            : [];
        $basePricing = self::calculateBaseAmountWithRatePlanPrices(
            $roomId,
            $ratePlanId,
            $roomBasePrice,
            $checkin,
            $nights,
            $seasonalRules,
            $ratePlanPrices,
            \is_array($ratePlanContext) ? (string) ($ratePlanContext['name'] ?? '') : ''
        );
        $baseAmount = isset($basePricing['base_amount']) ? (float) $basePricing['base_amount'] : 0.0;
        $seasonalModifierTotal = 0.0;

        if (isset($basePricing['nightly_rates']) && \is_array($basePricing['nightly_rates'])) {
            foreach ($basePricing['nightly_rates'] as $nightlyRate) {
                if (!\is_array($nightlyRate)) {
                    continue;
                }

                $seasonalModifierTotal += isset($nightlyRate['seasonal_modifier']) ? (float) $nightlyRate['seasonal_modifier'] : 0.0;
            }
        }

        $extraGuestCount = 0;
        $extraGuestAmount = 0.0;
        $roomSubtotal = self::roundPrice($baseAmount);

        $settings = self::getPricingEngineSettings();
        $tableTaxFeeRules = self::getTaxFeeRulesFromTable();

        if (!empty($tableTaxFeeRules)) {
            $taxFee = self::calculateTaxFeeTotalsFromTable($tableTaxFeeRules, $roomSubtotal, $nights);
            $feesTotal = isset($taxFee['fees_total']) ? (float) $taxFee['fees_total'] : 0.0;
            $taxesTotal = isset($taxFee['taxes_total']) ? (float) $taxFee['taxes_total'] : 0.0;
            $feesLines = isset($taxFee['fees_lines']) && \is_array($taxFee['fees_lines']) ? $taxFee['fees_lines'] : [];
            $taxesLines = isset($taxFee['taxes_lines']) && \is_array($taxFee['taxes_lines']) ? $taxFee['taxes_lines'] : [];
        } else {
            $taxRules = self::getPricingRuleGroup($settings, ['taxes', 'tax_rules']);
            $feeRules = self::getPricingRuleGroup($settings, ['fees', 'fee_rules']);
            $fees = self::calculateFeeTotal($feeRules, $roomSubtotal, $nights, $guests);
            $taxes = self::calculateTaxTotal($taxRules, $roomSubtotal);
            $feesTotal = (float) $fees['total'];
            $taxesTotal = (float) $taxes['total'];
            $feesLines = \is_array($fees['lines']) ? $fees['lines'] : [];
            $taxesLines = \is_array($taxes['lines']) ? $taxes['lines'] : [];
        }

        $discountBase = self::roundPrice($roomSubtotal + $feesTotal);
        $discount = CouponService::resolveCouponForBooking($couponCode, $discountBase, $checkin);

        $appliedCouponId = 0;

        if ($discount['amount'] > 0 && isset($discount['coupon']) && \is_array($discount['coupon']) && isset($discount['coupon']['id'])) {
            $appliedCouponId = (int) $discount['coupon']['id'];
        }

        $subtotalAfterDiscount = self::roundPrice(\max(0.0, $discountBase - $discount['amount']));
        $totalPrice = self::roundPrice($subtotalAfterDiscount + $taxesTotal);

        return [
            'success' => true,
            'room_id' => $roomId,
            'rate_plan_id' => \is_array($ratePlanContext) ? \max(0, $ratePlanId) : 0,
            'rate_plan_name' => \is_array($ratePlanContext) ? (string) ($ratePlanContext['name'] ?? '') : '',
            'checkin' => $checkin,
            'checkout' => $checkout,
            'nights' => $nights,
            'guests' => $guests,
            'room_base_price' => self::roundPrice($roomBasePrice),
            'base_capacity' => $baseCapacity,
            'extra_guest_price' => 0.0,
            'extra_guest_count' => $extraGuestCount,
            'base_amount' => $baseAmount,
            'nightly_rates' => isset($basePricing['nightly_rates']) && \is_array($basePricing['nightly_rates']) ? $basePricing['nightly_rates'] : [],
            'seasonal_rules_count' => \count($seasonalRules),
            'seasonal_modifier_total' => self::roundPrice($seasonalModifierTotal),
            'extra_guest_amount' => $extraGuestAmount,
            'room_subtotal' => $roomSubtotal,
            'fees_total' => $feesTotal,
            'discount_total' => $discount['amount'],
            'taxes_total' => $taxesTotal,
            'total_price' => $totalPrice,
            'applied_coupon' => $discount['applied_code'],
            'applied_coupon_id' => $appliedCouponId,
            'breakdown' => [
                'fees' => $feesLines,
                'taxes' => $taxesLines,
            ],
        ];
    }

    /**
     * @param array<string, mixed> $context
     * @param array<string, mixed> $guestForm
     * @return array{items: array<int, array<string, mixed>>, summary: array<string, float|int|string>, errors: array<int, string>, room_guest_counts: array<int, int>}
     */
    public static function buildCheckoutRoomItems(array $context, string $couponCode = '', array $guestForm = [], bool $strictRoomGuests = false): array
    {
        $items = [];
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
        }

        $allocation = ReservationEngine::getRoomGuestAllocations($roomRows, (int) ($context['guests'] ?? 1), $guestForm, $strictRoomGuests);
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

        foreach ($roomRows as $room) {
            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $roomGuests = isset($roomGuestCounts[$roomId]) ? (int) $roomGuestCounts[$roomId] : \max(1, (int) ($context['guests'] ?? 1));
            $pricing = self::calculateTotal(
                $roomId,
                (string) ($context['checkin'] ?? ''),
                (string) ($context['checkout'] ?? ''),
                $roomGuests,
                $couponCode,
                isset($room['selected_rate_plan_id']) ? (int) $room['selected_rate_plan_id'] : 0
            );

            if (\is_array($pricing) && !empty($pricing['success'])) {
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
                'pricing' => \is_array($pricing) ? $pricing : [],
            ];
        }

        return [
            'items' => $items,
            'summary' => $summary,
            'errors' => [],
            'room_guest_counts' => $roomGuestCounts,
        ];
    }

    public static function getPricingTableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'must_pricing';
    }

    public static function getTaxesTableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'must_taxes';
    }

    public static function getCouponsTableName(): string
    {
        global $wpdb;

        return $wpdb->prefix . 'must_coupons';
    }

    private static function roundPrice(float $value): float
    {
        return \round($value, 2);
    }

    private static function getPricingEngineSettings(): array
    {
        return MustBookingConfig::get_all_settings();
    }

    private static function getPricingRuleGroup(array $settings, array $aliases): array
    {
        foreach ($aliases as $key) {
            if (isset($settings[$key]) && \is_array($settings[$key])) {
                return $settings[$key];
            }
        }

        return [];
    }

    private static function getRoomPricingContext(int $roomId): ?array
    {
        return get_room_repository()->getRoomPricingContext($roomId);
    }

    private static function getRoomRatePlanContext(int $roomId, int $ratePlanId = 0): ?array
    {
        if ($roomId <= 0 || $ratePlanId <= 0) {
            return null;
        }

        return RatePlanEngine::getRoomRatePlan($roomId, $ratePlanId);
    }

    private static function getSeasonsForDateRange(string $checkin, string $checkout): array
    {
        if (
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout
        ) {
            return [];
        }

        return get_rate_plan_repository()->getSeasonsForRange($checkin, $checkout);
    }

    private static function resolveSeasonFromCache(string $date, array $seasons): ?array
    {
        foreach ($seasons as $season) {
            if (!\is_array($season)) {
                continue;
            }

            $startDate = isset($season['start_date']) ? (string) $season['start_date'] : '';
            $endDate = isset($season['end_date']) ? (string) $season['end_date'] : '';

            if ($startDate === '' || $endDate === '') {
                continue;
            }

            if ($startDate <= $date && $endDate >= $date) {
                return $season;
            }
        }

        return null;
    }

    private static function mapRatePlanSeasonalModifiersBySeason(array $modifiers): array
    {
        $mapped = [];

        foreach ($modifiers as $modifier) {
            if (!\is_array($modifier)) {
                continue;
            }

            $seasonId = isset($modifier['season_id']) ? (int) $modifier['season_id'] : 0;

            if ($seasonId <= 0) {
                continue;
            }

            $mapped[$seasonId] = $modifier;
        }

        return $mapped;
    }

    private static function normalizePriceModifierType(string $priceModifierType): string
    {
        $normalized = \sanitize_key($priceModifierType);

        if (!\in_array($normalized, ['fixed', 'percentage'], true)) {
            return 'fixed';
        }

        return $normalized;
    }

    private static function calculatePriceModifierAmount(float $basePrice, string $priceModifierType, float $modifierValue): float
    {
        $modifierValue = \max(0.0, $modifierValue);

        if (self::normalizePriceModifierType($priceModifierType) === 'percentage') {
            return self::roundPrice($basePrice * ($modifierValue / 100));
        }

        return self::roundPrice($modifierValue);
    }

    private static function resolveRatePlanSeasonModifierDetails(
        int $ratePlanId,
        string $date,
        float $baseRatePlanPrice,
        array $seasons = [],
        array $modifierMap = []
    ): array {
        if ($ratePlanId <= 0 || !AvailabilityEngine::isValidBookingDate($date)) {
            return [
                'amount' => 0.0,
                'season_id' => 0,
                'season_name' => '',
                'season_priority' => 0,
                'modifier_type' => '',
                'price_modifier_type' => '',
                'modifier_value' => 0.0,
            ];
        }

        $season = !empty($seasons)
            ? self::resolveSeasonFromCache($date, $seasons)
            : self::getSeasonForDate($date);

        if (!\is_array($season)) {
            return [
                'amount' => 0.0,
                'season_id' => 0,
                'season_name' => '',
                'season_priority' => 0,
                'modifier_type' => '',
                'price_modifier_type' => '',
                'modifier_value' => 0.0,
            ];
        }

        $seasonId = isset($season['id']) ? (int) $season['id'] : 0;
        $modifier = $seasonId > 0 && isset($modifierMap[$seasonId]) && \is_array($modifierMap[$seasonId])
            ? $modifierMap[$seasonId]
            : get_rate_plan_repository()->getSeasonalPriceModifier($seasonId, $ratePlanId);

        if (!\is_array($modifier)) {
            return [
                'amount' => 0.0,
                'season_id' => $seasonId,
                'season_name' => isset($season['name']) ? (string) $season['name'] : '',
                'season_priority' => isset($season['priority']) ? (int) $season['priority'] : 0,
                'modifier_type' => '',
                'price_modifier_type' => '',
                'modifier_value' => 0.0,
            ];
        }

        $priceModifierType = self::normalizePriceModifierType(
            (string) ($modifier['modifier_type'] ?? $modifier['price_modifier_type'] ?? 'fixed')
        );
        $modifierValue = isset($modifier['modifier_value']) ? (float) $modifier['modifier_value'] : 0.0;
        $amount = self::calculatePriceModifierAmount($baseRatePlanPrice, $priceModifierType, $modifierValue);

        return [
            'amount' => $amount,
            'season_id' => $seasonId,
            'season_name' => isset($season['name']) ? (string) $season['name'] : '',
            'season_priority' => isset($season['priority']) ? (int) $season['priority'] : 0,
            'modifier_type' => $priceModifierType,
            'price_modifier_type' => $priceModifierType,
            'modifier_value' => $modifierValue,
        ];
    }

    private static function resolveGenericRatePlanBasePrice(int $ratePlanId, string $date): float
    {
        if ($ratePlanId <= 0 || !AvailabilityEngine::isValidBookingDate($date)) {
            return 0.0;
        }

        $datedPrice = RatePlanEngine::getRatePlanPrice($ratePlanId, $date);

        if ($datedPrice > 0) {
            return self::roundPrice($datedPrice);
        }

        $defaultBasePrice = get_rate_plan_repository()->getRatePlanDefaultBasePrice($ratePlanId);

        return $defaultBasePrice !== null ? self::roundPrice((float) $defaultBasePrice) : 0.0;
    }

    private static function calculateBookingNights(string $checkin, string $checkout): int
    {
        if (
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout
        ) {
            return 0;
        }

        try {
            $start = new \DateTimeImmutable($checkin);
            $end = new \DateTimeImmutable($checkout);
        } catch (\Exception $exception) {
            return 0;
        }

        return (int) $start->diff($end)->days;
    }

    private static function isWeekendBookingDate(string $date): bool
    {
        if (!AvailabilityEngine::isValidBookingDate($date)) {
            return false;
        }

        try {
            $parsed = new \DateTimeImmutable($date);
        } catch (\Exception $exception) {
            return false;
        }

        return (int) $parsed->format('N') >= 6;
    }

    private static function getApplicableSeasonalPricingRules(int $roomId, string $checkin, string $checkout, int $nights): array
    {
        if (
            $nights <= 0 ||
            $roomId <= 0 ||
            !AvailabilityEngine::isValidBookingDate($checkin) ||
            !AvailabilityEngine::isValidBookingDate($checkout) ||
            $checkin >= $checkout
        ) {
            return [];
        }

        return get_room_repository()->getApplicableSeasonalPricingRules($roomId, $checkin, $checkout, $nights);
    }

    private static function resolveNightlyBaseRate(string $stayDate, float $roomBasePrice, bool $isWeekend, array $rules): array
    {
        $seasonalMatch = null;
        $weekendMatch = null;

        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $startDate = isset($rule['start_date']) ? (string) $rule['start_date'] : '';
            $endDate = isset($rule['end_date']) ? (string) $rule['end_date'] : '';

            if ($startDate === '' || $endDate === '' || $stayDate < $startDate || $stayDate > $endDate) {
                continue;
            }

            $priceOverride = isset($rule['price_override']) ? (float) $rule['price_override'] : 0.0;

            if ($seasonalMatch === null && $priceOverride > 0) {
                $seasonalMatch = [
                    'rate' => self::roundPrice($priceOverride),
                    'source' => 'seasonal',
                    'rule_id' => isset($rule['id']) ? (int) $rule['id'] : 0,
                    'rule_name' => isset($rule['name']) ? (string) $rule['name'] : '',
                ];
            }

            if ($isWeekend && $weekendMatch === null) {
                $weekendPrice = isset($rule['weekend_price']) ? (float) $rule['weekend_price'] : 0.0;

                if ($weekendPrice > 0) {
                    $weekendMatch = [
                        'rate' => self::roundPrice($weekendPrice),
                        'source' => 'weekend',
                        'rule_id' => isset($rule['id']) ? (int) $rule['id'] : 0,
                        'rule_name' => isset($rule['name']) ? (string) $rule['name'] : '',
                    ];
                }
            }

            if ($seasonalMatch !== null && (!$isWeekend || $weekendMatch !== null)) {
                break;
            }
        }

        if (\is_array($seasonalMatch)) {
            return $seasonalMatch;
        }

        if ($isWeekend && \is_array($weekendMatch)) {
            return $weekendMatch;
        }

        return [
            'rate' => self::roundPrice($roomBasePrice),
            'source' => 'base',
            'rule_id' => 0,
            'rule_name' => '',
        ];
    }

    private static function calculateBaseAmountWithRatePlanPrices(
        int $roomId,
        int $ratePlanId,
        float $roomBasePrice,
        string $checkin,
        int $nights,
        array $rules,
        array $ratePlanPrices = [],
        string $ratePlanName = ''
    ): array {
        unset($roomId);

        $baseTotal = 0.0;
        $nightlyRates = [];

        if ($nights <= 0 || !AvailabilityEngine::isValidBookingDate($checkin)) {
            return [
                'base_amount' => 0.0,
                'nightly_rates' => [],
            ];
        }

        $start = new \DateTimeImmutable($checkin);
        $checkout = $start->modify('+' . $nights . ' day')->format('Y-m-d');
        $seasonCache = $ratePlanId > 0 ? self::getSeasonsForDateRange($checkin, $checkout) : [];
        $modifierMap = $ratePlanId > 0
            ? self::mapRatePlanSeasonalModifiersBySeason(get_rate_plan_repository()->getSeasonalModifiersForRatePlan($ratePlanId))
            : [];

        for ($offset = 0; $offset < $nights; $offset++) {
            $stayDate = $start->modify('+' . $offset . ' day')->format('Y-m-d');
            $isWeekend = self::isWeekendBookingDate($stayDate);

            if ($ratePlanId > 0) {
                $baseRatePlanPrice = isset($ratePlanPrices[$stayDate]) && (float) $ratePlanPrices[$stayDate] > 0
                    ? self::roundPrice((float) $ratePlanPrices[$stayDate])
                    : self::roundPrice($roomBasePrice);
                $seasonModifier = self::resolveRatePlanSeasonModifierDetails(
                    $ratePlanId,
                    $stayDate,
                    $baseRatePlanPrice,
                    $seasonCache,
                    $modifierMap
                );
                $seasonModifierAmount = isset($seasonModifier['amount']) ? (float) $seasonModifier['amount'] : 0.0;
                $nightRate = self::roundPrice($baseRatePlanPrice + $seasonModifierAmount);
                $baseTotal += $nightRate;
                $nightlyRates[] = [
                    'date' => $stayDate,
                    'rate' => $nightRate,
                    'base_rate' => $baseRatePlanPrice,
                    'seasonal_modifier' => $seasonModifierAmount,
                    'source' => $seasonModifierAmount !== 0.0 ? 'seasonal_modifier' : 'rate_plan',
                    'rule_id' => isset($seasonModifier['season_id']) ? (int) $seasonModifier['season_id'] : 0,
                    'rule_name' => isset($seasonModifier['season_name']) && (string) $seasonModifier['season_name'] !== ''
                        ? (string) $seasonModifier['season_name']
                        : $ratePlanName,
                    'season_id' => isset($seasonModifier['season_id']) ? (int) $seasonModifier['season_id'] : 0,
                    'season_name' => isset($seasonModifier['season_name']) ? (string) $seasonModifier['season_name'] : '',
                    'season_priority' => isset($seasonModifier['season_priority']) ? (int) $seasonModifier['season_priority'] : 0,
                    'modifier_type' => isset($seasonModifier['modifier_type'])
                        ? (string) $seasonModifier['modifier_type']
                        : (isset($seasonModifier['price_modifier_type']) ? (string) $seasonModifier['price_modifier_type'] : ''),
                    'price_modifier_type' => isset($seasonModifier['price_modifier_type']) ? (string) $seasonModifier['price_modifier_type'] : '',
                    'modifier_value' => isset($seasonModifier['modifier_value']) ? (float) $seasonModifier['modifier_value'] : 0.0,
                    'is_weekend' => $isWeekend,
                ];

                continue;
            }

            $resolved = self::resolveNightlyBaseRate($stayDate, $roomBasePrice, $isWeekend, $rules);
            $nightRate = isset($resolved['rate']) ? (float) $resolved['rate'] : self::roundPrice($roomBasePrice);
            $baseTotal += $nightRate;
            $nightlyRates[] = [
                'date' => $stayDate,
                'rate' => self::roundPrice($nightRate),
                'source' => isset($resolved['source']) ? (string) $resolved['source'] : 'base',
                'rule_id' => isset($resolved['rule_id']) ? (int) $resolved['rule_id'] : 0,
                'rule_name' => isset($resolved['rule_name']) ? (string) $resolved['rule_name'] : '',
                'is_weekend' => $isWeekend,
            ];
        }

        return [
            'base_amount' => self::roundPrice($baseTotal),
            'nightly_rates' => $nightlyRates,
        ];
    }

    private static function isPricingRuleEnabled(array $rule): bool
    {
        if (!\array_key_exists('enabled', $rule)) {
            return true;
        }

        $enabled = $rule['enabled'];

        if (\is_string($enabled)) {
            return !\in_array(\strtolower($enabled), ['0', 'false', 'off', 'no'], true);
        }

        return (bool) $enabled;
    }

    private static function calculateFeeTotal(array $feeRules, float $roomSubtotal, int $nights, int $guests): array
    {
        $total = 0.0;
        $lines = [];

        foreach ($feeRules as $index => $rule) {
            if (!\is_array($rule) || !self::isPricingRuleEnabled($rule)) {
                continue;
            }

            $type = isset($rule['type']) ? (string) $rule['type'] : 'fixed';
            $value = isset($rule['value']) ? (float) $rule['value'] : 0.0;
            $name = isset($rule['name']) ? (string) $rule['name'] : 'Fee ' . ((int) $index + 1);

            if ($value < 0) {
                $value = 0.0;
            }

            if ($type === 'percent') {
                $amount = $roomSubtotal * ($value / 100);
            } elseif ($type === 'per_night') {
                $amount = $value * $nights;
            } elseif ($type === 'per_guest') {
                $amount = $value * $guests;
            } elseif ($type === 'per_guest_per_night') {
                $amount = $value * $guests * $nights;
            } else {
                $amount = $value;
            }

            $amount = self::roundPrice($amount);

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $lines[] = [
                'name' => $name,
                'type' => $type,
                'amount' => $amount,
            ];
        }

        return [
            'total' => self::roundPrice($total),
            'lines' => $lines,
        ];
    }

    private static function calculateTaxTotal(array $taxRules, float $taxableAmount): array
    {
        $total = 0.0;
        $lines = [];

        if ($taxableAmount <= 0) {
            return [
                'total' => 0.0,
                'lines' => [],
            ];
        }

        foreach ($taxRules as $index => $rule) {
            if (!\is_array($rule) || !self::isPricingRuleEnabled($rule)) {
                continue;
            }

            $type = isset($rule['type']) ? (string) $rule['type'] : 'percent';
            $value = isset($rule['value']) ? (float) $rule['value'] : 0.0;
            $name = isset($rule['name']) ? (string) $rule['name'] : 'Tax ' . ((int) $index + 1);

            if ($value < 0) {
                $value = 0.0;
            }

            if ($type === 'fixed') {
                $amount = $value;
            } else {
                $amount = $taxableAmount * ($value / 100);
                $type = 'percent';
            }

            $amount = self::roundPrice($amount);

            if ($amount <= 0) {
                continue;
            }

            $total += $amount;
            $lines[] = [
                'name' => $name,
                'type' => $type,
                'amount' => $amount,
            ];
        }

        return [
            'total' => self::roundPrice($total),
            'lines' => $lines,
        ];
    }

    private static function getTaxFeeRulesFromTable(): array
    {
        return get_payment_repository()->getTaxFeeRules();
    }

    private static function calculateTaxFeeTotalsFromTable(array $rules, float $roomSubtotal, int $nights): array
    {
        $feesTotal = 0.0;
        $taxesTotal = 0.0;
        $feesLines = [];
        $taxesLines = [];

        if ($roomSubtotal <= 0 || $nights <= 0) {
            return [
                'fees_total' => 0.0,
                'fees_lines' => [],
                'taxes_total' => 0.0,
                'taxes_lines' => [],
            ];
        }

        foreach ($rules as $index => $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $name = isset($rule['name']) ? \trim((string) $rule['name']) : '';
            $ruleType = isset($rule['rule_type']) ? \sanitize_key((string) $rule['rule_type']) : 'percentage';
            $ruleValue = isset($rule['rule_value']) ? (float) $rule['rule_value'] : 0.0;
            $applyMode = isset($rule['apply_mode']) ? \sanitize_key((string) $rule['apply_mode']) : 'stay';

            if ($name === '') {
                $name = 'Rule ' . ((int) $index + 1);
            }

            if ($ruleValue <= 0) {
                continue;
            }

            if ($applyMode !== 'night' && $applyMode !== 'stay') {
                $applyMode = 'stay';
            }

            if ($ruleType === 'percentage') {
                $amount = self::roundPrice($roomSubtotal * ($ruleValue / 100));

                if ($amount <= 0) {
                    continue;
                }

                $taxesTotal += $amount;
                $taxesLines[] = [
                    'name' => $name,
                    'type' => $ruleType,
                    'apply' => $applyMode,
                    'amount' => $amount,
                ];

                continue;
            }

            $multiplier = $applyMode === 'night' ? $nights : 1;
            $amount = self::roundPrice($ruleValue * $multiplier);

            if ($amount <= 0) {
                continue;
            }

            $feesTotal += $amount;
            $feesLines[] = [
                'name' => $name,
                'type' => 'fixed',
                'apply' => $applyMode,
                'amount' => $amount,
            ];
        }

        return [
            'fees_total' => self::roundPrice($feesTotal),
            'fees_lines' => $feesLines,
            'taxes_total' => self::roundPrice($taxesTotal),
            'taxes_lines' => $taxesLines,
        ];
    }
}
