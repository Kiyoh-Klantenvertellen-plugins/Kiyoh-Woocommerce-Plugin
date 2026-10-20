<?php
/**
 * Schedule rules for the optional Kiyoh invitation send time.
 *
 * Run from the repo root:
 *   php tests/invitation-schedule-test.php
 */

require_once __DIR__ . '/../kiyoh-woocommerce-plugin/kiyoh-woocommerce/includes/managers/class-invitation-schedule.php';

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

function at($local, $timezone) {
    $dt = DateTimeImmutable::createFromFormat('Y-m-d H:i:s', $local, new DateTimeZone($timezone));
    if (!$dt) {
        throw new RuntimeException('Bad fixture datetime ' . $local);
    }
    return $dt;
}

$ams = new DateTimeZone('Europe/Amsterdam');

// Monday 19 Oct 2026 15:00 + 3 days = Thursday 22 Oct 15:00, so 10:00 is the next day.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-19 15:00:00', 'Europe/Amsterdam'),
    3,
    '10:00',
    array(),
    $ams
);
expect_same('2026-10-23 10:00', $next->format('Y-m-d H:i'), 'delay pushes past the same-day send time');

// Monday 09:00 + 3 days = Thursday 09:00, so Thursday 10:00 is still after the delay.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-19 09:00:00', 'Europe/Amsterdam'),
    3,
    '10:00',
    array(),
    $ams
);
expect_same('2026-10-22 10:00', $next->format('Y-m-d H:i'), 'send time later on the delay day is used');

// Exact boundary: delay elapsed at the preferred time, so send then (not a day later).
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-19 10:00:00', 'Europe/Amsterdam'),
    3,
    '10:00',
    array(),
    $ams
);
expect_same('2026-10-22 10:00', $next->format('Y-m-d H:i'), 'exact delay boundary keeps that clock time');

// One second past the boundary waits until the next day.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-19 10:00:01', 'Europe/Amsterdam'),
    3,
    '10:00',
    array(),
    $ams
);
expect_same('2026-10-23 10:00', $next->format('Y-m-d H:i'), 'one second past the boundary moves to the next day');

// Delay 0, still before today's send time.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-19 09:00:00', 'Europe/Amsterdam'),
    0,
    '10:00',
    array(),
    $ams
);
expect_same('2026-10-19 10:00', $next->format('Y-m-d H:i'), 'delay 0 sends later the same day');

// Delay 0, today's send time already passed.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-19 15:00:00', 'Europe/Amsterdam'),
    0,
    '10:00',
    array(),
    $ams
);
expect_same('2026-10-20 10:00', $next->format('Y-m-d H:i'), 'delay 0 after today\'s time uses tomorrow');

// Friday 16:00, weekdays Mon–Fri, 09:00 → next Monday.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-23 16:00:00', 'Europe/Amsterdam'),
    0,
    '09:00',
    array(1, 2, 3, 4, 5),
    $ams
);
expect_same('2026-10-26 09:00', $next->format('Y-m-d H:i'), 'weekend is skipped when only weekdays are allowed');

// Friday morning, Friday itself is allowed.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-23 08:00:00', 'Europe/Amsterdam'),
    0,
    '09:00',
    array(5),
    $ams
);
expect_same('2026-10-23 09:00', $next->format('Y-m-d H:i'), 'allowed weekday later the same day');

// Only Monday, but Monday's time is already before the earliest moment → next Monday.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-10-18 12:00:00', 'Europe/Amsterdam'),
    1,
    '09:00',
    array(1),
    $ams
);
expect_same('2026-10-26 09:00', $next->format('Y-m-d H:i'), 'single weekday wraps to the following week');

// Empty and invalid weekdays mean every day.
expect_same(array(1, 2, 3, 4, 5, 6, 7), Kiyoh_Invitation_Schedule::normalize_weekdays(array()), 'empty weekdays mean every day');
expect_same(array(1, 2, 3, 4, 5, 6, 7), Kiyoh_Invitation_Schedule::normalize_weekdays(array(0, 8, 'nope')), 'invalid weekdays fall back to every day');
expect_same(array(1, 3), Kiyoh_Invitation_Schedule::sanitize_weekdays(array('3', 1, 1, 9)), 'stored weekdays drop invalid values and duplicates');

// DST spring forward (29 Mar 2026). Wall-clock time stays 09:00.
$next = Kiyoh_Invitation_Schedule::next_send_at(
    at('2026-03-28 08:00:00', 'Europe/Amsterdam'),
    1,
    '09:00',
    array(),
    $ams
);
expect_same('2026-03-29 09:00', $next->format('Y-m-d H:i'), 'DST spring forward keeps the wall-clock send time');
expect_same('+02:00', $next->format('P'), 'DST spring forward uses CEST');

// Input from UTC still schedules in the shop timezone.
$from_utc = new DateTimeImmutable('2026-10-19 13:00:00', new DateTimeZone('UTC')); // 15:00 Amsterdam
$next = Kiyoh_Invitation_Schedule::next_send_at($from_utc, 0, '16:00', array(), $ams);
expect_same('2026-10-19 16:00', $next->format('Y-m-d H:i'), 'UTC input is converted to the shop timezone');

// Seconds in the time string are ignored; invalid times are rejected.
expect_same('09:05', Kiyoh_Invitation_Schedule::normalize_send_time('09:05:30'), 'seconds are stripped');
expect_same('', Kiyoh_Invitation_Schedule::normalize_send_time(''), 'empty time stays empty');
expect_same('', Kiyoh_Invitation_Schedule::normalize_send_time('24:00'), '24:00 is rejected');
expect_same('', Kiyoh_Invitation_Schedule::normalize_send_time('9:00'), 'unpadded hour is rejected');
expect_false(Kiyoh_Invitation_Schedule::is_valid_time('10:60'), 'invalid minutes are rejected');

expect_false(Kiyoh_Invitation_Schedule::is_schedule_mode_active(array()), 'missing settings stay on the legacy path');
expect_false(Kiyoh_Invitation_Schedule::is_schedule_mode_active(array(
    'send_time_enabled' => false,
    'send_time' => '09:00',
)), 'disabled setting stays on the legacy path');
expect_false(Kiyoh_Invitation_Schedule::is_schedule_mode_active(array(
    'send_time_enabled' => true,
    'send_time' => '',
)), 'enabled with an empty time stays on the legacy path');
expect_false(Kiyoh_Invitation_Schedule::is_schedule_mode_active(array(
    'send_time_enabled' => '1',
    'send_time' => '25:01',
)), 'enabled with an invalid time stays on the legacy path');
expect_true(Kiyoh_Invitation_Schedule::is_schedule_mode_active(array(
    'send_time_enabled' => '1',
    'send_time' => '09:00',
)), 'enabled with HH:MM uses scheduling');

$threw = false;
try {
    Kiyoh_Invitation_Schedule::next_send_at(
        at('2026-10-19 09:00:00', 'Europe/Amsterdam'),
        0,
        'bad',
        array(),
        $ams
    );
} catch (InvalidArgumentException $e) {
    $threw = true;
}
expect_true($threw, 'invalid send time throws instead of guessing');

if ($failures > 0) {
    fwrite(STDERR, "\n{$failures} failed.\n");
    exit(1);
}

echo "\nAll invitation schedule tests passed.\n";
exit(0);
