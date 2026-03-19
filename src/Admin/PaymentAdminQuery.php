<?php

namespace MustHotelBooking\Admin;

final class PaymentAdminQuery
{
    private string $action;
    private int $reservationId;
    private string $status;
    private string $method;
    private string $reservationStatus;
    private string $paymentGroup;
    private string $search;
    private string $dateFrom;
    private string $dateTo;
    private bool $dueOnly;
    private int $paged;
    private int $perPage;

    /**
     * @param array<string, mixed> $request
     */
    private function __construct(array $request)
    {
        $this->action = isset($request['action']) ? \sanitize_key((string) $request['action']) : '';
        $this->reservationId = isset($request['reservation_id']) ? \absint($request['reservation_id']) : 0;
        $this->status = isset($request['status']) ? \sanitize_key((string) $request['status']) : '';
        $this->method = isset($request['method']) ? \sanitize_key((string) $request['method']) : '';
        $this->reservationStatus = isset($request['reservation_status']) ? \sanitize_key((string) $request['reservation_status']) : '';
        $this->paymentGroup = isset($request['payment_group']) ? \sanitize_key((string) $request['payment_group']) : '';
        $this->search = isset($request['search']) ? \sanitize_text_field((string) $request['search']) : '';
        $this->dateFrom = isset($request['date_from']) ? \sanitize_text_field((string) $request['date_from']) : '';
        $this->dateTo = isset($request['date_to']) ? \sanitize_text_field((string) $request['date_to']) : '';
        $this->dueOnly = !empty($request['due_only']);
        $this->paged = isset($request['paged']) ? \max(1, (int) $request['paged']) : 1;
        $this->perPage = isset($request['per_page']) ? \max(5, \min(100, (int) $request['per_page'])) : 20;
    }

    /**
     * @param array<string, mixed> $request
     */
    public static function fromRequest(array $request): self
    {
        return new self($request);
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getReservationId(): int
    {
        return $this->reservationId;
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return [
            'status' => $this->status,
            'method' => $this->method,
            'reservation_status' => $this->reservationStatus,
            'payment_group' => $this->paymentGroup,
            'search' => $this->search,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'due_only' => $this->dueOnly,
            'paged' => $this->paged,
            'per_page' => $this->perPage,
        ];
    }

    /**
     * @param array<string, scalar|int|bool> $overrides
     * @return array<string, scalar|int|bool>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        $args = [
            'status' => $this->status,
            'method' => $this->method,
            'reservation_status' => $this->reservationStatus,
            'payment_group' => $this->paymentGroup,
            'search' => $this->search,
            'date_from' => $this->dateFrom,
            'date_to' => $this->dateTo,
            'due_only' => $this->dueOnly ? 1 : 0,
            'paged' => $this->paged,
            'per_page' => $this->perPage,
        ];

        foreach ($overrides as $key => $value) {
            $args[$key] = $value;
        }

        return \array_filter(
            $args,
            static function ($value): bool {
                return $value !== '' && $value !== 0 && $value !== false && $value !== null;
            }
        );
    }
}
