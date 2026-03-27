<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Database\PricingRuleRepository;

final class PricingAdminActions
{
    private PricingRuleRepository $pricingRepository;
    private \MustHotelBooking\Database\RoomRepository $roomRepository;

    public function __construct()
    {
        $this->pricingRepository = new PricingRuleRepository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
    }

    /**
     * @param array<string, scalar> $args
     */
    private function redirectToPricingPage(array $args): void
    {
        $url = get_admin_pricing_page_url($args);

        if (!\wp_safe_redirect($url)) {
            \wp_redirect($url);
        }

        exit;
    }

    public function handleGetAction(PricingAdminQuery $query): void
    {
        $action = $query->getAction();

        if ($action === 'delete_rule') {
            $this->deleteRule($query);
            return;
        }

        if ($action === 'duplicate_rule') {
            $this->duplicateRule($query);
            return;
        }

        if ($action === 'toggle_rule_status') {
            $this->toggleRuleStatus($query);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function handleSaveRequest(PricingAdminQuery $query): array
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return $this->blankState();
        }

        $action = isset($_POST['must_pricing_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_pricing_action'])) : '';

        if ($action === 'save_base_price') {
            return $this->saveBasePrice($query);
        }

        if ($action === 'save_rule') {
            return $this->saveRule($query);
        }

        return $this->blankState();
    }

    /**
     * @return array<string, mixed>
     */
    private function saveBasePrice(PricingAdminQuery $query): array
    {
        $nonce = isset($_POST['must_pricing_base_nonce']) ? (string) \wp_unslash($_POST['must_pricing_base_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_pricing_save_base_price')) {
            return [
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'base_price_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'rule_errors' => [],
                'rule_form' => null,
            ];
        }

        $roomId = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
        $basePrice = isset($_POST['base_price']) ? \round(\max(0.0, (float) \wp_unslash($_POST['base_price'])), 2) : 0.0;
        $room = $this->roomRepository->getRoomById($roomId);

        if (!\is_array($room) || $roomId <= 0) {
            return [
                'errors' => [\__('Room listing not found.', 'must-hotel-booking')],
                'base_price_errors' => [\__('Room listing not found.', 'must-hotel-booking')],
                'rule_errors' => [],
                'rule_form' => null,
            ];
        }

        $room['base_price'] = $basePrice;
        $saved = $this->roomRepository->updateRoom($roomId, $room);

        if (!$saved) {
            return [
                'errors' => [\__('Unable to save base price.', 'must-hotel-booking')],
                'base_price_errors' => [\__('Unable to save base price.', 'must-hotel-booking')],
                'rule_errors' => [],
                'rule_form' => null,
            ];
        }

        $this->redirectToPricingPage($query->buildUrlArgs([
            'notice' => 'base_price_saved',
            'room_id' => $roomId,
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function saveRule(PricingAdminQuery $query): array
    {
        $nonce = isset($_POST['must_pricing_rule_nonce']) ? (string) \wp_unslash($_POST['must_pricing_rule_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_pricing_save_rule')) {
            return [
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'base_price_errors' => [],
                'rule_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'rule_form' => null,
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $values = $this->sanitizeRuleValues($rawPost);

        if (!empty($values['errors'])) {
            return [
                'errors' => (array) $values['errors'],
                'base_price_errors' => [],
                'rule_errors' => (array) $values['errors'],
                'rule_form' => $values,
            ];
        }

        $overlaps = $this->pricingRepository->getOverlappingRules($values, (int) ($values['id'] ?? 0));

        if (!empty($overlaps) && (int) ($values['priority'] ?? 10) <= 0) {
            $values['errors'][] = \__('Overlapping rules require a positive priority so resolution stays deterministic.', 'must-hotel-booking');
        }

        if (!empty($values['errors'])) {
            return [
                'errors' => (array) $values['errors'],
                'base_price_errors' => [],
                'rule_errors' => (array) $values['errors'],
                'rule_form' => $values,
            ];
        }

        $savedId = $this->pricingRepository->saveRule($values);

        if ($savedId <= 0) {
            return [
                'errors' => [\__('Unable to save pricing rule.', 'must-hotel-booking')],
                'base_price_errors' => [],
                'rule_errors' => [\__('Unable to save pricing rule.', 'must-hotel-booking')],
                'rule_form' => $values,
            ];
        }

        $this->redirectToPricingPage($query->buildUrlArgs([
            'notice' => (int) ($values['id'] ?? 0) > 0 ? 'rule_updated' : 'rule_created',
            'action' => 'edit_rule',
            'rule_id' => $savedId,
            'room_id' => (int) ($values['room_id'] ?? 0),
        ]));
    }

    private function deleteRule(PricingAdminQuery $query): void
    {
        $ruleId = $query->getRuleId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_pricing_delete_rule_' . $ruleId)) {
            $this->redirectToPricingPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $deleted = $this->pricingRepository->deleteRule($ruleId);
        $this->redirectToPricingPage($query->buildUrlArgs(['notice' => $deleted ? 'rule_deleted' : 'rule_delete_failed', 'action' => '', 'rule_id' => 0]));
    }

    private function duplicateRule(PricingAdminQuery $query): void
    {
        $ruleId = $query->getRuleId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_pricing_duplicate_rule_' . $ruleId)) {
            $this->redirectToPricingPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $duplicatedId = $this->pricingRepository->duplicateRule($ruleId);
        $this->redirectToPricingPage($query->buildUrlArgs([
            'notice' => $duplicatedId > 0 ? 'rule_duplicated' : 'rule_duplicate_failed',
            'action' => $duplicatedId > 0 ? 'edit_rule' : '',
            'rule_id' => $duplicatedId,
        ]));
    }

    private function toggleRuleStatus(PricingAdminQuery $query): void
    {
        $ruleId = $query->getRuleId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';
        $target = isset($_GET['target']) ? \sanitize_key((string) \wp_unslash($_GET['target'])) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_pricing_toggle_rule_' . $ruleId)) {
            $this->redirectToPricingPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $updated = $this->pricingRepository->toggleRuleStatus($ruleId, $target === 'active');
        $this->redirectToPricingPage($query->buildUrlArgs([
            'notice' => $updated ? ($target === 'active' ? 'rule_activated' : 'rule_deactivated') : 'rule_update_failed',
            'action' => '',
            'rule_id' => 0,
        ]));
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sanitizeRuleValues(array $source): array
    {
        $values = [
            'id' => isset($source['rule_id']) ? \absint(\wp_unslash($source['rule_id'])) : 0,
            'room_id' => isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0,
            'name' => isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '',
            'start_date' => isset($source['start_date']) ? \sanitize_text_field((string) \wp_unslash($source['start_date'])) : '',
            'end_date' => isset($source['end_date']) ? \sanitize_text_field((string) \wp_unslash($source['end_date'])) : '',
            'price_override' => isset($source['price_override']) ? \round(\max(0.0, (float) \wp_unslash($source['price_override'])), 2) : 0.0,
            'weekend_price' => isset($source['weekend_price']) ? \round(\max(0.0, (float) \wp_unslash($source['weekend_price'])), 2) : 0.0,
            'minimum_nights' => isset($source['minimum_nights']) ? \max(1, \absint(\wp_unslash($source['minimum_nights']))) : 1,
            'priority' => isset($source['priority']) ? (int) \wp_unslash($source['priority']) : 10,
            'is_active' => !empty($source['is_active']) ? 1 : 0,
            'errors' => [],
        ];

        if ($values['name'] === '') {
            $values['errors'][] = \__('Rule name is required.', 'must-hotel-booking');
        }

        if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['start_date']) || !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['end_date'])) {
            $values['errors'][] = \__('Start date and end date must use YYYY-MM-DD.', 'must-hotel-booking');
        } elseif ((string) $values['start_date'] > (string) $values['end_date']) {
            $values['errors'][] = \__('End date must be on or after start date.', 'must-hotel-booking');
        }

        if ((float) $values['price_override'] <= 0.0 && (float) $values['weekend_price'] <= 0.0) {
            $values['errors'][] = \__('Provide a nightly override, a weekend override, or both.', 'must-hotel-booking');
        }

        if ((int) $values['priority'] < 0) {
            $values['errors'][] = \__('Priority cannot be negative.', 'must-hotel-booking');
        }

        if ((int) $values['room_id'] > 0 && !$this->roomRepository->getRoomById((int) $values['room_id'])) {
            $values['errors'][] = \__('Selected room listing could not be found.', 'must-hotel-booking');
        }

        return $values;
    }

    /**
     * @return array<string, mixed>
     */
    private function blankState(): array
    {
        return [
            'errors' => [],
            'base_price_errors' => [],
            'rule_errors' => [],
            'rule_form' => null,
        ];
    }
}
