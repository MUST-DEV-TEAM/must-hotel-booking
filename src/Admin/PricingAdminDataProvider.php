<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Database\PricingRuleRepository;
use MustHotelBooking\Engine\PricingEngine;

final class PricingAdminDataProvider
{
    private PricingRuleRepository $pricingRepository;
    private \MustHotelBooking\Database\RoomRepository $roomRepository;
    private \MustHotelBooking\Database\RatePlanRepository $ratePlanRepository;

    public function __construct()
    {
        $this->pricingRepository = new PricingRuleRepository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
        $this->ratePlanRepository = \MustHotelBooking\Engine\get_rate_plan_repository();
    }

    /**
     * @param array<string, mixed> $state
     * @return array<string, mixed>
     */
    public function getPageData(PricingAdminQuery $query, array $state = []): array
    {
        $today = \current_time('Y-m-d');
        $rooms = $this->roomRepository->getAccommodationAdminRows();
        $roomIds = \array_values(\array_filter(\array_map(static fn(array $row): int => isset($row['id']) ? (int) $row['id'] : 0, $rooms)));
        $ratePlanSummaryMap = $this->ratePlanRepository->getRoomTypeRatePlanSummaryMap($roomIds);
        $allRules = $this->pricingRepository->getRules(['include_inactive' => true, 'today' => $today]);
        $roomRows = $this->buildRoomRows($rooms, $allRules, $ratePlanSummaryMap, $today);
        $filteredRoomRows = $this->filterRoomRows($roomRows, $query);
        $ruleRows = $this->buildRuleRows($allRules, $today);
        $filteredRuleRows = $this->filterRuleRows($ruleRows, $query);
        $submittedRuleForm = isset($state['rule_form']) && \is_array($state['rule_form']) ? $state['rule_form'] : null;

        return [
            'today' => $today,
            'filters' => [
                'room_id' => $query->getRoomId(),
                'status' => $query->getStatus(),
                'timeline' => $query->getTimeline(),
                'setup' => $query->getSetup(),
                'scope' => $query->getScope(),
                'rule_type' => $query->getRuleType(),
                'search' => $query->getSearch(),
            ],
            'room_options' => $this->buildRoomOptions($rooms),
            'summary_cards' => $this->buildSummaryCards($roomRows, $ruleRows),
            'room_rows' => $filteredRoomRows,
            'rule_rows' => $filteredRuleRows,
            'rule_form' => $this->getRuleFormData($query, $submittedRuleForm),
            'base_price_errors' => isset($state['base_price_errors']) && \is_array($state['base_price_errors']) ? $state['base_price_errors'] : [],
            'rule_errors' => isset($state['rule_errors']) && \is_array($state['rule_errors']) ? $state['rule_errors'] : [],
            'calculation_note' => \__('Booking totals currently resolve in this order: base room or selected rate plan nightly price, direct pricing overrides, fees, coupon discount, then taxes.', 'must-hotel-booking'),
        ];
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @param array<int, array<string, mixed>> $allRules
     * @param array<int, array<string, mixed>> $ratePlanSummaryMap
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomRows(array $rooms, array $allRules, array $ratePlanSummaryMap, string $today): array
    {
        $rows = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;
            $basePrice = isset($room['base_price']) ? (float) $room['base_price'] : 0.0;
            $roomRules = \array_values(\array_filter(
                $allRules,
                static function (array $rule) use ($roomId): bool {
                    $ruleRoomId = isset($rule['room_id']) ? (int) $rule['room_id'] : 0;
                    return $ruleRoomId === 0 || $ruleRoomId === $roomId;
                }
            ));
            $directRules = \array_values(\array_filter(
                $allRules,
                static function (array $rule) use ($roomId): bool {
                    return isset($rule['room_id']) && (int) $rule['room_id'] === $roomId;
                }
            ));
            $activeRules = \array_values(\array_filter($roomRules, static fn(array $rule): bool => !empty($rule['is_active'])));
            $activeDirectRules = \array_values(\array_filter($directRules, static fn(array $rule): bool => !empty($rule['is_active'])));
            $currentRule = $this->resolveCurrentRule($activeRules, $today);
            $nextRule = $this->resolveNextRule($activeRules, $today);
            $overlapCount = $this->countOverlaps($activeDirectRules);
            $ratePlanData = $ratePlanSummaryMap[$roomId] ?? ['active_assignment_count' => 0];
            $hasAdvancedPricing = (int) ($ratePlanData['active_assignment_count'] ?? 0) > 0;
            $missingPricing = !empty($room['is_active']) && !empty($room['is_bookable']) && $basePrice <= 0.0 && !$hasAdvancedPricing;
            $inactiveHasPricing = empty($room['is_active']) && ($basePrice > 0.0 || !empty($activeRules) || $hasAdvancedPricing);
            $currentNightly = $this->previewNightlyPrice($roomId, $today);
            $warnings = [];

            if ($missingPricing) {
                $warnings[] = \__('Missing base price and no active rate plan assignment.', 'must-hotel-booking');
            }

            if ($overlapCount > 0) {
                $warnings[] = \sprintf(_n('%d overlapping active override', '%d overlapping active overrides', $overlapCount, 'must-hotel-booking'), $overlapCount);
            }

            if ($inactiveHasPricing) {
                $warnings[] = \__('Accommodation is inactive but still has pricing configured.', 'must-hotel-booking');
            }

            $rows[] = [
                'id' => $roomId,
                'name' => (string) ($room['name'] ?? ''),
                'category' => (string) ($room['category'] ?? ''),
                'base_price' => $basePrice,
                'is_active' => !empty($room['is_active']),
                'is_bookable' => !empty($room['is_bookable']),
                'warnings' => $warnings,
                'missing_pricing' => $missingPricing,
                'overlap_count' => $overlapCount,
                'active_rule_count' => \count($activeRules),
                'active_direct_rule_count' => \count($activeDirectRules),
                'current_rule' => $currentRule,
                'next_rule' => $nextRule,
                'current_nightly' => $currentNightly,
                'has_advanced_pricing' => $hasAdvancedPricing,
                'active_rate_plan_count' => (int) ($ratePlanData['active_assignment_count'] ?? 0),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<int, array<string, mixed>>
     */
    private function buildRuleRows(array $rules, string $today): array
    {
        $rows = [];

        foreach ($rules as $rule) {
            if (!\is_array($rule)) {
                continue;
            }

            $priceOverride = isset($rule['price_override']) ? (float) $rule['price_override'] : 0.0;
            $weekendPrice = isset($rule['weekend_price']) ? (float) $rule['weekend_price'] : 0.0;
            $ruleRoomId = isset($rule['room_id']) ? (int) $rule['room_id'] : 0;
            $timeline = 'future';

            if ((string) ($rule['end_date'] ?? '') < $today) {
                $timeline = 'past';
            } elseif ((string) ($rule['start_date'] ?? '') <= $today && (string) ($rule['end_date'] ?? '') >= $today) {
                $timeline = 'current';
            }

            $rows[] = [
                'id' => isset($rule['id']) ? (int) $rule['id'] : 0,
                'room_id' => $ruleRoomId,
                'room_name' => $ruleRoomId > 0 ? (string) ($rule['room_name'] ?? '') : \__('All accommodations', 'must-hotel-booking'),
                'scope' => $ruleRoomId > 0 ? 'room' : 'global',
                'name' => (string) ($rule['name'] ?? ''),
                'start_date' => (string) ($rule['start_date'] ?? ''),
                'end_date' => (string) ($rule['end_date'] ?? ''),
                'price_override' => $priceOverride,
                'weekend_price' => $weekendPrice,
                'minimum_nights' => isset($rule['minimum_nights']) ? (int) $rule['minimum_nights'] : 1,
                'priority' => isset($rule['priority']) ? (int) $rule['priority'] : 10,
                'is_active' => !empty($rule['is_active']),
                'timeline' => $timeline,
                'rule_type_label' => $this->getRuleTypeLabel($priceOverride, $weekendPrice),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $roomRows
     * @return array<int, array<string, mixed>>
     */
    private function filterRoomRows(array $roomRows, PricingAdminQuery $query): array
    {
        return \array_values(\array_filter(
            $roomRows,
            function (array $row) use ($query): bool {
                if ($query->getRoomId() > 0 && (int) ($row['id'] ?? 0) !== $query->getRoomId()) {
                    return false;
                }

                if ($query->getSearch() !== '') {
                    $haystack = \strtolower((string) (($row['name'] ?? '') . ' ' . ($row['category'] ?? '')));

                    if (\strpos($haystack, \strtolower($query->getSearch())) === false) {
                        return false;
                    }
                }

                if ($query->getStatus() === 'active' && empty($row['is_active'])) {
                    return false;
                }

                if ($query->getStatus() === 'inactive' && !empty($row['is_active'])) {
                    return false;
                }

                if ($query->getSetup() === 'missing_pricing' && empty($row['missing_pricing'])) {
                    return false;
                }

                if ($query->getSetup() === 'overlap' && (int) ($row['overlap_count'] ?? 0) <= 0) {
                    return false;
                }

                if ($query->getSetup() === 'rate_plan' && empty($row['has_advanced_pricing'])) {
                    return false;
                }

                return true;
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $ruleRows
     * @return array<int, array<string, mixed>>
     */
    private function filterRuleRows(array $ruleRows, PricingAdminQuery $query): array
    {
        return \array_values(\array_filter(
            $ruleRows,
            function (array $row) use ($query): bool {
                if ($query->getRoomId() > 0 && (int) ($row['room_id'] ?? 0) !== 0 && (int) ($row['room_id'] ?? 0) !== $query->getRoomId()) {
                    return false;
                }

                if ($query->getStatus() === 'active' && empty($row['is_active'])) {
                    return false;
                }

                if ($query->getStatus() === 'inactive' && !empty($row['is_active'])) {
                    return false;
                }

                if ($query->getTimeline() !== '' && $query->getTimeline() !== 'all' && (string) ($row['timeline'] ?? '') !== $query->getTimeline()) {
                    return false;
                }

                if ($query->getScope() === 'global' && (string) ($row['scope'] ?? '') !== 'global') {
                    return false;
                }

                if ($query->getScope() === 'room' && (string) ($row['scope'] ?? '') !== 'room') {
                    return false;
                }

                if ($query->getRuleType() !== '' && $query->getRuleType() !== 'all') {
                    $typeKey = (float) ($row['price_override'] ?? 0.0) > 0.0 && (float) ($row['weekend_price'] ?? 0.0) > 0.0
                        ? 'mixed'
                        : ((float) ($row['weekend_price'] ?? 0.0) > 0.0 ? 'weekend' : 'nightly');

                    if ($typeKey !== $query->getRuleType()) {
                        return false;
                    }
                }

                if ($query->getSearch() !== '') {
                    $haystack = \strtolower((string) (($row['name'] ?? '') . ' ' . ($row['room_name'] ?? '')));

                    if (\strpos($haystack, \strtolower($query->getSearch())) === false) {
                        return false;
                    }
                }

                return true;
            }
        ));
    }

    /**
     * @param array<int, array<string, mixed>> $rooms
     * @return array<int, array<string, mixed>>
     */
    private function buildRoomOptions(array $rooms): array
    {
        $options = [];

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $roomId = isset($room['id']) ? (int) $room['id'] : 0;

            if ($roomId <= 0) {
                continue;
            }

            $options[] = [
                'id' => $roomId,
                'label' => (string) ($room['name'] ?? ('#' . $roomId)),
            ];
        }

        return $options;
    }

    /**
     * @param array<int, array<string, mixed>> $roomRows
     * @param array<int, array<string, mixed>> $ruleRows
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $roomRows, array $ruleRows): array
    {
        $missingPricing = 0;
        $overlapCount = 0;
        $activeRules = 0;

        foreach ($roomRows as $row) {
            if (!empty($row['missing_pricing'])) {
                $missingPricing++;
            }

            if ((int) ($row['overlap_count'] ?? 0) > 0) {
                $overlapCount += (int) $row['overlap_count'];
            }
        }

        foreach ($ruleRows as $row) {
            if (!empty($row['is_active'])) {
                $activeRules++;
            }
        }

        return [
            [
                'label' => \__('Room Listings', 'must-hotel-booking'),
                'value' => (string) \count($roomRows),
                'meta' => \__('Direct pricing is anchored to room/listing base rates.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Active Override Rules', 'must-hotel-booking'),
                'value' => (string) $activeRules,
                'meta' => \__('Date-range and weekend rules currently available to the pricing engine.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Missing Pricing', 'must-hotel-booking'),
                'value' => (string) $missingPricing,
                'meta' => \__('Active sellable room listings missing both base pricing and rate plan fallback.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Rule Overlaps', 'must-hotel-booking'),
                'value' => (string) $overlapCount,
                'meta' => \__('Overlapping direct override rules on the same room listing.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<string, mixed>|null $submittedForm
     * @return array<string, mixed>
     */
    private function getRuleFormData(PricingAdminQuery $query, ?array $submittedForm): array
    {
        $defaults = [
            'id' => 0,
            'room_id' => $query->getRoomId(),
            'name' => '',
            'start_date' => \current_time('Y-m-d'),
            'end_date' => (new \DateTimeImmutable(\current_time('Y-m-d')))->modify('+30 days')->format('Y-m-d'),
            'price_override' => 0.0,
            'weekend_price' => 0.0,
            'minimum_nights' => 1,
            'priority' => 10,
            'is_active' => 1,
            'warnings' => [],
        ];

        if (\is_array($submittedForm)) {
            $submittedForm['warnings'] = $this->buildRuleFormWarnings($submittedForm, $defaults);
            return \array_merge($defaults, $submittedForm);
        }

        if ($query->getAction() !== 'edit_rule' || $query->getRuleId() <= 0) {
            return $defaults;
        }

        $rule = $this->pricingRepository->getRuleById($query->getRuleId());

        if (!\is_array($rule)) {
            return $defaults;
        }

        return [
            'id' => (int) ($rule['id'] ?? 0),
            'room_id' => (int) ($rule['room_id'] ?? 0),
            'name' => (string) ($rule['name'] ?? ''),
            'start_date' => (string) ($rule['start_date'] ?? $defaults['start_date']),
            'end_date' => (string) ($rule['end_date'] ?? $defaults['end_date']),
            'price_override' => (float) ($rule['price_override'] ?? 0.0),
            'weekend_price' => (float) ($rule['weekend_price'] ?? 0.0),
            'minimum_nights' => (int) ($rule['minimum_nights'] ?? 1),
            'priority' => (int) ($rule['priority'] ?? 10),
            'is_active' => !empty($rule['is_active']) ? 1 : 0,
            'warnings' => $this->buildRuleFormWarnings($rule, $defaults),
        ];
    }

    /**
     * @param array<string, mixed> $rule
     * @param array<string, mixed> $defaults
     * @return array<int, string>
     */
    private function buildRuleFormWarnings(array $rule, array $defaults): array
    {
        $overlaps = $this->pricingRepository->getOverlappingRules($rule, isset($rule['id']) ? (int) $rule['id'] : 0);
        $warnings = [];

        if (!empty($overlaps)) {
            $warnings[] = \sprintf(_n('%d active overlap will compete with this rule.', '%d active overlaps will compete with this rule.', \count($overlaps), 'must-hotel-booking'), \count($overlaps));
        }

        if ((int) ($rule['room_id'] ?? 0) <= 0) {
            $warnings[] = \__('This rule applies globally unless a room-specific rule with higher precedence exists.', 'must-hotel-booking');
        }

        if ((string) ($rule['start_date'] ?? '') === (string) ($defaults['start_date'] ?? '')) {
            return $warnings;
        }

        $preview = $this->previewNightlyPrice((int) ($rule['room_id'] ?? 0), (string) ($rule['start_date'] ?? ''));

        if ($preview > 0) {
            $warnings[] = \sprintf(
                /* translators: %s is preview nightly price. */
                \__('Current preview for the first stay date resolves to %s before taxes and discounts.', 'must-hotel-booking'),
                \number_format_i18n($preview, 2)
            );
        }

        return $warnings;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed>|null
     */
    private function resolveCurrentRule(array $rules, string $today): ?array
    {
        $applicable = \array_values(\array_filter(
            $rules,
            static function (array $rule) use ($today): bool {
                return (string) ($rule['start_date'] ?? '') <= $today && (string) ($rule['end_date'] ?? '') >= $today;
            }
        ));

        if (empty($applicable)) {
            return null;
        }

        \usort($applicable, [$this, 'sortRulePrecedence']);

        return $applicable[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     * @return array<string, mixed>|null
     */
    private function resolveNextRule(array $rules, string $today): ?array
    {
        $future = \array_values(\array_filter(
            $rules,
            static function (array $rule) use ($today): bool {
                return (string) ($rule['end_date'] ?? '') >= $today;
            }
        ));

        if (empty($future)) {
            return null;
        }

        \usort(
            $future,
            static function (array $left, array $right): int {
                $leftDate = (string) ($left['start_date'] ?? '');
                $rightDate = (string) ($right['start_date'] ?? '');

                if ($leftDate === $rightDate) {
                    return ((int) ($left['priority'] ?? 10)) <=> ((int) ($right['priority'] ?? 10));
                }

                return $leftDate <=> $rightDate;
            }
        );

        return $future[0] ?? null;
    }

    /**
     * @param array<int, array<string, mixed>> $rules
     */
    private function countOverlaps(array $rules): int
    {
        $count = 0;
        $total = \count($rules);

        for ($index = 0; $index < $total; $index++) {
            $left = $rules[$index];

            for ($compareIndex = $index + 1; $compareIndex < $total; $compareIndex++) {
                $right = $rules[$compareIndex];

                if (
                    (string) ($left['start_date'] ?? '') <= (string) ($right['end_date'] ?? '')
                    && (string) ($left['end_date'] ?? '') >= (string) ($right['start_date'] ?? '')
                ) {
                    $count++;
                }
            }
        }

        return $count;
    }

    private function previewNightlyPrice(int $roomId, string $startDate): float
    {
        if ($roomId <= 0 || $startDate === '') {
            return 0.0;
        }

        try {
            $checkout = (new \DateTimeImmutable($startDate))->modify('+1 day')->format('Y-m-d');
        } catch (\Exception $exception) {
            return 0.0;
        }

        $pricing = PricingEngine::calculateTotal($roomId, $startDate, $checkout, 1);

        if (!\is_array($pricing) || empty($pricing['success'])) {
            return 0.0;
        }

        return isset($pricing['room_subtotal']) ? (float) $pricing['room_subtotal'] : 0.0;
    }

    private function getRuleTypeLabel(float $priceOverride, float $weekendPrice): string
    {
        if ($priceOverride > 0.0 && $weekendPrice > 0.0) {
            return \__('Nightly + weekend override', 'must-hotel-booking');
        }

        if ($weekendPrice > 0.0) {
            return \__('Weekend override', 'must-hotel-booking');
        }

        return \__('Nightly override', 'must-hotel-booking');
    }

    /**
     * @param array<string, mixed> $left
     * @param array<string, mixed> $right
     */
    private function sortRulePrecedence(array $left, array $right): int
    {
        $leftRoom = isset($left['room_id']) ? (int) $left['room_id'] : 0;
        $rightRoom = isset($right['room_id']) ? (int) $right['room_id'] : 0;

        if (($leftRoom > 0) !== ($rightRoom > 0)) {
            return $leftRoom > 0 ? -1 : 1;
        }

        $leftPriority = isset($left['priority']) ? (int) $left['priority'] : 10;
        $rightPriority = isset($right['priority']) ? (int) $right['priority'] : 10;

        if ($leftPriority !== $rightPriority) {
            return $rightPriority <=> $leftPriority;
        }

        $leftMinimum = isset($left['minimum_nights']) ? (int) $left['minimum_nights'] : 1;
        $rightMinimum = isset($right['minimum_nights']) ? (int) $right['minimum_nights'] : 1;

        if ($leftMinimum !== $rightMinimum) {
            return $rightMinimum <=> $leftMinimum;
        }

        $leftStart = (string) ($left['start_date'] ?? '');
        $rightStart = (string) ($right['start_date'] ?? '');

        if ($leftStart !== $rightStart) {
            return $rightStart <=> $leftStart;
        }

        $leftEnd = (string) ($left['end_date'] ?? '');
        $rightEnd = (string) ($right['end_date'] ?? '');

        if ($leftEnd !== $rightEnd) {
            return $leftEnd <=> $rightEnd;
        }

        return ((int) ($right['id'] ?? 0)) <=> ((int) ($left['id'] ?? 0));
    }
}
