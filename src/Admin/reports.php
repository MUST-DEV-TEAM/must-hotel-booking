<?php

namespace MustHotelBooking\Admin;

/**
 * @param array<string, scalar|int|bool> $args
 */
function get_admin_reports_page_url(array $args = []): string
{
    $baseUrl = \admin_url('admin.php?page=must-hotel-booking-reports');

    if (empty($args)) {
        return $baseUrl;
    }

    return \add_query_arg($args, $baseUrl);
}

/**
 * @param array<string, mixed> $filters
 * @param array<string, mixed> $filterOptions
 */
function render_reports_filter_form(array $filters, array $filterOptions): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Report Filters', 'must-hotel-booking') . '</h2>';
    echo '<form method="get" action="' . \esc_url(\admin_url('admin.php')) . '">';
    echo '<input type="hidden" name="page" value="must-hotel-booking-reports" />';
    echo '<table class="form-table" role="presentation"><tbody>';
    echo '<tr><th scope="row"><label for="must-report-preset">' . \esc_html__('Preset', 'must-hotel-booking') . '</label></th><td><select id="must-report-preset" name="preset">';

    foreach ((array) ($filterOptions['presets'] ?? []) as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['preset'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-report-date-from">' . \esc_html__('Date From', 'must-hotel-booking') . '</label></th><td><input id="must-report-date-from" type="date" name="date_from" value="' . \esc_attr((string) ($filters['date_from'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-report-date-to">' . \esc_html__('Date To', 'must-hotel-booking') . '</label></th><td><input id="must-report-date-to" type="date" name="date_to" value="' . \esc_attr((string) ($filters['date_to'] ?? '')) . '" /></td></tr>';
    echo '<tr><th scope="row"><label for="must-report-room-id">' . \esc_html__('Accommodation', 'must-hotel-booking') . '</label></th><td><select id="must-report-room-id" name="room_id"><option value="0">' . \esc_html__('All accommodations', 'must-hotel-booking') . '</option>';

    foreach ((array) ($filterOptions['rooms'] ?? []) as $roomOption) {
        if (!\is_array($roomOption)) {
            continue;
        }

        $roomId = (int) ($roomOption['id'] ?? 0);
        echo '<option value="' . \esc_attr((string) $roomId) . '"' . \selected((int) ($filters['room_id'] ?? 0), $roomId, false) . '>' . \esc_html((string) ($roomOption['label'] ?? '')) . '</option>';
    }

    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-report-reservation-status">' . \esc_html__('Reservation Status', 'must-hotel-booking') . '</label></th><td><select id="must-report-reservation-status" name="reservation_status">';

    foreach ((array) ($filterOptions['reservation_statuses'] ?? []) as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['reservation_status'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select></td></tr>';
    echo '<tr><th scope="row"><label for="must-report-payment-method">' . \esc_html__('Payment Method', 'must-hotel-booking') . '</label></th><td><select id="must-report-payment-method" name="payment_method">';

    foreach ((array) ($filterOptions['payment_methods'] ?? []) as $value => $label) {
        echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['payment_method'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
    }

    echo '</select></td></tr>';
    echo '</tbody></table>';
    \submit_button(\__('Apply Filters', 'must-hotel-booking'));
    echo ' <a class="button" href="' . \esc_url(get_admin_reports_page_url()) . '">' . \esc_html__('Reset', 'must-hotel-booking') . '</a>';
    echo '</form>';
    echo '</div>';
}

/**
 * @param array<int, array<string, string>> $cards
 */
function render_report_kpis(array $cards): void
{
    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(220px,1fr));gap:16px;margin-bottom:20px;">';

    foreach ($cards as $card) {
        echo '<article class="postbox" style="margin:0;padding:18px 20px;">';
        echo '<p style="margin:0 0 8px 0;color:#646970;font-size:12px;text-transform:uppercase;letter-spacing:0.06em;">' . \esc_html((string) ($card['label'] ?? '')) . '</p>';
        echo '<strong style="display:block;font-size:30px;line-height:1.1;">' . \esc_html((string) ($card['value'] ?? '')) . '</strong>';
        echo '<p style="margin:8px 0 0 0;color:#646970;">' . \esc_html((string) ($card['meta'] ?? '')) . '</p>';
        echo '</article>';
    }

    echo '</div>';
}

/**
 * @param array<int, string> $notes
 */
function render_report_notes(array $notes): void
{
    echo '<div class="notice notice-info" style="margin:0 0 20px 0;"><p><strong>' . \esc_html__('Metric Definitions', 'must-hotel-booking') . '</strong></p><ul style="margin:0 0 0 18px;">';

    foreach ($notes as $note) {
        echo '<li>' . \esc_html((string) $note) . '</li>';
    }

    echo '</ul></div>';
}

/**
 * @param array<int, array<string, string>> $rows
 */
function render_simple_report_table(string $title, array $columns, array $rows, string $emptyMessage): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html($title) . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';

    foreach ($columns as $column) {
        echo '<th>' . \esc_html((string) $column) . '</th>';
    }

    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="' . \esc_attr((string) \count($columns)) . '">' . \esc_html($emptyMessage) . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            echo '<tr>';

            foreach (\array_keys($columns) as $columnKey) {
                echo '<td>' . \esc_html((string) ($row[$columnKey] ?? '')) . '</td>';
            }

            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * @param array<int, array<string, string|int>> $rows
 */
function render_accommodation_report_table(array $rows): void
{
    echo '<div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Top Accommodations', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><thead><tr>';
    echo '<th>' . \esc_html__('Accommodation', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Reservations', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Booked Nights', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Revenue', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Average Booking Value', 'must-hotel-booking') . '</th>';
    echo '<th>' . \esc_html__('Actions', 'must-hotel-booking') . '</th>';
    echo '</tr></thead><tbody>';

    if (empty($rows)) {
        echo '<tr><td colspan="6">' . \esc_html__('No accommodation performance data was found for this range.', 'must-hotel-booking') . '</td></tr>';
    } else {
        foreach ($rows as $row) {
            $roomId = (int) ($row['room_id'] ?? 0);
            $roomUrl = $roomId > 0 && \function_exists(__NAMESPACE__ . '\get_admin_rooms_page_url')
                ? get_admin_rooms_page_url(['tab' => 'types', 'action' => 'edit_type', 'type_id' => $roomId])
                : '';
            $reservationUrl = $roomId > 0 && \function_exists(__NAMESPACE__ . '\get_admin_reservations_page_url')
                ? get_admin_reservations_page_url(['room_id' => $roomId, 'created_after' => ''])
                : '';

            echo '<tr>';
            echo '<td>' . \esc_html((string) ($row['room_name'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['reservations'] ?? '0')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['nights'] ?? '0')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['revenue'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) ($row['average_booking_value'] ?? '')) . '</td>';
            echo '<td>';

            if ($roomUrl !== '') {
                echo '<a href="' . \esc_url($roomUrl) . '">' . \esc_html__('Open Accommodation', 'must-hotel-booking') . '</a>';
            }

            if ($reservationUrl !== '') {
                if ($roomUrl !== '') {
                    echo ' | ';
                }

                echo '<a href="' . \esc_url($reservationUrl) . '">' . \esc_html__('View Reservations', 'must-hotel-booking') . '</a>';
            }

            echo '</td>';
            echo '</tr>';
        }
    }

    echo '</tbody></table>';
    echo '</div>';
}

/**
 * Render Reports admin page.
 */
function render_admin_reports_page(): void
{
    ensure_admin_capability();
    $query = ReportAdminQuery::fromRequest(\is_array($_GET) ? $_GET : []);
    $data = (new ReportAdminDataProvider())->getPageData($query);
    $stay = (array) ($data['stay'] ?? []);

    echo '<div class="wrap">';
    echo '<h1>' . \esc_html__('Reports', 'must-hotel-booking') . '</h1>';
    render_reports_filter_form((array) ($data['filters'] ?? []), (array) ($data['filter_options'] ?? []));
    render_report_notes((array) ($data['notes'] ?? []));
    render_report_kpis((array) ($data['kpis'] ?? []));

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;">';
    echo '<div>';
    render_simple_report_table(
        \__('Reservation Status Breakdown', 'must-hotel-booking'),
        ['label' => \__('Status', 'must-hotel-booking'), 'value' => \__('Reservations', 'must-hotel-booking')],
        (array) (($data['breakdowns']['reservation_status'] ?? [])),
        \__('No reservation rows matched the selected filters.', 'must-hotel-booking')
    );
    echo '</div><div>';
    render_simple_report_table(
        \__('Payment Status Breakdown', 'must-hotel-booking'),
        ['label' => \__('Payment State', 'must-hotel-booking'), 'value' => \__('Reservations', 'must-hotel-booking')],
        (array) (($data['breakdowns']['payment_status'] ?? [])),
        \__('No payment status rows matched the selected filters.', 'must-hotel-booking')
    );
    echo '</div></div>';

    echo '<div style="display:grid;grid-template-columns:repeat(auto-fit,minmax(320px,1fr));gap:20px;">';
    echo '<div>';
    render_simple_report_table(
        \__('Payment Method Breakdown', 'must-hotel-booking'),
        ['label' => \__('Method', 'must-hotel-booking'), 'value' => \__('Reservations', 'must-hotel-booking')],
        (array) (($data['breakdowns']['payment_method'] ?? [])),
        \__('No payment method rows matched the selected filters.', 'must-hotel-booking')
    );
    echo '</div><div class="postbox" style="padding:16px;margin-bottom:20px;">';
    echo '<h2 style="margin-top:0;">' . \esc_html__('Stay Summary', 'must-hotel-booking') . '</h2>';
    echo '<table class="widefat striped"><tbody>';
    echo '<tr><th>' . \esc_html__('Arrivals', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((int) ($stay['arrivals_count'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Departures', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((int) ($stay['departures_count'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Occupied Nights', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((int) ($stay['occupied_nights'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Available Nights', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((int) ($stay['available_nights'] ?? 0))) . '</td></tr>';
    echo '<tr><th>' . \esc_html__('Average Length of Stay', 'must-hotel-booking') . '</th><td>' . \esc_html(\number_format_i18n((float) ($stay['average_length_of_stay'] ?? 0.0), 1)) . '</td></tr>';
    echo '</tbody></table>';
    echo '</div></div>';

    render_simple_report_table(
        \__('Revenue Summary', 'must-hotel-booking'),
        ['label' => \__('Metric', 'must-hotel-booking'), 'value' => \__('Value', 'must-hotel-booking')],
        [
            ['label' => \__('Booked Revenue', 'must-hotel-booking'), 'value' => (string) (($data['revenue']['booked_revenue'] ?? ''))],
            ['label' => \__('Amount Paid', 'must-hotel-booking'), 'value' => (string) (($data['revenue']['amount_paid'] ?? ''))],
            ['label' => \__('Amount Due', 'must-hotel-booking'), 'value' => (string) (($data['revenue']['amount_due'] ?? ''))],
            ['label' => \__('Average Booking Value', 'must-hotel-booking'), 'value' => (string) (($data['revenue']['average_booking_value'] ?? ''))],
        ],
        \__('No revenue metrics were available.', 'must-hotel-booking')
    );

    render_simple_report_table(
        \sprintf(\__('Reservation Trend by %s', 'must-hotel-booking'), (string) (($data['trend']['granularity_label'] ?? __('Day', 'must-hotel-booking')))),
        ['label' => \__('Period', 'must-hotel-booking'), 'reservations' => \__('Reservations', 'must-hotel-booking'), 'revenue' => \__('Booked Revenue', 'must-hotel-booking')],
        (array) (($data['trend']['rows'] ?? [])),
        \__('No trend rows matched the selected filters.', 'must-hotel-booking')
    );

    render_accommodation_report_table((array) ($data['top_accommodations'] ?? []));

    render_simple_report_table(
        \__('Coupons & Discounts', 'must-hotel-booking'),
        ['coupon_code' => \__('Coupon Code', 'must-hotel-booking'), 'uses' => \__('Uses', 'must-hotel-booking'), 'discount_total' => \__('Discount Total', 'must-hotel-booking')],
        (array) ($data['coupons'] ?? []),
        \__('No coupon usage was recorded for the selected range.', 'must-hotel-booking')
    );

    render_simple_report_table(
        \__('Operational Reporting Issues', 'must-hotel-booking'),
        ['label' => \__('Issue', 'must-hotel-booking'), 'value' => \__('Count', 'must-hotel-booking')],
        (array) ($data['issues'] ?? []),
        \__('No obvious reporting inconsistencies were detected in this range.', 'must-hotel-booking')
    );

    echo '</div>';
}
