<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (!class_exists('Session', false)) {
    class Session
    {
        public static int $user = 7;
        public static array $rights = [];
        public static function getLoginUserID(): int { return self::$user; }
        public static function haveRight(string $name, int $mask): bool { return ((self::$rights[$name] ?? 0) & $mask) === $mask; }
    }
}
if (!class_exists('Ticket', false)) {
    class Ticket
    {
        public static array $readable = [44];
        public function getFromDB(int $id): bool { return $id === 44; }
        public function can(int $id, int $right): bool { return $right === READ && in_array($id, self::$readable, true); }
    }
}

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\BookingView;
use GlpiPlugin\Reservaplus\TicketTab;

function ticket_context_fixture(): array
{
    Session::$user = 7;
    Session::$rights = [Access::RIGHT => READ];
    Ticket::$readable = [44];
    $_SESSION = ['glpiactiveentities' => [1], 'glpigroups' => []];
    return ['id'=>12, 'tickets_id'=>44, 'entities_id'=>1, 'users_id_requester'=>7, 'users_id_borrower'=>7,
        'status'=>'submitted', 'revision'=>1, 'begin'=>'2026-11-02 08:00:00', 'end'=>'2026-11-02 09:00:00',
        'purpose'=>'Reunião', 'lines'=>[], 'events'=>[]];
}

rp_test('ticket return URLs are local, subdirectory aware and select the native plugin tab', function (): void {
    global $CFG_GLPI;
    $CFG_GLPI['root_doc'] = '/support';
    $url = BookingView::ticketTabUrl(44);
    rp_same('/support/front/ticket.form.php', parse_url($url, PHP_URL_PATH));
    parse_str((string) parse_url($url, PHP_URL_QUERY), $query);
    rp_same(['id'=>'44', 'forcetab'=>TicketTab::class . '$1'], $query);
    rp_same(null, parse_url($url, PHP_URL_HOST));
    rp_throws(static fn()=>BookingView::ticketTabUrl(0), InvalidArgumentException::class);
    rp_same(['_return_ticket'=>44], BookingView::ticketContextQuery(44));
    rp_same([], BookingView::ticketContextQuery(0));
});

rp_test('ticket context rejects URLs, malformed values and foreign ticket identities', function (): void {
    $booking = ticket_context_fixture();
    rp_same(44, BookingView::ticketContext($booking, '44'));
    rp_same(0, BookingView::ticketContext($booking, null));
    foreach (['https://example.invalid/', '//example.invalid/', '44&forcetab=evil', '45', '-44', ['44'], true, 44.0] as $bad) {
        rp_throws(static fn()=>BookingView::ticketContext($booking, $bad), DomainException::class);
    }
});

rp_test('plugin booking scope and native ticket read permission are independently required', function (): void {
    $booking = ticket_context_fixture();
    Ticket::$readable = [];
    rp_throws(static fn()=>BookingView::ticketContext($booking, 44), DomainException::class);
    Ticket::$readable = [44];
    $booking['entities_id'] = 2;
    rp_throws(static fn()=>BookingView::ticketContext($booking, 44), DomainException::class);
    $booking['entities_id'] = 1;
    Session::$rights = [];
    rp_throws(static fn()=>BookingView::ticketContext($booking, 44), DomainException::class);
});

rp_test('command tokens reject inserted or removed ticket context and expired or foreign actors', function (): void {
    ticket_context_fixture();
    $key = BookingView::command('approve', 12);
    BookingView::checkCommand($key, 'approve', 12);
    rp_throws(static fn()=>BookingView::checkCommand($key, 'approve', 12, 44), DomainException::class);
    // Simulate the command that a verified embedded form issued.
    $_SESSION['reservaplus_commands'][$key]['ticket'] = 44;
    BookingView::checkCommand($key, 'approve', 12, 44);
    BookingView::checkCommand($key, 'approve', 12, 44); // A retry remains valid.
    rp_throws(static fn()=>BookingView::checkCommand($key, 'approve', 12), DomainException::class);
    rp_throws(static fn()=>BookingView::checkCommand($key, 'approve', 12, 45), DomainException::class);
    Session::$user = 8;
    rp_throws(static fn()=>BookingView::checkCommand($key, 'approve', 12, 44), DomainException::class);
    Session::$user = 7;
    $_SESSION['reservaplus_commands'][$key]['expires'] = time()-1;
    rp_throws(static fn()=>BookingView::checkCommand($key, 'approve', 12, 44), DomainException::class);
});

rp_test('embedded rendering binds its forms and resets context even when a component fails', function (): void {
    global $DB;
    $booking = ticket_context_fixture();
    $DB = new class {
        public function request(array $query): array {
            BookingView::formStart('cancel', 12, 1);
            throw new RuntimeException('Fixture component failed');
        }
    };
    ob_start();
    try {
        rp_throws(static fn()=>BookingView::renderDetail($booking, 44), RuntimeException::class, 'Fixture component failed');
        $inside = ob_get_contents();
    } finally { ob_end_clean(); unset($GLOBALS['DB']); }
    rp_assert(str_contains($inside, 'name="_return_ticket" value="44"'));
    preg_match('/name="operation_key" value="([^"]+)"/', $inside, $token);
    BookingView::checkCommand($token[1], 'cancel', 12, 44);
    ob_start(); BookingView::formStart('cancel', 12, 1); $outside = ob_get_clean();
    rp_assert(!str_contains($outside, '_return_ticket'));
    preg_match('/name="operation_key" value="([^"]+)"/', $outside, $nextToken);
    BookingView::checkCommand($nextToken[1], 'cancel', 12);
});

rp_test('approval progress counts the actual decision rather than alternative covered targets', function (): void {
    $line = ['id'=>9, 'snapshot_json'=>json_encode(['resource'=>['policy_json'=>'{"approval_required":true}']])];
    $booking = ['status'=>'submitted', 'approvals'=>[
        ['lines_id'=>9, 'status'=>'covered', 'users_id_decider'=>0],
        ['lines_id'=>9, 'status'=>'pending', 'users_id_decider'=>0],
        ['lines_id'=>10, 'status'=>'approved', 'users_id_decider'=>55],
    ]];
    rp_same('pending', BookingView::approvalProgress($booking, $line)['status']);
    $booking['approvals'][1] = ['lines_id'=>9, 'status'=>'approved', 'users_id_decider'=>7];
    $progress = BookingView::approvalProgress($booking, $line);
    rp_same('approved', $progress['status']);
    rp_same(7, $progress['decision']['users_id_decider']);
    $booking['approvals'][1]['status'] = 'covered';
    rp_same('unavailable', BookingView::approvalProgress($booking, $line)['status']);
    $booking['approvals'][1]['status'] = 'rejected';
    rp_same('rejected', BookingView::approvalProgress($booking, $line)['status']);
});

rp_test('reapproval and closed requests do not reuse an old visual approval state', function (): void {
    $line = ['id'=>9, 'snapshot_json'=>json_encode(['resource'=>['policy_json'=>'{"approval_required":true}']])];
    $booking = ['status'=>'submitted', 'approvals'=>[['lines_id'=>9, 'status'=>'pending', 'users_id_decider'=>0]]];
    rp_same('pending', BookingView::approvalProgress($booking, $line)['status']);
    foreach (['cancelled', 'rejected', 'completed'] as $closed) {
        $booking['status'] = $closed;
        rp_same('closed', BookingView::approvalProgress($booking, $line)['status']);
    }
    $line['snapshot_json'] = json_encode(['resource'=>['policy_json'=>'{"approval_required":false}']]);
    rp_same('not_required', BookingView::approvalProgress($booking, $line)['status']);
});
