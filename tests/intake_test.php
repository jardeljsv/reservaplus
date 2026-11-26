<?php

declare(strict_types=1);
require_once __DIR__ . '/bootstrap.php';

if (!class_exists('Session', false)) {
    class Session
    {
        public static int $user = 7;
        public static function getLoginUserID(): int { return self::$user; }
        public static function haveRight(string $name, int $mask): bool { return false; }
    }
}
use GlpiPlugin\Reservaplus\RequestDraft;

class RpIntakeDropdownStub
{
    public static array $calls = [];
    public static function showFromArray(string $name, array $options, array $params = []): void
    {
        self::$calls[] = [$name, $options, $params];
    }
}


rp_test('cart retains selections across pages and removes only unchecked visible items', static function (): void {
    rp_same([1, 13, 21], RequestDraft::mergeSelection([1, 13], [20, 21], [21]));
    rp_same([1, 21], RequestDraft::mergeSelection([1, 13, 21], [13, 14], []));
    rp_throws(static fn() => RequestDraft::mergeSelection(range(1, 32), [40], [40]), DomainException::class);
});
rp_test('drafts isolate tabs, users and expiration without creating a booking', static function (): void {
    $_SESSION = []; Session::$user = 7;
    $one = RequestDraft::open('', ['resources_id' => 11, 'kind' => 'equipment']);
    $two = RequestDraft::open('', ['resources_id' => 12, 'kind' => 'room']);
    rp_assert($one['id'] !== $two['id']);
    rp_same([11], RequestDraft::open($one['id'])['resources_ids']);
    rp_same([12], RequestDraft::open($two['id'])['resources_ids']);
    Session::$user = 8;
    rp_throws(static fn() => RequestDraft::open($one['id']), DomainException::class);
    Session::$user = 7;
    $_SESSION['reservaplus_request_drafts'][$one['id']]['updated_at'] = time() - RequestDraft::TTL - 1;
    rp_throws(static fn() => RequestDraft::open($one['id']), DomainException::class);
});
rp_test('category navigation preserves cart and period while business submission excludes browsing controls', static function (): void {
    $_SESSION = []; Session::$user = 7;
    $draft = RequestDraft::open('', ['resources_ids' => [1, 13], 'begin' => '2026-10-29T10:00', 'end' => '2026-10-29T12:00']);
    $draft = RequestDraft::apply($draft, ['view_step' => 'browse', 'visible_ids' => [13, 14], 'resources_ids' => [13, 14], 'choose_kind' => 'room', 'begin' => $draft['begin'], 'end' => $draft['end']]);
    rp_same([1, 13, 14], $draft['resources_ids']); rp_same('room', $draft['kind']);
    rp_same('2026-10-29T10:00', $draft['begin']);
    $draft = RequestDraft::apply($draft, ['view_step' => 'details', 'purpose' => 'Treinamento da equipe', 'people_count' => 8, 'intent' => 'back_browse', 'users_id_borrower' => 999]);
    rp_same('Treinamento da equipe', $draft['purpose']); rp_same(7, $draft['users_id_borrower']);
    $data = RequestDraft::submission($draft);
    rp_assert(!isset($data['kind']) && !isset($data['id']) && !isset($data['page']));
    rp_same([1, 13, 14], $data['resources_ids']);
});
rp_test('location and group lotation IDs persist through details, review and submission', static function (): void {
    $_SESSION = []; Session::$user = 7;
    $draft = RequestDraft::open('', ['resources_ids' => [11]]);
    $draft = RequestDraft::apply($draft, ['view_step' => 'details', 'purpose' => 'Empréstimo',
        'locations_id' => 48, 'lotation_groups_id' => 72,
        'unit' => 'TEXTO INJETADO', 'matricula' => 'NAO-USAR', 'intent' => 'review']);
    rp_same(48, $draft['locations_id']);
    rp_same(72, $draft['lotation_groups_id']);
    $data = RequestDraft::submission($draft);
    rp_same(48, $data['locations_id']);
    rp_same(72, $data['lotation_groups_id']);
    rp_assert(!array_key_exists('unit', $data) && !array_key_exists('matricula', $data));
});

rp_test('GLPI native dropdown receives restricted options, selected ID and searchable native parameters', static function (): void {
    if (!class_exists('Dropdown', false)) {
        class_alias(RpIntakeDropdownStub::class, 'Dropdown');
    }
    RpIntakeDropdownStub::$calls = [];
    $method = new ReflectionMethod(\GlpiPlugin\Reservaplus\RequestIntakeView::class,
        'nativeSearchableDropdown');
    ob_start();
    $method->invoke(null, 'locations_id', 'Unidade / Localização GLPI',
        [13 => 'Hospital Regional', 14 => 'UBS'], 14, 'Selecione ou pesquise');
    $html = (string) ob_get_clean();
    rp_same(1, count(RpIntakeDropdownStub::$calls));
    [$name, $options, $params] = RpIntakeDropdownStub::$calls[0];
    rp_same('locations_id', $name);
    rp_same([0 => 'Selecione ou pesquise', 13 => 'Hospital Regional', 14 => 'UBS'], $options);
    rp_same(14, $params['value']);
    rp_assert($params['required'] === true);
    rp_assert($params['display_emptychoice'] === false);
    rp_assert(str_contains($html, 'for="dropdown_locations_id' . $params['rand'] . '"'));
});

rp_test('duration shortcuts and item removal preserve other request details', static function (): void {
    $_SESSION = []; Session::$user = 7;
    $draft = RequestDraft::open('', ['resources_ids' => [2, 3], 'begin' => '2026-10-29T10:00', 'end' => '2026-10-29T11:00', 'purpose' => 'Reunião']);
    $draft = RequestDraft::apply($draft, ['view_step' => 'browse', 'intent' => 'duration:120']);
    rp_same('2026-10-29T12:00', $draft['end']); rp_same([2, 3], $draft['resources_ids']);
    $draft = RequestDraft::apply($draft, ['intent' => 'remove:2']);
    rp_same([3], $draft['resources_ids']); rp_same('Reunião', $draft['purpose']);
});
rp_test('a stale review tab cannot submit a newer draft payload silently', static function (): void {
    $_SESSION = []; Session::$user = 7;
    $draft = RequestDraft::open('', ['resources_ids' => [2], 'purpose' => 'Versão A']);
    $draft['step'] = 'review';
    $first = hash('sha256', json_encode(RequestDraft::submission($draft), JSON_THROW_ON_ERROR));
    $draft['review_hash'] = $first;
    RequestDraft::assertReviewed($draft, $first);
    $draft['purpose'] = 'Versão B';
    $second = hash('sha256', json_encode(RequestDraft::submission($draft), JSON_THROW_ON_ERROR));
    $draft['review_hash'] = $second;
    rp_throws(static fn() => RequestDraft::assertReviewed($draft, $first), DomainException::class);
    RequestDraft::assertReviewed($draft, $second);
});
