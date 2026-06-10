<?php
namespace MustHotelBooking\Admin;
use MustHotelBooking\Database\ActivityRepository;
final class DangerousResetService
{
    public const TARGET_OPERATIONAL = 'hotel_operational_data';
    private const SELECTION_TRANSIENT_PREFIX = 'must_hotel_booking_selection_';
    private const CLOCK_CATALOG_CACHE_OPTION = 'must_hotel_booking_clock_catalog_snapshot';
    /**
     * @return array<string, array<string, mixed>>
     */
    public static function getDefinitions(): array
    {
        return [
            self::TARGET_OPERATIONAL => [
                'label' => \__('Reset Demo / Test Hotel Data', 'must-hotel-booking'),
                'summary' => \__('Delete all demo/testing hotel data before switching Clock PMS account or launching live, while keeping the plugin fully configured.', 'must-hotel-booking'),
                'warning' => \__('This deletes rooms, room types, physical rooms, bookings, guests, payments, refunds, pricing/rates, taxes, coupons, locks, provider mappings, provider logs, and activity history. It keeps plugin settings, Clock endpoint paths, Clock API credentials, Stripe/email configuration, managed pages, branding, staff users, WordPress users, and WordPress pages.', 'must-hotel-booking'),
                'delete_items' => [
                    \__('Room listings, room inventory, room meta, guests, reservations, payments, refunds, pricing rules, availability rules, rate plans, seasons, seasonal prices, taxes, coupons, locks, cancellation policies, provider mappings, provider sync jobs, provider request logs, activity history, Clock catalog cache, and in-progress booking selection sessions.', 'must-hotel-booking'),
                ],
                'preserve_items' => [
                    \__('Plugin settings, hotel identity, branding, Clock endpoint paths, Clock API credentials, Stripe configuration, email settings/templates, managed page assignments, portal access rules, staff users, WordPress users, and WordPress pages/posts.', 'must-hotel-booking'),
                ],
                'confirmation_phrase' => 'RESET DEMO DATA',
                'nonce_action' => 'must_dangerous_reset_demo_test_data',
                'success_notice' => 'hotel_operational_reset_completed',
                'submit_label' => \__('Run Demo/Test Reset', 'must-hotel-booking'),
                'target_label' => \__('Demo / test hotel data', 'must-hotel-booking'),
            ],
        ];
    }
    public static function canCurrentUserAccess(): bool
    {
        return \is_admin() && \current_user_can('manage_options');
    }
    /**
     * @param array<string, mixed> $overrides
     * @return array<string, mixed>
     */
    public static function getFormState(array $overrides = []): array
    {
        $form = [
            'active_target' => '',
            'values' => [],
        ];
        foreach (\array_keys(self::getDefinitions()) as $target) {
            $form['values'][$target] = [
                'selected_target' => '',
                'confirmation_phrase' => '',
                'acknowledged' => false,
            ];
        }
        $activeTarget = isset($overrides['active_target']) ? \sanitize_key((string) $overrides['active_target']) : '';
        if (isset(self::getDefinitions()[$activeTarget])) {
            $form['active_target'] = $activeTarget;
        }
        $rawValues = isset($overrides['values']) && \is_array($overrides['values']) ? $overrides['values'] : [];
        foreach (\array_keys(self::getDefinitions()) as $target) {
            $valueOverrides = isset($rawValues[$target]) && \is_array($rawValues[$target]) ? $rawValues[$target] : [];
            $form['values'][$target] = [
                'selected_target' => isset($valueOverrides['selected_target']) ? \sanitize_key((string) $valueOverrides['selected_target']) : '',
                'confirmation_phrase' => isset($valueOverrides['confirmation_phrase']) ? \sanitize_text_field((string) $valueOverrides['confirmation_phrase']) : '',
                'acknowledged' => !empty($valueOverrides['acknowledged']),
            ];
        }
        return $form;
    }
    /**
     * @param array<string, mixed> $source
     * @return array<string, mixed>
     */
    public static function processRequest(array $source, bool $persist): array
    {
        $definitions = self::getDefinitions();
        $target = isset($source['dangerous_reset_target']) ? \sanitize_key((string) \wp_unslash($source['dangerous_reset_target'])) : '';
        $form = self::getFormState(
            [
                'active_target' => $target,
                'values' => [
                    $target => [
                        'selected_target' => isset($source['dangerous_reset_target_selection']) ? \sanitize_key((string) \wp_unslash($source['dangerous_reset_target_selection'])) : '',
                        'confirmation_phrase' => isset($source['dangerous_reset_confirmation_phrase']) ? \sanitize_text_field((string) \wp_unslash($source['dangerous_reset_confirmation_phrase'])) : '',
                        'acknowledged' => !empty($source['dangerous_reset_acknowledge']),
                    ],
                ],
            ]
        );
        if (!isset($definitions[$target])) {
            return self::buildFailureResult(
                \__('Unknown dangerous reset target.', 'must-hotel-booking'),
                $form,
                ''
            );
        }
        if (!\is_admin()) {
            return self::buildFailureResult(
                \__('Dangerous reset actions are only available inside wp-admin.', 'must-hotel-booking'),
                $form,
                $target
            );
        }
        if (!\current_user_can('manage_options')) {
            return self::buildFailureResult(
                \__('You do not have permission to run destructive reset actions.', 'must-hotel-booking'),
                $form,
                $target
            );
        }
        $nonce = isset($source['must_settings_nonce']) ? (string) \wp_unslash($source['must_settings_nonce']) : '';
        if (!\wp_verify_nonce($nonce, (string) $definitions[$target]['nonce_action'])) {
            return self::buildFailureResult(
                \__('Security check failed. Please refresh the page and try again.', 'must-hotel-booking'),
                $form,
                $target
            );
        }
        $selectedTarget = (string) ($form['values'][$target]['selected_target'] ?? '');
        if ($selectedTarget !== $target) {
            return self::buildFailureResult(
                \__('Select the exact reset target before continuing.', 'must-hotel-booking'),
                $form,
                $target
            );
        }
        $expectedPhrase = (string) ($definitions[$target]['confirmation_phrase'] ?? '');
        $enteredPhrase = \trim((string) ($form['values'][$target]['confirmation_phrase'] ?? ''));
        if ($enteredPhrase !== $expectedPhrase) {
            return self::buildFailureResult(
                \sprintf(
                    /* translators: %s is the exact confirmation phrase. */
                    \__('Type "%s" exactly to confirm this reset.', 'must-hotel-booking'),
                    $expectedPhrase
                ),
                $form,
                $target
            );
        }
        if (empty($form['values'][$target]['acknowledged'])) {
            return self::buildFailureResult(
                \__('You must confirm that this action cannot be undone before continuing.', 'must-hotel-booking'),
                $form,
                $target
            );
        }
        $user = \wp_get_current_user();
        if (!$user instanceof \WP_User || $user->ID <= 0) {
            return self::buildFailureResult(
                \__('Unable to verify the current administrator account.', 'must-hotel-booking'),
                $form,
                $target
            );
        }
        $password = isset($source['dangerous_reset_password']) ? (string) \wp_unslash($source['dangerous_reset_password']) : '';
        if ($password === '' || !\wp_check_password($password, (string) $user->user_pass, $user->ID)) {
            return self::buildFailureResult(
                \__('The current WordPress password is incorrect.', 'must-hotel-booking'),
                $form,
                $target
            );
        }
        if (!$persist) {
            return [
                'notice' => '',
                'errors' => [],
                'form' => $form,
            ];
        }
        $result = self::executeReset($target, $user);
        if (!empty($result['success'])) {
            return [
                'notice' => (string) ($definitions[$target]['success_notice'] ?? ''),
                'errors' => [],
                'form' => self::getFormState(),
            ];
        }
        return self::buildFailureResult(
            (string) ($result['error'] ?? \__('The reset could not be completed.', 'must-hotel-booking')),
            $form,
            $target
        );
    }
    /**
     * @return array<string, mixed>
     */
    private static function executeReset(string $target, \WP_User $user): array
    {
        global $wpdb;
        if (!$wpdb instanceof \wpdb) {
            return [
                'success' => false,
                'error' => \__('WordPress database access is unavailable for the reset action.', 'must-hotel-booking'),
            ];
        }
        $transactionStarted = $wpdb->query('START TRANSACTION') !== false;
        try {
            self::deleteOperationalTables($wpdb);
            self::deleteClockCatalogCache($wpdb);
            self::deleteSelectionTransients($wpdb);
            self::writeResetActivity($wpdb, $user, $target);
            if ($transactionStarted && $wpdb->query('COMMIT') === false) {
                throw new \RuntimeException(\__('Unable to commit the reset transaction.', 'must-hotel-booking'));
            }
        } catch (\Throwable $throwable) {
            $rolledBack = $transactionStarted && $wpdb->query('ROLLBACK') !== false;
            return [
                'success' => false,
                'error' => self::buildExecutionFailureMessage(
                    $throwable->getMessage(),
                    $transactionStarted,
                    $rolledBack
                ),
            ];
        }
        return [
            'success' => true,
            'error' => '',
        ];
    }
    private static function deleteOperationalTables(\wpdb $wpdb): void
    {
        foreach (self::getOperationalResetTables($wpdb) as $tableName => $label) {
            self::deleteTableRows($wpdb, $tableName, $label);
        }
    }
    private static function deleteTableRows(\wpdb $wpdb, string $tableName, string $label): void
    {
        if (!self::tableExists($wpdb, $tableName)) {
            return;
        }
        self::queryOrThrow(
            $wpdb,
            'DELETE FROM ' . $tableName,
            \sprintf(
                /* translators: %s is a plugin data label. */
                \__('Unable to clear %s.', 'must-hotel-booking'),
                $label
            )
        );
    }
    private static function deleteSelectionTransients(\wpdb $wpdb): void
    {
        $selectionPrefix = $wpdb->esc_like('_transient_' . self::SELECTION_TRANSIENT_PREFIX) . '%';
        $selectionTimeoutPrefix = $wpdb->esc_like('_transient_timeout_' . self::SELECTION_TRANSIENT_PREFIX) . '%';
        $sql = $wpdb->prepare(
            'DELETE FROM ' . $wpdb->options . ' WHERE option_name LIKE %s OR option_name LIKE %s',
            $selectionPrefix,
            $selectionTimeoutPrefix
        );
        self::queryOrThrow(
            $wpdb,
            (string) $sql,
            \__('Unable to clear in-progress booking selection session data.', 'must-hotel-booking')
        );
    }
    private static function deleteClockCatalogCache(\wpdb $wpdb): void
    {
        \delete_option(self::CLOCK_CATALOG_CACHE_OPTION);
        $prefixes = [
            '_transient_must_hotel_booking_clock_',
            '_transient_timeout_must_hotel_booking_clock_',
            '_transient_mhb_clock_',
            '_transient_timeout_mhb_clock_',
        ];
        $conditions = [];
        $params = [];
        foreach ($prefixes as $prefix) {
            $conditions[] = 'option_name LIKE %s';
            $params[] = $wpdb->esc_like($prefix) . '%';
        }
        if (empty($conditions)) {
            return;
        }
        $sql = $wpdb->prepare(
            'DELETE FROM ' . $wpdb->options . ' WHERE ' . \implode(' OR ', $conditions),
            $params
        );
        self::queryOrThrow(
            $wpdb,
            (string) $sql,
            \__('Unable to clear Clock catalog cache.', 'must-hotel-booking')
        );
    }
    private static function writeResetActivity(\wpdb $wpdb, \WP_User $user, string $target): void
    {
        $repository = new ActivityRepository($wpdb);
        if (!$repository->activityTableExists()) {
            return;
        }
        $eventType = 'demo_test_data_reset';
        $message = \sprintf(
            /* translators: %s is the admin login. */
            \__('Demo/test hotel data reset completed by administrator %s.', 'must-hotel-booking'),
            $user->user_login
        );
        $activityId = $repository->createActivity(
            [
                'event_type' => $eventType,
                'severity' => 'warning',
                'entity_type' => 'system',
                'entity_id' => (int) $user->ID,
                'reference' => 'dangerous_reset',
                'message' => $message,
                'context_json' => (string) \wp_json_encode(
                    [
                        'reset_type' => $target,
                        'admin_user_id' => (int) $user->ID,
                        'admin_user_login' => (string) $user->user_login,
                    ]
                ),
                'created_at' => \current_time('mysql'),
            ]
        );
        if ($activityId <= 0) {
            throw new \RuntimeException(\__('Unable to record the reset activity log entry.', 'must-hotel-booking'));
        }
    }
    /**
     * @return array<string, string>
     */
    private static function getOperationalResetTables(\wpdb $wpdb): array
    {
        return [
            $wpdb->prefix . 'mhb_inventory_locks' => \__('inventory locks', 'must-hotel-booking'),
            $wpdb->prefix . 'must_payments' => \__('payments', 'must-hotel-booking'),
            $wpdb->prefix . 'must_reservations' => \__('reservations', 'must-hotel-booking'),
            $wpdb->prefix . 'must_guests' => \__('guests', 'must-hotel-booking'),
            $wpdb->prefix . 'must_refunds' => \__('refunds', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_refunds' => \__('refunds', 'must-hotel-booking'),
            $wpdb->prefix . 'must_pricing' => \__('pricing rules', 'must-hotel-booking'),
            $wpdb->prefix . 'must_availability' => \__('availability rules', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_rate_plan_prices' => \__('rate plan prices', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_seasonal_prices' => \__('seasonal prices', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_room_type_rate_plans' => \__('room type rate plan assignments', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_rate_plans' => \__('rate plans', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_seasons' => \__('seasons', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_cancellation_policies' => \__('cancellation policies', 'must-hotel-booking'),
            $wpdb->prefix . 'must_coupons' => \__('coupons', 'must-hotel-booking'),
            $wpdb->prefix . 'must_taxes' => \__('taxes', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_rooms' => \__('inventory units', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_room_types' => \__('inventory room listing mirrors', 'must-hotel-booking'),
            $wpdb->prefix . 'must_room_meta' => \__('room meta', 'must-hotel-booking'),
            $wpdb->prefix . 'must_rooms' => \__('room listings', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_provider_mappings' => \__('provider mappings', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_provider_sync_jobs' => \__('provider sync jobs', 'must-hotel-booking'),
            $wpdb->prefix . 'mhb_provider_request_logs' => \__('provider request logs', 'must-hotel-booking'),
            $wpdb->prefix . 'must_activity_log' => \__('activity log', 'must-hotel-booking'),
        ];
    }
    private static function tableExists(\wpdb $wpdb, string $tableName): bool
    {
        $result = $wpdb->get_var(
            $wpdb->prepare(
                'SHOW TABLES LIKE %s',
                $wpdb->esc_like($tableName)
            )
        );
        return \is_string($result) && $result !== '';
    }
    private static function queryOrThrow(\wpdb $wpdb, string $sql, string $failureMessage): void
    {
        if ($wpdb->query($sql) !== false) {
            return;
        }
        $databaseError = \trim((string) $wpdb->last_error);
        if ($databaseError !== '') {
            $failureMessage .= ' ' . $databaseError;
        }
        throw new \RuntimeException($failureMessage);
    }
    /**
     * @return array<string, mixed>
     */
    private static function buildFailureResult(string $message, array $form, string $activeTarget): array
    {
        $form['active_target'] = $activeTarget;
        return [
            'notice' => '',
            'errors' => [$message],
            'form' => $form,
        ];
    }
    private static function buildExecutionFailureMessage(string $message, bool $transactionStarted, bool $rolledBack): string
    {
        $message = \trim($message);
        if ($message === '') {
            $message = \__('The reset could not be completed.', 'must-hotel-booking');
        }
        if (!$transactionStarted) {
            return $message . ' ' . \__('The database did not confirm transactional rollback support for this request, so partial destructive changes may already have been applied.', 'must-hotel-booking');
        }
        if (!$rolledBack) {
            return $message . ' ' . \__('Rollback did not complete successfully, so partial destructive changes may already have been applied.', 'must-hotel-booking');
        }
        return $message;
    }
}
