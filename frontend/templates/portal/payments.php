<?php

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Core\PaymentMethodRegistry;
use MustHotelBooking\Core\StaffAccess;
use MustHotelBooking\Engine\PaymentStatusService;
use MustHotelBooking\Portal\PortalRenderer;
use MustHotelBooking\Portal\PortalRouter;
use function MustHotelBooking\Admin\get_reservation_payment_status_options;
use function MustHotelBooking\Admin\get_reservation_status_options;

$filters = isset($moduleData['filters']) && \is_array($moduleData['filters']) ? $moduleData['filters'] : [];
$detail = isset($moduleData['detail']) && \is_array($moduleData['detail']) ? $moduleData['detail'] : null;
$paymentForms = isset($moduleData['forms']) && \is_array($moduleData['forms']) ? $moduleData['forms'] : [];
$currency = MustBookingConfig::get_currency();
$paymentStatusOptions = \function_exists('MustHotelBooking\\Admin\\get_reservation_payment_status_options') ? get_reservation_payment_status_options() : [];
$reservationStatusOptions = \function_exists('MustHotelBooking\\Admin\\get_reservation_status_options') ? get_reservation_status_options() : [];
$paymentMethodCatalog = PaymentMethodRegistry::getCatalog();

$buildPaymentDetailUrl = static function (int $reservationId) use ($filters): string {
    $args = ['reservation_id' => $reservationId];

    foreach (['status', 'method', 'reservation_status', 'payment_group', 'search', 'date_from', 'date_to', 'due_only', 'paged', 'per_page'] as $key) {
        if (!isset($filters[$key]) || !\is_scalar($filters[$key])) {
            continue;
        }

        $value = (string) $filters[$key];

        if ($value === '' || $value === '0') {
            continue;
        }

        $args[$key] = $value;
    }

    return PortalRouter::getModuleUrl('payments', $args);
};

$renderStateAction = static function (string $action, string $label, string $nonceAction, string $detailUrl, int $reservationId, string $buttonClass = 'must-portal-secondary-button'): void {
    echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-inline-actions">';
    \wp_nonce_field($nonceAction, 'must_portal_payment_nonce');
    echo '<input type="hidden" name="must_portal_action" value="' . \esc_attr($action) . '" />';
    echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
    echo '<button type="submit" class="' . \esc_attr($buttonClass) . '">' . \esc_html($label) . '</button>';
    echo '</form>';
};

PortalRenderer::renderSummaryCards((array) ($moduleData['summary_cards'] ?? []));

echo '<section class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payments', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Review balances, collect on-property payments, and resolve payment-state issues from the portal.', 'must-hotel-booking') . '</p></div></div>';
echo '<form class="must-portal-filter-bar" method="get" action="' . \esc_url(PortalRouter::getModuleUrl('payments')) . '">';
echo '<input type="search" name="search" value="' . \esc_attr((string) ($filters['search'] ?? '')) . '" placeholder="' . \esc_attr__('Booking ID, guest, email', 'must-hotel-booking') . '" />';
echo '<select name="payment_group">';

foreach ((array) ($moduleData['payment_group_options'] ?? []) as $value => $label) {
    echo '<option value="' . \esc_attr((string) $value) . '"' . \selected((string) ($filters['payment_group'] ?? ''), (string) $value, false) . '>' . \esc_html((string) $label) . '</option>';
}

echo '</select><button type="submit" class="must-portal-secondary-button">' . \esc_html__('Apply', 'must-hotel-booking') . '</button></form>';

if (empty($moduleData['rows'])) {
    PortalRenderer::renderEmptyState(\__('No payment rows matched the current filters.', 'must-hotel-booking'));
} else {
    echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Reservation', 'must-hotel-booking') . '</th><th>' . \esc_html__('Guest', 'must-hotel-booking') . '</th><th>' . \esc_html__('Method', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Due', 'must-hotel-booking') . '</th><th></th></tr></thead><tbody>';

    foreach ((array) $moduleData['rows'] as $row) {
        if (!\is_array($row)) {
            continue;
        }

        $reservationId = (int) ($row['reservation_id'] ?? 0);

        echo '<tr><td><strong>' . \esc_html((string) ($row['booking_id'] ?? '')) . '</strong><br /><span class="must-portal-muted">' . \esc_html((string) ($row['accommodation'] ?? '')) . '</span></td>';
        echo '<td>' . \esc_html((string) ($row['guest'] ?? '')) . '</td><td>' . \esc_html((string) ($row['payment_method'] ?? '')) . '</td><td>';
        PortalRenderer::renderBadge((string) ($row['payment_status_key'] ?? 'info'), (string) ($row['payment_status'] ?? ''));
        echo '</td><td>' . \esc_html((string) ($row['amount_due'] ?? '')) . '</td><td><a class="must-portal-inline-link" href="' . \esc_url($buildPaymentDetailUrl($reservationId)) . '">' . \esc_html__('View', 'must-hotel-booking') . '</a></td></tr>';
    }

    echo '</tbody></table></div>';
    PortalRenderer::renderPagination((array) ($moduleData['pagination'] ?? []), 'payments');
}

echo '</section>';

if (\is_array($detail)) {
    $reservationId = (int) ($detail['reservation_id'] ?? 0);
    $detailUrl = $buildPaymentDetailUrl($reservationId);
    $state = isset($detail['state']) && \is_array($detail['state']) ? $detail['state'] : [];
    $reservation = isset($detail['reservation']) && \is_array($detail['reservation']) ? $detail['reservation'] : [];
    $payments = isset($detail['payments']) && \is_array($detail['payments']) ? $detail['payments'] : [];
    $timeline = isset($detail['timeline']) && \is_array($detail['timeline']) ? $detail['timeline'] : [];
    $warnings = isset($state['warnings']) && \is_array($state['warnings']) ? $state['warnings'] : [];
    $reservationStatus = \sanitize_key((string) ($reservation['status'] ?? ''));
    $paymentStatusKey = \sanitize_key((string) ($state['derived_status'] ?? ''));
    $paymentMethodKey = \sanitize_key((string) ($state['method'] ?? ''));
    $paymentStatusLabel = (string) ($paymentStatusOptions[$paymentStatusKey] ?? ($state['derived_status'] ?? \__('Unknown', 'must-hotel-booking')));
    $reservationStatusLabel = (string) ($reservationStatusOptions[$reservationStatus] ?? ($reservation['status'] ?? \__('Unknown', 'must-hotel-booking')));
    $paymentMethodLabel = isset($paymentMethodCatalog[$paymentMethodKey]['label']) ? (string) $paymentMethodCatalog[$paymentMethodKey]['label'] : (string) ($state['method'] ?? \__('Not recorded', 'must-hotel-booking'));
    $amountDue = (float) ($state['amount_due'] ?? 0.0);
    $amountPaid = (float) ($state['amount_paid'] ?? 0.0);
    $grossAmountPaid = (float) ($state['gross_amount_paid'] ?? $amountPaid);
    $amountRefunded = (float) ($state['amount_refunded'] ?? 0.0);
    $totalAmount = (float) ($state['total'] ?? 0.0);
    $transactionId = (string) ($state['transaction_id'] ?? '');
    $postForm = isset($paymentForms['post']) && \is_array($paymentForms['post']) ? $paymentForms['post'] : [];
    $refundForm = isset($paymentForms['refund']) && \is_array($paymentForms['refund']) ? $paymentForms['refund'] : [];
    $partialAmountValue = (string) ($postForm['amount'] ?? ($amountDue > 0.0 ? \number_format($amountDue, 2, '.', '') : ''));
    $postingReference = (string) ($postForm['transaction_id'] ?? '');
    $refundAmountValue = (string) ($refundForm['amount'] ?? ($amountPaid > 0.0 ? \number_format($amountPaid, 2, '.', '') : ''));
    $canManageOptions = \current_user_can('manage_options');
    $canPostPayment = \current_user_can(StaffAccess::CAP_PAYMENT_POST) || $canManageOptions;
    $canPostPartialPayment = \current_user_can(StaffAccess::CAP_PAYMENT_POST_PARTIAL) || $canManageOptions;
    $canMarkPaid = \current_user_can(StaffAccess::CAP_PAYMENT_MARK_PAID) || $canManageOptions;
    $canRefund = \current_user_can(StaffAccess::CAP_PAYMENT_REFUND) || $canManageOptions;
    $canReconcile = \current_user_can(StaffAccess::CAP_PAYMENT_RECONCILE) || $canManageOptions;
    $canIssueReceipt = \current_user_can(StaffAccess::CAP_PAYMENT_RECEIPT) || $canManageOptions;
    $canIssueInvoice = \current_user_can(StaffAccess::CAP_PAYMENT_INVOICE) || $canManageOptions;
    $canPostBalance = $amountDue > 0.0 && !\in_array($reservationStatus, ['cancelled', 'blocked'], true);
    $canRenderReceipt = $canIssueReceipt && (!empty($payments) || $amountPaid > 0.0);
    $canRenderInvoice = $canIssueInvoice && $totalAmount > 0.0;

    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payment detail', 'must-hotel-booking') . '</h2><p>' . \esc_html((string) ($detail['booking_id'] ?? '')) . '</p></div></div><div class="must-portal-definition-list">';
    PortalRenderer::renderDefinitionRow(\__('Guest', 'must-hotel-booking'), (string) ($detail['guest_name'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Accommodation', 'must-hotel-booking'), (string) ($detail['accommodation'] ?? ''));
    PortalRenderer::renderDefinitionRow(\__('Payment method', 'must-hotel-booking'), $paymentMethodLabel);
    PortalRenderer::renderDefinitionRow(\__('Reservation total', 'must-hotel-booking'), \number_format_i18n($totalAmount, 2) . ' ' . $currency);
    PortalRenderer::renderDefinitionRow(\__('Amount paid', 'must-hotel-booking'), \number_format_i18n($grossAmountPaid, 2) . ' ' . $currency);
    if ($amountRefunded > 0.0) {
        PortalRenderer::renderDefinitionRow(\__('Amount refunded', 'must-hotel-booking'), \number_format_i18n($amountRefunded, 2) . ' ' . $currency);
    }
    PortalRenderer::renderDefinitionRow(\__('Amount due', 'must-hotel-booking'), \number_format_i18n($amountDue, 2) . ' ' . $currency);
    if ($transactionId !== '') {
        PortalRenderer::renderDefinitionRow(\__('Gateway reference', 'must-hotel-booking'), $transactionId);
    }
    echo '</div><div class="must-portal-inline-actions">';
    PortalRenderer::renderBadge($paymentStatusKey !== '' ? $paymentStatusKey : 'info', $paymentStatusLabel);
    PortalRenderer::renderBadge($reservationStatus !== '' ? $reservationStatus : 'info', $reservationStatusLabel);
    echo '</div></article>';

    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Operational actions', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Collect funds, issue documents, and reconcile payment-state exceptions only when the current reservation state allows it.', 'must-hotel-booking') . '</p></div></div>';

    if (!empty($warnings)) {
        foreach ($warnings as $warning) {
            echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) $warning) . '</strong></div>';
            PortalRenderer::renderBadge('warning', \__('Review', 'must-hotel-booking'));
            echo '</div>';
        }
    }

    if ($canPostPayment && $canPostBalance) {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid">';
        \wp_nonce_field('must_portal_payment_action_payment_post_' . $reservationId, 'must_portal_payment_nonce');
        echo '<input type="hidden" name="must_portal_action" value="payment_post" />';
        echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
        echo '<input type="hidden" name="amount" value="' . \esc_attr(\number_format($amountDue, 2, '.', '')) . '" />';
        echo '<label class="must-portal-form-full"><span>' . \esc_html__('Reference / receipt number', 'must-hotel-booking') . '</span><input type="text" name="transaction_id" value="' . \esc_attr($postingReference) . '" placeholder="' . \esc_attr__('Optional desk receipt or ledger reference', 'must-hotel-booking') . '" /></label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-primary-button">' . \esc_html__('Post full payment', 'must-hotel-booking') . '</button><span class="must-portal-muted">' . \esc_html(\sprintf(\__('Collect %1$s %2$s and close the current balance.', 'must-hotel-booking'), \number_format_i18n($amountDue, 2), $currency)) . '</span></div>';
        echo '</form>';
    }

    if ($canPostPartialPayment && $canPostBalance) {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid">';
        \wp_nonce_field('must_portal_payment_action_payment_post_partial_' . $reservationId, 'must_portal_payment_nonce');
        echo '<input type="hidden" name="must_portal_action" value="payment_post_partial" />';
        echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
        echo '<label><span>' . \esc_html__('Partial amount', 'must-hotel-booking') . '</span><input type="number" min="0.01" max="' . \esc_attr(\number_format($amountDue, 2, '.', '')) . '" step="0.01" name="amount" value="' . \esc_attr($partialAmountValue) . '" /></label>';
        echo '<label><span>' . \esc_html__('Reference / receipt number', 'must-hotel-booking') . '</span><input type="text" name="transaction_id" value="' . \esc_attr($postingReference) . '" placeholder="' . \esc_attr__('Optional desk receipt or ledger reference', 'must-hotel-booking') . '" /></label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-secondary-button">' . \esc_html__('Post partial payment', 'must-hotel-booking') . '</button></div>';
        echo '</form>';
    }

    if ($canMarkPaid && PaymentStatusService::canTransition($state, 'mark_paid')) {
        $renderStateAction('payment_mark_paid', \__('Mark paid', 'must-hotel-booking'), 'must_portal_payment_action_mark_paid_' . $reservationId, $detailUrl, $reservationId, 'must-portal-secondary-button');
    }

    if ($canRefund && $amountPaid > 0.0 && $paymentMethodKey === 'stripe' && $transactionId !== '') {
        echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-form-grid">';
        \wp_nonce_field('must_portal_payment_action_payment_refund_' . $reservationId, 'must_portal_payment_nonce');
        echo '<input type="hidden" name="must_portal_action" value="payment_refund" />';
        echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
        echo '<label><span>' . \esc_html__('Refund amount', 'must-hotel-booking') . '</span><input type="number" min="0.01" max="' . \esc_attr(\number_format($amountPaid, 2, '.', '')) . '" step="0.01" name="amount" value="' . \esc_attr($refundAmountValue) . '" /></label>';
        echo '<div class="must-portal-form-full must-portal-inline-actions"><button type="submit" class="must-portal-secondary-button">' . \esc_html__('Issue refund', 'must-hotel-booking') . '</button></div>';
        echo '</form>';
    } elseif ($canRefund && $amountPaid > 0.0 && $paymentMethodKey === 'pay_at_hotel') {
        echo '<p class="must-portal-muted">' . \esc_html__('Pay-at-hotel refunds still need to be handled manually outside the plugin.', 'must-hotel-booking') . '</p>';
    }

    if ($canReconcile) {
        echo '<div class="must-portal-inline-actions">';

        if (PaymentStatusService::canTransition($state, 'mark_unpaid')) {
            $renderStateAction('payment_mark_unpaid', \__('Mark unpaid', 'must-hotel-booking'), 'must_portal_payment_action_mark_unpaid_' . $reservationId, $detailUrl, $reservationId);
        }

        if (PaymentStatusService::canTransition($state, 'mark_pending')) {
            $renderStateAction('payment_mark_pending', \__('Mark pending', 'must-hotel-booking'), 'must_portal_payment_action_mark_pending_' . $reservationId, $detailUrl, $reservationId);
        }

        if (PaymentStatusService::canTransition($state, 'mark_pay_at_hotel')) {
            $renderStateAction('payment_mark_pay_at_hotel', \__('Set pay at hotel', 'must-hotel-booking'), 'must_portal_payment_action_mark_pay_at_hotel_' . $reservationId, $detailUrl, $reservationId);
        }

        if (PaymentStatusService::canTransition($state, 'mark_failed')) {
            $renderStateAction('payment_mark_failed', \__('Mark failed', 'must-hotel-booking'), 'must_portal_payment_action_mark_failed_' . $reservationId, $detailUrl, $reservationId);
        }

        echo '</div>';
    }

    if ($canRenderReceipt || $canRenderInvoice) {
        echo '<div class="must-portal-inline-actions">';

        if ($canRenderReceipt) {
            echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-inline-actions" target="_blank">';
            \wp_nonce_field('must_portal_payment_action_payment_issue_receipt_' . $reservationId, 'must_portal_payment_nonce');
            echo '<input type="hidden" name="must_portal_action" value="payment_issue_receipt" />';
            echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
            echo '<button type="submit" class="must-portal-secondary-button">' . \esc_html__('Generate receipt', 'must-hotel-booking') . '</button>';
            echo '</form>';
        }

        if ($canRenderInvoice) {
            echo '<form method="post" action="' . \esc_url($detailUrl) . '" class="must-portal-inline-actions" target="_blank">';
            \wp_nonce_field('must_portal_payment_action_payment_issue_invoice_' . $reservationId, 'must_portal_payment_nonce');
            echo '<input type="hidden" name="must_portal_action" value="payment_issue_invoice" />';
            echo '<input type="hidden" name="reservation_id" value="' . \esc_attr((string) $reservationId) . '" />';
            echo '<button type="submit" class="must-portal-secondary-button">' . \esc_html__('Generate invoice', 'must-hotel-booking') . '</button>';
            echo '</form>';
        }

        echo '</div>';
    }

    echo '</article></section>';

    echo '<section class="must-portal-grid must-portal-grid--2">';
    echo '<article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Transaction rows', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Ledger entries attached to this reservation.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($payments)) {
        PortalRenderer::renderEmptyState(\__('No transaction rows are linked to this reservation yet.', 'must-hotel-booking'));
    } else {
        echo '<div class="must-portal-table-wrap"><table class="must-portal-table"><thead><tr><th>' . \esc_html__('Amount', 'must-hotel-booking') . '</th><th>' . \esc_html__('Method', 'must-hotel-booking') . '</th><th>' . \esc_html__('Status', 'must-hotel-booking') . '</th><th>' . \esc_html__('Reference', 'must-hotel-booking') . '</th><th>' . \esc_html__('Recorded', 'must-hotel-booking') . '</th></tr></thead><tbody>';

        foreach ($payments as $paymentRow) {
            if (!\is_array($paymentRow)) {
                continue;
            }

            echo '<tr>';
            echo '<td>' . \esc_html(\number_format_i18n((float) ($paymentRow['amount'] ?? 0.0), 2) . ' ' . (string) ($paymentRow['currency'] ?? $currency)) . '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['method'] ?? '')) . '</td>';
            echo '<td>';
            PortalRenderer::renderBadge((string) ($paymentRow['status'] ?? 'info'), (string) ($paymentRow['status'] ?? ''));
            echo '</td>';
            echo '<td>' . \esc_html((string) ($paymentRow['transaction_id'] ?? '')) . '</td>';
            echo '<td>' . \esc_html((string) (($paymentRow['paid_at'] ?? '') !== '' ? $paymentRow['paid_at'] : ($paymentRow['created_at'] ?? ''))) . '</td>';
            echo '</tr>';
        }

        echo '</tbody></table></div>';
    }

    echo '</article><article class="must-portal-panel"><div class="must-portal-panel-header"><div><h2>' . \esc_html__('Payment timeline', 'must-hotel-booking') . '</h2><p>' . \esc_html__('Recent payment events and follow-up captured for this reservation.', 'must-hotel-booking') . '</p></div></div>';

    if (empty($timeline)) {
        PortalRenderer::renderEmptyState(\__('No payment activity has been logged for this reservation yet.', 'must-hotel-booking'));
    } else {
        foreach ($timeline as $item) {
            if (!\is_array($item)) {
                continue;
            }

            echo '<div class="must-portal-feed-item"><div><strong>' . \esc_html((string) ($item['message'] ?? '')) . '</strong><span>' . \esc_html((string) ($item['created_at'] ?? '')) . '</span></div>';
            PortalRenderer::renderBadge((string) ($item['severity'] ?? 'info'));
            echo '</div>';
        }
    }

    echo '</article></section>';
}
