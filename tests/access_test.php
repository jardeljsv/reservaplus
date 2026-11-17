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

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\Store;

function access_fixture(): array
{
    global $DB;
    Session::$user = 7;
    Session::$rights = [Access::RIGHT => READ | CREATE];
    $_SESSION = ['glpiactiveentities' => [1], 'glpigroups' => [10]];
    $DB = new class {
        public function request(array $query): array {
            return match ($query['FROM']) {
                'glpi_users' => [['id' => 7, 'is_active' => 1, 'is_deleted' => 0]],
                'glpi_profiles_users' => [['users_id' => 7, 'entities_id' => 1, 'is_recursive' => 0]],
                'glpi_groups' => [['id' => (int) $query['WHERE']['id'], 'entities_id' => 1, 'is_recursive' => 0]],
                'glpi_groups_users' => array_map(static fn(int $group): array => ['groups_id' => $group], $_SESSION['glpigroups']),
                default => [],
            };
        }
    };
    return ['id' => 1, 'entities_id' => 1, 'is_deleted' => 0, 'publication' => 'published',
        'groups_id_manage' => 20, 'groups_id_ticket' => 20, 'groups_id_approve' => 30,
        'custodian_users_id' => 7, 'policy_json' => '{"requester_groups_ids":[10]}'];
}

rp_test('eligible requester can discover and request published resource', function (): void {
    $row = access_fixture();
    rp_assert(Access::resource($row));
    rp_assert(Access::resource($row, 'request'));
});
rp_test('read permission cannot submit', function (): void {
    $row = access_fixture(); Session::$rights[Access::RIGHT] = READ;
    rp_assert(!Access::resource($row, 'request'));
});
rp_test('cross entity denied even to plugin administrator', function (): void {
    $row = access_fixture(); Session::$rights[Access::RIGHT] |= Access::ADMIN;
    $row['entities_id'] = 2;
    rp_assert(!Access::resource($row));
});
rp_test('custodian is not automatically approver or manager', function (): void {
    $row = access_fixture();
    Session::$rights[Access::RIGHT] |= Access::APPROVE | Access::MANAGE;
    rp_assert(!Access::resource($row, 'approve'));
    rp_assert(!Access::resource($row, 'manage'));
});
rp_test('designated approval group still needs capability', function (): void {
    $row = access_fixture(); $_SESSION['glpigroups'][] = 30;
    rp_assert(!Access::resource($row, 'approve'));
    Session::$rights[Access::RIGHT] |= Access::APPROVE;
    rp_assert(Access::resource($row, 'approve'));
});
rp_test('manager requires matching responsible group', function (): void {
    $row = access_fixture(); Session::$rights[Access::RIGHT] |= Access::MANAGE;
    rp_assert(!Access::resource($row, 'manage'));
    $_SESSION['glpigroups'][] = 20;
    rp_assert(Access::resource($row, 'manage'));
    $row['publication'] = 'draft';
    rp_assert(Access::resource($row, 'read'));
});
rp_test('invalid requester policy fails closed', function (): void {
    $row = access_fixture();
    foreach (['{broken', '{"requester_groups_ids":"all"}', '{"requester_groups_ids":["bad"]}', '{"requester_groups_ids":[0]}'] as $bad) {
        $row['policy_json'] = $bad;
        rp_assert(!Access::resource($row));
    }
});
rp_test('requester loses discovery on group change or retirement', function (): void {
    $row = access_fixture(); $_SESSION['glpigroups'] = [];
    rp_assert(!Access::resource($row));
    $row = access_fixture(); $row['publication'] = 'retired';
    rp_assert(!Access::resource($row));
});
rp_test('anonymous user has no workflow access', function (): void {
    $row = access_fixture(); Session::$user = 0;
    rp_assert(!Access::resource($row));
});
rp_test('ordinary requester cannot mutate another users booking', function (): void {
    access_fixture();
    $booking = ['id' => 12, 'entities_id' => 1, 'users_id_requester' => 8, 'users_id_borrower' => 9];
    rp_assert(!Access::booking($booking, 'request'));
    $booking['users_id_borrower'] = 7;
    rp_assert(Access::booking($booking, 'request'));
});
rp_test('revoked DB group membership overrides a stale session', function (): void {
    global $DB;
    $row = access_fixture();
    $DB = new class {
        public function request(array $query): array { return []; }
    };
    try { rp_assert(!Access::resource($row, 'request')); }
    finally { unset($GLOBALS['DB']); }
});
rp_test('plugin SQL identifiers and JSON data reject corruption', function (): void {
    rp_throws(fn() => Store::table('resources; DROP TABLE users'));
    rp_throws(fn() => Store::decode('null'));
    rp_same(['x' => 'ação'], Store::decode(Store::json(['x' => 'ação'])));
});
