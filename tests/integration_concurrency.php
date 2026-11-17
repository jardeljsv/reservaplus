<?php
declare(strict_types=1);
/** Real multi-process reservation races against an explicitly disposable GLPI database. */
if (getenv('RP_TEST_ALLOW_WRITES') !== '1' || !getenv('RP_TEST_DATABASE') || !getenv('GLPI_ROOT')) { fwrite(STDERR, "Disposable database guards required.\n"); exit(2); }
ob_start();
require __DIR__ . '/bootstrap.php';
global $DB;
rp_same(getenv('RP_TEST_DATABASE'), $DB->dbdefault);
use GlpiPlugin\Reservaplus\{Access,BookingService,ResourceService,Sector,Store};
function race_login(): int {
    $user = new User(); rp_assert($user->getFromDBbyName(getenv('RP_TEST_ADMIN') ?: 'glpi'));
    $auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user; Session::init($auth);
    Plugin::load('reservaplus'); $_SESSION['glpiactiveprofile'][Access::RIGHT] = Access::ADMIN;
    return (int) $user->getID();
}
$admin = race_login();
if (($argv[1] ?? '') === '--worker') {
    $payload = json_decode(base64_decode($argv[2]), true, 32, JSON_THROW_ON_ERROR);
    fwrite(STDOUT, "READY\n"); fflush(STDOUT);
    if (trim((string) fgets(STDIN)) !== 'GO') { exit(3); }
    try {
        if ($payload['kind'] === 'submit') { $id = BookingService::submit($payload['input'], $payload['key']); }
        else { $id = $payload['id']; BookingService::act($id, 'approve', ['expected_revision' => 1], $payload['key']); }
        fwrite(STDOUT, json_encode(['ok' => true, 'id' => $id]) . "\n");
    } catch (Throwable $error) {
        fwrite(STDOUT, json_encode(['ok' => false, 'class' => get_class($error), 'message' => $error->getMessage()]) . "\n");
    }
    ob_end_clean(); exit;
}
function race(array $a, array $b, int $heldResource = 0): array {
    $children = [];
    foreach ([$a, $b] as $payload) {
        $process = proc_open([PHP_BINARY, '-c', php_ini_loaded_file(), __FILE__, '--worker', base64_encode(json_encode($payload))], [['pipe', 'r'], ['pipe', 'w'], ['pipe', 'w']], $pipes);
        rp_assert(is_resource($process), 'Spawn worker');
        stream_set_timeout($pipes[1], 30); stream_set_timeout($pipes[2], 30);
        $children[] = [$process, $pipes];
    }
    foreach ($children as [$process, $pipes]) { rp_same("READY\n", fgets($pipes[1]), 'Worker ready'); }
    $go = static function () use ($children, $heldResource): void {
        if ($heldResource > 0) { Store::lock('resources', $heldResource); }
        foreach ($children as [$process, $pipes]) { fwrite($pipes[0], "GO\n"); fflush($pipes[0]); fclose($pipes[0]); }
        // Both workers enter their aggregate transactions while this resource is held.
        if ($heldResource > 0) { usleep(500000); }
    };
    if ($heldResource > 0) { Store::transaction($go); } else { $go(); }
    $results = [];
    foreach ($children as [$process, $pipes]) {
        $line = fgets($pipes[1]); $errors = stream_get_contents($pipes[2]);
        fclose($pipes[1]); fclose($pipes[2]); $status = proc_close($process);
        rp_same(0, $status, 'Worker exit: ' . $errors);
        $results[] = json_decode((string) $line, true, 32, JSON_THROW_ON_ERROR);
    }
    return $results;
}
$tag = 'RP Race ' . bin2hex(random_bytes(5));
$group = new Group(); $gid = (int) $group->add(['name' => $tag, 'entities_id' => 0, 'is_assign' => 1, 'is_usergroup' => 1]); rp_assert($gid > 0);
$membership = new Group_User(); rp_assert((bool) $membership->add(['groups_id' => $gid, 'users_id' => $admin])); race_login();
$sector = Sector::save(['name' => $tag, 'code' => str_replace(' ', '-', $tag), 'entities_id' => 0, 'is_active' => 1]);
$resource = static function (string $name, bool $approval, bool $restricted = false) use ($tag, $gid, $admin, $sector): int {
    return ResourceService::save(['name' => $tag . ' ' . $name, 'entities_id' => 0, 'publication' => 'published', 'sectors_id' => $sector,
        'groups_id_ticket' => $gid, 'groups_id_manage' => $gid, 'groups_id_approve' => $gid, 'custodian_users_id' => $admin,
        'policy_json' => ['approval_required' => $approval, 'signature_stage' => 'none', 'return_signature_required' => false, 'requester_groups_ids' => $restricted ? [$gid] : []]]);
};
$input = static fn(int $rid): array => ['resources_ids' => [$rid], 'begin' => date('Y-m-d H:i:s', time() + 7200), 'end' => date('Y-m-d H:i:s', time() + 10800), 'purpose' => 'Concurrent allocation proof'];
$key = static fn(): string => 'race-' . bin2hex(random_bytes(16));
$rid = $resource('distinct commands', false); $payload = ['kind' => 'submit', 'input' => $input($rid)];
$results = race($payload + ['key' => $key()], $payload + ['key' => $key()]);
rp_same(1, count(array_filter($results, static fn($r) => $r['ok'])), 'Only one competing submit commits: ' . json_encode($results));
rp_same(1, count(Store::find('bookinglines', ['resources_id' => $rid, 'state' => 'allocated'])));
rp_test('concurrent distinct submissions commit exactly one allocation', static fn() => rp_assert(true));
$rid = $resource('same command', false); $payload = ['kind' => 'submit', 'input' => $input($rid), 'key' => $key()];
$results = race($payload, $payload);
rp_assert($results[0]['ok'] && $results[1]['ok'], json_encode($results)); rp_same($results[0]['id'], $results[1]['id']);
rp_same(1, count(Store::find('bookinglines', ['resources_id' => $rid])));
rp_test('concurrent duplicate submissions return one booking identity', static fn() => rp_assert(true));
for ($i = 0; $i < 3; ++$i) {
    $rid = $resource('approval race ' . $i, true); $data = $input($rid);
    $one = BookingService::submit($data, $key()); $two = BookingService::submit($data, $key());
    $results = race(['kind' => 'approve', 'id' => $one, 'key' => $key()], ['kind' => 'approve', 'id' => $two, 'key' => $key()], $rid);
    rp_same(1, count(array_filter($results, static fn($r) => $r['ok'])), 'Only one competing approval commits: ' . json_encode($results));
    rp_same(1, count(Store::find('bookinglines', ['resources_id' => $rid, 'state' => 'allocated'])));
    $native = (int) Store::row('resources', $rid)['reservationitems_id'];
    rp_same(1, countElementsInTable('glpi_reservations', ['reservationitems_id' => $native]));
}
rp_test('concurrent approvals serialize and create one native allocation in three races', static fn() => rp_assert(true));
$rid = $resource('restricted approval race', true, true); $data = $input($rid);
$one = BookingService::submit($data, $key()); $two = BookingService::submit($data, $key());
$results = race(['kind' => 'approve', 'id' => $one, 'key' => $key()], ['kind' => 'approve', 'id' => $two, 'key' => $key()], $rid);
rp_same(1, count(array_filter($results, static fn($r) => $r['ok'])), 'Restricted calendar only one allocation: ' . json_encode($results));
rp_same(1, count(Store::find('bookinglines', ['resources_id' => $rid, 'state' => 'allocated'])));
rp_test('concurrent private calendar approvals reject stale competing allocation', static fn() => rp_assert(true));
ob_end_clean();
