<?php

namespace MustHotelBooking\Core;

final class PaymentMethodRegistry
{
    /**
     * @return array<string, array<string, string>>
     */
    public static function getCatalog(): array
    {
        return [
            'pay_at_hotel' => [
                'label' => \__('Pay at hotel', 'must-hotel-booking'),
                'description' => \__('Guest pays in cash at the property during check-in or check-out.', 'must-hotel-booking'),
            ],
            'stripe' => [
                'label' => \__('Stripe', 'must-hotel-booking'),
                'description' => \__('Guest pays online immediately with card via Stripe Checkout.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @return array<string, bool>
     */
    public static function getDefaultStates(): array
    {
        return [
            'pay_at_hotel' => true,
            'stripe' => false,
        ];
    }

    /**
     * @param mixed $value
     * @return array<string, bool>
     */
    public static function normalizeStates($value): array
    {
        $defaults = self::getDefaultStates();
        $catalog = self::getCatalog();
        $states = [];

        if (\is_array($value)) {
            foreach ($value as $method => $rawState) {
                if (!\is_string($method) || !isset($catalog[$method])) {
                    continue;
                }

                $states[$method] = (bool) $rawState;
            }
        }

        return \array_merge($defaults, $states);
    }

    /**
     * @return array<string, bool>
     */
    public static function getStates(): array
    {
        return self::normalizeStates(
            MustBookingConfig::get_setting('payment_methods', self::getDefaultStates())
        );
    }

    /**
     * @param array<string, bool> $states
     */
    public static function saveStates(array $states): bool
    {
        MustBookingConfig::set_setting('payment_methods', self::normalizeStates($states));

        return true;
    }

    /**
     * @return array<int, string>
     */
    public static function getEnabled(): array
    {
        $enabled = [];

        foreach (self::getStates() as $method => $isEnabled) {
            if ($isEnabled) {
                $enabled[] = $method;
            }
        }

        return $enabled;
    }
}