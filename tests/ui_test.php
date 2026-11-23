<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (!class_exists('Session', false)) {
    class Session
    {
        public static int $user = 7;
        public static function getLoginUserID(): int { return self::$user; }
    }
}

use GlpiPlugin\Reservaplus\BookingView;

rp_test('workflow commands bind action, resource context, user and expiry while allowing retries', function (): void {
    $_SESSION = [];
    Session::$user = 7;
    $key = BookingView::command('receive', 42);
    BookingView::checkCommand($key, 'receive', 42);
    BookingView::checkCommand($key, 'receive', 42);
    rp_throws(fn() => BookingView::checkCommand($key, 'issue', 42), DomainException::class);
    rp_throws(fn() => BookingView::checkCommand($key, 'receive', 43), DomainException::class);
    Session::$user = 8;
    rp_throws(fn() => BookingView::checkCommand($key, 'receive', 42), DomainException::class);
    Session::$user = 7;
    $_SESSION['reservaplus_commands'][$key]['expires'] = time() - 1;
    rp_throws(fn() => BookingView::checkCommand($key, 'receive', 42), DomainException::class);
});

rp_test('untrusted field values stay escaped and repeated fields retain unique label targets', function (): void {
    $attack = '\"><script>alert(1)</script>';
    ob_start();
    BookingView::input('begin', 'Início', $attack);
    BookingView::input('begin', 'Início', $attack);
    BookingView::textarea('purpose', 'Finalidade', '</textarea><script>evil()</script>');
    $html = ob_get_clean();
    rp_assert(!str_contains($html, '<script>'));
    preg_match_all('/\bid="([^"]+)"/', $html, $ids);
    rp_same(count($ids[1]), count(array_unique($ids[1])));
    foreach ($ids[1] as $id) { rp_assert(str_contains($html, 'for="' . $id . '"')); }
    rp_same('', BookingView::e(['malformed' => 'query']));
});

rp_test('approved operational bookings show delivery shortcut only with allocated items and issue rights', function (): void {
    $approved = ['id' => 21, 'status' => 'approved'];
    $allocated = [['state' => 'allocated', 'resources_id' => 15]];
    rp_assert(BookingView::issueListShortcutEligible($approved, $allocated, true));
    rp_assert(!BookingView::issueListShortcutEligible($approved, $allocated, false));
    rp_assert(!BookingView::issueListShortcutEligible(['status' => 'submitted'], $allocated, true));
    rp_assert(!BookingView::issueListShortcutEligible(['status' => 'issued'], $allocated, true));
    rp_assert(!BookingView::issueListShortcutEligible($approved, [], true));
    rp_assert(!BookingView::issueListShortcutEligible($approved, [['state' => 'requested']], true));
    rp_assert(!BookingView::issueListShortcutEligible($approved,
        [['state' => 'allocated'], ['state' => 'issued']], true));
});

rp_test('return shortcuts only offer real physical receipt and subsequent inspection', function (): void {
    $issued = ['state' => 'issued', 'received_at' => null, 'accessories_json' => '[]'];
    rp_assert(BookingView::returnReceiptLineEligible($issued));
    rp_assert(!BookingView::inspectionLineEligible($issued, false));
    $received = ['state' => 'received', 'received_at' => '2026-11-23 16:00:00',
        'accessories_json' => json_encode([['name' => 'Fonte', 'issued' => 1, 'received' => 0]])];
    rp_assert(BookingView::returnReceiptLineEligible($received));
    rp_assert(BookingView::inspectionLineEligible($received, false));
    $received['accessories_json'] = json_encode([['name' => 'Fonte', 'issued' => 1, 'received' => 1]]);
    rp_assert(!BookingView::returnReceiptLineEligible($received));
    rp_assert(BookingView::inspectionLineEligible($received, false));
    foreach (['quarantine', 'maintenance'] as $state) {
        $received['state'] = $state;
        rp_assert(!BookingView::returnReceiptLineEligible($received));
        rp_assert(BookingView::inspectionLineEligible($received, false));
    }
    rp_assert(!BookingView::inspectionLineEligible(['state' => 'received', 'received_at' => null], false));
    rp_assert(!BookingView::returnReceiptLineEligible(['state' => 'ready']));
    rp_assert(!BookingView::inspectionLineEligible(['state' => 'ready'], true));
    rp_assert(BookingView::inspectionLineEligible(['state' => 'allocated'], true));
    rp_assert(BookingView::inspectionLineEligible(['state' => 'issued'], true));
    rp_assert(!BookingView::inspectionLineEligible(['state' => 'allocated'], false));
});

rp_test('return list differentiates physical receipt, signing and PDF finalization', function (): void {
    $booking = ['id' => 22, 'status' => 'returning'];
    $lines = [['received_at' => '2026-11-23 18:15:00', 'inspection_at' => null]];
    $alerts = BookingView::returnTermAlertsForList($booking, $lines, []);
    rp_same(1, count($alerts));
    rp_same('return_receipt', $alerts[0]['purpose']);
    rp_assert(str_contains($alerts[0]['label'], 'emissão'));
    $docs = [
        ['id' => 25, 'purpose' => 'return_receipt', 'status' => 'partially_signed', 'is_current' => true, 'can_sign' => true],
    ];
    $alerts = BookingView::returnTermAlertsForList($booking, $lines, $docs);
    rp_same(25, $alerts[0]['document_id']);
    rp_assert($alerts[0]['can_sign']);
    rp_assert(str_contains($alerts[0]['label'], 'assinatura'));
    $docs[0]['status'] = 'complete';
    rp_same([], BookingView::returnTermAlertsForList($booking, $lines, $docs));
    $lines[0]['inspection_at'] = '2026-11-23 18:20:00';
    $docs[] = ['id' => 26, 'purpose' => 'return_inspection', 'status' => 'prepared', 'is_current' => true, 'can_sign' => false];
    $alerts = BookingView::returnTermAlertsForList($booking, $lines, $docs);
    rp_same(1, count($alerts));
    rp_same('return_inspection', $alerts[0]['purpose']);
    rp_assert(str_contains($alerts[0]['label'], 'assinatura'));
    rp_assert(!$alerts[0]['can_sign']);
    $docs[1]['status'] = 'artifact_error';
    $alerts = BookingView::returnTermAlertsForList($booking, $lines, $docs);
    rp_assert(str_contains($alerts[0]['label'], 'finalização'));
    $docs[1]['status'] = 'complete';
    rp_same([], BookingView::returnTermAlertsForList($booking, $lines, $docs));
    rp_same([], BookingView::returnTermAlertsForList(['status' => 'completed'], $lines, $docs));
});

rp_test('new simplified returns only flag receipt signing, never a third inspection document', function (): void {
    $booking = ['id' => 34, 'status' => 'returning',
        'policy_snapshot_json' => json_encode(['return_workflow' => 'two_terms_v1'])];
    $lines = [[
        'received_at' => '2026-11-23 18:10:00',
        'inspection_at' => '2026-11-23 18:35:00',
    ]];
    $docs = [['id' => 40, 'purpose' => 'return_receipt', 'status' => 'prepared',
        'is_current' => true, 'can_sign' => true]];
    $alerts = BookingView::returnTermAlertsForList($booking, $lines, $docs);
    rp_same(1, count($alerts));
    rp_same('return_receipt', $alerts[0]['purpose']);
    rp_assert(str_contains($alerts[0]['label'], 'termo de devolução'));
    $docs[0]['status'] = 'complete';
    rp_same([], BookingView::returnTermAlertsForList($booking, $lines, $docs));
});

rp_test('workflow links honor GLPI subdirectory and errors do not reveal runtime internals', function (): void {
    global $CFG_GLPI;
    $CFG_GLPI['root_doc'] = '/support';
    rp_same('/support/plugins/reservaplus/front/booking.php?id=7', BookingView::url('booking.php', ['id' => 7]));
    rp_same('/support/front/ticket.form.php?id=8', BookingView::ticketUrl(8));
    rp_same('Escolha outro período.', BookingView::publicError(new DomainException('Escolha outro período.')));
    rp_assert(!str_contains(BookingView::publicError(new RuntimeException('SQL failure /private/config.php')), '/private'));
});
