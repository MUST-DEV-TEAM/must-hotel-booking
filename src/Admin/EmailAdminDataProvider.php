<?php

namespace MustHotelBooking\Admin;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\EmailEngine;
use MustHotelBooking\Engine\EmailLayoutEngine;

final class EmailAdminDataProvider
{
    private \MustHotelBooking\Database\ActivityRepository $activityRepository;

    public function __construct()
    {
        $this->activityRepository = \MustHotelBooking\Engine\get_activity_repository();
    }

    /**
     * @param array<string, mixed> $saveState
     * @return array<string, mixed>
     */
    public function getPageData(EmailAdminQuery $query, array $saveState): array
    {
        $records = EmailEngine::getTemplateRecords();
        $logRows = $this->buildEmailLogRows();
        $lastTestByTemplate = [];

        foreach ($logRows as $row) {
            if (!\is_array($row) || (string) ($row['mode'] ?? '') !== 'test' || (string) ($row['status'] ?? '') !== 'sent') {
                continue;
            }

            $templateKey = (string) ($row['template_key'] ?? '');

            if ($templateKey !== '' && !isset($lastTestByTemplate[$templateKey])) {
                $lastTestByTemplate[$templateKey] = (string) ($row['created_at'] ?? '');
            }
        }

        $warnings = $this->buildSystemWarnings($records, $logRows);
        $rows = $this->buildTemplateRows($records, $logRows, $lastTestByTemplate, $query->getFilters());
        $selectedTemplateKey = isset($saveState['selected_template_key']) && (string) $saveState['selected_template_key'] !== ''
            ? (string) $saveState['selected_template_key']
            : ($query->getTemplateKey() !== '' ? $query->getTemplateKey() : (string) (\array_key_first($records) ?? ''));
        $selectedTemplate = isset($saveState['template_form']) && \is_array($saveState['template_form'])
            ? $saveState['template_form']
            : ($records[$selectedTemplateKey] ?? null);
        $preview = $selectedTemplateKey !== ''
            ? EmailEngine::renderTemplatePreview($selectedTemplateKey, $query->getReservationId())
            : null;

        return [
            'filters' => $query->getFilters(),
            'summary_cards' => $this->buildSummaryCards($records, $logRows),
            'rows' => $rows,
            'selected_template' => $selectedTemplate,
            'selected_template_key' => $selectedTemplateKey,
            'preview' => $preview,
            'placeholders' => EmailEngine::getTemplatePlaceholderLabels(),
            'layout_placeholders' => EmailLayoutEngine::getSupportedLayoutPlaceholders(),
            'logs' => $this->filterLogRows($logRows, $selectedTemplateKey),
            'warnings' => $warnings,
            'settings_form' => $this->buildSettingsForm(isset($saveState['settings_form']) && \is_array($saveState['settings_form']) ? $saveState['settings_form'] : []),
            'template_errors' => isset($saveState['template_errors']) && \is_array($saveState['template_errors']) ? $saveState['template_errors'] : [],
            'settings_errors' => isset($saveState['settings_errors']) && \is_array($saveState['settings_errors']) ? $saveState['settings_errors'] : [],
            'test_errors' => isset($saveState['test_errors']) && \is_array($saveState['test_errors']) ? $saveState['test_errors'] : [],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $records
     * @param array<int, array<string, mixed>> $logRows
     * @param array<string, string> $lastTestByTemplate
     * @param array<string, string> $filters
     * @return array<int, array<string, mixed>>
     */
    private function buildTemplateRows(array $records, array $logRows, array $lastTestByTemplate, array $filters): array
    {
        $rows = [];
        $latestLogByTemplate = [];

        foreach ($logRows as $row) {
            if (!\is_array($row)) {
                continue;
            }

            $templateKey = (string) ($row['template_key'] ?? '');

            if ($templateKey !== '' && !isset($latestLogByTemplate[$templateKey])) {
                $latestLogByTemplate[$templateKey] = $row;
            }
        }

        foreach ($records as $templateKey => $record) {
            if (!$this->matchesFilters($record, $filters)) {
                continue;
            }

            $invalidPlaceholders = $this->findInvalidPlaceholders($record);
            $warnings = [];

            if (!empty($record['used_in_flow']) && empty($record['enabled'])) {
                $warnings[] = \__('Template is disabled but still mapped to a live booking flow.', 'must-hotel-booking');
            }

            if (!empty($invalidPlaceholders)) {
                $warnings[] = \sprintf(
                    /* translators: %s is a comma-separated list of placeholders. */
                    \__('Unknown placeholders: %s', 'must-hotel-booking'),
                    \implode(', ', $invalidPlaceholders)
                );
            }

            $latestLog = $latestLogByTemplate[$templateKey] ?? null;

            $rows[] = [
                'key' => $templateKey,
                'label' => (string) ($record['label'] ?? $templateKey),
                'audience' => (string) ($record['audience'] ?? ''),
                'flow_type' => (string) ($record['flow_type'] ?? ''),
                'enabled' => !empty($record['enabled']),
                'subject' => (string) ($record['subject'] ?? ''),
                'updated_at' => (string) ($record['updated_at'] ?? ''),
                'last_test_sent' => (string) ($lastTestByTemplate[$templateKey] ?? ''),
                'latest_log' => \is_array($latestLog) ? $latestLog : null,
                'warnings' => $warnings,
            ];
        }

        return $rows;
    }

    /**
     * @param array<string, mixed> $record
     * @param array<string, string> $filters
     */
    private function matchesFilters(array $record, array $filters): bool
    {
        $audience = isset($filters['audience']) ? (string) $filters['audience'] : '';
        $flowType = isset($filters['flow_type']) ? (string) $filters['flow_type'] : '';
        $enabled = isset($filters['enabled']) ? (string) $filters['enabled'] : '';
        $search = \strtolower(isset($filters['search']) ? (string) $filters['search'] : '');

        if ($audience !== '' && (string) ($record['audience'] ?? '') !== $audience) {
            return false;
        }

        if ($flowType !== '' && (string) ($record['flow_type'] ?? '') !== $flowType) {
            return false;
        }

        if ($enabled === 'enabled' && empty($record['enabled'])) {
            return false;
        }

        if ($enabled === 'disabled' && !empty($record['enabled'])) {
            return false;
        }

        if ($search !== '') {
            $haystack = \strtolower(
                (string) ($record['label'] ?? '') . ' ' .
                (string) ($record['key'] ?? '') . ' ' .
                (string) ($record['subject'] ?? '')
            );

            if (\strpos($haystack, $search) === false) {
                return false;
            }
        }

        return true;
    }

    /**
     * @param array<string, array<string, mixed>> $records
     * @param array<int, array<string, mixed>> $logRows
     * @return array<int, array<string, string>>
     */
    private function buildSummaryCards(array $records, array $logRows): array
    {
        $enabled = 0;
        $disabled = 0;
        $failed = 0;

        foreach ($records as $record) {
            if (!\is_array($record)) {
                continue;
            }

            if (!empty($record['enabled'])) {
                $enabled++;
            } else {
                $disabled++;
            }
        }

        foreach ($logRows as $row) {
            if (\is_array($row) && (string) ($row['status'] ?? '') === 'failed') {
                $failed++;
            }
        }

        return [
            [
                'label' => \__('Templates', 'must-hotel-booking'),
                'value' => (string) \count($records),
                'meta' => \__('Templates currently available in the booking flow.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Enabled', 'must-hotel-booking'),
                'value' => (string) $enabled,
                'meta' => \__('Templates allowed to send right now.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Disabled', 'must-hotel-booking'),
                'value' => (string) $disabled,
                'meta' => \__('Templates intentionally blocked from sending.', 'must-hotel-booking'),
            ],
            [
                'label' => \__('Recent Failures', 'must-hotel-booking'),
                'value' => (string) $failed,
                'meta' => \__('Recent email send failures in the activity log.', 'must-hotel-booking'),
            ],
        ];
    }

    /**
     * @param array<string, array<string, mixed>> $records
     * @param array<int, array<string, mixed>> $logRows
     * @return array<int, string>
     */
    private function buildSystemWarnings(array $records, array $logRows): array
    {
        $warnings = [];

        if (!\is_email(MustBookingConfig::get_email_from_email())) {
            $warnings[] = \__('Sender email is not configured correctly.', 'must-hotel-booking');
        }

        if (\trim(MustBookingConfig::get_email_from_name()) === '') {
            $warnings[] = \__('Sender name is missing.', 'must-hotel-booking');
        }

        foreach ($records as $record) {
            if (!\is_array($record)) {
                continue;
            }

            if ((string) ($record['subject'] ?? '') === '') {
                $warnings[] = \sprintf(\__('Template %s is missing a subject.', 'must-hotel-booking'), (string) ($record['label'] ?? ''));
            }

            if (\trim((string) ($record['body'] ?? '')) === '') {
                $warnings[] = \sprintf(\__('Template %s is missing a body.', 'must-hotel-booking'), (string) ($record['label'] ?? ''));
            }
        }

        foreach ($logRows as $row) {
            if (\is_array($row) && (string) ($row['status'] ?? '') === 'failed') {
                $warnings[] = \__('Recent email failures were recorded. Review the log below.', 'must-hotel-booking');
                break;
            }
        }

        return \array_values(\array_unique($warnings));
    }

    /**
     * @return array<int, array<string, mixed>>
     */
    private function buildEmailLogRows(): array
    {
        $rows = [];

        foreach ($this->activityRepository->getRecentActivitiesByEventTypes(['email_sent', 'email_failed'], 50) as $activity) {
            if (!\is_array($activity)) {
                continue;
            }

            $context = \json_decode((string) ($activity['context_json'] ?? ''), true);
            $context = \is_array($context) ? $context : [];
            $rows[] = [
                'template_key' => isset($context['template_key']) ? (string) $context['template_key'] : '',
                'recipient_email' => isset($context['recipient_email']) ? (string) $context['recipient_email'] : '',
                'reservation_id' => isset($context['reservation_id']) ? (int) $context['reservation_id'] : 0,
                'mode' => isset($context['email_mode']) ? (string) $context['email_mode'] : 'automated',
                'status' => (string) ($activity['event_type'] ?? '') === 'email_failed' ? 'failed' : 'sent',
                'severity' => (string) ($activity['severity'] ?? 'info'),
                'message' => (string) ($activity['message'] ?? ''),
                'reference' => (string) ($activity['reference'] ?? ''),
                'created_at' => (string) ($activity['created_at'] ?? ''),
            ];
        }

        return $rows;
    }

    /**
     * @param array<int, array<string, mixed>> $rows
     * @return array<int, array<string, mixed>>
     */
    private function filterLogRows(array $rows, string $templateKey): array
    {
        if ($templateKey === '') {
            return \array_slice($rows, 0, 15);
        }

        $filtered = [];

        foreach ($rows as $row) {
            if (\is_array($row) && (string) ($row['template_key'] ?? '') === $templateKey) {
                $filtered[] = $row;
            }
        }

        return \array_slice($filtered, 0, 15);
    }

    /**
     * @param array<string, mixed> $template
     * @return array<int, string>
     */
    private function findInvalidPlaceholders(array $template): array
    {
        $valid = EmailEngine::getTemplatePlaceholders();
        $candidate = (string) ($template['subject'] ?? '') . "\n" . (string) ($template['heading'] ?? '') . "\n" . (string) ($template['body'] ?? '');
        \preg_match_all('/\{[a-z0-9_]+\}/i', $candidate, $matches);
        $placeholders = isset($matches[0]) && \is_array($matches[0]) ? \array_unique($matches[0]) : [];
        $invalid = [];

        foreach ($placeholders as $placeholder) {
            if (!\in_array((string) $placeholder, $valid, true)) {
                $invalid[] = (string) $placeholder;
            }
        }

        return $invalid;
    }

    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    private function buildSettingsForm(array $overrides): array
    {
        return \array_merge(
            [
                'booking_notification_email' => MustBookingConfig::get_booking_notification_email(),
                'email_from_name' => MustBookingConfig::get_email_from_name(),
                'email_from_email' => MustBookingConfig::get_email_from_email(),
                'email_reply_to' => MustBookingConfig::get_email_reply_to(),
                'hotel_phone' => MustBookingConfig::get_hotel_phone(),
                'email_logo_url' => MustBookingConfig::get_email_logo_url(),
                'email_button_color' => MustBookingConfig::get_email_button_color(),
                'email_footer_text' => MustBookingConfig::get_email_footer_text(),
                'email_layout_type' => MustBookingConfig::get_email_layout_type(),
                'custom_email_layout_html' => MustBookingConfig::get_custom_email_layout_html(),
            ],
            $overrides
        );
    }
}
