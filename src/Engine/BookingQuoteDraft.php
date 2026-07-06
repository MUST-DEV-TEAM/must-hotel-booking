<?php

namespace MustHotelBooking\Engine;

use MustHotelBooking\Core\MustBookingConfig;

final class BookingQuoteDraft
{
    private const VERSION = 2;

    /**
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $selectedRooms
     * @param array<string, mixed> $roomItems
     * @param array<string, mixed> $guestForm
     * @return array<string, mixed>
     */
    public static function create(
        array $context,
        array $selectedRooms,
        array $roomItems,
        string $couponCode = '',
        array $guestForm = [],
        string $step = 'checkout'
    ): array {
        $now = \time();
        $roomIds = self::selectedRoomIds($selectedRooms);
        $firstRoomId = isset($roomIds[0]) ? (int) $roomIds[0] : 0;
        $items = self::normalizeItems(isset($roomItems['items']) && \is_array($roomItems['items']) ? $roomItems['items'] : []);
        $summary = self::normalizeSummary(isset($roomItems['summary']) && \is_array($roomItems['summary']) ? $roomItems['summary'] : []);
        $guestComposition = self::guestComposition($context);
        $draft = [
            'version' => self::VERSION,
            'token' => self::generateToken(),
            'accommodation_id' => self::firstAccommodationId($items, $firstRoomId),
            'inventory_room_id' => $firstRoomId,
            'checkin' => self::text($context['checkin'] ?? ''),
            'checkout' => self::text($context['checkout'] ?? ''),
            'guests' => \max(1, (int) ($context['guests'] ?? 1)),
            'adults' => $guestComposition['adults'],
            'children' => $guestComposition['children'],
            'child_ages' => $guestComposition['child_ages'],
            'room_count' => \max(1, (int) ($context['room_count'] ?? \count($roomIds))),
            'accommodation_type' => self::key($context['accommodation_type'] ?? ''),
            'selected_rooms' => self::normalizeSelectedRooms($selectedRooms),
            'availability_snapshot' => [
                'available' => true,
                'room_ids' => $roomIds,
                'checked_at' => $now,
            ],
            'pricing_snapshot' => [
                'items' => $items,
                'summary' => $summary,
                'currency' => \class_exists(MustBookingConfig::class) ? MustBookingConfig::get_currency() : 'EUR',
            ],
            'taxes_snapshot' => [
                'total' => (float) ($summary['taxes_total'] ?? 0.0),
            ],
            'fees_snapshot' => [
                'total' => (float) ($summary['fees_total'] ?? 0.0),
            ],
            'guarantee_policy_snapshot' => self::guaranteePolicySnapshot($items),
            'cancellation_policy_snapshot' => self::cancellationPolicySnapshot($items),
            'coupon_code' => self::text($couponCode),
            'guest_allocation_signature' => self::guestAllocationSignature($guestForm),
            'created_at' => $now,
            'expires_at' => $now + LockEngine::getTtlSeconds(),
            'current_step' => self::normalizeStep($step),
        ];
        $draft['signature'] = self::signature($draft);

        return $draft;
    }

    /**
     * @param mixed $draft
     * @return array<string, mixed>
     */
    public static function normalize($draft): array
    {
        if (!\is_array($draft)) {
            return [];
        }

        $normalized = [
            'version' => (int) ($draft['version'] ?? 0),
            'token' => self::token($draft['token'] ?? ''),
            'accommodation_id' => \max(0, (int) ($draft['accommodation_id'] ?? 0)),
            'inventory_room_id' => \max(0, (int) ($draft['inventory_room_id'] ?? 0)),
            'checkin' => self::text($draft['checkin'] ?? ''),
            'checkout' => self::text($draft['checkout'] ?? ''),
            'guests' => \max(1, (int) ($draft['guests'] ?? 1)),
            'adults' => \max(1, (int) ($draft['adults'] ?? $draft['guests'] ?? 1)),
            'children' => \max(0, (int) ($draft['children'] ?? 0)),
            'child_ages' => self::childAges($draft['child_ages'] ?? []),
            'room_count' => \max(1, (int) ($draft['room_count'] ?? 1)),
            'accommodation_type' => self::key($draft['accommodation_type'] ?? ''),
            'selected_rooms' => self::normalizeSelectedRooms($draft['selected_rooms'] ?? []),
            'availability_snapshot' => [
                'available' => !empty($draft['availability_snapshot']['available']),
                'room_ids' => self::integerList($draft['availability_snapshot']['room_ids'] ?? []),
                'checked_at' => \max(0, (int) ($draft['availability_snapshot']['checked_at'] ?? 0)),
            ],
            'pricing_snapshot' => [
                'items' => self::normalizeItems($draft['pricing_snapshot']['items'] ?? []),
                'summary' => self::normalizeSummary($draft['pricing_snapshot']['summary'] ?? []),
                'currency' => self::text($draft['pricing_snapshot']['currency'] ?? ''),
            ],
            'taxes_snapshot' => [
                'total' => (float) ($draft['taxes_snapshot']['total'] ?? 0.0),
            ],
            'fees_snapshot' => [
                'total' => (float) ($draft['fees_snapshot']['total'] ?? 0.0),
            ],
            'guarantee_policy_snapshot' => self::sanitizeValue($draft['guarantee_policy_snapshot'] ?? []),
            'cancellation_policy_snapshot' => self::sanitizeValue($draft['cancellation_policy_snapshot'] ?? []),
            'coupon_code' => self::text($draft['coupon_code'] ?? ''),
            'guest_allocation_signature' => self::text($draft['guest_allocation_signature'] ?? ''),
            'created_at' => \max(0, (int) ($draft['created_at'] ?? 0)),
            'expires_at' => \max(0, (int) ($draft['expires_at'] ?? 0)),
            'current_step' => self::normalizeStep($draft['current_step'] ?? ''),
            'signature' => self::text($draft['signature'] ?? ''),
        ];

        if ($normalized['version'] !== self::VERSION || $normalized['token'] === '') {
            return [];
        }

        return $normalized;
    }

    /**
     * @param mixed $draft
     * @param array<string, mixed> $context
     * @param array<int, array<string, mixed>> $selectedRooms
     * @param array<string, mixed> $guestForm
     */
    public static function isValidFor(
        $draft,
        array $context,
        array $selectedRooms,
        string $couponCode = '',
        array $guestForm = []
    ): bool {
        $draft = self::normalize($draft);

        if (empty($draft) || (int) ($draft['expires_at'] ?? 0) <= \time()) {
            return false;
        }

        $providedSignature = (string) ($draft['signature'] ?? '');
        $guestComposition = self::guestComposition($context);

        if ($providedSignature === '' || !\hash_equals(self::signature($draft), $providedSignature)) {
            return false;
        }

        return
            (string) ($draft['checkin'] ?? '') === self::text($context['checkin'] ?? '')
            && (string) ($draft['checkout'] ?? '') === self::text($context['checkout'] ?? '')
            && (int) ($draft['guests'] ?? 0) === \max(1, (int) ($context['guests'] ?? 1))
            && (int) ($draft['adults'] ?? 0) === $guestComposition['adults']
            && (int) ($draft['children'] ?? 0) === $guestComposition['children']
            && (array) ($draft['child_ages'] ?? []) === $guestComposition['child_ages']
            && (int) ($draft['room_count'] ?? 0) === \max(1, (int) ($context['room_count'] ?? \count($selectedRooms)))
            && (string) ($draft['accommodation_type'] ?? '') === self::key($context['accommodation_type'] ?? '')
            && (string) ($draft['coupon_code'] ?? '') === self::text($couponCode)
            && (string) ($draft['guest_allocation_signature'] ?? '') === self::guestAllocationSignature($guestForm)
            && self::normalizeSelectedRooms($draft['selected_rooms'] ?? []) === self::normalizeSelectedRooms($selectedRooms)
            && !empty($draft['availability_snapshot']['available'])
            && !empty($draft['pricing_snapshot']['items']);
    }

    /**
     * @param mixed $draft
     */
    public static function validationFailureReason(
        $draft,
        array $context,
        array $selectedRooms,
        string $couponCode = '',
        array $guestForm = []
    ): string {
        $normalized = self::normalize($draft);

        if (empty($normalized)) {
            return \is_array($draft) && !empty($draft) ? 'tampered' : 'missing';
        }

        if ((int) ($normalized['expires_at'] ?? 0) <= \time()) {
            return 'expired';
        }

        $providedSignature = (string) ($normalized['signature'] ?? '');

        if ($providedSignature === '' || !\hash_equals(self::signature($normalized), $providedSignature)) {
            return 'tampered';
        }

        return self::isValidFor($normalized, $context, $selectedRooms, $couponCode, $guestForm)
            ? ''
            : 'changed';
    }

    /**
     * @param mixed $draft
     * @return array<string, mixed>
     */
    public static function roomItems($draft): array
    {
        $draft = self::normalize($draft);

        if (empty($draft)) {
            return [];
        }

        return [
            'items' => (array) ($draft['pricing_snapshot']['items'] ?? []),
            'summary' => (array) ($draft['pricing_snapshot']['summary'] ?? []),
            'errors' => [],
            'room_guest_counts' => self::roomGuestCounts((array) ($draft['pricing_snapshot']['items'] ?? [])),
            'quote_token' => (string) ($draft['token'] ?? ''),
            'quote_expires_at' => (int) ($draft['expires_at'] ?? 0),
            'quote_cache' => 'hit',
        ];
    }

    /**
     * @param mixed $draft
     * @return array<string, mixed>
     */
    public static function pricingForRoom($draft, int $roomId, int $ratePlanId = 0): array
    {
        $draft = self::normalize($draft);

        if (empty($draft) || $roomId <= 0) {
            return [];
        }

        foreach ((array) ($draft['pricing_snapshot']['items'] ?? []) as $item) {
            if (!\is_array($item) || (int) ($item['room_id'] ?? 0) !== $roomId) {
                continue;
            }

            if ($ratePlanId > 0 && (int) ($item['rate_plan_id'] ?? 0) !== $ratePlanId) {
                continue;
            }

            return isset($item['pricing']) && \is_array($item['pricing']) ? $item['pricing'] : [];
        }

        return [];
    }

    /**
     * The guest must explicitly review any total or currency change.
     *
     * @param mixed $draft
     * @param array<string, mixed> $freshPricing
     */
    public static function pricingMatches($draft, int $roomId, int $ratePlanId, array $freshPricing): bool
    {
        $draft = self::normalize($draft);
        $stored = self::pricingForRoom($draft, $roomId, $ratePlanId);

        if (empty($draft) || empty($stored) || !isset($stored['total_price']) || !isset($freshPricing['total_price'])) {
            return false;
        }

        $storedCurrency = \strtoupper(self::text($stored['currency'] ?? ($draft['pricing_snapshot']['currency'] ?? '')));
        $freshCurrency = \strtoupper(self::text($freshPricing['currency'] ?? ($draft['pricing_snapshot']['currency'] ?? '')));

        return \abs((float) $stored['total_price'] - (float) $freshPricing['total_price']) < 0.01
            && $storedCurrency !== ''
            && $storedCurrency === $freshCurrency;
    }

    /**
     * @param mixed $draft
     * @param array<string, mixed> $freshPricing
     */
    public static function guaranteePolicyMatches($draft, int $roomId, array $freshPricing): bool
    {
        $draft = self::normalize($draft);

        if (empty($draft)) {
            return false;
        }

        $storedPolicyId = 0;

        foreach ((array) ($draft['guarantee_policy_snapshot'] ?? []) as $snapshot) {
            if (\is_array($snapshot) && (int) ($snapshot['room_id'] ?? 0) === $roomId) {
                $storedPolicyId = \max(0, (int) ($snapshot['guarantee_policy_id'] ?? 0));
                break;
            }
        }

        return $storedPolicyId === \max(0, (int) ($freshPricing['guarantee_policy_id'] ?? 0));
    }

    /**
     * @param mixed $draft
     * @return array<string, mixed>
     */
    public static function withStep($draft, string $step): array
    {
        $draft = self::normalize($draft);

        if (empty($draft)) {
            return [];
        }

        $draft['current_step'] = self::normalizeStep($step);
        $draft['signature'] = self::signature($draft);

        return $draft;
    }

    /** @param array<string, mixed> $draft */
    private static function signature(array $draft): string
    {
        unset($draft['signature']);
        $encoded = \function_exists('wp_json_encode') ? \wp_json_encode($draft) : \json_encode($draft);

        return \hash_hmac('sha256', \is_string($encoded) ? $encoded : '', self::signingKey());
    }

    private static function signingKey(): string
    {
        if (\function_exists('wp_salt')) {
            return (string) \wp_salt('auth');
        }

        if (\defined('AUTH_SALT')) {
            return (string) AUTH_SALT;
        }

        return 'must-hotel-booking-quote-draft';
    }

    private static function generateToken(): string
    {
        try {
            return \bin2hex(\random_bytes(24));
        } catch (\Throwable $exception) {
            return \hash('sha256', \uniqid('mhb_quote_', true));
        }
    }

    /** @param mixed $value */
    private static function token($value): string
    {
        $value = \strtolower((string) \preg_replace('/[^a-f0-9]/i', '', (string) $value));

        return \strlen($value) >= 32 ? \substr($value, 0, 64) : '';
    }

    /** @param mixed $value */
    private static function text($value): string
    {
        return \function_exists('sanitize_text_field')
            ? \sanitize_text_field((string) $value)
            : \trim((string) $value);
    }

    /** @param mixed $value */
    private static function key($value): string
    {
        return \function_exists('sanitize_key')
            ? \sanitize_key((string) $value)
            : \strtolower((string) \preg_replace('/[^a-z0-9_\-]/i', '', (string) $value));
    }

    /** @param mixed $value */
    private static function normalizeStep($value): string
    {
        $step = self::key($value);

        return \in_array($step, ['booking', 'accommodation', 'checkout', 'confirmation', 'payment'], true)
            ? $step
            : 'checkout';
    }

    /**
     * @param mixed $selectedRooms
     * @return array<int, array<string, int>>
     */
    private static function normalizeSelectedRooms($selectedRooms): array
    {
        if (!\is_array($selectedRooms)) {
            return [];
        }

        $normalized = [];

        foreach ($selectedRooms as $selectedRoom) {
            if (!\is_array($selectedRoom)) {
                continue;
            }

            $roomId = \max(0, (int) ($selectedRoom['room_id'] ?? 0));

            if ($roomId <= 0) {
                continue;
            }

            $normalized[$roomId] = [
                'room_id' => $roomId,
                'rate_plan_id' => \max(0, (int) ($selectedRoom['rate_plan_id'] ?? 0)),
            ];
        }

        \ksort($normalized);

        return \array_values($normalized);
    }

    /** @param array<int, array<string, mixed>> $selectedRooms @return array<int, int> */
    private static function selectedRoomIds(array $selectedRooms): array
    {
        $ids = [];

        foreach (self::normalizeSelectedRooms($selectedRooms) as $row) {
            $ids[] = (int) $row['room_id'];
        }

        return $ids;
    }

    /** @param mixed $values @return array<int, int> */
    private static function integerList($values): array
    {
        if (!\is_array($values)) {
            return [];
        }

        $normalized = [];

        foreach ($values as $value) {
            $value = \max(0, (int) $value);

            if ($value > 0) {
                $normalized[$value] = $value;
            }
        }

        return \array_values($normalized);
    }

    /** @param mixed $items @return array<int, array<string, mixed>> */
    private static function normalizeItems($items): array
    {
        if (!\is_array($items)) {
            return [];
        }

        $normalized = [];

        foreach ($items as $item) {
            if (!\is_array($item)) {
                continue;
            }

            $roomId = \max(0, (int) ($item['room_id'] ?? 0));

            if ($roomId <= 0) {
                continue;
            }

            $normalized[] = [
                'room_id' => $roomId,
                'rate_plan_id' => \max(0, (int) ($item['rate_plan_id'] ?? 0)),
                'rate_plan' => self::sanitizeValue($item['rate_plan'] ?? []),
                'assigned_guests' => \max(1, (int) ($item['assigned_guests'] ?? 1)),
                'room' => self::sanitizeValue($item['room'] ?? []),
                'pricing' => self::normalizePricing($item['pricing'] ?? []),
            ];
        }

        return $normalized;
    }

    /** @param mixed $pricing @return array<string, mixed> */
    private static function normalizePricing($pricing): array
    {
        if (!\is_array($pricing)) {
            return [];
        }

        $allowed = [
            'success',
            'total_price',
            'room_subtotal',
            'fees_total',
            'discount_total',
            'taxes_total',
            'nights',
            'applied_coupon',
            'applied_coupon_id',
            'currency',
            'guarantee_policy_id',
            'provider_rate_id',
            'nightly_rates',
            'cancellation_policy',
        ];
        $normalized = [];

        foreach ($allowed as $key) {
            if (\array_key_exists($key, $pricing)) {
                $normalized[$key] = self::sanitizeValue($pricing[$key]);
            }
        }

        return $normalized;
    }

    /** @param mixed $summary @return array<string, mixed> */
    private static function normalizeSummary($summary): array
    {
        $summary = \is_array($summary) ? $summary : [];

        return [
            'room_subtotal' => (float) ($summary['room_subtotal'] ?? 0.0),
            'fees_total' => (float) ($summary['fees_total'] ?? 0.0),
            'discount_total' => (float) ($summary['discount_total'] ?? 0.0),
            'taxes_total' => (float) ($summary['taxes_total'] ?? 0.0),
            'total_price' => (float) ($summary['total_price'] ?? 0.0),
            'nights' => \max(0, (int) ($summary['nights'] ?? 0)),
            'applied_coupon' => self::text($summary['applied_coupon'] ?? ''),
        ];
    }

    /** @param array<int, array<string, mixed>> $items */
    private static function firstAccommodationId(array $items, int $fallback): int
    {
        foreach ($items as $item) {
            $room = isset($item['room']) && \is_array($item['room']) ? $item['room'] : [];
            $roomTypeId = \max(0, (int) ($room['room_type_id'] ?? 0));

            if ($roomTypeId > 0) {
                return $roomTypeId;
            }
        }

        return $fallback;
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, mixed> */
    private static function cancellationPolicySnapshot(array $items): array
    {
        $policies = [];

        foreach ($items as $item) {
            $pricing = isset($item['pricing']) && \is_array($item['pricing']) ? $item['pricing'] : [];

            if (!empty($pricing['cancellation_policy'])) {
                $policies[] = self::sanitizeValue($pricing['cancellation_policy']);
            }
        }

        return $policies;
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, array<string, int>> */
    private static function guaranteePolicySnapshot(array $items): array
    {
        $policies = [];

        foreach ($items as $item) {
            $roomId = \max(0, (int) ($item['room_id'] ?? 0));
            $pricing = isset($item['pricing']) && \is_array($item['pricing']) ? $item['pricing'] : [];

            if ($roomId <= 0) {
                continue;
            }

            $policies[] = [
                'room_id' => $roomId,
                'guarantee_policy_id' => \max(0, (int) ($pricing['guarantee_policy_id'] ?? 0)),
            ];
        }

        return $policies;
    }

    /** @param array<string, mixed> $context @return array{adults: int, children: int, child_ages: array<int, int>} */
    private static function guestComposition(array $context): array
    {
        $guests = \max(1, (int) ($context['guests'] ?? 1));
        $children = \max(0, \min($guests - 1, (int) ($context['children'] ?? 0)));
        $adults = isset($context['adults'])
            ? \max(1, (int) $context['adults'])
            : \max(1, $guests - $children);
        $childAges = $children > 0
            ? \array_slice(self::childAges($context['child_ages'] ?? []), 0, $children)
            : [];

        return [
            'adults' => $adults,
            'children' => $children,
            'child_ages' => $childAges,
        ];
    }

    /** @param mixed $ages @return array<int, int> */
    private static function childAges($ages): array
    {
        if (!\is_array($ages)) {
            return [];
        }

        $normalized = [];

        foreach ($ages as $age) {
            if (!\is_numeric($age)) {
                continue;
            }

            $normalized[] = \max(0, \min(17, (int) $age));
        }

        return $normalized;
    }

    /** @param array<string, mixed> $guestForm */
    private static function guestAllocationSignature(array $guestForm): string
    {
        $counts = [];
        $roomGuests = isset($guestForm['room_guests']) && \is_array($guestForm['room_guests'])
            ? $guestForm['room_guests']
            : [];

        foreach ($roomGuests as $roomId => $roomGuest) {
            $roomId = \max(0, (int) $roomId);

            if ($roomId <= 0 || !\is_array($roomGuest)) {
                continue;
            }

            $counts[$roomId] = \max(1, (int) ($roomGuest['guest_count'] ?? 1));
        }

        \ksort($counts);

        return \hash('sha256', (string) \json_encode($counts));
    }

    /** @param array<int, array<string, mixed>> $items @return array<int, int> */
    private static function roomGuestCounts(array $items): array
    {
        $counts = [];

        foreach ($items as $item) {
            $roomId = \max(0, (int) ($item['room_id'] ?? 0));

            if ($roomId > 0) {
                $counts[$roomId] = \max(1, (int) ($item['assigned_guests'] ?? 1));
            }
        }

        return $counts;
    }

    /** @param mixed $value @return mixed */
    private static function sanitizeValue($value)
    {
        if (\is_bool($value) || \is_int($value) || \is_float($value) || $value === null) {
            return $value;
        }

        if (\is_array($value)) {
            $clean = [];

            foreach ($value as $key => $child) {
                $key = \is_string($key) ? self::key($key) : (int) $key;

                if (\is_string($key) && \in_array($key, ['provider_product', 'provider_quote', 'request', 'response'], true)) {
                    continue;
                }

                $clean[$key] = self::sanitizeValue($child);
            }

            return $clean;
        }

        return self::text($value);
    }
}
