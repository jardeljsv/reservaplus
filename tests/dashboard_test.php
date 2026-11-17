<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';
use GlpiPlugin\Reservaplus\TechnicianDashboard;
use GlpiPlugin\Reservaplus\Store;

$now = '2026-10-28 12:00:00';
$booking = ['status' => 'issued', 'begin' => '2026-10-28 08:00:00', 'end' => '2026-10-28 11:00:00'];
$equipment = ['state' => 'issued', 'received_at' => null, 'inspection_at' => null, 'snapshot_json' => Store::json(['resource' => ['kind' => 'equipment']])];
$room = ['state' => 'allocated', 'received_at' => null, 'inspection_at' => null, 'snapshot_json' => Store::json(['resource' => ['kind' => 'room']])];

rp_test('main-item lateness stops at receipt while outstanding accessories retain their own overdue state', static function () use ($booking, $equipment, $now): void {
    $flags = TechnicianDashboard::physicalFlags($booking, [$equipment], $now);
    rp_assert($flags['loans'] && $flags['overdue']);
    $received = array_replace($equipment, ['state' => 'received', 'received_at' => '2026-10-28 11:30:00', 'accessories_json' => '[{"name":"Charger","issued":1,"received":0}]']);
    $flags = TechnicianDashboard::physicalFlags($booking, [$received], $now);
    rp_assert($flags['loans'] && $flags['overdue'] && !$flags['overdue_items'] && $flags['overdue_accessories'] && $flags['inspection']);
    $received['accessories_json'] = '[{"name":"Charger","issued":1,"received":1}]';
    $flags = TechnicianDashboard::physicalFlags($booking, [$received], $now);
    rp_assert(!$flags['loans'] && !$flags['overdue'] && $flags['inspection']);
});
rp_test('rooms enter inspection after use without inventing equipment custody', static function () use ($booking, $room, $now): void {
    $flags = TechnicianDashboard::physicalFlags($booking, [$room], $now);
    rp_assert(!$flags['overdue'] && !$flags['loans'] && $flags['inspection']);
    $flags = TechnicianDashboard::physicalFlags(array_replace($booking, ['end' => '2026-10-28 13:00:00']), [$room], $now);
    rp_assert($flags['loans'] && !$flags['inspection'] && !$flags['overdue']);
});
rp_test('partial multi-resource returns preserve the remaining custody queue', static function () use ($booking, $equipment, $now): void {
    $received = array_replace($equipment, ['state' => 'received', 'received_at' => $now]);
    $flags = TechnicianDashboard::physicalFlags($booking, [$equipment, $received], $now);
    rp_assert($flags['loans'] && $flags['overdue'] && $flags['inspection']);
});
rp_test('closed bookings cannot reenter physical action queues', static function () use ($booking, $equipment, $now): void {
    foreach (['completed', 'cancelled', 'rejected'] as $status) {
        $flags = TechnicianDashboard::physicalFlags(array_replace($booking, ['status' => $status]), [$equipment], $now);
        rp_assert(!array_filter($flags));
    }
});
rp_test('inspection completion requires every resource line to have a recorded inspection', static function () use ($booking, $equipment, $now): void {
    $ready = array_replace($equipment, ['state' => 'ready', 'inspection_at' => $now, 'received_at' => $now]);
    rp_assert(TechnicianDashboard::physicalFlags($booking, [$ready], $now)['all_inspected']);
    rp_assert(!TechnicianDashboard::physicalFlags($booking, [$ready, $equipment], $now)['all_inspected']);
    rp_assert(!TechnicianDashboard::physicalFlags($booking, [], $now)['all_inspected']);
});

if (!class_exists('Session', false)) {
    class Session
    {
        public static int $user = 7;
        public static array $rights = [];
        public static function getLoginUserID(): int { return self::$user; }
        public static function haveRight(string $name, int $mask): bool { return ((self::$rights[$name] ?? 0) & $mask) === $mask; }
    }
}
rp_test('ordinary requester cannot gain technician queue visibility from owning a booking', static function () use ($equipment, $now): void {
    Session::$rights = [\GlpiPlugin\Reservaplus\Access::RIGHT => READ | CREATE];
    $_SESSION = ['glpiactiveentities' => [1], 'glpigroups' => []];
    $own = ['id' => 7, 'entities_id' => 1, 'users_id_requester' => 7, 'users_id_borrower' => 7,
        'status' => 'issued', 'begin' => '2026-10-28 08:00:00', 'end' => '2026-10-28 11:00:00', 'lines' => [$equipment]];
    rp_assert(!TechnicianDashboard::isStaff());
    foreach (['approvals', 'ready', 'loans', 'overdue', 'inspection', 'integration'] as $queue) {
        rp_assert(!TechnicianDashboard::matchesQueue($own, $queue), 'Requester saw team queue '.$queue);
    }
    rp_assert(!TechnicianDashboard::belongsToTeam($own));
});
