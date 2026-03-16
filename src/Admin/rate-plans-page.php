<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Database\CancellationPolicyRepository;
use MustHotelBooking\Database\InventoryRepository;
use MustHotelBooking\Database\RatePlanRepository;

/**
 * @param array<string, scalar> $args
 */
function get_admin_rate_plans_page_url(array $args = []): string
{
    $base_url = \admin_url('admin.php?page=must-hotel-booking-rate-plans');

    if (empty($args)) {
        return $base_url;
    }

    return \add_query_arg($args, $base_url);
}

function get_rate_plan_repository_instance(): RatePlanRepository
{
    static $repository = null;

    if (!$repository instanceof RatePlanRepository) {
        $repository = new RatePlanRepository();
    }

    return $repository;
}

function get_cancellation_policy_repository_instance(): CancellationPolicyRepository
{
    static $repository = null;

    if (!$repository instanceof CancellationPolicyRepository) {
        $repository = new CancellationPolicyRepository();
    }

    return $repository;
}

/**
 * @return array<string, mixed>
 */
function get_rate_plan_form_defaults(): array
{
    return [
        'rate_plan_id' => 0,
        'name' => '',
        'description' => '',
        'cancellation_policy_id' => 0,
        'is_active' => 1,
    ];
}

/**
 * @param array<string, mixed> $source
 * @return array<string, mixed>
 */
function sanitize_rate_plan_form_values(array $source): array
{
    $values = [
        'rate_plan_id' => isset($source['rate_plan_id']) ? \absint(\wp_unslash($source['rate_plan_id'])) : 0,
        'name' => isset($source['name']) ? \sanitize_text_field((string) \wp_unslash($source['name'])) : '',
        'description' => isset($source['description']) ? \sanitize_textarea_field((string) \wp_unslash($source['description'])) : '',
        'cancellation_policy_id' => isset($source['cancellation_policy_id']) ? \absint(\wp_unslash($source['cancellation_policy_id'])) : 0,
        'is_active' => !empty($source['is_active']) ? 1 : 0,
        'errors' => [],
    ];

    if ((string) $values['name'] === '') {
        $values['errors'][] = \__('Rate plan name is required.', 'must-hotel-booking');
    }

    return $values;
}

/**
 * @param array<string, mixed>|null $submitted_form
 * @return array<string, mixed>
 */
function get_rate_plan_form_data(?array $submitted_form = null): array
{
    $defaults = get_rate_plan_form_defaults();

    if (\is_array($submitted_form)) {
        return \array_merge($defaults, $submitted_form);
    }

    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';
    $rate_plan_id = isset($_GET['rate_plan_id']) ? \absint(\wp_unslash($_GET['rate_plan_id'])) : 0;

    if ($action !== 'edit' || $rate_plan_id <= 0) {
        return $defaults;
    }

    $rate_plan = get_rate_plan_repository_instance()->getRatePlanById($rate_plan_id);

    if (!\is_array($rate_plan)) {
        return $defaults;
    }

    return [
        'rate_plan_id' => (int) ($rate_plan['id'] ?? 0),
        'name' => (string) ($rate_plan['name'] ?? ''),
        'description' => (string) ($rate_plan['description'] ?? ''),
        'cancellation_policy_id' => (int) ($rate_plan['cancellation_policy_id'] ?? 0),
        'is_active' => !empty($rate_plan['is_active']) ? 1 : 0,
    ];
}

/**
 * @return array<int, array<string, mixed>>
 */
function get_rate_plan_assignable_rooms(): array
{
    static $repository = null;

    if (!$repository instanceof InventoryRepository) {
        $repository = new InventoryRepository();
    }

    $roomTypes = $repository->getRoomTypes();

    if (!empty($roomTypes)) {
        return $roomTypes;
    }

    if (\function_exists(__NAMESPACE__ . '\get_rooms_list_rows')) {
        return (array) get_rooms_list_rows();
    }

    return [];
}

/**
 * @return array<string, mixed>
 */
function sanitize_rate_plan_assignment_values(array $source): array
{
    $values = [
        'rate_plan_id' => isset($source['rate_plan_id']) ? \absint(\wp_unslash($source['rate_plan_id'])) : 0,
        'room_type_id' => isset($source['room_type_id']) ? \absint(\wp_unslash($source['room_type_id'])) : 0,
        'base_price' => isset($source['base_price']) ? \round(\max(0.0, (float) \wp_unslash($source['base_price'])), 2) : 0.0,
        'max_occupancy' => isset($source['max_occupancy']) ? \max(1, \absint(\wp_unslash($source['max_occupancy']))) : 1,
        'errors' => [],
    ];

    if ((int) $values['rate_plan_id'] <= 0) {
        $values['errors'][] = \__('Select a rate plan before adding assignments.', 'must-hotel-booking');
    }

    if ((int) $values['room_type_id'] <= 0) {
        $values['errors'][] = \__('Please select a room type.', 'must-hotel-booking');
    }

    return $values;
}

/**
 * @return array<string, mixed>
 */
function sanitize_rate_plan_price_values(array $source): array
{
    $values = [
        'rate_plan_id' => isset($source['rate_plan_id']) ? \absint(\wp_unslash($source['rate_plan_id'])) : 0,
        'date' => isset($source['date']) ? \sanitize_text_field((string) \wp_unslash($source['date'])) : '',
        'price' => isset($source['price']) ? \round(\max(0.0, (float) \wp_unslash($source['price'])), 2) : 0.0,
        'errors' => [],
    ];

    if ((int) $values['rate_plan_id'] <= 0) {
        $values['errors'][] = \__('Select a rate plan before saving custom prices.', 'must-hotel-booking');
    }

    if (!\preg_match('/^\d{4}-\d{2}-\d{2}$/', (string) $values['date'])) {
        $values['errors'][] = \__('Please provide a valid date in YYYY-MM-DD format.', 'must-hotel-booking');
    }

    return $values;
}

function maybe_handle_rate_plan_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_rate_plan') {
        return;
    }

    $rate_plan_id = isset($_GET['rate_plan_id']) ? \absint(\wp_unslash($_GET['rate_plan_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($rate_plan_id <= 0 || !\wp_verify_nonce($nonce, 'must_rate_plan_delete_' . $rate_plan_id)) {
        \wp_safe_redirect(get_admin_rate_plans_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    $deleted = get_rate_plan_repository_instance()->deleteRatePlan($rate_plan_id);
    \wp_safe_redirect(get_admin_rate_plans_page_url(['notice' => $deleted ? 'rate_plan_deleted' : 'rate_plan_delete_failed']));
    exit;
}

function maybe_handle_rate_plan_assignment_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_rate_plan_assignment') {
        return;
    }

    $assignment_id = isset($_GET['assignment_id']) ? \absint(\wp_unslash($_GET['assignment_id'])) : 0;
    $rate_plan_id = isset($_GET['rate_plan_id']) ? \absint(\wp_unslash($_GET['rate_plan_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($assignment_id <= 0 || $rate_plan_id <= 0 || !\wp_verify_nonce($nonce, 'must_rate_plan_assignment_delete_' . $assignment_id)) {
        \wp_safe_redirect(get_admin_rate_plans_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    $deleted = get_rate_plan_repository_instance()->deleteRoomTypeAssignment($assignment_id);
    \wp_safe_redirect(
        get_admin_rate_plans_page_url([
            'notice' => $deleted ? 'assignment_deleted' : 'assignment_delete_failed',
            'action' => 'edit',
            'rate_plan_id' => $rate_plan_id,
        ])
    );
    exit;
}

function maybe_handle_rate_plan_price_delete_request(): void
{
    $action = isset($_GET['action']) ? \sanitize_key((string) \wp_unslash($_GET['action'])) : '';

    if ($action !== 'delete_rate_plan_price') {
        return;
    }

    $price_id = isset($_GET['price_id']) ? \absint(\wp_unslash($_GET['price_id'])) : 0;
    $rate_plan_id = isset($_GET['rate_plan_id']) ? \absint(\wp_unslash($_GET['rate_plan_id'])) : 0;
    $nonce = isset($_GET['_wpnonce']) ? (string) \wp_unslash($_GET['_wpnonce']) : '';

    if ($price_id <= 0 || $rate_plan_id <= 0 || !\wp_verify_nonce($nonce, 'must_rate_plan_price_delete_' . $price_id)) {
        \wp_safe_redirect(get_admin_rate_plans_page_url(['notice' => 'invalid_nonce']));
        exit;
    }

    $deleted = get_rate_plan_repository_instance()->deleteRatePlanPrice($price_id);
    \wp_safe_redirect(
        get_admin_rate_plans_page_url([
            'notice' => $deleted ? 'price_deleted' : 'price_delete_failed',
            'action' => 'edit',
            'rate_plan_id' => $rate_plan_id,
        ])
    );
    exit;
}

/**
 * @return array<string, mixed>
 */
function maybe_handle_rate_plan_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $action = isset($_POST['must_rate_plan_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_rate_plan_action'])) : '';

    if ($action !== 'save_rate_plan') {
        return [
            'errors' => [],
            'form' => null,
        ];
    }

    $nonce = isset($_POST['must_rate_plan_nonce']) ? (string) \wp_unslash($_POST['must_rate_plan_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_rate_plan_save')) {
        return [
            'errors' => [\__('Security check failed. Please try again.', 'must-hotel-booking')],
            'form' => null,
        ];
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $form = sanitize_rate_plan_form_values($raw_post);
    $rate_plan_id = (int) $form['rate_plan_id'];

    if (!empty($form['errors'])) {
        return [
            'errors' => (array) $form['errors'],
            'form' => $form,
        ];
    }

    $repository = get_rate_plan_repository_instance();
    $saved_id = 0;

    if ($rate_plan_id > 0) {
        $updated = $repository->updateRatePlan(
            $rate_plan_id,
            [
                'name' => (string) $form['name'],
                'description' => (string) $form['description'],
                'cancellation_policy_id' => (int) $form['cancellation_policy_id'],
                'is_active' => !empty($form['is_active']),
            ]
        );

        if ($updated) {
            $saved_id = $rate_plan_id;
        }
    } else {
        $saved_id = $repository->createRatePlan(
            [
                'name' => (string) $form['name'],
                'description' => (string) $form['description'],
                'cancellation_policy_id' => (int) $form['cancellation_policy_id'],
                'is_active' => !empty($form['is_active']),
            ]
        );
    }

    if ($saved_id <= 0) {
        return [
            'errors' => [\__('Unable to save the rate plan.', 'must-hotel-booking')],
            'form' => $form,
        ];
    }

    \wp_safe_redirect(
        get_admin_rate_plans_page_url([
            'notice' => $rate_plan_id > 0 ? 'rate_plan_updated' : 'rate_plan_created',
            'action' => 'edit',
            'rate_plan_id' => $saved_id,
        ])
    );
    exit;
}

/**
 * @return array<int, string>
 */
function maybe_handle_rate_plan_assignment_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_rate_plan_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_rate_plan_action'])) : '';

    if ($action !== 'save_rate_plan_assignment') {
        return [];
    }

    $nonce = isset($_POST['must_rate_plan_assignment_nonce']) ? (string) \wp_unslash($_POST['must_rate_plan_assignment_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_rate_plan_assignment_save')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $form = sanitize_rate_plan_assignment_values($raw_post);

    if (!empty($form['errors'])) {
        return (array) $form['errors'];
    }

    $saved_id = get_rate_plan_repository_instance()->saveRoomTypeAssignment(
        (int) $form['rate_plan_id'],
        (int) $form['room_type_id'],
        (float) $form['base_price'],
        (int) $form['max_occupancy']
    );

    if ($saved_id <= 0) {
        return [\__('Unable to save the room type assignment.', 'must-hotel-booking')];
    }

    \wp_safe_redirect(
        get_admin_rate_plans_page_url([
            'notice' => 'assignment_saved',
            'action' => 'edit',
            'rate_plan_id' => (int) $form['rate_plan_id'],
        ])
    );
    exit;
}

/**
 * @return array<int, string>
 */
function maybe_handle_rate_plan_price_save_request(): array
{
    $request_method = isset($_SERVER['REQUEST_METHOD']) ? \strtoupper((string) $_SERVER['REQUEST_METHOD']) : 'GET';

    if ($request_method !== 'POST') {
        return [];
    }

    $action = isset($_POST['must_rate_plan_action']) ? \sanitize_key((string) \wp_unslash($_POST['must_rate_plan_action'])) : '';

    if ($action !== 'save_rate_plan_price') {
        return [];
    }

    $nonce = isset($_POST['must_rate_plan_price_nonce']) ? (string) \wp_unslash($_POST['must_rate_plan_price_nonce']) : '';

    if (!\wp_verify_nonce($nonce, 'must_rate_plan_price_save')) {
        return [\__('Security check failed. Please try again.', 'must-hotel-booking')];
    }

    /** @var array<string, mixed> $raw_post */
    $raw_post = \is_array($_POST) ? $_POST : [];
    $form = sanitize_rate_plan_price_values($raw_post);

    if (!empty($form['errors'])) {
        return (array) $form['errors'];
    }

    $saved_id = get_rate_plan_repository_instance()->saveRatePlanPrice(
        (int) $form['rate_plan_id'],
        (string) $form['date'],
        (float) $form['price']
    );

    if ($saved_id <= 0) {
        return [\__('Unable to save the custom rate plan price.', 'must-hotel-booking')];
    }

    \wp_safe_redirect(
        get_admin_rate_plans_page_url([
            'notice' => 'price_saved',
            'action' => 'edit',
            'rate_plan_id' => (int) $form['rate_plan_id'],
        ])
    );
    exit;
}

function render_rate_plan_admin_notice_from_query(): void
{
    $notice = isset($_GET['notice']) ? \sanitize_key((string) \wp_unslash($_GET['notice'])) : '';

    if ($notice === '') {
        return;
    }

    $messages = [
        'rate_plan_created' => ['success', \__('Rate plan created successfully.', 'must-hotel-booking')],
        'rate_plan_updated' => ['success', \__('Rate plan updated successfully.', 'must-hotel-booking')],
        'rate_plan_deleted' => ['success', \__('Rate plan deleted successfully.', 'must-hotel-booking')],
        'assignment_saved' => ['success', \__('Room type assignment saved successfully.', 'must-hotel-booking')],
        'assignment_deleted' => ['success', \__('Room type assignment removed successfully.', 'must-hotel-booking')],
        'price_saved' => ['success', \__('Custom rate plan price saved successfully.', 'must-hotel-booking')],
        'price_deleted' => ['success', \__('Custom rate plan price removed successfully.', 'must-hotel-booking')],
        'rate_plan_delete_failed' => ['error', \__('Unable to delete the rate plan.', 'must-hotel-booking')],
        'assignment_delete_failed' => ['error', \__('Unable to delete the room type assignment.', 'must-hotel-booking')],
        'price_delete_failed' => ['error', \__('Unable to delete the custom rate plan price.', 'must-hotel-booking')],
        'invalid_nonce' => ['error', \__('Security check failed. Please try again.', 'must-hotel-booking')],
    ];

    if (!isset($messages[$notice])) {
        return;
    }

    $type = (string) $messages[$notice][0];
    $message = (string) $messages[$notice][1];
    $class = $type === 'success' ? 'notice notice-success' : 'notice notice-error';

    echo '<div class="' . \esc_attr($class) . '"><p>' . \esc_html($message) . '</p></div>';
}

function render_admin_rate_plans_page(): void
{
    ensure_admin_capability();

    maybe_handle_rate_plan_delete_request();
    maybe_handle_rate_plan_assignment_delete_request();
    maybe_handle_rate_plan_price_delete_request();

    $save_state = maybe_handle_rate_plan_save_request();
    $assignment_errors = maybe_handle_rate_plan_assignment_save_request();
    $price_errors = maybe_handle_rate_plan_price_save_request();
    $form_errors = isset($save_state['errors']) && \is_array($save_state['errors']) ? $save_state['errors'] : [];
    $submitted_form = isset($save_state['form']) && \is_array($save_state['form']) ? $save_state['form'] : null;
    $errors = \array_values(\array_unique(\array_filter(\array_merge($form_errors, $assignment_errors, $price_errors))));
    $form = get_rate_plan_form_data($submitted_form);
    $rate_plan_id = (int) ($form['rate_plan_id'] ?? 0);
    $is_edit_mode = $rate_plan_id > 0;
    $repository = get_rate_plan_repository_instance();
    $cancellation_policies = get_cancellation_policy_repository_instance()->getPolicies();
    $rate_plans = $repository->getRatePlans(true);
    $assignments = $is_edit_mode ? $repository->getAssignmentsByRatePlanId($rate_plan_id) : [];
    $rate_plan_prices = $is_edit_mode ? $repository->getRatePlanPriceRows($rate_plan_id) : [];
    $rooms = get_rate_plan_assignable_rooms();

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Rate Plans', 'must-hotel-booking') . '</h1>';

    render_rate_plan_admin_notice_from_query();

    if (!empty($errors)) {
        echo '<div class="notice notice-error"><ul>';

        foreach ($errors as $error) {
            echo '<li>' . \esc_html((string) $error) . '</li>';
        }

        echo '</ul></div>';
    }

    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($is_edit_mode ? __('Edit Rate Plan', 'must-hotel-booking') : __('Create Rate Plan', 'must-hotel-booking')) . '</h2>';
    echo '<form method="post" action="' . \esc_url(get_admin_rate_plans_page_url()) . '">';
    \wp_nonce_field('must_rate_plan_save', 'must_rate_plan_nonce');
    echo '<input type="hidden" name="must_rate_plan_action" value="save_rate_plan" />';
    echo '<input type="hidden" name="rate_plan_id" value="' . \esc_attr((string) $rate_plan_id) . '" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-rate-plan-name">' . \esc_html__('Name', 'must-hotel-booking') . '</label></th>';
    echo '<td><input id="must-rate-plan-name" type="text" class="regular-text" name="name" value="' . \esc_attr((string) $form['name']) . '" required /></td></tr>';
    echo '<tr><th scope="row"><label for="must-rate-plan-description">' . \esc_html__('Description', 'must-hotel-booking') . '</label></th>';
    echo '<td><textarea id="must-rate-plan-description" class="large-text" name="description" rows="4">' . \esc_textarea((string) $form['description']) . '</textarea></td></tr>';
    echo '<tr><th scope="row"><label for="must-rate-plan-cancellation-policy">' . \esc_html__('Cancellation Policy', 'must-hotel-booking') . '</label></th>';
    echo '<td><select id="must-rate-plan-cancellation-policy" name="cancellation_policy_id">';
    echo '<option value="0">' . \esc_html__('No policy assigned', 'must-hotel-booking') . '</option>';

    foreach ($cancellation_policies as $policy) {
        if (!\is_array($policy)) {
            continue;
        }

        $policy_id = isset($policy['id']) ? (int) $policy['id'] : 0;

        if ($policy_id <= 0) {
            continue;
        }

        $policy_name = isset($policy['name']) ? (string) $policy['name'] : ('#' . $policy_id);
        echo '<option value="' . \esc_attr((string) $policy_id) . '"' . \selected((int) $form['cancellation_policy_id'], $policy_id, false) . '>' . \esc_html($policy_name) . '</option>';
    }

    echo '</select></td></tr>';
    echo '<tr><th scope="row">' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<td><label><input type="checkbox" name="is_active" value="1"' . \checked(!empty($form['is_active']), true, false) . ' /> ' . \esc_html__('Active', 'must-hotel-booking') . '</label></td></tr>';
    echo '</tbody></table>';
    \submit_button($is_edit_mode ? __('Update Rate Plan', 'must-hotel-booking') : __('Create Rate Plan', 'must-hotel-booking'));

    if ($is_edit_mode) {
        echo '<a class="button button-secondary" href="' . \esc_url(get_admin_rate_plans_page_url()) . '">' . \esc_html__('Add New Rate Plan', 'must-hotel-booking') . '</a>';
    }

    echo '</form>';
    echo '</div>';

    if ($is_edit_mode) {
        echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
        echo '<h2 style="margin-top:0;">' . \esc_html__('Assign To Room Types', 'must-hotel-booking') . '</h2>';
        echo '<form method="post" action="' . \esc_url(get_admin_rate_plans_page_url()) . '">';
        \wp_nonce_field('must_rate_plan_assignment_save', 'must_rate_plan_assignment_nonce');
        echo '<input type="hidden" name="must_rate_plan_action" value="save_rate_plan_assignment" />';
        echo '<input type="hidden" name="rate_plan_id" value="' . \esc_attr((string) $rate_plan_id) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="must-rate-plan-room-type">' . \esc_html__('Room Type', 'must-hotel-booking') . '</label></th>';
        echo '<td><select id="must-rate-plan-room-type" name="room_type_id" required>';
        echo '<option value="">' . \esc_html__('Select room type', 'must-hotel-booking') . '</option>';

        foreach ($rooms as $room) {
            if (!\is_array($room)) {
                continue;
            }

            $room_id = isset($room['id']) ? (int) $room['id'] : 0;

            if ($room_id <= 0) {
                continue;
            }

            $room_name = isset($room['name']) ? (string) $room['name'] : '';
            $room_capacity = isset($room['capacity']) ? (int) $room['capacity'] : 0;
            $room_label = $room_name !== '' ? $room_name : ('#' . $room_id);

            if ($room_capacity > 0) {
                $room_label .= ' (' . \sprintf(
                    /* translators: %d is room type capacity. */
                    \__('Capacity %d', 'must-hotel-booking'),
                    $room_capacity
                ) . ')';
            } elseif (isset($room['category']) && (string) $room['category'] !== '') {
                $room_label .= ' (' . (string) $room['category'] . ')';
            }

            echo '<option value="' . \esc_attr((string) $room_id) . '">' . \esc_html($room_label) . '</option>';
        }

        echo '</select></td></tr>';
        echo '<tr><th scope="row"><label for="must-rate-plan-base-price">' . \esc_html__('Base Price', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-rate-plan-base-price" type="number" min="0" step="0.01" name="base_price" value="0.00" required /></td></tr>';
        echo '<tr><th scope="row"><label for="must-rate-plan-max-occupancy">' . \esc_html__('Max Occupancy', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-rate-plan-max-occupancy" type="number" min="1" step="1" name="max_occupancy" value="2" required /></td></tr>';
        echo '</tbody></table>';
        \submit_button(__('Assign Rate Plan', 'must-hotel-booking'));
        echo '</form>';
        echo '</div>';

        echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
        echo '<h2 style="margin-top:0;">' . \esc_html__('Custom Prices By Date', 'must-hotel-booking') . '</h2>';
        echo '<form method="post" action="' . \esc_url(get_admin_rate_plans_page_url()) . '">';
        \wp_nonce_field('must_rate_plan_price_save', 'must_rate_plan_price_nonce');
        echo '<input type="hidden" name="must_rate_plan_action" value="save_rate_plan_price" />';
        echo '<input type="hidden" name="rate_plan_id" value="' . \esc_attr((string) $rate_plan_id) . '" />';
        echo '<table class="form-table" role="presentation"><tbody>';
        echo '<tr><th scope="row"><label for="must-rate-plan-price-date">' . \esc_html__('Date', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-rate-plan-price-date" type="date" name="date" required /></td></tr>';
        echo '<tr><th scope="row"><label for="must-rate-plan-price-value">' . \esc_html__('Custom Price', 'must-hotel-booking') . '</label></th>';
        echo '<td><input id="must-rate-plan-price-value" type="number" min="0" step="0.01" name="price" value="0.00" required /></td></tr>';
        echo '</tbody></table>';
        \submit_button(__('Save Custom Price', 'must-hotel-booking'));
        echo '</form>';
        echo '</div>';
    }

    echo '<h2>' . \esc_html__('Existing Rate Plans', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>' . \esc_html__('Name', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Status', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Assignments', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rate_plans)) {
        echo '<tr><td colspan="4">' . \esc_html__('No rate plans found.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rate_plans as $rate_plan) {
            if (!\is_array($rate_plan)) {
                continue;
            }

            $current_rate_plan_id = isset($rate_plan['id']) ? (int) $rate_plan['id'] : 0;
            $assignment_count = \count($repository->getAssignmentsByRatePlanId($current_rate_plan_id));
            $edit_url = get_admin_rate_plans_page_url(['action' => 'edit', 'rate_plan_id' => $current_rate_plan_id]);
            $delete_url = \wp_nonce_url(
                get_admin_rate_plans_page_url(['action' => 'delete_rate_plan', 'rate_plan_id' => $current_rate_plan_id]),
                'must_rate_plan_delete_' . $current_rate_plan_id
            );

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($rate_plan['name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html(!empty($rate_plan['is_active']) ? __('Active', 'must-hotel-booking') : __('Inactive', 'must-hotel-booking')) . '</td>';
            echo '<td>' . \esc_html((string) $assignment_count) . '</td>';
            echo '<td>';
            echo '<a class="button button-small" href="' . \esc_url($edit_url) . '">' . \esc_html__('Edit', 'must-hotel-booking') . '</a> ';
            echo '<a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Delete this rate plan?', 'must-hotel-booking')) . '\');">' . \esc_html__('Delete', 'must-hotel-booking') . '</a>';
            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';

    if ($is_edit_mode) {
        echo '<h2 style="margin-top:24px;">' . \esc_html__('Assigned Room Types', 'must-hotel-booking') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . \esc_html__('Room Type', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Base Price', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Max Occupancy', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($assignments)) {
            echo '<tr><td colspan="4">' . \esc_html__('No room types assigned yet.', 'must-hotel-booking') . '</td></tr>';
        } else {
            foreach ($assignments as $assignment) {
                if (!\is_array($assignment)) {
                    continue;
                }

                $assignment_id = isset($assignment['id']) ? (int) $assignment['id'] : 0;
                $delete_url = \wp_nonce_url(
                    get_admin_rate_plans_page_url([
                        'action' => 'delete_rate_plan_assignment',
                        'assignment_id' => $assignment_id,
                        'rate_plan_id' => $rate_plan_id,
                    ]),
                    'must_rate_plan_assignment_delete_' . $assignment_id
                );

                echo '<tr>';
                echo '<td>' . \esc_html((string) ($assignment['room_name'] ?? ('#' . (int) ($assignment['room_type_id'] ?? 0)))) . '</td>';
                echo '<td>' . \esc_html(\number_format_i18n((float) ($assignment['base_price'] ?? 0.0), 2)) . '</td>';
                echo '<td>' . \esc_html((string) ((int) ($assignment['max_occupancy'] ?? 1))) . '</td>';
                echo '<td><a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Remove this assignment?', 'must-hotel-booking')) . '\');">' . \esc_html__('Remove', 'must-hotel-booking') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';

        echo '<h2 style="margin-top:24px;">' . \esc_html__('Custom Price Calendar', 'must-hotel-booking') . '</h2>';
        echo '<table class="widefat striped">';
        echo '<thead><tr>';
        echo '<th>' . \esc_html__('Date', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Price', 'must-hotel-booking') . '</th>';
        echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
        echo '</tr></thead><tbody>';

        if (empty($rate_plan_prices)) {
            echo '<tr><td colspan="3">' . \esc_html__('No custom date prices saved yet.', 'must-hotel-booking') . '</td></tr>';
        } else {
            foreach ($rate_plan_prices as $price_row) {
                if (!\is_array($price_row)) {
                    continue;
                }

                $price_id = isset($price_row['id']) ? (int) $price_row['id'] : 0;
                $delete_url = \wp_nonce_url(
                    get_admin_rate_plans_page_url([
                        'action' => 'delete_rate_plan_price',
                        'price_id' => $price_id,
                        'rate_plan_id' => $rate_plan_id,
                    ]),
                    'must_rate_plan_price_delete_' . $price_id
                );

                echo '<tr>';
                echo '<td>' . \esc_html((string) ($price_row['date'] ?? '')) . '</td>';
                echo '<td>' . \esc_html(\number_format_i18n((float) ($price_row['price'] ?? 0.0), 2)) . '</td>';
                echo '<td><a class="button button-small button-link-delete" href="' . \esc_url($delete_url) . '" onclick="return confirm(\'' . \esc_js(__('Remove this custom price?', 'must-hotel-booking')) . '\');">' . \esc_html__('Remove', 'must-hotel-booking') . '</a></td>';
                echo '</tr>';
            }
        }

        echo '</tbody></table>';
    }

    echo '</div>';
}
