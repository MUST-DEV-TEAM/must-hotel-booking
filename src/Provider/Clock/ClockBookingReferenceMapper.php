<?php

namespace MustHotelBooking\Provider\Clock;

final class ClockBookingReferenceMapper
{
    private const WEBSITE_REFERENCE_PREFIX = 'Website booking reference: ';

    /**
     * @param array<string, mixed> $payload
     * @return array{payload: array<string, mixed>, primary_field: string, fallback_fields: array<int, string>, reference_text: string}
     */
    public static function applyWebsiteReferenceToPayload(array $payload, string $websiteReference, string $primaryField = 'reference_number'): array
    {
        $websiteReference = self::cleanText($websiteReference, 191);
        $primaryField = self::cleanKey($primaryField);
        $referenceText = self::WEBSITE_REFERENCE_PREFIX . $websiteReference;

        if (!isset($payload['booking']) || !\is_array($payload['booking'])) {
            $payload['booking'] = [];
        }

        if ($websiteReference !== '' && $primaryField !== '') {
            $payload['booking'][$primaryField] = $websiteReference;
        }

        if ($websiteReference !== '') {
            $note = isset($payload['booking']['note']) && \is_scalar($payload['booking']['note'])
                ? (string) $payload['booking']['note']
                : '';

            if (\strpos($note, $referenceText) === false) {
                $note = \trim($note);
                $note .= ($note !== '' ? "\n\n" : '') . $referenceText;
            }

            $payload['booking']['note'] = $note;
        }

        return [
            'payload' => $payload,
            'primary_field' => $primaryField !== '' ? $primaryField : 'note',
            'fallback_fields' => ['note'],
            'reference_text' => $referenceText,
        ];
    }

    /**
     * @param array<string, mixed> $reservation
     * @return array{clock_booking_id: string, clock_booking_reference: string}
     */
    public static function extractClockIdentifiers(array $reservation): array
    {
        $clockBookingId = self::firstString($reservation, ['id', 'booking_id', 'provider_booking_id', 'reservation_id']);
        $clockBookingReference = self::firstString($reservation, [
            'reference_number',
            'reference',
            'confirmation_number',
            'booking_number',
            'number',
            'display_reference',
            'code',
        ]);

        if ($clockBookingId === '') {
            $clockBookingId = $clockBookingReference;
        }

        if ($clockBookingReference === '') {
            $clockBookingReference = $clockBookingId;
        }

        return [
            'clock_booking_id' => $clockBookingId,
            'clock_booking_reference' => $clockBookingReference,
        ];
    }

    /** @param array<string, mixed> $source @param array<int, string> $keys */
    public static function firstString(array $source, array $keys): string
    {
        foreach ($keys as $key) {
            if (isset($source[$key]) && \is_scalar($source[$key])) {
                $value = self::cleanText((string) $source[$key], 191);

                if ($value !== '') {
                    return $value;
                }
            }

            if (isset($source[$key]) && \is_array($source[$key])) {
                $nested = self::firstString($source[$key], ['id', 'number', 'reference', 'code']);

                if ($nested !== '') {
                    return $nested;
                }
            }
        }

        return '';
    }

    private static function cleanText(string $value, int $maxLength): string
    {
        $value = \function_exists('sanitize_text_field') ? \sanitize_text_field($value) : \trim(\strip_tags($value));

        return \substr($value, 0, $maxLength);
    }

    private static function cleanKey(string $value): string
    {
        return \function_exists('sanitize_key')
            ? \sanitize_key($value)
            : \strtolower((string) \preg_replace('/[^a-zA-Z0-9_\-]/', '', $value));
    }
}
