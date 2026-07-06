<?php
declare(strict_types=1);

if (\PHP_SAPI !== 'cli') {
    exit(1);
}

$root = \dirname(__DIR__);
$failures = [];

$assert = static function (bool $condition, string $message) use (&$failures): void {
    if (!$condition) {
        $failures[] = $message;
    }
};

$paymentRegistry = (string) \file_get_contents($root . '/src/Core/PaymentMethodRegistry.php');
$mustConfig = (string) \file_get_contents($root . '/src/Core/MustBookingConfig.php');
$paymentEngine = (string) \file_get_contents($root . '/src/Engine/PaymentEngine.php');
$confirmationPage = (string) \file_get_contents($root . '/src/Frontend/confirmation-page.php');
$bookingTemplate = (string) \file_get_contents($root . '/frontend/templates/booking-confirmation.php');
$paymentAdminActions = (string) \file_get_contents($root . '/src/Admin/PaymentAdminActions.php');
$settingsPage = (string) \file_get_contents($root . '/src/Admin/SettingsPage.php');
$settingsDiagnostics = (string) \file_get_contents($root . '/src/Admin/SettingsDiagnostics.php');
$supportDiagnostics = (string) \file_get_contents($root . '/src/Core/SupportDiagnosticsEndpoint.php');

$assert(\strpos($paymentRegistry, "'pay_at_hotel' => false") !== false, 'Pay at hotel must be disabled in payment method registry defaults.');
$assert(\strpos($mustConfig, "'pay_at_hotel_enabled' => false") !== false, 'Settings defaults must include pay_at_hotel_enabled=false.');
$assert(\strpos($mustConfig, "'default_payment_mode' => 'pokpay'") !== false, 'Fresh settings must default public checkout to PokPay.');
$assert(\strpos($mustConfig, "'payment_methods' => ['pay_at_hotel' => false, 'stripe' => false, 'pokpay' => true]") !== false, 'Fresh payment methods must enable PokPay without enabling Pay at hotel.');

$assert(\strpos($paymentEngine, 'getPublicCheckoutPaymentPolicy') !== false, 'PaymentEngine must expose public checkout payment policy diagnostics.');
$assert(\strpos($paymentEngine, 'validatePublicCheckoutPaymentMethod') !== false, 'PaymentEngine must validate public checkout payment methods before reservation creation.');
$assert(\strpos($paymentEngine, "?: 'pay_at_hotel'") === false, 'PaymentEngine must never use pay_at_hotel as an empty-method fallback.');
$assert(\strpos($paymentEngine, "empty(\$options)") === false || \strpos($paymentEngine, "\$options['pay_at_hotel']") === false, 'Checkout methods must not inject Pay at hotel when no online method is available.');

$validatorPosition = \strpos($confirmationPage, 'validatePublicCheckoutPaymentMethod');
$createPosition = \strpos($confirmationPage, 'create_checkout_reservations');
$assert($validatorPosition !== false, 'Confirmation POST path must call the public payment validator.');
$assert($createPosition !== false && $validatorPosition !== false && $validatorPosition < $createPosition, 'Public payment validation must run before any reservation/provider creation.');
$assert(\strpos($confirmationPage, 'clearInvalidPublicPaymentMethodDraft') !== false, 'Old public checkout drafts using disabled Pay at hotel must be cleared or invalidated.');

$assert(\strpos($bookingTemplate, '$payment_methods as $payment_method_key') !== false, 'Booking confirmation template should render only methods supplied by PaymentEngine.');

$assert(\strpos($paymentAdminActions, 'pay_at_hotel_enabled') !== false, 'Payments settings save must persist the explicit Pay at hotel toggle.');
$assert(\strpos($paymentAdminActions, 'normalizeDefaultPaymentMode') !== false, 'Payments settings save must normalize invalid default payment mode after method toggles change.');
$assert(\strpos($settingsPage, 'pay_at_hotel_enabled') !== false, 'Settings UI must expose Pay at hotel enablement state.');
$assert(\strpos($settingsPage, 'Pay at hotel allows confirmed bookings without online payment') !== false, 'Admin UI must warn before enabling Pay at hotel.');
$assert(\strpos($settingsPage, 'PaymentEngine::normalizeDefaultPaymentMode') !== false, 'Settings save must reject or switch disabled Pay at hotel defaults.');

foreach ([
    'default_payment_mode',
    'pay_at_hotel_enabled',
    'public_checkout_payment_policy',
    'enabled_online_methods',
    'public_offline_payment_allowed',
    'backend_rejects_disabled_pay_at_hotel',
    'last_public_booking_payment_method',
    'last_public_booking_payment_status',
] as $diagnosticKey) {
    $assert(\strpos($settingsDiagnostics, $diagnosticKey) !== false, 'Settings diagnostics must expose ' . $diagnosticKey . '.');
}

$assert(\strpos($supportDiagnostics, 'payment_policy') !== false, 'Support diagnostics production readiness must include payment policy blocking checks.');
$assert(\strpos($supportDiagnostics, 'public_booking_can_confirm_unpaid') !== false, 'Production readiness must block public confirmed/unpaid payment risk.');

if ($failures) {
    echo "FAIL\n" . \implode("\n", $failures) . "\n";
    exit(1);
}

echo "Payment public checkout policy tests passed.\n";
