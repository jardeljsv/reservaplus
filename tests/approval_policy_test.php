<?php
declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\ApprovalPolicy;
use GlpiPlugin\Reservaplus\ResourceService;
use GlpiPlugin\Reservaplus\Schema;
use GlpiPlugin\Reservaplus\Store;

if (!class_exists('Session', false)) {
    class Session {
        public static int $user = 7;
        public static array $rights = [];
        public static function getLoginUserID(): int { return self::$user; }
        public static function haveRight(string $name, int $mask): bool { return ((self::$rights[$name] ?? 0) & $mask) === $mask; }
    }
}
if (!class_exists('Ticket', false)) {
    class Ticket {
        public const DEMAND_TYPE = 2;
        public const INCIDENT_TYPE = 1;
        public static array $rows = [];
        public static bool $readable = true;
        public array $fields = [];
        public function getFromDB(int $id): bool { $this->fields = self::$rows[$id] ?? []; return $this->fields !== []; }
        public function can(int $id, int $right): bool { return self::$readable && isset(self::$rows[$id]) && $right === READ; }
    }
}
if (!class_exists('TicketValidation', false)) {
    class TicketValidation {
        public static string $rightname = 'ticketvalidation';
        public const VALIDATEREQUEST = 4096;
        public const VALIDATEINCIDENT = 8192;
    }
}
class ApprovalReadFixtureDB {
    public array $tables = [];
    public function request(array $query): ArrayIterator {
        $rows = $this->tables[$query['FROM']] ?? [];
        $rows = array_values(array_filter($rows, static function (array $row) use ($query): bool {
            foreach ($query['WHERE'] ?? [] as $key => $value) {
                if (is_array($value) ? !in_array($row[$key] ?? null, $value, false) : (string) ($row[$key] ?? '') !== (string) $value) { return false; }
            }
            return true;
        }));
        return new ArrayIterator(isset($query['LIMIT']) ? array_slice($rows, 0, (int) $query['LIMIT']) : $rows);
    }
}
function approval_fixture(): array {
    global $DB;
    Session::$user = 7;
    Session::$rights = [Access::RIGHT => READ | Access::APPROVE, TicketValidation::$rightname => TicketValidation::VALIDATEREQUEST];
    $_SESSION = ['glpiactiveentities' => [1], 'glpigroups' => [10]];
    Ticket::$readable = true;
    Ticket::$rows = [101 => ['id' => 101, 'entities_id' => 1, 'type' => Ticket::DEMAND_TYPE]];
    $DB = new ApprovalReadFixtureDB();
    $DB->tables = [
        'glpi_users' => [['id' => 7, 'is_active' => 1, 'is_deleted' => 0]],
        'glpi_profiles_users' => [['users_id' => 7, 'entities_id' => 1, 'is_recursive' => 0]],
        'glpi_groups' => [['id' => 10, 'entities_id' => 1], ['id' => 20, 'entities_id' => 1], ['id' => 30, 'entities_id' => 2]],
        'glpi_groups_users' => [['users_id' => 7, 'groups_id' => 10]],
        Store::table('approvaldefaults') => [
            ['id' => 1, 'entities_id' => 1, 'kind' => 'equipment', 'groups_json' => '[10,20]', 'revision' => 2],
            ['id' => 2, 'entities_id' => 2, 'kind' => 'equipment', 'groups_json' => '[30]', 'revision' => 5],
            ['id' => 3, 'entities_id' => 0, 'kind' => 'room', 'groups_json' => '[30]', 'revision' => 1],
        ],
    ];
    return ['id' => 1, 'entities_id' => 1, 'kind' => 'equipment', 'publication' => 'published', 'is_deleted' => 0,
        'groups_id_ticket' => 100, 'groups_id_manage' => 100, 'groups_id_approve' => 20, 'config_revision' => 3, 'policy_json' => '{}'];
}
function approval_booking(array $one, array $two): array {
    global $DB;
    $DB->tables[Store::table('resources')] = [$one, $two];
    $DB->tables[Store::table('bookinglines')] = [
        ['id' => 11, 'bookings_id' => 1, 'resources_id' => $one['id'], 'snapshot_json' => Store::json(['resource' => ApprovalPolicy::snapshot($one)])],
        ['id' => 12, 'bookings_id' => 1, 'resources_id' => $two['id'], 'snapshot_json' => Store::json(['resource' => ApprovalPolicy::snapshot($two)])],
    ];
    return ['id' => 1, 'entities_id' => 1, 'tickets_id' => 101, 'users_id_requester' => 8, 'users_id_borrower' => 9];
}

rp_test('legacy approval keeps exactly its old group and custom pools replace it', static function (): void {
    $r = approval_fixture();
    $r['policy_json'] = Store::json(['approver_groups_ids' => [10], 'approver_users_ids' => [7]]);
    rp_same([20], ApprovalPolicy::resolve($r)['groups_ids']); rp_same([], ApprovalPolicy::resolve($r)['users_ids']);
    $r['policy_json'] = Store::json(['approval_mode' => 'custom', 'approver_groups_ids' => [10], 'approver_users_ids' => [7]]);
    rp_same([10], ApprovalPolicy::resolve($r)['groups_ids']); rp_same([7], ApprovalPolicy::resolve($r)['users_ids']);
});
rp_test('defaults are exact entity and resource type with no implicit ancestor fallback', static function (): void {
    $r = approval_fixture(); $r['policy_json'] = '{"approval_mode":"inherit"}';
    rp_same([10,20], ApprovalPolicy::resolve($r)['groups_ids']); rp_same(2, ApprovalPolicy::resolve($r)['revision']);
    $r['kind'] = 'room'; rp_same([], ApprovalPolicy::resolve($r)['groups_ids']);
    rp_throws(static fn() => ApprovalPolicy::defaults(2), DomainException::class);
    rp_throws(static fn() => ApprovalPolicy::validate($r), DomainException::class, 'padrão');
    rp_same([], ApprovalPolicy::validate($r, false)['groups_ids']);
});
rp_test('frozen approval roster survives default changes and rejects corruption', static function (): void {
    global $DB;
    $r = approval_fixture(); $r['policy_json'] = '{"approval_mode":"inherit"}';
    $frozen = ApprovalPolicy::snapshot($r);
    $DB->tables[Store::table('approvaldefaults')][0]['groups_json'] = '[20]';
    $DB->tables[Store::table('approvaldefaults')][0]['revision'] = 3;
    rp_same([10,20], ApprovalPolicy::resolve($frozen)['groups_ids']); rp_same([20], ApprovalPolicy::resolve($r)['groups_ids']);
    $frozen['_approval_policy']['groups_ids'] = 'all'; rp_throws(static fn() => ApprovalPolicy::resolve($frozen), InvalidArgumentException::class);
});
rp_test('snapshot validates target entity and active individual membership', static function (): void {
    global $DB;
    $r = approval_fixture(); $r['policy_json'] = '{"approval_mode":"custom","approver_groups_ids":[30]}';
    rp_throws(static fn() => ApprovalPolicy::snapshot($r), DomainException::class, 'entidade');
    $r['policy_json'] = '{"approval_mode":"custom","approver_users_ids":[7]}'; rp_same([7], ApprovalPolicy::snapshot($r)['_approval_policy']['users_ids']);
    $DB->tables['glpi_users'][0]['is_active'] = 0;
    rp_throws(static fn() => ApprovalPolicy::snapshot($r), DomainException::class, 'ativo');
});
rp_test('administrator and custody assignment cannot bypass a custom approval roster', static function (): void {
    $r = approval_fixture(); Session::$rights[Access::RIGHT] = Access::ADMIN;
    $r['custodian_users_id'] = 7; $r['default_assignee_users_id'] = 7;
    $r['policy_json'] = '{"approval_mode":"custom","approver_groups_ids":[20]}';
    rp_assert(!Access::resource($r, 'approve')); rp_assert(Access::resource($r, 'manage'));
    $r['policy_json'] = '{"approval_mode":"custom","approver_users_ids":[7]}'; rp_assert(Access::resource($r, 'approve'));
});
rp_test('group revocation immediately overrides an existing scoped session', static function (): void {
    global $DB;
    $r = approval_fixture(); $r['policy_json'] = '{"approval_mode":"custom","approver_groups_ids":[10]}';
    rp_assert(ApprovalPolicy::matches($r)); $DB->tables['glpi_groups_users'] = []; rp_assert(!ApprovalPolicy::matches($r));
});
rp_test('disabled individuals and groups transferred out of entity lose approval discovery', static function (): void {
    global $DB;
    $r = approval_fixture(); $r['policy_json'] = '{"approval_mode":"custom","approver_users_ids":[7]}';
    rp_assert(ApprovalPolicy::matches($r)); $DB->tables['glpi_users'][0]['is_active'] = 0; rp_assert(!ApprovalPolicy::matches($r));
    $r = approval_fixture(); $r['policy_json'] = '{"approval_mode":"custom","approver_groups_ids":[10]}';
    rp_assert(ApprovalPolicy::matches($r)); $DB->tables['glpi_groups'][0]['entities_id'] = 2; rp_assert(!ApprovalPolicy::matches($r));
});
rp_test('native approval right follows actual ticket type and active actor entity', static function (): void {
    global $DB;
    approval_fixture(); $b = ['id' => 1, 'entities_id' => 1, 'tickets_id' => 101];
    rp_assert(ApprovalPolicy::canValidateTicket($b));
    Session::$rights[TicketValidation::$rightname] = TicketValidation::VALIDATEINCIDENT; rp_assert(!ApprovalPolicy::canValidateTicket($b));
    Ticket::$rows[101]['type'] = Ticket::INCIDENT_TYPE; rp_assert(ApprovalPolicy::canValidateTicket($b));
    $DB->tables['glpi_users'][0]['is_active'] = 0; rp_assert(!ApprovalPolicy::canValidateTicket($b));
    $DB->tables['glpi_users'][0]['is_active'] = 1; Ticket::$readable = false; rp_assert(!ApprovalPolicy::canValidateTicket($b));
});
rp_test('partial approval uses frozen targets while rescheduling requires every required line', static function (): void {
    global $DB;
    $a = approval_fixture(); $a['policy_json'] = '{"approval_mode":"custom","approver_users_ids":[7]}';
    $b = $a; $b['id'] = 2; $b['policy_json'] = '{"approval_mode":"custom","approver_groups_ids":[20]}';
    $booking = approval_booking($a, $b); $lines = $DB->tables[Store::table('bookinglines')];
    rp_assert(Access::booking($booking)); rp_assert(Access::booking($booking, 'approve'));
    rp_assert(Access::canApproveLine($booking, $lines[0])); rp_assert(!Access::canApproveLine($booking, $lines[1])); rp_assert(!Access::canReschedule($booking));
    $DB->tables[Store::table('resources')][0]['policy_json'] = '{"approval_mode":"custom","approver_groups_ids":[20]}';
    rp_assert(Access::canApproveLine($booking, $lines[0]), 'Current resource edits cannot retarget a frozen approval');
    $_SESSION['glpigroups'][] = 20; $DB->tables['glpi_groups_users'][] = ['users_id' => 7, 'groups_id' => 20];
    rp_assert(Access::canReschedule($booking));
});
rp_test('partial approval cannot reveal a foreign private resource in the same aggregate', static function (): void {
    global $DB;
    $a = approval_fixture(); $a['policy_json'] = '{"approval_mode":"custom","approver_users_ids":[7]}';
    $b = $a; $b['id'] = 2; $b['policy_json'] = '{"approval_mode":"custom","approver_groups_ids":[20],"requester_groups_ids":[20]}';
    $booking = approval_booking($a, $b); $line = $DB->tables[Store::table('bookinglines')][0];
    rp_assert(!Access::booking($booking)); rp_assert(!Access::canApproveLine($booking, $line));
});
rp_test('approval defaults schema is entity unique and changes remain auditable', static function (): void {
    $definitions = Schema::definitions();
    rp_assert(in_array('UNIQUE KEY `entity_kind` (`entities_id`,`kind`)', $definitions['approvaldefaults']['keys'], true));
    foreach (['before_json','after_json','users_id_actor','revision'] as $column) { rp_assert(isset($definitions['approvaldefaultchanges']['columns'][$column])); }
    rp_throws(static fn() => ResourceService::normalizePolicy(['approval_mode' => 'custom', 'approver_users_ids' => range(1, 101)]), InvalidArgumentException::class);
});
rp_test('normalizing an old resource preserves legacy policy semantics on a name-only edit', static function (): void {
    $old = ResourceService::normalize(['name' => 'Nome anterior', 'groups_id_approve' => 20]);
    $legacy = Store::decode($old['policy_json']); unset($legacy['approval_mode']);
    $old['policy_json'] = Store::json($legacy);
    $edited = ResourceService::normalize(['name' => 'Nome corrigido'], $old);
    rp_assert(ResourceService::policy($old) == ResourceService::policy($edited));
    rp_same('legacy', ResourceService::policy($edited)['approval_mode']);
    rp_same([20], ApprovalPolicy::resolve($edited)['groups_ids']);
});
rp_test('approval-free rescheduling needs scoped management and ticket read but no validation capability', static function (): void {
    global $DB;
    $a = approval_fixture(); $a['groups_id_manage'] = 10; $a['policy_json'] = '{"approval_mode":"custom","approval_required":false}';
    $b = $a; $b['id'] = 2;
    $booking = approval_booking($a, $b);
    Session::$rights = [Access::RIGHT => READ | Access::MANAGE];
    rp_assert(Access::canReschedule($booking));
    Ticket::$readable = false; rp_assert(!Access::canReschedule($booking)); Ticket::$readable = true;
    $DB->tables['glpi_users'][0]['is_active'] = 0; rp_assert(!Access::canReschedule($booking)); $DB->tables['glpi_users'][0]['is_active'] = 1;
    $DB->tables[Store::table('resources')][1]['groups_id_manage'] = 20; rp_assert(!Access::canReschedule($booking));
});
rp_test('selector target checks do not disclose or accept foreign and inactive references', static function (): void {
    global $DB;
    approval_fixture();
    rp_assert(ApprovalPolicy::validTarget('Group', 10, 1)); rp_assert(ApprovalPolicy::validTarget('User', 7, 1));
    rp_assert(!ApprovalPolicy::validTarget('Group', 30, 1)); rp_assert(!ApprovalPolicy::validTarget('Group', 30, 2));
    rp_assert(!ApprovalPolicy::validTarget('Computer', 10, 1)); rp_assert(!ApprovalPolicy::validTarget('Group', 0, 1));
    $DB->tables['glpi_users'][0]['is_active'] = 0; rp_assert(!ApprovalPolicy::validTarget('User', 7, 1));
});
