<?php

namespace MustHotelBooking\Engine;

/**
 * Round monetary values to 2 decimal places.
 */
function round_price(float $value): float
{
    return \round($value, 2);
}

/**
 * Get pricing rules table name.
 */
function get_pricing_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_pricing';
}

/**
 * Get taxes/fees rules table name.
 */
function get_taxes_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_taxes';
}

/**
 * Get coupons table name.
 */
function get_coupons_table_name(): string
{
    global $wpdb;

    return $wpdb->prefix . 'must_coupons';
}

/**
 * Resolve plugin settings for pricing rules.
 *
 * @return array<string, mixed>
 */
function get_pricing_engine_settings(): array
{
    if (!\function_exists(__NAMESPACE__ . '\get_plugin_settings')) {
        return [];
    }

    $settings = get_plugin_settings();

    return \is_array($settings) ? $settings : [];
}

/**
 * Extract a pricing rules array from settings using known key aliases.
 *
 * @param array<string, mixed> $settings
 * @param string[]             $aliases
 * @return array<int|string, mixed>
 */
function get_pricing_rule_group(array $settings, array $aliases): array
{
    foreach ($aliases as $key) {
        if (isset($settings[$key]) && \is_array($settings[$key])) {
            return $settings[$key];
        }
    }

    return [];
}

/**
 * Resolve room base pricing context.
 *
 * @return array<string, int|float>|null
 */
function get_room_pricing_context(int $room_id): ?array
{
    return get_room_repository()->getRoomPricingContext($room_id);
}

/**
 * @return array<string, mixed>|null
 */
function get_room_rate_plan_context(int $room_id, int $rate_plan_id = 0): ?array
{
    if ($room_id <= 0 || $rate_plan_id <= 0) {
        return null;
    }

    return RatePlanEngine::getRoomRatePlan($room_id, $rate_plan_id);
}

/**
 * @return array<string, mixed>|null
 */
function get_season_for_date(string $date): ?array
{
    if (!\function_exists(__NAMESPACE__ . '\is_valid_booking_date') || !is_valid_booking_date($date)) {
        return null;
    }

    return get_rate_plan_repository()->getSeasonForDate($date);
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_seasons_for_date_range(string $checkin, string $checkout): array
{
    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !is_valid_booking_date($checkin) ||
        !is_valid_booking_date($checkout) ||
        $checkin >= $checkout
    ) {
        return [];
    }

    return get_rate_plan_repository()->getSeasonsForRange($checkin, $checkout);
}

/**
 * @param array<int, array<string, mixed>> $seasons
 * @return array<string, mixed>|null
 */
function resolve_season_from_cache(string $date, array $seasons): ?array
{
    foreach ($seasons as $season) {
        if (!\is_array($season)) {
            continue;
        }

        $start_date = isset($season['start_date']) ? (string) $season['start_date'] : '';
        $end_date = isset($season['end_date']) ? (string) $season['end_date'] : '';

        if ($start_date === '' || $end_date === '') {
            continue;
        }

        if ($start_date <= $date && $end_date >= $date) {
            return $season;
        }
    }

    return null;
}

/**
 * @param array<int, array<string, mixed>> $modifiers
 * @return array<int, array<string, mixed>>
 */
function map_rate_plan_seasonal_modifiers_by_season(array $modifiers): array
{
    $mapped = [];

    foreach ($modifiers as $modifier) {
        if (!\is_array($modifier)) {
            continue;
        }

        $season_id = isset($modifier['season_id']) ? (int) $modifier['season_id'] : 0;

        if ($season_id <= 0) {
            continue;
        }

        $mapped[$season_id] = $modifier;
    }

    return $mapped;
}

function normalize_price_modifier_type(string $priceModifierType): string
{
    $normalized = \sanitize_key($priceModifierType);

    if (!\in_array($normalized, ['fixed', 'percentage'], true)) {
        return 'fixed';
    }

    return $normalized;
}

function calculate_price_modifier_amount(float $basePrice, string $priceModifierType, float $modifierValue): float
{
    $modifierValue = \max(0.0, $modifierValue);

    if (normalize_price_modifier_type($priceModifierType) === 'percentage') {
        return round_price($basePrice * ($modifierValue / 100));
    }

    return round_price($modifierValue);
}

/**
 * @param array<int, array<string, mixed>> $seasons
 * @param array<int, array<string, mixed>> $modifierMap
 * @return array<string, int|float|string>
 */
function resolve_rate_plan_season_modifier_details(
    int $ratePlanId,
    string $date,
    float $baseRatePlanPrice,
    array $seasons = [],
    array $modifierMap = []
): array {
    if (
        $ratePlanId <= 0 ||
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !is_valid_booking_date($date)
    ) {
        return [
            'amount' => 0.0,
            'season_id' => 0,
            'season_name' => '',
            'season_priority' => 0,
            'price_modifier_type' => '',
            'modifier_value' => 0.0,
        ];
    }

    $season = !empty($seasons) ? resolve_season_from_cache($date, $seasons) : get_season_for_date($date);

    if (!\is_array($season)) {
        return [
            'amount' => 0.0,
            'season_id' => 0,
            'season_name' => '',
            'season_priority' => 0,
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
            'price_modifier_type' => '',
            'modifier_value' => 0.0,
        ];
    }

    $priceModifierType = normalize_price_modifier_type((string) ($modifier['price_modifier_type'] ?? 'fixed'));
    $modifierValue = isset($modifier['modifier_value']) ? (float) $modifier['modifier_value'] : 0.0;
    $amount = calculate_price_modifier_amount($baseRatePlanPrice, $priceModifierType, $modifierValue);

    return [
        'amount' => $amount,
        'season_id' => $seasonId,
        'season_name' => isset($season['name']) ? (string) $season['name'] : '',
        'season_priority' => isset($season['priority']) ? (int) $season['priority'] : 0,
        'price_modifier_type' => $priceModifierType,
        'modifier_value' => $modifierValue,
    ];
}

function resolve_generic_rate_plan_base_price(int $ratePlanId, string $date): float
{
    if ($ratePlanId <= 0 || !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') || !is_valid_booking_date($date)) {
        return 0.0;
    }

    $datedPrice = RatePlanEngine::getRatePlanPrice($ratePlanId, $date);

    if ($datedPrice > 0) {
        return round_price($datedPrice);
    }

    $defaultBasePrice = get_rate_plan_repository()->getRatePlanDefaultBasePrice($ratePlanId);

    return $defaultBasePrice !== null ? round_price((float) $defaultBasePrice) : 0.0;
}

function calculate_season_modifier(int $ratePlanId, string $date): float
{
    $baseRatePlanPrice = resolve_generic_rate_plan_base_price($ratePlanId, $date);
    $modifier = resolve_rate_plan_season_modifier_details($ratePlanId, $date, $baseRatePlanPrice);

    return isset($modifier['amount']) ? (float) $modifier['amount'] : 0.0;
}

function calculate_seasonal_price(int $ratePlanId, string $date): float
{
    $baseRatePlanPrice = resolve_generic_rate_plan_base_price($ratePlanId, $date);

    if ($baseRatePlanPrice <= 0) {
        return 0.0;
    }

    return round_price($baseRatePlanPrice + calculate_season_modifier($ratePlanId, $date));
}

/**
 * Calculate nights between check-in and check-out.
 */
function calculate_booking_nights(string $checkin, string $checkout): int
{
    if (!\function_exists(__NAMESPACE__ . '\is_valid_booking_date')) {
        return 0;
    }

    if (!is_valid_booking_date($checkin) || !is_valid_booking_date($checkout) || $checkin >= $checkout) {
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

/**
 * Check whether a booking date falls on weekend (Saturday/Sunday).
 */
function is_weekend_booking_date(string $date): bool
{
    if (!\function_exists(__NAMESPACE__ . '\is_valid_booking_date') || !is_valid_booking_date($date)) {
        return false;
    }

    try {
        $parsed = new \DateTimeImmutable($date);
    } catch (\Exception $exception) {
        return false;
    }

    $day_of_week = (int) $parsed->format('N');

    return $day_of_week >= 6;
}

/**
 * Load seasonal pricing rules overlapping the selected stay.
 *
 * @return array<int, array<string, mixed>>
 */
function get_applicable_seasonal_pricing_rules(int $room_id, string $checkin, string $checkout, int $nights): array
{
    if (
        $nights <= 0 ||
        $room_id <= 0 ||
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !is_valid_booking_date($checkin) ||
        !is_valid_booking_date($checkout) ||
        $checkin >= $checkout
    ) {
        return [];
    }

    return get_room_repository()->getApplicableSeasonalPricingRules($room_id, $checkin, $checkout, $nights);
}

/**
 * Resolve nightly base rate by priority: seasonal -> weekend -> base.
 *
 * @param array<int, array<string, mixed>> $rules
 * @return array{rate: float, source: string, rule_id: int, rule_name: string}
 */
function resolve_nightly_base_rate(string $stay_date, float $room_base_price, bool $is_weekend, array $rules): array
{
    $seasonal_match = null;
    $weekend_match = null;

    foreach ($rules as $rule) {
        if (!\is_array($rule)) {
            continue;
        }

        $start_date = isset($rule['start_date']) ? (string) $rule['start_date'] : '';
        $end_date = isset($rule['end_date']) ? (string) $rule['end_date'] : '';

        if ($start_date === '' || $end_date === '' || $stay_date < $start_date || $stay_date > $end_date) {
            continue;
        }

        $price_override = isset($rule['price_override']) ? (float) $rule['price_override'] : 0.0;

        if ($seasonal_match === null && $price_override > 0) {
            $seasonal_match = [
                'rate' => round_price($price_override),
                'source' => 'seasonal',
                'rule_id' => isset($rule['id']) ? (int) $rule['id'] : 0,
                'rule_name' => isset($rule['name']) ? (string) $rule['name'] : '',
            ];
        }

        if ($is_weekend && $weekend_match === null) {
            $weekend_price = isset($rule['weekend_price']) ? (float) $rule['weekend_price'] : 0.0;

            if ($weekend_price > 0) {
                $weekend_match = [
                    'rate' => round_price($weekend_price),
                    'source' => 'weekend',
                    'rule_id' => isset($rule['id']) ? (int) $rule['id'] : 0,
                    'rule_name' => isset($rule['name']) ? (string) $rule['name'] : '',
                ];
            }
        }

        if ($seasonal_match !== null && (!$is_weekend || $weekend_match !== null)) {
            break;
        }
    }

    if (\is_array($seasonal_match)) {
        return $seasonal_match;
    }

    if ($is_weekend && \is_array($weekend_match)) {
        return $weekend_match;
    }

    return [
        'rate' => round_price($room_base_price),
        'source' => 'base',
        'rule_id' => 0,
        'rule_name' => '',
    ];
}

/**
 * Calculate total base amount using seasonal/weekend/base nightly priority.
 *
 * @param array<int, array<string, mixed>> $rules
 * @return array{base_amount: float, nightly_rates: array<int, array<string, mixed>>}
 */
function calculate_base_amount_with_seasonal_rules(float $room_base_price, string $checkin, int $nights, array $rules): array
{
    $base_total = 0.0;
    $nightly_rates = [];

    if ($nights <= 0 || !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') || !is_valid_booking_date($checkin)) {
        return [
            'base_amount' => 0.0,
            'nightly_rates' => [],
        ];
    }

    $start = new \DateTimeImmutable($checkin);

    for ($offset = 0; $offset < $nights; $offset++) {
        $stay_date = $start->modify('+' . $offset . ' day')->format('Y-m-d');
        $is_weekend = is_weekend_booking_date($stay_date);
        $resolved = resolve_nightly_base_rate($stay_date, $room_base_price, $is_weekend, $rules);
        $night_rate = isset($resolved['rate']) ? (float) $resolved['rate'] : round_price($room_base_price);

        $base_total += $night_rate;
        $nightly_rates[] = [
            'date' => $stay_date,
            'rate' => round_price($night_rate),
            'source' => isset($resolved['source']) ? (string) $resolved['source'] : 'base',
            'rule_id' => isset($resolved['rule_id']) ? (int) $resolved['rule_id'] : 0,
            'rule_name' => isset($resolved['rule_name']) ? (string) $resolved['rule_name'] : '',
            'is_weekend' => $is_weekend,
        ];
    }

    return [
        'base_amount' => round_price($base_total),
        'nightly_rates' => $nightly_rates,
    ];
}

/**
 * Normalize rule enabled flag.
 */
function is_pricing_rule_enabled(array $rule): bool
{
    if (!\array_key_exists('enabled', $rule)) {
        return true;
    }

    $enabled = $rule['enabled'];

    if (\is_string($enabled)) {
        $disabled_values = ['0', 'false', 'off', 'no'];

        return !\in_array(\strtolower($enabled), $disabled_values, true);
    }

    return (bool) $enabled;
}

/**
 * Resolve coupon rule by code.
 *
 * @param array<int|string, mixed> $coupon_rules
 * @return array<string, mixed>|null
 */
function find_coupon_rule(string $coupon_code, array $coupon_rules): ?array
{
    $needle = \strtoupper(\trim($coupon_code));

    if ($needle === '') {
        return null;
    }

    foreach ($coupon_rules as $rule_key => $rule_value) {
        if (\is_array($rule_value)) {
            $resolved_code = isset($rule_value['code']) ? (string) $rule_value['code'] : '';

            if ($resolved_code === '' && \is_string($rule_key)) {
                $resolved_code = $rule_key;
            }

            if (\strtoupper(\trim($resolved_code)) === $needle) {
                return $rule_value;
            }
        }
    }

    return null;
}

/**
 * Resolve coupon rule from database table.
 *
 * @return array<string, mixed>|null
 */
function get_coupon_rule_from_table(string $coupon_code): ?array
{
    return get_payment_repository()->getCouponByCode($coupon_code);
}

/**
 * Calculate nightly totals using explicit rate-plan day prices first, then seasonal/weekend/base fallback.
 *
 * @param array<int, array<string, mixed>> $rules
 * @param array<string, float> $ratePlanPrices
 * @return array{base_amount: float, nightly_rates: array<int, array<string, mixed>>}
 */
function calculate_base_amount_with_rate_plan_prices(
    int $room_id,
    int $rate_plan_id,
    float $room_base_price,
    string $checkin,
    int $nights,
    array $rules,
    array $ratePlanPrices = [],
    string $ratePlanName = ''
): array {
    $base_total = 0.0;
    $nightly_rates = [];

    if ($nights <= 0 || !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') || !is_valid_booking_date($checkin)) {
        return [
            'base_amount' => 0.0,
            'nightly_rates' => [],
        ];
    }

    $start = new \DateTimeImmutable($checkin);
    $checkout = $start->modify('+' . $nights . ' day')->format('Y-m-d');
    $seasonCache = $rate_plan_id > 0 ? get_seasons_for_date_range($checkin, $checkout) : [];
    $modifierMap = $rate_plan_id > 0
        ? map_rate_plan_seasonal_modifiers_by_season(get_rate_plan_repository()->getSeasonalModifiersForRatePlan($rate_plan_id))
        : [];

    for ($offset = 0; $offset < $nights; $offset++) {
        $stay_date = $start->modify('+' . $offset . ' day')->format('Y-m-d');
        $is_weekend = is_weekend_booking_date($stay_date);

        if ($rate_plan_id > 0) {
            $baseRatePlanPrice = isset($ratePlanPrices[$stay_date]) && (float) $ratePlanPrices[$stay_date] > 0
                ? round_price((float) $ratePlanPrices[$stay_date])
                : round_price($room_base_price);
            $seasonModifier = resolve_rate_plan_season_modifier_details(
                $rate_plan_id,
                $stay_date,
                $baseRatePlanPrice,
                $seasonCache,
                $modifierMap
            );
            $seasonModifierAmount = isset($seasonModifier['amount']) ? (float) $seasonModifier['amount'] : 0.0;
            $nightRate = round_price($baseRatePlanPrice + $seasonModifierAmount);
            $base_total += $nightRate;
            $nightly_rates[] = [
                'date' => $stay_date,
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
                'price_modifier_type' => isset($seasonModifier['price_modifier_type']) ? (string) $seasonModifier['price_modifier_type'] : '',
                'modifier_value' => isset($seasonModifier['modifier_value']) ? (float) $seasonModifier['modifier_value'] : 0.0,
                'is_weekend' => $is_weekend,
            ];

            continue;
        }

        $resolved = resolve_nightly_base_rate($stay_date, $room_base_price, $is_weekend, $rules);
        $night_rate = isset($resolved['rate']) ? (float) $resolved['rate'] : round_price($room_base_price);
        $base_total += $night_rate;
        $nightly_rates[] = [
            'date' => $stay_date,
            'rate' => round_price($night_rate),
            'source' => isset($resolved['source']) ? (string) $resolved['source'] : 'base',
            'rule_id' => isset($resolved['rule_id']) ? (int) $resolved['rule_id'] : 0,
            'rule_name' => isset($resolved['rule_name']) ? (string) $resolved['rule_name'] : '',
            'is_weekend' => $is_weekend,
        ];
    }

    return [
        'base_amount' => round_price($base_total),
        'nightly_rates' => $nightly_rates,
    ];
}

/**
 * Increment coupon usage count by coupon ID.
 */
function increment_coupon_usage_count(int $coupon_id): bool
{
    return get_payment_repository()->incrementCouponUsage($coupon_id);
}

/**
 * Increment coupon usage by coupon code.
 */
function increment_coupon_usage_by_code(string $coupon_code): bool
{
    $coupon = get_coupon_rule_from_table($coupon_code);

    if (!\is_array($coupon) || !isset($coupon['id'])) {
        return false;
    }

    return increment_coupon_usage_count((int) $coupon['id']);
}

/**
 * Calculate fee total and lines.
 *
 * @param array<int|string, mixed> $fee_rules
 * @return array{total: float, lines: array<int, array<string, float|string>>}
 */
function calculate_fee_total(array $fee_rules, float $room_subtotal, int $nights, int $guests): array
{
    $total = 0.0;
    $lines = [];

    foreach ($fee_rules as $index => $rule) {
        if (!\is_array($rule) || !is_pricing_rule_enabled($rule)) {
            continue;
        }

        $type = isset($rule['type']) ? (string) $rule['type'] : 'fixed';
        $value = isset($rule['value']) ? (float) $rule['value'] : 0.0;
        $name = isset($rule['name']) ? (string) $rule['name'] : 'Fee ' . ((int) $index + 1);
        $amount = 0.0;

        if ($value < 0) {
            $value = 0.0;
        }

        if ($type === 'percent') {
            $amount = $room_subtotal * ($value / 100);
        } elseif ($type === 'per_night') {
            $amount = $value * $nights;
        } elseif ($type === 'per_guest') {
            $amount = $value * $guests;
        } elseif ($type === 'per_guest_per_night') {
            $amount = $value * $guests * $nights;
        } else {
            $amount = $value;
        }

        $amount = round_price($amount);

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
        'total' => round_price($total),
        'lines' => $lines,
    ];
}

/**
 * Calculate coupon discount amount.
 *
 * @param array<string, mixed>|null $coupon_rule
 * @return array{amount: float, applied_code: string}
 */
function calculate_coupon_discount(float $discount_base, string $coupon_code, ?array $coupon_rule): array
{
    if ($coupon_rule === null || $discount_base <= 0) {
        return [
            'amount' => 0.0,
            'applied_code' => '',
        ];
    }

    if (!is_pricing_rule_enabled($coupon_rule)) {
        return [
            'amount' => 0.0,
            'applied_code' => '',
        ];
    }

    $type = isset($coupon_rule['type']) ? (string) $coupon_rule['type'] : 'fixed';
    $value = isset($coupon_rule['value']) ? (float) $coupon_rule['value'] : 0.0;

    if ($value <= 0) {
        return [
            'amount' => 0.0,
            'applied_code' => '',
        ];
    }

    $discount = 0.0;

    if ($type === 'percent' || $type === 'percentage') {
        $discount = $discount_base * ($value / 100);
    } else {
        $discount = $value;
    }

    if (isset($coupon_rule['max_discount'])) {
        $max_discount = (float) $coupon_rule['max_discount'];

        if ($max_discount > 0) {
            $discount = \min($discount, $max_discount);
        }
    }

    $discount = \min(round_price($discount), round_price($discount_base));

    if ($discount <= 0) {
        return [
            'amount' => 0.0,
            'applied_code' => '',
        ];
    }

    return [
        'amount' => $discount,
        'applied_code' => \strtoupper(\trim($coupon_code)),
    ];
}

/**
 * Calculate tax total and lines.
 *
 * @param array<int|string, mixed> $tax_rules
 * @return array{total: float, lines: array<int, array<string, float|string>>}
 */
function calculate_tax_total(array $tax_rules, float $taxable_amount): array
{
    $total = 0.0;
    $lines = [];

    if ($taxable_amount <= 0) {
        return [
            'total' => 0.0,
            'lines' => [],
        ];
    }

    foreach ($tax_rules as $index => $rule) {
        if (!\is_array($rule) || !is_pricing_rule_enabled($rule)) {
            continue;
        }

        $type = isset($rule['type']) ? (string) $rule['type'] : 'percent';
        $value = isset($rule['value']) ? (float) $rule['value'] : 0.0;
        $name = isset($rule['name']) ? (string) $rule['name'] : 'Tax ' . ((int) $index + 1);
        $amount = 0.0;

        if ($value < 0) {
            $value = 0.0;
        }

        if ($type === 'fixed') {
            $amount = $value;
        } else {
            $amount = $taxable_amount * ($value / 100);
            $type = 'percent';
        }

        $amount = round_price($amount);

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
        'total' => round_price($total),
        'lines' => $lines,
    ];
}

/**
 * Load taxes and fees rules from database table.
 *
 * @return array<int, array<string, mixed>>
 */
function get_tax_fee_rules_from_table(): array
{
    return get_payment_repository()->getTaxFeeRules();
}

/**
 * Calculate taxes and fees using database rules.
 *
 * @param array<int, array<string, mixed>> $rules
 * @return array{fees_total: float, fees_lines: array<int, array<string, float|string>>, taxes_total: float, taxes_lines: array<int, array<string, float|string>>}
 */
function calculate_tax_fee_totals_from_table(array $rules, float $room_subtotal, int $nights): array
{
    $fees_total = 0.0;
    $taxes_total = 0.0;
    $fees_lines = [];
    $taxes_lines = [];

    if ($room_subtotal <= 0 || $nights <= 0) {
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
        $rule_type = isset($rule['rule_type']) ? \sanitize_key((string) $rule['rule_type']) : 'percentage';
        $rule_value = isset($rule['rule_value']) ? (float) $rule['rule_value'] : 0.0;
        $apply_mode = isset($rule['apply_mode']) ? \sanitize_key((string) $rule['apply_mode']) : 'stay';

        if ($name === '') {
            $name = 'Rule ' . ((int) $index + 1);
        }

        if ($rule_value <= 0) {
            continue;
        }

        if ($apply_mode !== 'night' && $apply_mode !== 'stay') {
            $apply_mode = 'stay';
        }

        $amount = 0.0;

        if ($rule_type === 'percentage') {
            // Percentage taxes are applied after base price calculation on stay subtotal.
            $amount = $room_subtotal * ($rule_value / 100);
            $amount = round_price($amount);

            if ($amount <= 0) {
                continue;
            }

            $taxes_total += $amount;
            $taxes_lines[] = [
                'name' => $name,
                'type' => $rule_type,
                'apply' => $apply_mode,
                'amount' => $amount,
            ];

            continue;
        }

        $multiplier = $apply_mode === 'night' ? $nights : 1;
        $amount = round_price($rule_value * $multiplier);

        if ($amount <= 0) {
            continue;
        }

        $fees_total += $amount;
        $fees_lines[] = [
            'name' => $name,
            'type' => 'fixed',
            'apply' => $apply_mode,
            'amount' => $amount,
        ];
    }

    return [
        'fees_total' => round_price($fees_total),
        'fees_lines' => $fees_lines,
        'taxes_total' => round_price($taxes_total),
        'taxes_lines' => $taxes_lines,
    ];
}

/**
 * Calculate final booking pricing with taxes, fees, and coupon discounts.
 *
 * @return array<string, mixed>
 */
function calculate_booking_price(int $room_id, string $checkin, string $checkout, int $guests = 1, string $coupon_code = '', int $rate_plan_id = 0): array
{
    $nights = calculate_booking_nights($checkin, $checkout);

    if ($nights <= 0 || $room_id <= 0) {
        return [
            'success' => false,
            'message' => 'Invalid booking dates or room.',
        ];
    }

    $guests = \max(1, $guests);
    $room_context = get_room_pricing_context($room_id);
    $rate_plan_context = get_room_rate_plan_context($room_id, $rate_plan_id);

    if ($rate_plan_id > 0 && !\is_array($rate_plan_context)) {
        return [
            'success' => false,
            'message' => 'Selected rate plan is not available.',
        ];
    }

    if (!\is_array($room_context)) {
        return [
            'success' => false,
            'message' => 'Room pricing data not found.',
        ];
    }

    $room_base_price = isset($room_context['room_base_price']) ? (float) $room_context['room_base_price'] : 0.0;
    $base_capacity = isset($room_context['base_capacity']) ? (int) $room_context['base_capacity'] : 1;

    if (\is_array($rate_plan_context)) {
        $rate_plan_base_price = isset($rate_plan_context['base_price']) ? (float) $rate_plan_context['base_price'] : 0.0;

        if ($rate_plan_base_price > 0) {
            $room_base_price = $rate_plan_base_price;
        }

        if (!empty($rate_plan_context['max_occupancy'])) {
            $base_capacity = \min($base_capacity, \max(1, (int) $rate_plan_context['max_occupancy']));
        }
    }

    if ($base_capacity <= 0) {
        $base_capacity = 1;
    }

    if ($guests > $base_capacity) {
        return [
            'success' => false,
            'message' => 'Selected rate plan cannot host the requested guest count.',
        ];
    }

    $seasonal_rules = get_applicable_seasonal_pricing_rules($room_id, $checkin, $checkout, $nights);
    $rate_plan_prices = \is_array($rate_plan_context) && $rate_plan_id > 0
        ? get_rate_plan_repository()->getRatePlanPricesInRange($rate_plan_id, $checkin, $checkout)
        : [];
    $base_pricing = calculate_base_amount_with_rate_plan_prices(
        $room_id,
        $rate_plan_id,
        $room_base_price,
        $checkin,
        $nights,
        $seasonal_rules,
        $rate_plan_prices,
        \is_array($rate_plan_context) ? (string) ($rate_plan_context['name'] ?? '') : ''
    );
    $base_amount = isset($base_pricing['base_amount']) ? (float) $base_pricing['base_amount'] : 0.0;
    $seasonal_modifier_total = 0.0;

    if (isset($base_pricing['nightly_rates']) && \is_array($base_pricing['nightly_rates'])) {
        foreach ($base_pricing['nightly_rates'] as $nightly_rate) {
            if (!\is_array($nightly_rate)) {
                continue;
            }

            $seasonal_modifier_total += isset($nightly_rate['seasonal_modifier']) ? (float) $nightly_rate['seasonal_modifier'] : 0.0;
        }
    }

    $extra_guest_count = 0;
    $extra_guest_amount = 0.0;
    $room_subtotal = round_price($base_amount);

    $settings = get_pricing_engine_settings();
    $coupon_rules = get_pricing_rule_group($settings, ['coupons', 'coupon_rules']);
    $table_coupon_rule = get_coupon_rule_from_table($coupon_code);

    $table_tax_fee_rules = get_tax_fee_rules_from_table();

    if (!empty($table_tax_fee_rules)) {
        $tax_fee = calculate_tax_fee_totals_from_table($table_tax_fee_rules, $room_subtotal, $nights);
        $fees_total = isset($tax_fee['fees_total']) ? (float) $tax_fee['fees_total'] : 0.0;
        $taxes_total = isset($tax_fee['taxes_total']) ? (float) $tax_fee['taxes_total'] : 0.0;
        $fees_lines = isset($tax_fee['fees_lines']) && \is_array($tax_fee['fees_lines']) ? $tax_fee['fees_lines'] : [];
        $taxes_lines = isset($tax_fee['taxes_lines']) && \is_array($tax_fee['taxes_lines']) ? $tax_fee['taxes_lines'] : [];
    } else {
        $tax_rules = get_pricing_rule_group($settings, ['taxes', 'tax_rules']);
        $fee_rules = get_pricing_rule_group($settings, ['fees', 'fee_rules']);
        $fees = calculate_fee_total($fee_rules, $room_subtotal, $nights, $guests);
        $taxes = calculate_tax_total($tax_rules, $room_subtotal);
        $fees_total = (float) $fees['total'];
        $taxes_total = (float) $taxes['total'];
        $fees_lines = \is_array($fees['lines']) ? $fees['lines'] : [];
        $taxes_lines = \is_array($taxes['lines']) ? $taxes['lines'] : [];
    }

    $discount_base = round_price($room_subtotal + $fees_total);
    $coupon_rule = \is_array($table_coupon_rule) ? $table_coupon_rule : find_coupon_rule($coupon_code, $coupon_rules);
    $discount = calculate_coupon_discount($discount_base, $coupon_code, $coupon_rule);
    $applied_coupon_id = 0;

    if ($discount['amount'] > 0 && \is_array($coupon_rule) && isset($coupon_rule['id'])) {
        $applied_coupon_id = (int) $coupon_rule['id'];
    }

    $subtotal_after_discount = round_price(\max(0.0, $discount_base - $discount['amount']));
    $total_price = round_price($subtotal_after_discount + $taxes_total);

    return [
        'success' => true,
        'room_id' => $room_id,
        'rate_plan_id' => \is_array($rate_plan_context) ? \max(0, $rate_plan_id) : 0,
        'rate_plan_name' => \is_array($rate_plan_context) ? (string) ($rate_plan_context['name'] ?? '') : '',
        'checkin' => $checkin,
        'checkout' => $checkout,
        'nights' => $nights,
        'guests' => $guests,
        'room_base_price' => round_price($room_base_price),
        'base_capacity' => $base_capacity,
        'extra_guest_price' => 0.0,
        'extra_guest_count' => $extra_guest_count,
        'base_amount' => $base_amount,
        'nightly_rates' => isset($base_pricing['nightly_rates']) && \is_array($base_pricing['nightly_rates']) ? $base_pricing['nightly_rates'] : [],
        'seasonal_rules_count' => \count($seasonal_rules),
        'seasonal_modifier_total' => round_price($seasonal_modifier_total),
        'extra_guest_amount' => $extra_guest_amount,
        'room_subtotal' => $room_subtotal,
        'fees_total' => $fees_total,
        'discount_total' => $discount['amount'],
        'taxes_total' => $taxes_total,
        'total_price' => $total_price,
        'applied_coupon' => $discount['applied_code'],
        'applied_coupon_id' => $applied_coupon_id,
        'breakdown' => [
            'fees' => $fees_lines,
            'taxes' => $taxes_lines,
        ],
    ];
}

/**
 * Bootstrap pricing engine module.
 */
function bootstrap_pricing_engine(): void
{
    // Runtime hooks can be added here when checkout handlers are implemented.
}

\add_action('must_hotel_booking/init', __NAMESPACE__ . '\bootstrap_pricing_engine');
