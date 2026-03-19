<?php

namespace MustHotelBooking\Admin;

final class GuestAdminQuery
{
    /** @var array<string, mixed> */
    private array $filters;

    private int $guestId;

    /**
     * @param array<string, mixed> $filters
     */
    private function __construct(array $filters, int $guestId)
    {
        $this->filters = $filters;
        $this->guestId = $guestId;
    }

    /**
     * @param array<string, mixed> $source
     */
    public static function fromRequest(array $source): self
    {
        $search = isset($source['search'])
            ? \sanitize_text_field((string) \wp_unslash($source['search']))
            : (isset($source['s']) ? \sanitize_text_field((string) \wp_unslash($source['s'])) : '');
        $country = isset($source['country']) ? \sanitize_text_field((string) \wp_unslash($source['country'])) : '';
        $stayState = isset($source['stay_state']) ? \sanitize_key((string) \wp_unslash($source['stay_state'])) : '';
        $attention = isset($source['attention']) ? \sanitize_key((string) \wp_unslash($source['attention'])) : '';
        $flagged = isset($source['flagged']) ? \sanitize_key((string) \wp_unslash($source['flagged'])) : '';
        $perPage = isset($source['per_page']) ? (int) $source['per_page'] : 20;
        $paged = isset($source['paged']) ? (int) $source['paged'] : 1;

        return new self(
            [
                'search' => $search,
                'country' => $country,
                'stay_state' => $stayState,
                'attention' => $attention,
                'flagged' => $flagged,
                'has_notes' => !empty($source['has_notes']) ? 1 : 0,
                'per_page' => \max(1, \min(100, $perPage)),
                'paged' => \max(1, $paged),
            ],
            isset($source['guest_id']) ? \absint(\wp_unslash($source['guest_id'])) : 0
        );
    }

    /**
     * @return array<string, mixed>
     */
    public function getFilters(): array
    {
        return $this->filters;
    }

    public function getGuestId(): int
    {
        return $this->guestId;
    }

    /**
     * @param array<string, scalar|int|bool> $overrides
     * @return array<string, scalar|int|bool>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        $args = [];

        foreach (['search', 'country', 'stay_state', 'attention', 'flagged'] as $key) {
            if (!empty($this->filters[$key])) {
                $args[$key] = (string) $this->filters[$key];
            }
        }

        if (!empty($this->filters['has_notes'])) {
            $args['has_notes'] = 1;
        }

        if (!empty($this->filters['per_page']) && (int) $this->filters['per_page'] !== 20) {
            $args['per_page'] = (int) $this->filters['per_page'];
        }

        if (!empty($this->filters['paged']) && (int) $this->filters['paged'] > 1) {
            $args['paged'] = (int) $this->filters['paged'];
        }

        if ($this->guestId > 0) {
            $args['guest_id'] = $this->guestId;
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
