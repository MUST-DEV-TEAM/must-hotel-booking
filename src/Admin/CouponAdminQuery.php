<?php

namespace MustHotelBooking\Admin;

final class CouponAdminQuery
{
    /** @var array<string, mixed> */
    private array $filters;
    private string $action;
    private int $couponId;

    /**
     * @param array<string, mixed> $filters
     */
    private function __construct(array $filters, string $action, int $couponId)
    {
        $this->filters = $filters;
        $this->action = $action;
        $this->couponId = $couponId;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromRequest(array $source): self
    {
        return new self(
            [
                'search' => isset($source['search']) ? \sanitize_text_field((string) \wp_unslash($source['search'])) : '',
                'status' => isset($source['status']) ? \sanitize_key((string) \wp_unslash($source['status'])) : '',
                'discount_type' => isset($source['discount_type']) ? \sanitize_key((string) \wp_unslash($source['discount_type'])) : '',
                'per_page' => isset($source['per_page']) ? (int) $source['per_page'] : 20,
                'paged' => isset($source['paged']) ? (int) $source['paged'] : 1,
            ],
            isset($source['action']) ? \sanitize_key((string) \wp_unslash($source['action'])) : '',
            isset($source['coupon_id']) ? \absint(\wp_unslash($source['coupon_id'])) : 0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getCouponId(): int
    {
        return $this->couponId;
    }

    /**
     * @param array<string, scalar|int|bool> $overrides
     * @return array<string, scalar|int|bool>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        $args = [];

        foreach (['search', 'status', 'discount_type'] as $key) {
            if (!empty($this->filters[$key])) {
                $args[$key] = (string) $this->filters[$key];
            }
        }

        if (!empty($this->filters['per_page']) && (int) $this->filters['per_page'] !== 20) {
            $args['per_page'] = (int) $this->filters['per_page'];
        }

        if (!empty($this->filters['paged']) && (int) $this->filters['paged'] > 1) {
            $args['paged'] = (int) $this->filters['paged'];
        }

        if ($this->couponId > 0) {
            $args['coupon_id'] = $this->couponId;
        }

        if ($this->action !== '') {
            $args['action'] = $this->action;
        }

        foreach ($overrides as $key => $value) {
            if ($value === '' || $value === false || $value === null || $value === 0) {
                unset($args[$key]);
                continue;
            }

            $args[$key] = $value;
        }

        return $args;
    }
}
