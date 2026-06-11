<?php

namespace MustHotelBooking\Provider\Clock;

use MustHotelBooking\Database\RefundRepository;

final class ClockFolioRefundSyncService
{
    public function __construct(?RefundRepository $refunds = null, ?ClockApiClient $client = null)
    {
        unset($refunds, $client);
    }

    /** @param array<string, mixed> $refund @return array<string, mixed> */
    public function syncRefund(array $refund): array
    {
        $refundId = (int) ($refund['id'] ?? 0);

        if ($refundId <= 0) {
            return ['success' => false, 'message' => \__('Clock folio refund sync is missing a refund ID.', 'must-hotel-booking')];
        }

        return (new ClockPaymentAccountingService())->syncRefund($refundId);
    }
}
