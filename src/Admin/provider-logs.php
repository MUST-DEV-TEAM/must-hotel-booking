<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Engine\AvailabilityEngine;
use MustHotelBooking\Provider\ProviderManager;
use MustHotelBooking\Provider\Storage\ProviderRequestLogRepository;

function render_admin_provider_logs_page(): void
{
    ensure_admin_capability('must-hotel-booking-provider-logs');

    $diagnostic = maybe_run_provider_log_diagnostic();
    $selectedOperation = isset($_GET['operation']) ? \sanitize_text_field((string) \wp_unslash($_GET['operation'])) : '';
    $limit = isset($_GET['limit']) ? \absint(\wp_unslash($_GET['limit'])) : 30;

    if ($limit < 10) {
        $limit = 10;
    }

    if ($limit > 100) {
        $limit = 100;
    }

    echo '<div class="wrap must-provider-logs-page">';
    echo '<h1>' . \esc_html__('Provider Logs / Clock Debug', 'must-hotel-booking') . '</h1>';
    echo '<p class="description">' . \esc_html__('Use this page to inspect recent Clock API requests and run a direct booking diagnostic without opening the database.', 'must-hotel-booking') . '</p>';

    render_provider_log_diagnostic_form($diagnostic);
    render_provider_logs_filters($selectedOperation, $limit);
    render_provider_logs_table($selectedOperation, $limit);

    echo '</div>';
}

function render_provider_log_diagnostic_form(?array $diagnostic): void
{
    $roomId = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 42;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '2026-05-31';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '2026-06-05';
    $guests = isset($_POST['guests']) ? \max(1, \absint(\wp_unslash($_POST['guests']))) : 1;

    echo '<div class="card" style="max-width: 1100px; padding: 18px; margin-top: 16px;">';
    echo '<h2>' . \esc_html__('Run booking diagnostic', 'must-hotel-booking') . '</h2>';
    echo '<p class="description">' . \esc_html__('This runs the same provider checks used by the public booking flow and then shows whether availability, quote, and rate plans passed.', 'must-hotel-booking') . '</p>';

    echo '<form method="post" action="">';
    \wp_nonce_field('must_provider_log_diagnostic', 'must_provider_log_diagnostic_nonce');

    echo '<table class="form-table" role="presentation"><tbody>';

    echo '<tr><th scope="row"><label for="must-provider-room-id">' . \esc_html__('Room ID', 'must-hotel-booking') . '</label></th><td>';
    echo '<input id="must-provider-room-id" class="regular-text" type="number" min="1" name="room_id" value="' . \esc_attr((string) $roomId) . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="must-provider-checkin">' . \esc_html__('Check-in', 'must-hotel-booking') . '</label></th><td>';
    echo '<input id="must-provider-checkin" class="regular-text" type="date" name="checkin" value="' . \esc_attr($checkin) . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="must-provider-checkout">' . \esc_html__('Check-out', 'must-hotel-booking') . '</label></th><td>';
    echo '<input id="must-provider-checkout" class="regular-text" type="date" name="checkout" value="' . \esc_attr($checkout) . '" />';
    echo '</td></tr>';

    echo '<tr><th scope="row"><label for="must-provider-guests">' . \esc_html__('Guests', 'must-hotel-booking') . '</label></th><td>';
    echo '<input id="must-provider-guests" class="regular-text" type="number" min="1" name="guests" value="' . \esc_attr((string) $guests) . '" />';
    echo '</td></tr>';

    echo '</tbody></table>';

    echo '<p><button type="submit" name="must_run_provider_diagnostic" value="1" class="button button-primary">' . \esc_html__('Run Diagnostic', 'must-hotel-booking') . '</button></p>';
    echo '</form>';

    if (\is_array($diagnostic)) {
        render_provider_log_diagnostic_result($diagnostic);
    }

    echo '</div>';
}

function maybe_run_provider_log_diagnostic(): ?array
{
    if (empty($_POST['must_run_provider_diagnostic'])) {
        return null;
    }

    $nonce = isset($_POST['must_provider_log_diagnostic_nonce'])
        ? (string) \wp_unslash($_POST['must_provider_log_diagnostic_nonce'])
        : '';

    if (!\wp_verify_nonce($nonce, 'must_provider_log_diagnostic')) {
        return [
            'status' => 'error',
            'message' => \__('Security check failed.', 'must-hotel-booking'),
        ];
    }

    $roomId = isset($_POST['room_id']) ? \absint(\wp_unslash($_POST['room_id'])) : 0;
    $checkin = isset($_POST['checkin']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkin'])) : '';
    $checkout = isset($_POST['checkout']) ? \sanitize_text_field((string) \wp_unslash($_POST['checkout'])) : '';
    $guests = isset($_POST['guests']) ? \max(1, \absint(\wp_unslash($_POST['guests']))) : 1;

    if ($roomId <= 0 || !AvailabilityEngine::isValidBookingDate($checkin) || !AvailabilityEngine::isValidBookingDate($checkout) || $checkin >= $checkout) {
        return [
            'status' => 'error',
            'message' => \__('Enter a valid room ID, check-in, and check-out date.', 'must-hotel-booking'),
        ];
    }

    try {
        $provider = ProviderManager::active();
        $availability = $provider->availability();
        $quote = $provider->quote();

        $availableRoom = $availability->getAvailableRoomById($roomId, $checkin, $checkout, $guests);
        $ratePlans = $quote->getRoomRatePlansWithPricing($roomId, $checkin, $checkout, $guests);
        $quoteResult = $quote->calculateTotal($roomId, $checkin, $checkout, $guests);

        return [
            'status' => 'ok',
            'provider_key' => $provider->getKey(),
            'room_id' => $roomId,
            'checkin' => $checkin,
            'checkout' => $checkout,
            'guests' => $guests,
            'availability_passed' => \is_array($availableRoom),
            'available_room' => \is_array($availableRoom) ? $availableRoom : null,
            'rate_plan_count' => \count(\is_array($ratePlans) ? $ratePlans : []),
            'rate_plans' => \is_array($ratePlans) ? $ratePlans : [],
            'quote_success' => !empty($quoteResult['success']),
            'quote_result' => \is_array($quoteResult) ? $quoteResult : [],
        ];
    } catch (\Throwable $throwable) {
        return [
            'status' => 'error',
            'message' => $throwable->getMessage(),
        ];
    }
}

function render_provider_log_diagnostic_result(array $diagnostic): void
{
    echo '<hr />';
    echo '<h3>' . \esc_html__('Diagnostic result', 'must-hotel-booking') . '</h3>';

    if (($diagnostic['status'] ?? '') === 'error') {
        echo '<div class="notice notice-error inline"><p>' . \esc_html((string) ($diagnostic['message'] ?? 'Error')) . '</p></div>';
        return;
    }

    $availabilityPassed = !empty($diagnostic['availability_passed']);
    $ratePlanCount = isset($diagnostic['rate_plan_count']) ? (int) $diagnostic['rate_plan_count'] : 0;
    $quoteSuccess = !empty($diagnostic['quote_success']);

    echo '<table class="widefat striped" style="max-width: 900px;">';
    echo '<tbody>';
    echo '<tr><th>' . \esc_html__('Provider', 'must-hotel-booking') . '</th><td>' . \esc_html((string) ($diagnostic['provider_key'] ?? '')) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Availability check', 'must-hotel-booking') . '</th><td>' . \esc_html($availabilityPassed ? 'PASSED' : 'FAILED') . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Rate plans with pricing', 'must-hotel-booking') . '</th><td>' . \esc_html((string) $ratePlanCount) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Quote calculation', 'must-hotel-booking') . '</th><td>' . \esc_html($quoteSuccess ? 'PASSED' : 'FAILED') . '</td></tr>';
    echo '</tbody>';
    echo '</table>';

    if ($availabilityPassed && $ratePlanCount === 0) {
        echo '<div class="notice notice-warning inline"><p>';
        echo \esc_html__('Room availability passed, but no bookable rate plan/pricing was returned. This usually means Clock WBE/rate publication/mapping/pricing is wrong, or the plugin parser does not recognize the Clock products response.', 'must-hotel-booking');
        echo '</p></div>';
    }

    echo '<details style="margin-top: 12px;"><summary>' . \esc_html__('Full diagnostic payload', 'must-hotel-booking') . '</summary>';
    echo '<textarea readonly style="width:100%; min-height:320px; font-family:monospace;">' . \esc_textarea(provider_logs_pretty_json($diagnostic)) . '</textarea>';
    echo '</details>';
}

function render_provider_logs_filters(string $selectedOperation, int $limit): void
{
    $operations = [
        '' => 'All Clock outbound operations',
        'clock.rates_availability.disabled_dates' => 'clock.rates_availability.disabled_dates',
        'clock.rates_availability.check' => 'clock.rates_availability.check',
        'clock.rates_availability.search' => 'clock.rates_availability.search',
        'clock.products.rate_plans' => 'clock.products.rate_plans',
        'clock.products.quote' => 'clock.products.quote',
        'clock.products.checkout' => 'clock.products.checkout',
    ];

    echo '<div class="card" style="max-width: 1100px; padding: 18px; margin-top: 16px;">';
    echo '<h2>' . \esc_html__('Recent Clock request logs', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-provider-logs" />';

    echo '<label for="must-provider-operation"><strong>' . \esc_html__('Operation', 'must-hotel-booking') . '</strong></label> ';
    echo '<select id="must-provider-operation" name="operation">';

    foreach ($operations as $value => $label) {
        echo '<option value="' . \esc_attr($value) . '"' . \selected($selectedOperation, $value, false) . '>' . \esc_html($label) . '</option>';
    }

    echo '</select> ';

    echo '<label for="must-provider-limit"><strong>' . \esc_html__('Limit', 'must-hotel-booking') . '</strong></label> ';
    echo '<input id="must-provider-limit" type="number" min="10" max="100" name="limit" value="' . \esc_attr((string) $limit) . '" /> ';

    echo '<button type="submit" class="button">' . \esc_html__('Filter', 'must-hotel-booking') . '</button>';
    echo '</form>';
    echo '</div>';
}

function render_provider_logs_table(string $selectedOperation, int $limit): void
{
    global $wpdb;

    $logs = new ProviderRequestLogRepository();

    if (!$logs->providerRequestLogsTableExists()) {
        echo '<div class="notice notice-warning"><p>' . \esc_html__('Provider request logs table does not exist. Reactivate the plugin to create missing tables.', 'must-hotel-booking') . '</p></div>';
        return;
    }

    $table = $wpdb->prefix . 'mhb_provider_request_logs';
    $where = 'provider = %s AND direction = %s';
    $params = ['clock', 'outbound'];

    if ($selectedOperation !== '') {
        $where .= ' AND operation = %s';
        $params[] = $selectedOperation;
    }

    $params[] = $limit;

    $rows = $wpdb->get_results(
        $wpdb->prepare(
            'SELECT id, created_at, operation, http_status, success, error_code, error_message, duration_ms, request_summary, response_summary
             FROM ' . $table . '
             WHERE ' . $where . '
             ORDER BY created_at DESC, id DESC
             LIMIT %d',
            ...$params
        ),
        ARRAY_A
    );

    echo '<div class="card" style="max-width: 1400px; padding: 18px; margin-top: 16px;">';

    if (empty($rows)) {
        echo '<p>' . \esc_html__('No Clock request logs found for this filter.', 'must-hotel-booking') . '</p>';
        echo '</div>';
        return;
    }

    echo '<table class="widefat striped">';
    echo '<thead><tr>';
    echo '<th>ID</th>';
    echo '<th>Created</th>';
    echo '<th>Operation</th>';
    echo '<th>HTTP</th>';
    echo '<th>Success</th>';
    echo '<th>Duration</th>';
    echo '<th>Error</th>';
    echo '<th>Details</th>';
    echo '</tr></thead>';
    echo '<tbody>';

    foreach ($rows as $row) {
        $id = (int) ($row['id'] ?? 0);
        $success = !empty($row['success']);

        echo '<tr>';
        echo '<td>' . \esc_html((string) $id) . '</td>';
        echo '<td>' . \esc_html((string) ($row['created_at'] ?? '')) . '</td>';
        echo '<td><code>' . \esc_html((string) ($row['operation'] ?? '')) . '</code></td>';
        echo '<td>' . \esc_html((string) ((int) ($row['http_status'] ?? 0))) . '</td>';
        echo '<td>' . \esc_html($success ? 'yes' : 'no') . '</td>';
        echo '<td>' . \esc_html((string) ((int) ($row['duration_ms'] ?? 0))) . 'ms</td>';
        echo '<td>' . \esc_html((string) ($row['error_message'] ?? '')) . '</td>';
        echo '<td>';
        echo '<details>';
        echo '<summary>' . \esc_html__('Open', 'must-hotel-booking') . '</summary>';

        echo '<p><strong>' . \esc_html__('Request', 'must-hotel-booking') . '</strong></p>';
        echo '<textarea readonly style="width:100%; min-height:180px; font-family:monospace;">' . \esc_textarea(provider_logs_pretty_json((string) ($row['request_summary'] ?? ''))) . '</textarea>';

        echo '<p><strong>' . \esc_html__('Response', 'must-hotel-booking') . '</strong></p>';
        echo '<textarea readonly style="width:100%; min-height:260px; font-family:monospace;">' . \esc_textarea(provider_logs_pretty_json((string) ($row['response_summary'] ?? ''))) . '</textarea>';

        echo '</details>';
        echo '</td>';
        echo '</tr>';
    }

    echo '</tbody>';
    echo '</table>';
    echo '</div>';
}

/**
 * @param mixed $value
 */
function provider_logs_pretty_json($value): string
{
    if (\is_array($value)) {
        $encoded = \wp_json_encode($value, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);
        return \is_string($encoded) ? $encoded : '';
    }

    $value = \is_scalar($value) ? (string) $value : '';

    if ($value === '') {
        return '';
    }

    $decoded = \json_decode($value, true);

    if (\json_last_error() !== \JSON_ERROR_NONE) {
        return $value;
    }

    $encoded = \wp_json_encode($decoded, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES | \JSON_UNESCAPED_UNICODE);

    return \is_string($encoded) ? $encoded : $value;
}