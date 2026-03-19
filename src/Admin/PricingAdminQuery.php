<?php

namespace MustHotelBooking\Admin;

final class PricingAdminQuery
{
    private string $action;
    private int $ruleId;
    private int $roomId;
    private string $status;
    private string $timeline;
    private string $setup;
    private string $scope;
    private string $ruleType;
    private string $search;
    private string $notice;

    /**
     * @param array<string, mixed> $request
     */
    public static function fromRequest(array $request): self
    {
        return new self(
            isset($request['action']) ? \sanitize_key((string) $request['action']) : '',
            isset($request['rule_id']) ? \absint($request['rule_id']) : 0,
            isset($request['room_id']) ? \absint($request['room_id']) : 0,
            isset($request['status']) ? \sanitize_key((string) $request['status']) : '',
            isset($request['timeline']) ? \sanitize_key((string) $request['timeline']) : '',
            isset($request['setup']) ? \sanitize_key((string) $request['setup']) : '',
            isset($request['scope']) ? \sanitize_key((string) $request['scope']) : '',
            isset($request['rule_type']) ? \sanitize_key((string) $request['rule_type']) : '',
            isset($request['search']) ? \sanitize_text_field((string) $request['search']) : '',
            isset($request['notice']) ? \sanitize_key((string) $request['notice']) : ''
        );
    }

    public function __construct(
        string $action,
        int $ruleId,
        int $roomId,
        string $status,
        string $timeline,
        string $setup,
        string $scope,
        string $ruleType,
        string $search,
        string $notice
    ) {
        $this->action = $action;
        $this->ruleId = $ruleId;
        $this->roomId = $roomId;
        $this->status = $status;
        $this->timeline = $timeline;
        $this->setup = $setup;
        $this->scope = $scope;
        $this->ruleType = $ruleType;
        $this->search = $search;
        $this->notice = $notice;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getRuleId(): int
    {
        return $this->ruleId;
    }

    public function getRoomId(): int
    {
        return $this->roomId;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getTimeline(): string
    {
        return $this->timeline;
    }

    public function getSetup(): string
    {
        return $this->setup;
    }

    public function getScope(): string
    {
        return $this->scope;
    }

    public function getRuleType(): string
    {
        return $this->ruleType;
    }

    public function getSearch(): string
    {
        return $this->search;
    }

    public function getNotice(): string
    {
        return $this->notice;
    }

    /**
     * @param array<string, scalar> $overrides
     * @return array<string, scalar>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        $args = [
            'room_id' => $this->roomId,
            'status' => $this->status,
            'timeline' => $this->timeline,
            'setup' => $this->setup,
            'scope' => $this->scope,
            'rule_type' => $this->ruleType,
            'search' => $this->search,
        ];

        $merged = \array_merge($args, $overrides);

        foreach ($merged as $key => $value) {
            if ($value === '' || $value === 0 || $value === 'all') {
                unset($merged[$key]);
            }
        }

        return $merged;
    }
}
