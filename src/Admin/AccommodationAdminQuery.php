<?php

namespace MustHotelBooking\Admin;

final class AccommodationAdminQuery
{
    private string $tab;
    private string $action;
    private int $categoryId;
    private int $typeId;
    private int $unitId;
    private int $paged;
    private int $perPage;
    private string $search;
    private string $category;
    private string $status;
    private string $bookable;
    private string $setup;
    private string $future;
    private int $unitTypeId;
    private string $unitStatus;
    private string $notice;

    /**
     * @param array<string, mixed> $request
     */
    public static function fromRequest(array $request): self
    {
        $tab = isset($request['tab']) ? \sanitize_key((string) $request['tab']) : 'rooms';
        $action = isset($request['action']) ? \sanitize_key((string) $request['action']) : '';
        $categoryId = isset($request['category_id']) ? \absint($request['category_id']) : 0;
        $legacyRoomId = isset($request['room_id']) ? \absint($request['room_id']) : 0;
        $typeId = isset($request['type_id']) ? \absint($request['type_id']) : 0;
        $unitId = isset($request['unit_id']) ? \absint($request['unit_id']) : 0;

        if ($action === 'edit' && $typeId <= 0 && $legacyRoomId > 0) {
            $action = 'edit_room';
            $typeId = $legacyRoomId;
        }

        if ($tab === 'types') {
            $tab = 'rooms';
        }

        if ($action === 'edit_type') {
            $action = 'edit_room';
        }

        if (!\in_array($tab, ['categories', 'rooms', 'units'], true)) {
            if ($action === 'edit_category') {
                $tab = 'categories';
            } elseif ($action === 'edit_unit') {
                $tab = 'units';
            } else {
                $tab = 'rooms';
            }
        }

        if ($action === 'edit_category') {
            $tab = 'categories';
        } elseif ($action === 'edit_room') {
            $tab = 'rooms';
        } elseif ($action === 'edit_unit') {
            $tab = 'units';
        }

        return new self(
            $tab,
            $action,
            $categoryId,
            $typeId,
            $unitId,
            \max(1, isset($request['paged']) ? (int) $request['paged'] : 1),
            \max(1, \min(50, isset($request['per_page']) ? (int) $request['per_page'] : 12)),
            isset($request['search']) ? \sanitize_text_field((string) $request['search']) : '',
            isset($request['category']) ? \sanitize_key((string) $request['category']) : '',
            isset($request['status']) ? \sanitize_key((string) $request['status']) : '',
            isset($request['bookable']) ? \sanitize_key((string) $request['bookable']) : '',
            isset($request['setup']) ? \sanitize_key((string) $request['setup']) : '',
            isset($request['future']) ? \sanitize_key((string) $request['future']) : '',
            isset($request['room_type_id']) ? \absint($request['room_type_id']) : 0,
            isset($request['unit_status']) ? \sanitize_key((string) $request['unit_status']) : '',
            isset($request['notice']) ? \sanitize_key((string) $request['notice']) : ''
        );
    }

    public function __construct(
        string $tab,
        string $action,
        int $categoryId,
        int $typeId,
        int $unitId,
        int $paged,
        int $perPage,
        string $search,
        string $category,
        string $status,
        string $bookable,
        string $setup,
        string $future,
        int $unitTypeId,
        string $unitStatus,
        string $notice
    ) {
        $this->tab = $tab;
        $this->action = $action;
        $this->categoryId = $categoryId;
        $this->typeId = $typeId;
        $this->unitId = $unitId;
        $this->paged = $paged;
        $this->perPage = $perPage;
        $this->search = $search;
        $this->category = $category;
        $this->status = $status;
        $this->bookable = $bookable;
        $this->setup = $setup;
        $this->future = $future;
        $this->unitTypeId = $unitTypeId;
        $this->unitStatus = $unitStatus;
        $this->notice = $notice;
    }

    public function getTab(): string
    {
        return $this->tab;
    }

    public function getAction(): string
    {
        return $this->action;
    }

    public function getCategoryId(): int
    {
        return $this->categoryId;
    }

    public function getTypeId(): int
    {
        return $this->typeId;
    }

    public function getRoomId(): int
    {
        return $this->typeId;
    }

    public function getUnitId(): int
    {
        return $this->unitId;
    }

    public function getPaged(): int
    {
        return $this->paged;
    }

    public function getPerPage(): int
    {
        return $this->perPage;
    }

    public function getSearch(): string
    {
        return $this->search;
    }

    public function getCategory(): string
    {
        return $this->category;
    }

    public function getStatus(): string
    {
        return $this->status;
    }

    public function getBookable(): string
    {
        return $this->bookable;
    }

    public function getSetup(): string
    {
        return $this->setup;
    }

    public function getFuture(): string
    {
        return $this->future;
    }

    public function getUnitTypeId(): int
    {
        return $this->unitTypeId;
    }

    public function getUnitStatus(): string
    {
        return $this->unitStatus;
    }

    public function getNotice(): string
    {
        return $this->notice;
    }

    public function isCategoriesTab(): bool
    {
        return $this->tab === 'categories';
    }

    public function isRoomsTab(): bool
    {
        return $this->tab === 'rooms';
    }

    public function isTypesTab(): bool
    {
        return $this->isRoomsTab();
    }

    public function isUnitsTab(): bool
    {
        return $this->tab === 'units';
    }

    /**
     * @param array<string, scalar> $overrides
     * @return array<string, scalar>
     */
    public function buildUrlArgs(array $overrides = []): array
    {
        $args = [
            'tab' => $this->tab,
            'search' => $this->search,
            'status' => $this->status,
            'bookable' => $this->bookable,
            'future' => $this->future,
            'paged' => $this->paged,
        ];

        if ($this->isRoomsTab()) {
            $args['category'] = $this->category;
            $args['setup'] = $this->setup;
        } elseif ($this->isUnitsTab()) {
            $args['room_type_id'] = $this->unitTypeId;
            $args['unit_status'] = $this->unitStatus;
        }

        $merged = \array_merge($args, $overrides);

        foreach ($merged as $key => $value) {
            if ($value === '' || $value === 0 || $value === 'all' || $value === 1 && $key === 'paged') {
                unset($merged[$key]);
            }
        }

        return $merged;
    }
}
