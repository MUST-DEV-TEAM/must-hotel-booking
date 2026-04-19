<?php

namespace MustHotelBooking\Provider;

final class ProviderCapabilities
{
    public const AVAILABILITY = 'availability';
    public const QUOTE = 'quote';
    public const RATE_PLANS = 'rate_plans';
    public const HOLDS = 'holds';
    public const RESERVATION_CREATE = 'reservation_create';
    public const RESERVATION_UPDATE = 'reservation_update';
    public const RESERVATION_CANCEL = 'reservation_cancel';
    public const PAYMENTS = 'payments';
    public const ADMIN_MUTATIONS = 'admin_mutations';
    public const PORTAL_MUTATIONS = 'portal_mutations';
    public const DIAGNOSTICS = 'diagnostics';
    public const CATALOG = 'catalog';
    public const MAPPINGS = 'mappings';

    /** @var array<string, bool> */
    private $flags;

    /** @param array<string, bool> $flags */
    public function __construct(array $flags = [])
    {
        $this->flags = \array_merge(self::defaults(), $flags);
    }

    public static function local(): self
    {
        return new self([
            self::AVAILABILITY => true,
            self::QUOTE => true,
            self::RATE_PLANS => true,
            self::HOLDS => true,
            self::RESERVATION_CREATE => true,
            self::RESERVATION_UPDATE => true,
            self::RESERVATION_CANCEL => true,
            self::PAYMENTS => true,
            self::ADMIN_MUTATIONS => true,
            self::PORTAL_MUTATIONS => true,
        ]);
    }

    public static function clockScaffold(): self
    {
        return new self([
            self::DIAGNOSTICS => true,
            self::CATALOG => true,
            self::MAPPINGS => true,
        ]);
    }

    public static function clockPublicBooking(): self
    {
        return new self([
            self::AVAILABILITY => true,
            self::QUOTE => true,
            self::RATE_PLANS => true,
            self::HOLDS => true,
            self::RESERVATION_CREATE => true,
            self::DIAGNOSTICS => true,
            self::CATALOG => true,
            self::MAPPINGS => true,
        ]);
    }

    public function supports(string $capability): bool
    {
        return !empty($this->flags[$capability]);
    }

    /** @return array<string, bool> */
    public function toArray(): array
    {
        return $this->flags;
    }

    /** @return array<string, bool> */
    private static function defaults(): array
    {
        return [
            self::AVAILABILITY => false,
            self::QUOTE => false,
            self::RATE_PLANS => false,
            self::HOLDS => false,
            self::RESERVATION_CREATE => false,
            self::RESERVATION_UPDATE => false,
            self::RESERVATION_CANCEL => false,
            self::PAYMENTS => false,
            self::ADMIN_MUTATIONS => false,
            self::PORTAL_MUTATIONS => false,
            self::DIAGNOSTICS => false,
            self::CATALOG => false,
            self::MAPPINGS => false,
        ];
    }
}
