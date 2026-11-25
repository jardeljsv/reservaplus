<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Reservaplus\Booking;
use GlpiPlugin\Reservaplus\BookingService;
use GlpiPlugin\Reservaplus\CalendarBridge;

rp_test('strict dates reject overflow and inverted intervals', static function (): void {
    rp_same(['2026-10-28 09:30:00', '2026-10-28 10:30:00'], Booking::period('2026-10-28T09:30', '2026-10-28T10:30'));
    rp_throws(static fn() => Booking::date('2026-02-30 10:00:00'), InvalidArgumentException::class);
    rp_throws(static fn() => Booking::period('2026-10-28T10:00', '2026-10-28T10:00'), InvalidArgumentException::class);
    rp_throws(static fn() => Booking::date('tomorrow noon'), InvalidArgumentException::class);
});

rp_test('half-open intervals permit consecutive bookings', static function (): void {
    rp_assert(!Booking::overlaps('2026-10-28 09:00:00', '2026-10-28 10:00:00', '2026-10-28 10:00:00', '2026-10-28 11:00:00'));
    rp_assert(Booking::overlaps('2026-10-28 09:00:00', '2026-10-28 10:00:01', '2026-10-28 10:00:00', '2026-10-28 11:00:00'));
});

rp_test('availability buffers apply before and after the full loan', static function (): void {
    rp_same(['2026-10-28 08:45:00', '2026-10-29 10:30:00'], CalendarBridge::interval(
        ['policy_json' => '{"buffer_before_minutes":15,"buffer_after_minutes":30}'], '2026-10-28 09:00:00', '2026-10-29 10:00:00'
    ));
});

rp_test('partial accessory return does not silently complete remaining items', static function (): void {
    $issued = [['name' => 'Carregador', 'issued' => 1, 'received' => 0], ['name' => 'Cabo', 'issued' => 2, 'received' => 0]];
    $first = Booking::receiveAccessories($issued, [1 => 1]);
    rp_same(0, $first[0]['received']); rp_same(1, $first[1]['received']);
    rp_assert(!Booking::accessoriesComplete($first));
    $final = Booking::receiveAccessories($first, [0 => 1, 1 => 1]);
    rp_assert(Booking::accessoriesComplete($final));
    rp_throws(static fn() => Booking::receiveAccessories($final, [0 => 1]), InvalidArgumentException::class);
    rp_throws(static fn() => Booking::receiveAccessories($issued, [0 => -1]), InvalidArgumentException::class);
    rp_throws(static fn() => Booking::receiveAccessories($issued, [3 => 1]), InvalidArgumentException::class);
    rp_throws(static fn() => Booking::receiveAccessories($issued, [0 => 0.5]), InvalidArgumentException::class);
});

rp_test('signature requirements combine mandatory resource stages', static function (): void {
    $line = static fn(string $stage): array => ['snapshot_json' => json_encode(['resource' => ['policy_json' => json_encode(['signature_stage' => $stage])]])];
    rp_same(['request', 'handover'], BookingService::signaturePurposes([$line('submission'), $line('approval'), $line('handover'), $line('none')]));
    rp_same(['handover'], BookingService::signaturePurposes([$line('approval')]));
    rp_same([], BookingService::signaturePurposes([$line('none')]));
});

rp_test('new equipment uses unified signed return while historic workflows remain frozen', static function (): void {
    rp_assert(BookingService::unifiedReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'unified_return_v1']),
    ]));
    rp_assert(!BookingService::threeTermReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'unified_return_v1']),
    ]));
    rp_assert(!BookingService::unifiedReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'three_terms_v2']),
    ]));
    rp_assert(BookingService::threeTermReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'three_terms_v2']),
    ]));
    rp_assert(!BookingService::simplifiedReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'three_terms_v2']),
    ]));
    rp_assert(!BookingService::threeTermReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'two_terms_v1']),
    ]));

    rp_assert(BookingService::simplifiedReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'two_terms_v1']),
    ]));
    rp_assert(!BookingService::simplifiedReturn(['policy_snapshot_json' => null]));
    rp_assert(!BookingService::simplifiedReturn([
        'policy_snapshot_json' => json_encode(['return_workflow' => 'legacy']),
    ]));
});

rp_test('early delivery grace is bounded and never changes the approved period', static function (): void {
    $begin = '2026-11-17 17:00:00';
    $end = '2026-11-17 18:00:00';
    rp_assert(BookingService::canIssueAt($begin, $end, '2026-11-17 16:45:00'));
    rp_assert(BookingService::canIssueAt($begin, $end, '2026-11-17 16:59:59'));
    rp_assert(BookingService::canIssueAt($begin, $end, '2026-11-17 17:00:00'));
    rp_assert(!BookingService::canIssueAt($begin, $end, '2026-11-17 16:44:59'));
    rp_assert(!BookingService::canIssueAt($begin, $end, '2026-11-17 18:00:00'));
    rp_assert(!BookingService::canIssueAt($begin, $end, '2026-11-17 16:30:00', 30));
});

rp_test('command retry ignores fresh CSRF tokens but binds material receipt facts', static function (): void {
    $facts = ['expected_revision' => 3, 'lines_ids' => [8], 'received_at' => '2026-10-28 10:00:00', 'accessories' => [8 => [0 => 1]]];
    rp_same(BookingService::commandFingerprint(4, 'receive', $facts + ['_glpi_csrf_token' => 'first']), BookingService::commandFingerprint(4, 'receive', $facts + ['_glpi_csrf_token' => 'second']));
    rp_assert(BookingService::commandFingerprint(4, 'receive', $facts) !== BookingService::commandFingerprint(4, 'receive', array_replace($facts, ['received_at' => '2026-10-28 11:00:00'])));
});

rp_test('native raw CRUD and collection search cannot bypass aggregate workflow', static function (): void {
    foreach (['canView', 'canCreate', 'canUpdate', 'canDelete', 'canPurge'] as $method) { rp_same(false, Booking::$method()); }
    $booking = new Booking();
    rp_same(false, $booking->prepareInputForAdd(['status' => 'approved']));
    rp_same(false, $booking->prepareInputForUpdate(['status' => 'completed']));
    rp_same(false, $booking->pre_deleteItem()); rp_same(false, $booking->pre_purgeItem());
});
