<?php

namespace must_hotel_booking;

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
    global $wpdb;

    if ($room_id <= 0) {
        return null;
    }

    $rooms_table = $wpdb->prefix . 'must_rooms';
    $room_meta_table = $wpdb->prefix . 'must_room_meta';

    $room = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT *
            FROM {$rooms_table}
            WHERE id = %d
            LIMIT 1",
            $room_id
        ),
        ARRAY_A
    );

    if (!\is_array($room)) {
        return null;
    }

    $meta_rows = $wpdb->get_results(
        $wpdb->prepare(
            "SELECT meta_key, meta_value
            FROM {$room_meta_table}
            WHERE room_id = %d
                AND meta_key IN ('base_capacity', 'extra_guest_price')",
            $room_id
        ),
        ARRAY_A
    );

    $base_capacity = isset($room['max_guests']) ? (int) $room['max_guests'] : 1;
    $extra_guest_price = isset($room['extra_guest_price']) ? (float) $room['extra_guest_price'] : 0.0;

    if (\is_array($meta_rows)) {
        foreach ($meta_rows as $meta_row) {
            if (!\is_array($meta_row) || !isset($meta_row['meta_key'])) {
                continue;
            }

            $key = (string) $meta_row['meta_key'];
            $value = isset($meta_row['meta_value']) ? (string) $meta_row['meta_value'] : '';

            if ($key === 'base_capacity') {
                $parsed_capacity = (int) $value;

                if ($parsed_capacity > 0) {
                    $base_capacity = $parsed_capacity;
                }
            }

            if ($key === 'extra_guest_price' && $extra_guest_price <= 0) {
                $extra_guest_price = (float) $value;
            }
        }
    }

    if ($base_capacity <= 0) {
        $base_capacity = 1;
    }

    return [
        'room_id' => (int) $room['id'],
        'room_base_price' => (float) $room['base_price'],
        'base_capacity' => $base_capacity,
        'extra_guest_price' => $extra_guest_price,
    ];
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
    global $wpdb;

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

    $sql = $wpdb->prepare(
        "SELECT
            id,
            name,
            room_id,
            start_date,
            end_date,
            price_override,
            weekend_price,
            minimum_nights
        FROM " . get_pricing_table_name() . "
        WHERE start_date < %s
            AND end_date >= %s
            AND minimum_nights <= %d
            AND (room_id = 0 OR room_id = %d)
        ORDER BY room_id DESC, minimum_nights DESC, start_date DESC, end_date ASC, id DESC",
        $checkout,
        $checkin,
        $nights,
        $room_id
    );

    $rows = $wpdb->get_results($sql, ARRAY_A);

    return \is_array($rows) ? $rows : [];
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
    global $wpdb;

    $needle = \strtoupper(\trim($coupon_code));
    $needle = (string) \preg_replace('/[^A-Z0-9_-]/', '', $needle);

    if ($needle === '') {
        return null;
    }

    $table_name = get_coupons_table_name();
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    if (!\is_string($table_exists) || $table_exists === '') {
        return null;
    }

    $row = $wpdb->get_row(
        $wpdb->prepare(
            "SELECT
                id,
                code,
                discount_type,
                discount_value,
                valid_from,
                valid_until,
                usage_limit,
                usage_count
            FROM {$table_name}
            WHERE code = %s
            LIMIT 1",
            $needle
        ),
        ARRAY_A
    );

    if (!\is_array($row)) {
        return null;
    }

    $today = \current_time('Y-m-d');
    $valid_from = isset($row['valid_from']) ? (string) $row['valid_from'] : '';
    $valid_until = isset($row['valid_until']) ? (string) $row['valid_until'] : '';

    if (
        !\function_exists(__NAMESPACE__ . '\is_valid_booking_date') ||
        !is_valid_booking_date($valid_from) ||
        !is_valid_booking_date($valid_until) ||
        $today < $valid_from ||
        $today > $valid_until
    ) {
        return null;
    }

    $usage_limit = isset($row['usage_limit']) ? (int) $row['usage_limit'] : 0;
    $usage_count = isset($row['usage_count']) ? (int) $row['usage_count'] : 0;

    if ($usage_limit > 0 && $usage_count >= $usage_limit) {
        return null;
    }

    $discount_type = isset($row['discount_type']) ? \sanitize_key((string) $row['discount_type']) : 'percentage';
    $normalized_type = $discount_type === 'fixed' ? 'fixed' : 'percent';

    return [
        'id' => isset($row['id']) ? (int) $row['id'] : 0,
        'code' => isset($row['code']) ? (string) $row['code'] : $needle,
        'type' => $normalized_type,
        'value' => isset($row['discount_value']) ? (float) $row['discount_value'] : 0.0,
        'usage_limit' => $usage_limit,
        'usage_count' => $usage_count,
        'valid_from' => $valid_from,
        'valid_until' => $valid_until,
    ];
}

/**
 * Increment coupon usage count by coupon ID.
 */
function increment_coupon_usage_count(int $coupon_id): bool
{
    global $wpdb;

    if ($coupon_id <= 0) {
        return false;
    }

    $table_name = get_coupons_table_name();
    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    if (!\is_string($table_exists) || $table_exists === '') {
        return false;
    }

    $updated = $wpdb->query(
        $wpdb->prepare(
            "UPDATE {$table_name}
            SET usage_count = usage_count + 1
            WHERE id = %d
                AND (usage_limit = 0 OR usage_count < usage_limit)",
            $coupon_id
        )
    );

    return \is_int($updated) && $updated > 0;
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
    global $wpdb;

    $table_name = get_taxes_table_name();

    $table_exists = $wpdb->get_var(
        $wpdb->prepare(
            'SHOW TABLES LIKE %s',
            $table_name
        )
    );

    if (!\is_string($table_exists) || $table_exists === '') {
        return [];
    }

    $rows = $wpdb->get_results(
        'SELECT id, name, rule_type, rule_value, apply_mode
        FROM ' . $table_name . '
        ORDER BY id ASC',
        ARRAY_A
    );

    return \is_array($rows) ? $rows : [];
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
function calculate_booking_price(int $room_id, string $checkin, string $checkout, int $guests = 1, string $coupon_code = ''): array
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

    if (!\is_array($room_context)) {
        return [
            'success' => false,
            'message' => 'Room pricing data not found.',
        ];
    }

    $room_base_price = isset($room_context['room_base_price']) ? (float) $room_context['room_base_price'] : 0.0;
    $base_capacity = isset($room_context['base_capacity']) ? (int) $room_context['base_capacity'] : 1;
    $extra_guest_price = isset($room_context['extra_guest_price']) ? (float) $room_context['extra_guest_price'] : 0.0;

    if ($base_capacity <= 0) {
        $base_capacity = 1;
    }

    if ($extra_guest_price < 0) {
        $extra_guest_price = 0.0;
    }

    $seasonal_rules = get_applicable_seasonal_pricing_rules($room_id, $checkin, $checkout, $nights);
    $base_pricing = calculate_base_amount_with_seasonal_rules($room_base_price, $checkin, $nights, $seasonal_rules);
    $base_amount = isset($base_pricing['base_amount']) ? (float) $base_pricing['base_amount'] : 0.0;
    $extra_guest_count = \max(0, $guests - $base_capacity);
    $extra_guest_amount = round_price($extra_guest_count * $extra_guest_price);
    $room_subtotal = round_price($base_amount + $extra_guest_amount);

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
        'checkin' => $checkin,
        'checkout' => $checkout,
        'nights' => $nights,
        'guests' => $guests,
        'room_base_price' => round_price($room_base_price),
        'base_capacity' => $base_capacity,
        'extra_guest_price' => round_price($extra_guest_price),
        'extra_guest_count' => $extra_guest_count,
        'base_amount' => $base_amount,
        'nightly_rates' => isset($base_pricing['nightly_rates']) && \is_array($base_pricing['nightly_rates']) ? $base_pricing['nightly_rates'] : [],
        'seasonal_rules_count' => \count($seasonal_rules),
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
