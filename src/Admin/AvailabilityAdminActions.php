<?php

namespace MustHotelBooking\Admin;

final class AvailabilityAdminActions
{
    private \MustHotelBooking\Database\AvailabilityRepository $availabilityRepository;
    private \MustHotelBooking\Database\ReservationRepository $reservationRepository;
    private \MustHotelBooking\Database\RoomRepository $roomRepository;

    public function __construct()
    {
        $this->availabilityRepository = \MustHotelBooking\Engine\get_availability_repository();
        $this->reservationRepository = \MustHotelBooking\Engine\get_reservation_repository();
        $this->roomRepository = \MustHotelBooking\Engine\get_room_repository();
    }

    /**
     * @param array<string, scalar> $args
     */
    private function redirectToAvailabilityPage(array $args): void
    {
        $url = get_admin_availability_rules_page_url($args);

        if (!\wp_safe_redirect($url)) {
            \wp_redirect($url);
        }

        exit;
    }

    public function handleGetAction(AvailabilityAdminQuery $query): void
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
            return;
        }

        if ($action === 'delete_block') {
            $this->deleteBlock($query);
            return;
        }

        if ($action === 'duplicate_block') {
            $this->duplicateBlock($query);
        }
    }

    /**
     * @return array<string, mixed>
     */
    public function handleSaveRequest(AvailabilityAdminQuery $query): array
    {
        $requestMethod = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

        if ($requestMethod !== 'POST') {
            return $this->blankState();
        }

        $action = isset($_POST['must_availability_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_availability_action'])) : '';

        if ($action === 'save_rule') {
            return $this->saveRule($query);
        }

        if ($action === 'save_block') {
            return $this->saveBlock($query);
        }

        return $this->blankState();
    }

    /**
     * @return array<string, mixed>
     */
    private function saveRule(AvailabilityAdminQuery $query): array
    {
        $nonce = isset($_POST['must_availability_rule_nonce']) ? (string) \wp_unslash($_POST['must_availability_rule_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_availability_save_rule')) {
            return [
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'rule_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'block_errors' => [],
                'rule_form' => null,
                'block_form' => null,
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $values = $this->sanitizeRuleValues($rawPost);
        $conflicts = [];

        if ((int) ($values['room_id'] ?? 0) > 0 && (string) ($values['availability_date'] ?? '') !== '' && (string) ($values['end_date'] ?? '') !== '') {
            $conflicts = $this->availabilityRepository->getOverlappingReservationRows(
                (int) $values['room_id'],
                (string) $values['availability_date'],
                (string) $values['end_date'],
                0
            );
        }

        if (!empty($values['errors'])) {
            return [
                'errors' => (array) $values['errors'],
                'rule_errors' => (array) $values['errors'],
                'block_errors' => [],
                'rule_form' => $values,
                'block_form' => null,
            ];
        }

        $savedId = $this->availabilityRepository->saveRule($values);

        if ($savedId <= 0) {
            return [
                'errors' => [\__('Unable to save availability rule.', 'must-hotel-booking')],
                'rule_errors' => [\__('Unable to save availability rule.', 'must-hotel-booking')],
                'block_errors' => [],
                'rule_form' => $values,
                'block_form' => null,
            ];
        }

        $this->redirectToAvailabilityPage($query->buildUrlArgs([
            'notice' => !empty($conflicts) ? 'rule_saved_with_conflict' : ((int) ($values['id'] ?? 0) > 0 ? 'rule_updated' : 'rule_created'),
            'action' => 'edit_rule',
            'rule_id' => $savedId,
            'room_id' => (int) ($values['room_id'] ?? 0),
        ]));
    }

    /**
     * @return array<string, mixed>
     */
    private function saveBlock(AvailabilityAdminQuery $query): array
    {
        $nonce = isset($_POST['must_availability_block_nonce']) ? (string) \wp_unslash($_POST['must_availability_block_nonce']) : '';

        if (!\wp_verify_nonce($nonce, 'must_availability_save_block')) {
            return [
                'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'rule_errors' => [],
                'block_errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
                'rule_form' => null,
                'block_form' => null,
            ];
        }

        /** @var array<string, mixed> $rawPost */
        $rawPost = \is_array($_POST) ? $_POST : [];
        $values = $this->sanitizeBlockValues($rawPost);

        if (!empty($values['errors'])) {
            return [
                'errors' => (array) $values['errors'],
                'rule_errors' => [],
                'block_errors' => (array) $values['errors'],
                'rule_form' => null,
                'block_form' => $values,
            ];
        }

        $blockId = (int) ($values['id'] ?? 0);
        $conflicts = $this->reservationRepository->getBlockedReservationConflicts(
            (int) $values['room_id'],
            (string) $values['checkin'],
            (string) $values['checkout'],
            $blockId
        );

        if ($blockId > 0) {
            $current = $this->reservationRepository->getReservation($blockId);

            if (!\is_array($current) || (string) ($current['status'] ?? '') !== 'blocked') {
                return [
                    'errors' => [\__('The selected manual block could not be found.', 'must-hotel-booking')],
                    'rule_errors' => [],
                    'block_errors' => [\__('The selected manual block could not be found.', 'must-hotel-booking')],
                    'rule_form' => null,
                    'block_form' => $values,
                ];
            }

            $saved = $this->reservationRepository->updateReservation($blockId, [
                'room_id' => (int) $values['room_id'],
                'checkin' => (string) $values['checkin'],
                'checkout' => (string) $values['checkout'],
                'notes' => (string) ($values['notes'] ?? ''),
            ]);
            $savedId = $saved ? $blockId : 0;
        } else {
            $savedId = $this->reservationRepository->createBlockedReservation(
                (int) $values['room_id'],
                (string) $values['checkin'],
                (string) $values['checkout'],
                \current_time('mysql')
            );

            if ($savedId > 0 && (string) ($values['notes'] ?? '') !== '') {
                $this->reservationRepository->updateReservation($savedId, ['notes' => (string) $values['notes']]);
            }
        }

        if ($savedId <= 0) {
            return [
                'errors' => [\__('Unable to save the manual block.', 'must-hotel-booking')],
                'rule_errors' => [],
                'block_errors' => [\__('Unable to save the manual block.', 'must-hotel-booking')],
                'rule_form' => null,
                'block_form' => $values,
            ];
        }

        $this->redirectToAvailabilityPage($query->buildUrlArgs([
            'notice' => !empty($conflicts) ? 'block_saved_with_conflict' : ($blockId > 0 ? 'block_updated' : 'block_created'),
            'action' => 'edit_block',
            'block_id' => $savedId,
            'room_id' => (int) ($values['room_id'] ?? 0),
        ]));
    }

    private function deleteRule(AvailabilityAdminQuery $query): void
    {
        $ruleId = $query->getRuleId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_delete_rule_' . $ruleId)) {
            $this->redirectToAvailabilityPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $deleted = $this->availabilityRepository->deleteAvailabilityRule($ruleId);
        $this->redirectToAvailabilityPage($query->buildUrlArgs([
            'notice' => $deleted ? 'rule_deleted' : 'rule_delete_failed',
            'action' => '',
            'rule_id' => 0,
        ]));
    }

    private function duplicateRule(AvailabilityAdminQuery $query): void
    {
        $ruleId = $query->getRuleId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_duplicate_rule_' . $ruleId)) {
            $this->redirectToAvailabilityPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $duplicatedId = $this->availabilityRepository->duplicateRule($ruleId);
        $this->redirectToAvailabilityPage($query->buildUrlArgs([
            'notice' => $duplicatedId > 0 ? 'rule_duplicated' : 'rule_duplicate_failed',
            'action' => $duplicatedId > 0 ? 'edit_rule' : '',
            'rule_id' => $duplicatedId,
        ]));
    }

    private function toggleRuleStatus(AvailabilityAdminQuery $query): void
    {
        $ruleId = $query->getRuleId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';
        $target = isset($_GET['target']) ? \sanitize_key((string) \wp_unslash($_GET['target'])) : '';

        if ($ruleId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_toggle_rule_' . $ruleId)) {
            $this->redirectToAvailabilityPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $updated = $this->availabilityRepository->toggleRuleStatus($ruleId, $target === 'active');
        $this->redirectToAvailabilityPage($query->buildUrlArgs([
            'notice' => $updated ? ($target === 'active' ? 'rule_activated' : 'rule_deactivated') : 'rule_update_failed',
            'action' => '',
            'rule_id' => 0,
        ]));
    }

    private function deleteBlock(AvailabilityAdminQuery $query): void
    {
        $blockId = $query->getBlockId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($blockId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_delete_block_' . $blockId)) {
            $this->redirectToAvailabilityPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $deleted = $this->reservationRepository->deleteReservation($blockId, 'blocked');
        $this->redirectToAvailabilityPage($query->buildUrlArgs([
            'notice' => $deleted ? 'block_deleted' : 'block_delete_failed',
            'action' => '',
            'block_id' => 0,
        ]));
    }

    private function duplicateBlock(AvailabilityAdminQuery $query): void
    {
        $blockId = $query->getBlockId();
        $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

        if ($blockId <= 0 || !\wp_verify_nonce($nonce, 'must_availability_duplicate_block_' . $blockId)) {
            $this->redirectToAvailabilityPage($query->buildUrlArgs(['notice' => 'invalid_nonce']));
        }

        $block = $this->reservationRepository->getReservation($blockId);

        if (!\is_array($block) || (string) ($block['status'] ?? '') !== 'blocked') {
            $this->redirectToAvailabilityPage($query->buildUrlArgs(['notice' => 'block_duplicate_failed']));
        }

        $duplicatedId = $this->reservationRepository->createBlockedReservation(
            (int) ($block['room_id'] ?? 0),
            (string) ($block['checkin'] ?? ''),
            (string) ($block['checkout'] ?? ''),
            \current_time('mysql')
        );

        if ($duplicatedId > 0 && (string) ($block['notes'] ?? '') !== '') {
            $this->reservationRepository->updateReservation($duplicatedId, ['notes' => (string) $block['notes']]);
        }

        $this->redirectToAvailabilityPage($query->buildUrlArgs([
            'notice' => $duplicatedId > 0 ? 'block_duplicated' : 'block_duplicate_failed',
            'action' => $duplicatedId > 0 ? 'edit_block' : '',
            'block_id' => $duplicatedId,
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
            'availability_date' => isset($source['availability_date']) ? \sanitize_text_field((string) \wp_unslash($source['availability_date'])) : '',
            'end_date' => isset($source['end_date']) ? \sanitize_text_field((string) \wp_unslash($source['end_date'])) : '',
            'rule_type' => isset($source['rule_type']) ? \sanitize_key((string) \wp_unslash($source['rule_type'])) : '',
            'rule_value' => isset($source['rule_value']) ? \absint(\wp_unslash($source['rule_value'])) : 0,
            'is_active' => !empty($source['is_active']) ? 1 : 0,
            'is_available' => 0,
            'reason' => isset($source['reason']) ? \sanitize_text_field((string) \wp_unslash($source['reason'])) : '',
            'errors' => [],
        ];

        if ($values['name'] === '') {
            $values['errors'][] = \__('Rule name is required.', 'must-hotel-booking');
        }

        if (!\in_array($values['rule_type'], ['maintenance_block', 'minimum_stay', 'maximum_stay', 'closed_arrival', 'closed_departure'], true)) {
            $values['errors'][] = \__('Select a valid availability rule type.', 'must-hotel-booking');
        }

        if (
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['availability_date']) ||
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['end_date'])
        ) {
            $values['errors'][] = \__('Start date and end date must use YYYY-MM-DD.', 'must-hotel-booking');
        } elseif ((string) $values['availability_date'] > (string) $values['end_date']) {
            $values['errors'][] = \__('End date must be on or after start date.', 'must-hotel-booking');
        }

        if ((int) $values['room_id'] > 0 && !$this->roomRepository->getRoomById((int) $values['room_id'])) {
            $values['errors'][] = \__('Selected room listing could not be found.', 'must-hotel-booking');
        }

        if (\in_array($values['rule_type'], ['minimum_stay', 'maximum_stay'], true) && (int) $values['rule_value'] < 1) {
            $values['errors'][] = \__('Stay rules require a value of at least 1 night.', 'must-hotel-booking');
        }

        if (!\in_array($values['rule_type'], ['minimum_stay', 'maximum_stay'], true)) {
            $values['rule_value'] = 0;
        }

        if ($values['reason'] === '') {
            $values['reason'] = $values['name'];
        }

        return $values;
    }

    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    private function sanitizeBlockValues(array $source): array
    {
        $values = [
            'id' => isset($source['block_id']) ? \absint(\wp_unslash($source['block_id'])) : 0,
            'room_id' => isset($source['room_id']) ? \absint(\wp_unslash($source['room_id'])) : 0,
            'checkin' => isset($source['checkin']) ? \sanitize_text_field((string) \wp_unslash($source['checkin'])) : '',
            'checkout' => isset($source['checkout']) ? \sanitize_text_field((string) \wp_unslash($source['checkout'])) : '',
            'notes' => isset($source['notes']) ? \sanitize_textarea_field((string) \wp_unslash($source['notes'])) : '',
            'errors' => [],
        ];

        if ((int) $values['room_id'] <= 0 || !$this->roomRepository->getRoomById((int) $values['room_id'])) {
            $values['errors'][] = \__('Select a valid room listing for the manual block.', 'must-hotel-booking');
        }

        if (
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['checkin']) ||
            !\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['checkout'])
        ) {
            $values['errors'][] = \__('Start date and end date must use YYYY-MM-DD.', 'must-hotel-booking');
        } elseif ((string) $values['checkin'] >= (string) $values['checkout']) {
            $values['errors'][] = \__('The manual block end date must be after the start date.', 'must-hotel-booking');
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
            'rule_errors' => [],
            'block_errors' => [],
            'rule_form' => null,
            'block_form' => null,
        ];
    }
}
