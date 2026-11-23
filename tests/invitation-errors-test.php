<?php
/**
 * Invitation error mapping and retry rules.
 *
 * Run from the repo root:
 *   php tests/invitation-errors-test.php
 */

if (!function_exists('__')) {
    function __($text, $domain = 'default') {
        return $text;
    }
}

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/class-kiyoh-invitation-errors.php';

$failures = 0;

function expect_same($expected, $actual, $message) {
    global $failures;
    if ($expected !== $actual) {
        $failures++;
        fwrite(STDERR, "FAIL: {$message}\n  expected " . var_export($expected, true) . "\n  actual   " . var_export($actual, true) . "\n");
        return;
    }
    echo "OK: {$message}\n";
}

function expect_true($actual, $message) {
    expect_same(true, $actual, $message);
}

function expect_false($actual, $message) {
    expect_same(false, $actual, $message);
}

expect_true(Kiyoh_Invitation_Errors::is_permanent('MAIL_LIMIT_EXCEED'), 'MAIL_LIMIT_EXCEED is permanent');
expect_false(Kiyoh_Invitation_Errors::is_permanent('http_error'), 'http_error is not permanent');
expect_true(Kiyoh_Invitation_Errors::should_retry('http_error', 503), '503 is retryable');
expect_false(Kiyoh_Invitation_Errors::should_retry('MAIL_LIMIT_EXCEED', 400), 'mail limit is not retryable');
expect_same(900, Kiyoh_Invitation_Errors::retry_delay_seconds(0), 'first retry waits 15 minutes');

$now = 1_700_000_000;
$resolved = Kiyoh_Invitation_Errors::resolve_failure_status('http_error', 503, 1, $now);
expect_same('error', $resolved['status'], 'first 503 failure stays in error for cron retry');
expect_same($now + 900, $resolved['retry_at'], 'first failure schedules 15 minute backoff');

$final = Kiyoh_Invitation_Errors::resolve_failure_status('http_error', 503, 3, $now);
expect_same('failed_final', $final['status'], 'third failure is final');

$limit = Kiyoh_Invitation_Errors::resolve_failure_status('MAIL_LIMIT_EXCEED', 400, 1, $now);
expect_same('failed_final', $limit['status'], 'mail limit fails permanently on first try');

$message = Kiyoh_Invitation_Errors::merchant_message('MAIL_LIMIT_EXCEED');
expect_true(strpos($message, 'email invitation limit') !== false, 'mail limit merchant message is descriptive');
expect_same('', Kiyoh_Invitation_Errors::merchant_message('UNKNOWN_CODE'), 'unknown codes have no merchant override');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} failed.\n");
    exit(1);
}

echo "\nAll invitation error tests passed.\n";
exit(0);