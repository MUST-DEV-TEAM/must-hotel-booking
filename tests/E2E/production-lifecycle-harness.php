<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    \http_response_code(403);
    echo 'CLI only.' . PHP_EOL;
    exit(1);
}

$wpLoad = null;
$dir = __DIR__;

for ($i = 0; $i < 8; $i++) {
    $candidate = $dir . DIRECTORY_SEPARATOR . 'wp-load.php';
    if (\is_file($candidate)) {
        $wpLoad = $candidate;
        break;
    }
    $parent = \dirname($dir);
    if ($parent === $dir) {
        break;
    }
    $dir = $parent;
}

if ($wpLoad === null) {
    \fwrite(STDERR, 'Unable to locate wp-load.php.' . PHP_EOL);
    exit(1);
}

require $wpLoad;

use MustHotelBooking\Core\MustBookingConfig;
use MustHotelBooking\Engine\PaymentEngine;
use MustHotelBooking\Provider\Clock\ClockConfig;

require_once __DIR__ . '/production-lifecycle-runner.php';

$args = \array_slice($argv, 1);
$allowExternalWrites = \in_array('--allow-external-writes', $args, true);
$correlationId = '';

foreach ($args as $arg) {
    if (\strpos($arg, '--correlation-id=') === 0) {
        $correlationId = \trim((string) \substr($arg, 17));
    }
}

if ($correlationId === '') {
    $correlationId = 'MHB-E2E-' . \gmdate('Ymd') . '-' . \substr(\bin2hex(\random_bytes(4)), 0, 8);
}

$pluginRoot = \dirname(__DIR__, 2);
$backupState = latestBackupState($pluginRoot);
$siteEnvironment = PaymentEngine::getActiveSiteEnvironment();
$stripeCredentials = PaymentEngine::getStripeEnvironmentCredentials($siteEnvironment);
$pokpayCredentials = PaymentEngine::getPokPayEnvironmentCredentials($siteEnvironment);
$pokpayApiEnvironment = PaymentEngine::getPokPayApiEnvironment($siteEnvironment);
$publicCallbackBase = MustBookingConfig::get_public_callback_base_url();
$stripeWebhookUrl = PaymentEngine::getStripeWebhookUrl();
$pokpayWebhookUrl = MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/pokpay/webhook');
$clockWebhookUrl = MustBookingConfig::build_public_rest_url('must-hotel-booking/v1/clock/webhook');
$clockBasicUsernameSet = ClockConfig::webhookBasicUsername() !== '';
$clockBasicPasswordSet = ClockConfig::webhookBasicPassword() !== '';
$clockBasicAuthIncomplete = $clockBasicUsernameSet !== $clockBasicPasswordSet;
$clockSnsTopicPinned = ClockConfig::webhookSnsTopicArn() !== '';

$checks = [];
$checks[] = check(
    'backup.current_database_tables',
    !empty($backupState['database_backup_present']),
    'BLOCKED',
    'Current ignored DB/options/table backup exists.',
    'Create a backup with tools/clock-e2e-backup.php before external writes.',
    $backupState
);
$checks[] = check(
    'backup.plugin_files',
    !empty($backupState['plugin_file_backup_present']),
    'BLOCKED',
    'Ignored plugin-file snapshot exists.',
    'Create an ignored plugin-file backup before external writes.',
    ['plugin_file_backup' => (string) ($backupState['plugin_file_backup'] ?? '')]
);
$checks[] = check(
    'environment.site_non_production',
    $siteEnvironment !== 'production',
    'FAIL',
    'Active site environment is non-production.',
    'Active site environment is production; external writes are not allowed.',
    ['active_site_environment' => $siteEnvironment]
);
$checks[] = check(
    'environment.clock_sandbox',
    ClockConfig::environment() === 'sandbox',
    'FAIL',
    'Clock is configured for sandbox.',
    'Clock is not configured for sandbox; external Clock writes are blocked.',
    ['clock_environment' => ClockConfig::environment()]
);
$checks[] = check(
    'environment.pokpay_staging',
    $pokpayApiEnvironment === 'staging',
    'FAIL',
    'PokPay API environment is staging.',
    'PokPay API environment is production; external PokPay writes are blocked.',
    ['pokpay_api_environment' => $pokpayApiEnvironment]
);
$checks[] = check(
    'environment.stripe_not_live',
    $siteEnvironment !== 'production' && !looksLiveSecret((string) ($stripeCredentials['secret_key'] ?? '')),
    'FAIL',
    'Stripe active credentials are not live-mode credentials by setting/key shape.',
    'Stripe active credentials look live or production-selected; external Stripe writes are blocked.',
    ['active_site_environment' => $siteEnvironment, 'secret_set' => ((string) ($stripeCredentials['secret_key'] ?? '') !== '')]
);
$checks[] = check(
    'credentials.stripe_configured',
    (string) ($stripeCredentials['publishable_key'] ?? '') !== '' && (string) ($stripeCredentials['secret_key'] ?? '') !== '',
    'BLOCKED',
    'Stripe test/staging credentials are configured.',
    'Stripe test/staging credentials are missing.',
    ['publishable_key_set' => ((string) ($stripeCredentials['publishable_key'] ?? '') !== ''), 'secret_key_set' => ((string) ($stripeCredentials['secret_key'] ?? '') !== '')]
);
$checks[] = check(
    'credentials.stripe_webhook_secret',
    (string) ($stripeCredentials['webhook_secret'] ?? '') !== '',
    'BLOCKED',
    'Stripe webhook signing secret is configured.',
    'Stripe webhook signing secret is missing; signed webhook E2E is blocked.',
    ['webhook_secret_set' => ((string) ($stripeCredentials['webhook_secret'] ?? '') !== '')]
);
$checks[] = check(
    'credentials.pokpay_configured',
    (string) ($pokpayCredentials['merchant_id'] ?? '') !== ''
        && (string) ($pokpayCredentials['key_id'] ?? '') !== ''
        && (string) ($pokpayCredentials['key_secret'] ?? '') !== '',
    'BLOCKED',
    'PokPay staging credentials are configured.',
    'PokPay staging credentials are missing.',
    [
        'merchant_id_set' => ((string) ($pokpayCredentials['merchant_id'] ?? '') !== ''),
        'key_id_set' => ((string) ($pokpayCredentials['key_id'] ?? '') !== ''),
        'key_secret_set' => ((string) ($pokpayCredentials['key_secret'] ?? '') !== ''),
    ]
);
$checks[] = check(
    'credentials.clock_configured',
    ClockConfig::isConfigured(),
    'BLOCKED',
    'Clock sandbox credentials are configured.',
    'Clock sandbox credentials are missing.',
    ['clock_configured' => ClockConfig::isConfigured()]
);
$checks[] = check(
    'credentials.clock_inbound_auth',
    !$clockBasicAuthIncomplete && (($clockBasicUsernameSet && $clockBasicPasswordSet) || $clockSnsTopicPinned),
    'BLOCKED',
    'Clock inbound requires Amazon SNS signatures plus a pinned Topic ARN or complete Basic authentication.',
    'Clock inbound is not source-bound; configure a pinned Topic ARN or both Basic authentication values.',
    [
        'sns_signature_verification' => true,
        'sns_topic_arn_pinned' => $clockSnsTopicPinned,
        'basic_auth_set' => $clockBasicUsernameSet && $clockBasicPasswordSet,
        'basic_auth_incomplete' => $clockBasicAuthIncomplete,
        'legacy_token_or_hmac_set' => ClockConfig::webhookSecret() !== '',
    ]
);
$checks[] = check(
    'callbacks.public_https_base',
    isPublicHttpsUrl($publicCallbackBase)
        && isPublicHttpsUrl($stripeWebhookUrl)
        && isPublicHttpsUrl($pokpayWebhookUrl)
        && isPublicHttpsUrl($clockWebhookUrl),
    'BLOCKED',
    'Provider callback and webhook URLs are public HTTPS URLs.',
    'Provider callback and webhook URLs are not public HTTPS URLs; external callback E2E is blocked.',
    [
        'public_callback_base_url_set' => $publicCallbackBase !== '',
        'stripe_webhook_public' => isPublicHttpsUrl($stripeWebhookUrl),
        'pokpay_webhook_public' => isPublicHttpsUrl($pokpayWebhookUrl),
        'clock_webhook_public' => isPublicHttpsUrl($clockWebhookUrl),
    ]
);
$checks[] = check(
    'clock.deposit_accounting_configured',
    ClockConfig::hasVerifiedDepositPaymentEndpoint(),
    'BLOCKED',
    'Clock deposit accounting endpoint configuration is available.',
    'Clock deposit accounting endpoint configuration is missing.',
    ['payment_posting_mode' => ClockConfig::paymentPostingMode()]
);

$baseWritePrerequisitesPassed = !hasStatus($checks, 'FAIL')
    && statusFor($checks, 'backup.current_database_tables') === 'PASS'
    && statusFor($checks, 'backup.plugin_files') === 'PASS'
    && statusFor($checks, 'environment.site_non_production') === 'PASS'
    && statusFor($checks, 'callbacks.public_https_base') === 'PASS';
$stripeWritePrerequisitesPassed = $baseWritePrerequisitesPassed
    && statusFor($checks, 'environment.stripe_not_live') === 'PASS'
    && statusFor($checks, 'credentials.stripe_configured') === 'PASS'
    && statusFor($checks, 'credentials.stripe_webhook_secret') === 'PASS';
$pokpayWritePrerequisitesPassed = $baseWritePrerequisitesPassed
    && statusFor($checks, 'environment.pokpay_staging') === 'PASS'
    && statusFor($checks, 'credentials.pokpay_configured') === 'PASS';
$clockOutboundWritePrerequisitesPassed = $baseWritePrerequisitesPassed
    && statusFor($checks, 'environment.clock_sandbox') === 'PASS'
    && statusFor($checks, 'credentials.clock_configured') === 'PASS'
    && statusFor($checks, 'clock.deposit_accounting_configured') === 'PASS';
$clockInboundPrerequisitesPassed = $clockOutboundWritePrerequisitesPassed
    && statusFor($checks, 'credentials.clock_inbound_auth') === 'PASS';
$lifecycle = [
    lifecycleStep('stripe.payment_lifecycle', $allowExternalWrites && $stripeWritePrerequisitesPassed, 'Stripe payment E2E waits for --allow-external-writes, Stripe test credentials, signed webhook support, and public HTTPS callbacks.'),
    lifecycleStep('stripe.refund_lifecycle', $allowExternalWrites && $stripeWritePrerequisitesPassed, 'Stripe refund E2E waits for a completed Stripe test payment and signed refund webhook support.'),
    lifecycleStep('pokpay.payment_lifecycle', $allowExternalWrites && $pokpayWritePrerequisitesPassed, 'PokPay staging payment E2E waits for --allow-external-writes, staging credentials, and public HTTPS callbacks; manual browser checkout may still be required.'),
    lifecycleStep('pokpay.refund_lifecycle', $allowExternalWrites && $pokpayWritePrerequisitesPassed, 'PokPay refund E2E waits for a paid staging SDK order and refund endpoint verification.'),
    lifecycleStep('clock.positive_accounting', $allowExternalWrites && $clockOutboundWritePrerequisitesPassed, 'Clock positive accounting E2E waits for --allow-external-writes, Clock sandbox credentials, and a test payment record.'),
    lifecycleStep('clock.negative_accounting', $allowExternalWrites && $clockOutboundWritePrerequisitesPassed, 'Clock negative accounting E2E waits for a completed provider refund and original posted payment folio.'),
    lifecycleStep('clock.inbound_webhook_replay', $allowExternalWrites && $clockInboundPrerequisitesPassed, 'Clock inbound webhook replay waits for --allow-external-writes, public HTTPS callbacks, Clock sandbox credentials, and SNS-compatible replay data. A legacy shared secret is not required for Clock PUSH/SNS.'),
    lifecycleStep('duplicates.webhooks_and_callbacks', $allowExternalWrites && $stripeWritePrerequisitesPassed && $pokpayWritePrerequisitesPassed, 'Duplicate Stripe/PokPay callback/webhook replay waits for provider-specific write readiness. Clock inbound duplicate replay is covered by the Clock inbound SNS replay tests.'),
];

$writeRun = [
    'external_writes_performed' => false,
    'scenarios' => [],
    'manual_actions' => [],
    'external_records_created' => [],
    'state_file' => '',
];

if ($allowExternalWrites && \function_exists('mhb_e2e_run_write_lifecycle')) {
    $writeRun = mhb_e2e_run_write_lifecycle(
        $correlationId,
        [
            'stripe_ready' => $stripeWritePrerequisitesPassed,
            'pokpay_ready' => $pokpayWritePrerequisitesPassed,
            'clock_outbound_ready' => $clockOutboundWritePrerequisitesPassed,
            'clock_inbound_ready' => $clockInboundPrerequisitesPassed,
            'stripe_webhook_url' => $stripeWebhookUrl,
            'pokpay_webhook_url' => $pokpayWebhookUrl,
        ]
    );
}

$overall = 'PASS';
if (hasStatus($checks, 'FAIL') || hasStatus($lifecycle, 'FAIL')) {
    $overall = 'FAIL';
} elseif (hasStatus($checks, 'BLOCKED') || hasStatus($lifecycle, 'BLOCKED')) {
    $overall = 'BLOCKED';
}

if (!empty($writeRun['scenarios']) && \is_array($writeRun['scenarios'])) {
    if (hasStatus($writeRun['scenarios'], 'FAIL')) {
        $overall = 'FAIL';
    } elseif (hasStatus($writeRun['scenarios'], 'BLOCKED') && $overall !== 'FAIL') {
        $overall = 'BLOCKED';
    } elseif ($overall === 'BLOCKED') {
        $onlyClockInboundBlocked = true;
        foreach ($checks as $checkRow) {
            if ((string) ($checkRow['status'] ?? '') === 'BLOCKED' && (string) ($checkRow['name'] ?? '') !== 'credentials.clock_inbound_auth') {
                $onlyClockInboundBlocked = false;
                break;
            }
        }
        if ($onlyClockInboundBlocked && !hasStatus($writeRun['scenarios'], 'BLOCKED')) {
            $overall = 'PASS';
        }
    }
}

$report = [
    'tool' => 'production-lifecycle-harness',
    'mode' => $allowExternalWrites ? 'write_requested' : 'read_only',
    'external_writes_performed' => !empty($writeRun['external_writes_performed']),
    'correlation_id' => $correlationId,
    'overall_status' => $overall,
    'generated_at_utc' => \gmdate('c'),
    'checks' => $checks,
    'lifecycle' => $lifecycle,
    'scenarios' => isset($writeRun['scenarios']) && \is_array($writeRun['scenarios']) ? $writeRun['scenarios'] : [],
    'manual_actions' => isset($writeRun['manual_actions']) && \is_array($writeRun['manual_actions']) ? $writeRun['manual_actions'] : [],
    'external_records_created' => isset($writeRun['external_records_created']) && \is_array($writeRun['external_records_created']) ? $writeRun['external_records_created'] : [],
    'state_file' => isset($writeRun['state_file']) ? (string) $writeRun['state_file'] : '',
];

echo \wp_json_encode($report, \JSON_PRETTY_PRINT | \JSON_UNESCAPED_SLASHES) . PHP_EOL;

exit($overall === 'FAIL' ? 1 : 0);

/**
 * @param array<string, mixed> $details
 * @return array<string, mixed>
 */
function check(string $name, bool $passed, string $blockedOrFailStatus, string $passMessage, string $blockedMessage, array $details = []): array
{
    return [
        'name' => $name,
        'status' => $passed ? 'PASS' : $blockedOrFailStatus,
        'message' => $passed ? $passMessage : $blockedMessage,
        'details' => $details,
    ];
}

/**
 * @return array<string, mixed>
 */
function lifecycleStep(string $name, bool $ready, string $blockedMessage): array
{
    return [
        'name' => $name,
        'status' => $ready ? 'READY' : 'BLOCKED',
        'message' => $ready ? 'Prerequisites passed; write-enabled implementation may run this step.' : $blockedMessage,
    ];
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function hasStatus(array $rows, string $status): bool
{
    foreach ($rows as $row) {
        if ((string) ($row['status'] ?? '') === $status) {
            return true;
        }
    }

    return false;
}

/**
 * @param array<int, array<string, mixed>> $rows
 */
function statusFor(array $rows, string $name): string
{
    foreach ($rows as $row) {
        if ((string) ($row['name'] ?? '') === $name) {
            return (string) ($row['status'] ?? '');
        }
    }

    return '';
}

function isPublicHttpsUrl(string $url): bool
{
    $parts = \wp_parse_url($url);
    $scheme = isset($parts['scheme']) ? \strtolower((string) $parts['scheme']) : '';
    $host = isset($parts['host']) ? \strtolower((string) $parts['host']) : '';

    if ($scheme !== 'https' || $host === '') {
        return false;
    }

    if (\in_array($host, ['localhost', '127.0.0.1', '::1'], true)) {
        return false;
    }

    if (\substr($host, -10) === '.localhost' || \substr($host, -6) === '.local' || \substr($host, -5) === '.test') {
        return false;
    }

    return true;
}

function looksLiveSecret(string $secret): bool
{
    return \strpos($secret, 'sk_live_') === 0 || \strpos($secret, 'rk_live_') === 0;
}

/**
 * @return array<string, mixed>
 */
function latestBackupState(string $pluginRoot): array
{
    $backupRoot = $pluginRoot . DIRECTORY_SEPARATOR . 'tools' . DIRECTORY_SEPARATOR . 'backups';
    $state = [
        'database_backup_present' => false,
        'database_backup' => '',
        'plugin_file_backup_present' => false,
        'plugin_file_backup' => '',
    ];

    if (!\is_dir($backupRoot)) {
        return $state;
    }

    $manifestFiles = \glob($backupRoot . DIRECTORY_SEPARATOR . 'clock-e2e-*' . DIRECTORY_SEPARATOR . 'manifest.json');
    if (\is_array($manifestFiles) && !empty($manifestFiles)) {
        \rsort($manifestFiles, \SORT_STRING);
        $latestManifest = (string) $manifestFiles[0];
        $decoded = \json_decode((string) \file_get_contents($latestManifest), true);
        $state['database_backup_present'] = \is_array($decoded)
            && !empty($decoded['gitignored'])
            && isset($decoded['tables'])
            && \is_array($decoded['tables']);
        $state['database_backup'] = makeRelativePath($pluginRoot, $latestManifest);
    }

    $pluginArchives = \glob($backupRoot . DIRECTORY_SEPARATOR . 'plugin-files-*.zip');
    if (\is_array($pluginArchives) && !empty($pluginArchives)) {
        \rsort($pluginArchives, \SORT_STRING);
        $latestArchive = (string) $pluginArchives[0];
        $state['plugin_file_backup_present'] = \is_file($latestArchive) && \filesize($latestArchive) > 0;
        $state['plugin_file_backup'] = makeRelativePath($pluginRoot, $latestArchive);
    }

    return $state;
}

function makeRelativePath(string $root, string $path): string
{
    $root = \rtrim(\str_replace('\\', '/', $root), '/') . '/';
    $path = \str_replace('\\', '/', $path);

    return \strpos($path, $root) === 0 ? \substr($path, \strlen($root)) : $path;
}
