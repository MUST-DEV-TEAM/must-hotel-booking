<?php

namespace MustHotelBooking\Admin;

final class EmailAdminQuery
{
    private string $action;
    private string $templateKey;
    private string $audience;
    private string $flowType;
    private string $enabled;
    private string $search;
    private int $reservationId;

    /**
     * @param array<string, mixed> $request
     */
    private function __construct(array $request)
    {
        $this->action = isset($request['action']) ? \sanitize_key((string) $request['action']) : '';
        $this->templateKey = isset($request['template_key']) ? \sanitize_key((string) $request['template_key']) : '';
        $this->audience = isset($request['audience']) ? \sanitize_key((string) $request['audience']) : '';
        $this->flowType = isset($request['flow_type']) ? \sanitize_key((string) $request['flow_type']) : '';
        $this->enabled = isset($request['enabled']) ? \sanitize_key((string) $request['enabled']) : '';
        $this->search = isset($request['search']) ? \sanitize_text_field((string) $request['search']) : '';
        $this->reservationId = isset($request['reservation_id']) ? \absint($request['reservation_id']) : 0;
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

    public function getTemplateKey(): string
    {
        return $this->templateKey;
    }

    public function getReservationId(): int
    {
        return $this->reservationId;
    }

    /**
     * @return array<string, string>
     */
    public function getFilters(): array
    {
        return [
            'audience' => $this->audience,
            'flow_type' => $this->flowType,
            'enabled' => $this->enabled,
            'search' => $this->search,
        ];
    }

    /**
     * @param array<string, scalar|int|bool> $overrides
     * @return array<string, scalar|int|bool>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        $args = [
            'template_key' => $this->templateKey,
            'audience' => $this->audience,
            'flow_type' => $this->flowType,
            'enabled' => $this->enabled,
            'search' => $this->search,
            'reservation_id' => $this->reservationId,
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
