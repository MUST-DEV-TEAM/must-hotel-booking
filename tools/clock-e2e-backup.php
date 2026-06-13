<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    http_response_code(403);
    echo 'CLI only.' . PHP_EOL;
    exit(1);
}

$root = \dirname(__DIR__, 4);
$wpLoad = $root . '/wp-load.php';

if (!\is_file($wpLoad)) {
    fwrite(STDERR, 'Unable to locate wp-load.php.' . PHP_EOL);
    exit(1);
}

require $wpLoad;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Provider\Clock\ClockConfig;

global $wpdb;

$timestamp = \gmdate('Ymd-His');
$backupRoot = __DIR__ . '/backups/clock-e2e-' . $timestamp;

if (!\wp_mkdir_p($backupRoot . '/tables')) {
    fwrite(STDERR, 'Unable to create backup directory.' . PHP_EOL);
    exit(1);
}

\file_put_contents($backupRoot . '/.htaccess', "Require all denied\nDeny from all\n");
\file_put_contents(
    $backupRoot . '/web.config',
    "<?xml version=\"1.0\" encoding=\"UTF-8\"?>\n<configuration><system.webServer><security><authorization><remove users=\"*\" roles=\"\" verbs=\"\" /><add accessType=\"Deny\" users=\"*\" /></authorization></security></system.webServer></configuration>\n"
);

$optionNames = [
    'home',
    'siteurl',
    MustBookingConfig::get_option_name(),
    'must_hotel_booking_db_version',
];

$options = [];

foreach ($optionNames as $optionName) {
    $options[$optionName] = \get_option($optionName, null);
}

$settings = MustBookingConfig::get_all_settings();
$options['_derived'] = [
    'active_site_environment' => PaymentEngine::getActiveSiteEnvironment(),
    'public_callback_base_url' => MustBookingConfig::get_public_callback_base_url(),
    'clock_environment' => ClockConfig::environment(),
    'clock_payment_posting_mode' => ClockConfig::paymentPostingMode(),
    'clock_same_day_folio_payment_enabled' => ClockConfig::sameDayFolioPaymentEnabled(),
    'clock_deposit_endpoint_configured' => ClockConfig::hasVerifiedDepositPaymentEndpoint(),
];

$tableNames = [
    'must_reservations',
    'must_payments',
    'must_refunds',
    'must_clock_folio_accounting',
    'mhb_provider_request_logs',
    'mhb_provider_sync_jobs',
    'mhb_provider_mappings',
];

$tables = [];

foreach ($tableNames as $suffix) {
    $table = $wpdb->prefix . $suffix;
    $exists = (string) $wpdb->get_var($wpdb->prepare('SHOW TABLES LIKE %s', $table)) === $table;
    $rowCount = 0;
    $file = '';

    if ($exists) {
        $rows = (array) $wpdb->get_results("SELECT * FROM `{$table}`", ARRAY_A);
        $rowCount = \count($rows);
        $file = 'tables/' . $suffix . '.json';
        \file_put_contents($backupRoot . '/' . $file, \wp_json_encode($rows, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));
    }

    $tables[$suffix] = [
        'table' => $table,
        'exists' => $exists,
        'row_count' => $rowCount,
        'file' => $file,
    ];
}

$sensitive = [
    'created_at_utc' => $timestamp,
    'site' => [
        'home_url' => \home_url('/'),
        'site_url' => \site_url('/'),
        'rest_url' => \rest_url('/'),
    ],
    'options' => $options,
    'tables' => $tables,
];

$sensitiveFile = $backupRoot . '/options-sensitive.json';
\file_put_contents($sensitiveFile, \wp_json_encode($sensitive, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

$manifest = [
    'created_at_utc' => $timestamp,
    'backup_dir' => $backupRoot,
    'contains_sensitive_local_values' => true,
    'gitignored' => true,
    'web_deny_files' => [
        '.htaccess' => \is_file($backupRoot . '/.htaccess'),
        'web.config' => \is_file($backupRoot . '/web.config'),
    ],
    'options_backed_up' => $optionNames,
    'derived_settings' => $options['_derived'],
    'tables' => $tables,
];

$manifestFile = $backupRoot . '/manifest.json';
\file_put_contents($manifestFile, \wp_json_encode($manifest, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES));

$readBack = \json_decode((string) \file_get_contents($manifestFile), true);

if (!\is_array($readBack) || (string) ($readBack['created_at_utc'] ?? '') !== $timestamp) {
    fwrite(STDERR, 'Backup verification failed.' . PHP_EOL);
    exit(1);
}

echo \wp_json_encode([
    'success' => true,
    'backup_dir' => $backupRoot,
    'manifest' => $manifestFile,
    'sensitive_options_file' => 'options-sensitive.json',
    'tables' => \array_map(
        static function (array $row): array {
            return [
                'exists' => !empty($row['exists']),
                'row_count' => (int) ($row['row_count'] ?? 0),
                'file' => (string) ($row['file'] ?? ''),
            ];
        },
        $tables
    ),
], \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;
