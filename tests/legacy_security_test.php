<?php

declare(strict_types=1);

/** Isolated behavioral unit tests. These are not GLPI runtime integration tests. */
namespace {
    const READ = 1;
    const UPDATE = 2;
    const CREATE = 4;
    const PURGE = 16;
    function __($text, $domain = null) { return $text; }
    class CommonDBTM { public array $fields = []; }
    class Html {}
    class Session
    {
        public static array $rights = [];
        public static int $uid = 10;
        public static array $entities = [1];
        public static bool $helpdesk = true;
        public static function haveRight($name, $mask) { return (self::$rights[$name] ?? 0) & $mask; }
        public static function getLoginUserID() { return self::$uid; }
        public static function haveAccessToEntity($id, $recursive = false) { return in_array((int) $id, self::$entities, true); }
    }
    class Computer extends CommonDBTM
    {
        public static array $rows = [];
        public function getFromDB($id) { $this->fields = self::$rows[$id] ?? []; return $this->fields !== []; }
        public function getName() { return $this->fields['name']; }
    }
    class Group extends Computer {}
    final class Rows implements \IteratorAggregate
    {
        public function __construct(private array $rows) {}
        public function current() { return $this->rows[0] ?? false; }
        public function getIterator(): \Traversable { return new \ArrayIterator($this->rows); }
    }
    final class FakeDB
    {
        public array $tables = [];
        public array $snapshot = [];
        public bool $failUpdate = false;
        public bool $failLock = false;
        public function tableExists($table) { return array_key_exists($table, $this->tables); }
        public function request(array $q): Rows
        {
            $rows = array_values(array_filter($this->tables[$q['FROM']] ?? [], fn($row) => $this->matches($row, $q['WHERE'] ?? [])));
            if (isset($q['LIMIT'])) { $rows = array_slice($rows, $q['START'] ?? 0, $q['LIMIT']); }
            return new Rows($rows);
        }
        private function matches(array $row, array $where): bool
        {
            foreach ($where as $key => $value) {
                if ($key === 'OR') {
                    $matched = false;
                    foreach ($value as $part) { $matched = $matched || $this->matches($row, $part); }
                    if (!$matched) { return false; }
                } elseif ($key === 'AND' || is_int($key)) {
                    foreach ($key === 'AND' ? $value : [$value] as $part) { if (!$this->matches($row, $part)) { return false; } }
                } elseif ($key === 'NOT') {
                    if ($this->matches($row, $value)) { return false; }
                } else {
                    $field = str_contains((string) $key, '.') ? substr((string) $key, strrpos((string) $key, '.') + 1) : $key;
                    $actual = $row[$field] ?? null;
                    if (is_array($value) && count($value) === 2 && in_array($value[0], ['>', '<', '>=', '<='], true)) {
                        $ok = match ($value[0]) { '>' => $actual > $value[1], '<' => $actual < $value[1], '>=' => $actual >= $value[1], '<=' => $actual <= $value[1] };
                        if (!$ok) { return false; }
                    } elseif (is_array($value)) {
                        if (!in_array($actual, $value, true)) { return false; }
                    } elseif ($actual != $value) { return false; }
                }
            }
            return true;
        }
        public function beginTransaction() { $this->snapshot = $this->tables; }
        public function commit() { $this->snapshot = []; }
        public function rollBack() { $this->tables = $this->snapshot; $this->snapshot = []; }
        public function doQuery($sql) { return !$this->failLock; }
        public function update($table, $values, $where) {
            if ($this->failUpdate) { return false; }
            foreach ($this->tables[$table] as &$row) { if ($this->matches($row, $where)) { $row = array_merge($row, $values); } }
            return true;
        }
        public function delete($table, $where) {
            $this->tables[$table] = array_values(array_filter($this->tables[$table], fn($row) => !$this->matches($row, $where)));
            return true;
        }
    }
    class Reservation extends CommonDBTM
    {
        public static int $refuseId = 0;
        public static bool $authorized = true;
        public function getFromDB($id) { global $DB; $this->fields = $DB->request(['FROM' => 'glpi_reservations', 'WHERE' => ['id' => $id]])->current() ?: []; return $this->fields !== []; }
        public function can($id, $right) { return self::$authorized; }
        public function delete($input, $force, $history) { global $DB; return $input['id'] !== self::$refuseId && $DB->delete('glpi_reservations', ['id' => $input['id']]); }
    }
}
namespace GlpiPlugin\Reservaplus {
    class Config { public array $fields = ['allow_view_others' => 0]; public static function getSingleton() { return new self(); } }
    class Dashboard { public static function isHelpdeskInterface() { return \Session::$helpdesk; } public static function getUrl($path) { return '/plugins/reservaplus/front/' . $path; } }
    class Block { public static function getTable() { return 'glpi_plugin_reservaplus_blocks'; } }
    class Resource extends \Computer {}
    class Access { public static bool $visible = true; public static function resource(array $row, string $cap = 'read'): bool { return self::$visible; } }
    class NotificationService { public static int $events = 0; public static function cancelled($row) { self::$events++; } public static function webhook($type, $row) { self::$events++; } }
}
namespace {
    use GlpiPlugin\Reservaplus\AvailabilityService;
    use GlpiPlugin\Reservaplus\ItemGroup;
    use GlpiPlugin\Reservaplus\LegacyCalendar;
    use GlpiPlugin\Reservaplus\ReservationRequest;
    require_once dirname(__DIR__) . '/src/ItemGroup.php';
    require_once dirname(__DIR__) . '/src/ReservationRequest.php';
    require_once dirname(__DIR__) . '/src/AvailabilityService.php';
    require_once dirname(__DIR__) . '/src/LegacyCalendar.php';
    $checks = 0;
    function check($value, string $message): void { global $checks; if (!$value) { throw new \RuntimeException($message); } $checks++; }
    function seed(): array
    {
        global $DB;
        $DB = new FakeDB();
        Session::$rights = ['plugin_reservaplus_reservation' => READ];
        Session::$entities = [1]; Session::$uid = 10;
        $_SESSION = ['glpiactiveentities' => [1], 'glpigroups' => [7]];
        Computer::$rows = [1 => ['id' => 1, 'name' => 'Device A', 'entities_id' => 1], 2 => ['id' => 2, 'name' => 'Secret B', 'entities_id' => 2]];
        $row = ['id' => 1, 'entities_id' => 1, 'reservationitems_id' => 1, 'users_id_requester' => 10, 'users_id_for' => 10, 'status' => 'created', 'begin' => '2026-11-01 10:00:00', 'end' => '2026-11-01 11:00:00', 'native_reservations_json' => '[100,101]'];
        $DB->tables = [
            'glpi_reservationitems' => [ ['id' => 1, 'itemtype' => Computer::class, 'items_id' => 1, 'is_active' => 1], ['id' => 2, 'itemtype' => Computer::class, 'items_id' => 2, 'is_active' => 1] ],
            'glpi_plugin_reservaplus_requests' => [$row],
            'glpi_plugin_reservaplus_item_groups' => [],
            'glpi_plugin_reservaplus_resources' => [],
            'glpi_plugin_reservaplus_blocks' => [],
            'glpi_reservations' => [ ['id' => 100, 'users_id' => 10, 'reservationitems_id' => 1, 'begin' => $row['begin'], 'end' => $row['end']], ['id' => 101, 'users_id' => 10, 'reservationitems_id' => 1, 'begin' => $row['begin'], 'end' => $row['end']] ],
        ];
        Reservation::$refuseId = 0; Reservation::$authorized = true;
        \GlpiPlugin\Reservaplus\Access::$visible = true;
        return $row;
    }
    $row = seed();
    check(ReservationRequest::canViewRow($row), 'READ owner can view');
    check(!ReservationRequest::canCreate(), 'READ does not imply CREATE');
    check(!ReservationRequest::canEditRow($row), 'READ does not imply UPDATE');
    check(!ReservationRequest::canCancelRow($row), 'READ does not imply cancellation');
    check(!ReservationRequest::canDeleteRow($row), 'READ does not imply PURGE');
    Session::$rights['plugin_reservaplus_reservation'] |= UPDATE;
    check(ReservationRequest::canEditRow($row), 'UPDATE owner can edit');
    check(ReservationRequest::canCancelRow($row), 'UPDATE owner can cancel');
    check(!ReservationRequest::canDeleteRow($row), 'UPDATE does not imply PURGE');
    Session::$rights['plugin_reservaplus_reservation'] |= PURGE;
    check(ReservationRequest::canDeleteRow($row), 'PURGE owner can delete');
    $other = array_replace($row, ['users_id_requester' => 20, 'users_id_for' => 20]);
    check(!ReservationRequest::canViewRow($other), 'Helpdesk cannot duplicate/read another request');
    Session::$rights['config'] = UPDATE;
    check(!ReservationRequest::canViewRow(array_replace($row, ['entities_id' => 2])), 'Admin still scoped by entity');
    check(ItemGroup::getAllowedItemIds() === [1], 'Admin allowed IDs do not bypass entity');
    Session::$rights['config'] = 0;
    $DB->tables['glpi_plugin_reservaplus_item_groups'] = [['id' => 1, 'reservationitems_id' => 1, 'groups_id' => 8]];
    check(!ItemGroup::isAllowed(1), 'Wrong group cannot access item');
    check(!ReservationRequest::canEditRow($row), 'Ownership cannot bypass current group scope');
    $DB->tables['glpi_plugin_reservaplus_item_groups'] = [];
    $DB->tables['glpi_reservationitems'][0]['is_active'] = 0;
    check(!ItemGroup::isAllowed(1), 'Inactive item cannot be booked');
    check(ReservationRequest::canViewRow($row), 'Inactive legacy history stays readable');
    $DB->tables['glpi_reservationitems'][0]['is_active'] = 1;
    $DB->tables['glpi_plugin_reservaplus_resources'] = [['id' => 77, 'reservationitems_id' => 1, 'native_itemtype' => Computer::class, 'native_items_id' => 1]];
    check(!ItemGroup::isLegacyBookable(1), 'Native alias managed by workflow cannot be booked through legacy form');
    check(!ReservationRequest::canCancelRow($row), 'Managed alias cannot bypass workflow cancellation');
    \GlpiPlugin\Reservaplus\Access::$visible = false;
    check(!ItemGroup::isAllowed(1), 'Legacy views respect workflow resource read policy');
    check(ItemGroup::getAllowedItemIds() === [], 'Restricted workflow aliases excluded from legacy calendar projection');
    $context = ['id' => 1, 'itemtype' => Computer::class, '_entities_id' => 1];
    check(AvailabilityService::blockApplies(['entities_id' => 1, 'reservationitems_id' => 0], $context), 'Entity-wide block matches own entity');
    check(!AvailabilityService::blockApplies(['entities_id' => 2, 'reservationitems_id' => 0], $context), 'Entity-wide block cannot block another entity');
    check(!AvailabilityService::blockApplies(['entities_id' => 1, 'reservationitems_id' => 0, 'itemtype' => 'Monitor'], $context), 'Type block only matches its type');
    check(!AvailabilityService::blockApplies(['entities_id' => 1, 'reservationitems_id' => 2], $context), 'Specific block cannot block another item');
    $row = seed(); Session::$rights['plugin_reservaplus_reservation'] |= UPDATE;
    Reservation::$refuseId = 101;
    check(!ReservationRequest::cancelRequest(1), 'Refused native deletion causes failure');
    check(count($DB->tables['glpi_reservations']) === 2, 'Partial native delete rolled back');
    check($DB->tables['glpi_plugin_reservaplus_requests'][0]['status'] === 'created', 'Failed cancellation preserves state');
    check($DB->tables['glpi_plugin_reservaplus_requests'][0]['native_reservations_json'] === '[100,101]', 'Failed cancellation preserves links');
    Reservation::$refuseId = 0; $DB->failUpdate = true;
    check(!ReservationRequest::cancelRequest(1), 'Metadata failure causes cancellation failure');
    check(count($DB->tables['glpi_reservations']) === 2, 'Metadata failure rolls native deletes back');
    $DB->failUpdate = false;
    check(ReservationRequest::cancelRequest(1), 'Authorized successful cancel commits');
    check($DB->tables['glpi_reservations'] === [], 'Successful cancel removes native occupancy');
    check($DB->tables['glpi_plugin_reservaplus_requests'][0]['status'] === 'cancelled', 'Successful cancel updates state');
    $row = seed();
    $ownEvents = LegacyCalendar::events('2026-11-01', '2026-11-02');
    check($ownEvents[0]['canCancel'] === false && $ownEvents[0]['canDelete'] === false, 'READ calendar cannot offer mutation controls');
    Session::$rights['plugin_reservaplus_reservation'] |= UPDATE;
    $ownEvents = LegacyCalendar::events('2026-11-01', '2026-11-02');
    check($ownEvents[0]['canCancel'] === true && $ownEvents[0]['canDelete'] === false, 'UPDATE calendar offers cancel independently of PURGE');
    $DB->tables['glpi_plugin_reservaplus_requests'][0]['users_id_requester'] = 20;
    $DB->tables['glpi_plugin_reservaplus_requests'][0]['users_id_for'] = 20;
    $events = LegacyCalendar::events('2026-11-01', '2026-11-02');
    check(count($events) === 1, 'Calendar deduplicates linked native intervals');
    check($events[0]['title'] === 'Reservado' && !isset($events[0]['requestId'], $events[0]['url']), 'Other-user calendar event contains no borrower identity or action');
    echo "legacy_security_test: {$checks} behavioral checks passed (isolated unit harness)\n";
}
