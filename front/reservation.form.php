<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\Config;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ItemSetting;
use GlpiPlugin\Reservaplus\NotificationService;
use GlpiPlugin\Reservaplus\ReservationRequest;
use GlpiPlugin\Reservaplus\Rule;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

// Every POST selects one operation and checks its own capability. GET flags never lower POST rights.
$postedOperations = array_intersect(['add', 'update'], array_keys($_POST));
if ($_SERVER['REQUEST_METHOD'] === 'POST' && count($postedOperations) !== 1) {
    http_response_code(400);
    exit;
}
$plugin_reservaplus_is_edit_request = $_SERVER['REQUEST_METHOD'] === 'POST'
    ? isset($_POST['update']) : (int) ($_GET['edit'] ?? 0) > 0;
Session::checkRight(ReservationRequest::$rightname, $plugin_reservaplus_is_edit_request ? UPDATE : CREATE);

function plugin_reservaplus_normalize_datetime(string $value): string
{
    $timestamp = strtotime(str_replace('T', ' ', $value));
    if ($timestamp === false) {
        return '';
    }

    return date('Y-m-d H:i:s', $timestamp);
}

function plugin_reservaplus_get_item_display_name(string $itemtype, int $itemsId): string
{
    if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
        return $itemtype !== '' ? $itemtype . ' #' . $itemsId : '#' . $itemsId;
    }

    $obj = new $itemtype();
    if (!$obj->getFromDB($itemsId)) {
        return $itemtype . ' #' . $itemsId;
    }

    $name = method_exists($obj, 'getName') ? $obj->getName() : '';
    return $name !== '' ? $name : $itemtype . ' #' . $itemsId;
}

function plugin_reservaplus_get_reservable_items(): array
{
    global $DB;

    if (!$DB->tableExists('glpi_reservationitems')) {
        return [];
    }

    $settings = ItemSetting::getAll();

    $items = [];
    foreach ($DB->request([
        'FROM'  => 'glpi_reservationitems',
        'WHERE' => ['is_active' => 1],
        'ORDER' => ['id ASC'],
    ]) as $row) {
        if (!ItemGroup::isLegacyBookable((int) $row['id'])) {
            continue;
        }
        $itemtype = (string) ($row['itemtype'] ?? '');
        $row['_label'] = plugin_reservaplus_get_item_display_name(
            $itemtype,
            (int) ($row['items_id'] ?? 0)
        );
        $row['_typelabel'] = ($itemtype !== '' && class_exists($itemtype) && method_exists($itemtype, 'getTypeName'))
            ? (string) $itemtype::getTypeName(2)
            : ($itemtype !== '' ? $itemtype : __('Outros', 'reservaplus'));
        $row['_setting'] = $settings[(int) ($row['id'] ?? 0)] ?? ItemSetting::emptySetting();
        $items[] = $row;
    }

    // Filtra itens por acesso de grupo
    $allowedIds = ItemGroup::getAllowedItemIds();
    if ($allowedIds !== null) {
        $items = array_values(array_filter($items, static fn(array $i): bool => in_array((int) ($i['id'] ?? 0), $allowedIds, true)));
    }

    // Ordena por tipo e depois por nome para agrupar no <optgroup>
    usort($items, static function (array $a, array $b): int {
        return [$a['_typelabel'], $a['_label']] <=> [$b['_typelabel'], $b['_label']];
    });

    return $items;
}

function plugin_reservaplus_get_active_users(): array
{
    global $DB;

    if (!$DB->tableExists('glpi_users')) {
        return [];
    }

    $users = [];
    foreach ($DB->request([
        'SELECT' => ['glpi_users.id', 'glpi_users.name', 'glpi_users.realname', 'glpi_users.firstname'],
        'DISTINCT' => true,
        'FROM' => 'glpi_users',
        'INNER JOIN' => ['glpi_profiles_users' => ['FKEY' => ['glpi_users' => 'id', 'glpi_profiles_users' => 'users_id']]],
        'WHERE' => [
            'glpi_users.is_deleted' => 0,
            'glpi_users.is_active' => 1,
            'glpi_profiles_users.entities_id' => ReservationRequest::entityIds() ?: [-1],
        ],
        'ORDER' => ['realname ASC', 'firstname ASC'],
        'LIMIT' => 500,
    ]) as $row) {
        $first = trim((string) ($row['firstname'] ?? ''));
        $last  = trim((string) ($row['realname'] ?? ''));
        $full  = trim($first . ' ' . $last);
        $row['_label'] = $full !== '' ? $full : (string) ($row['name'] ?? '#' . $row['id']);
        $users[] = $row;
    }

    // O ORDER do banco usa realname (sobrenome); quem procura na tela lê o nome
    // completo, então a ordenação final é pelo rótulo exibido.
    usort($users, static fn(array $a, array $b): int => strnatcasecmp(
        ReservationRequest::normalizeForSearch((string) ($a['_label'] ?? '')),
        ReservationRequest::normalizeForSearch((string) ($b['_label'] ?? ''))
    ));

    return $users;
}

function plugin_reservaplus_is_eligible_user(int $userId, ?int $entityId = null): bool
{
    global $DB;
    $user = new User();
    if ($userId <= 0 || !$user->getFromDB($userId)
        || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
        return false;
    }
    foreach ($DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $userId]]) as $profile) {
        $profileEntity = (int) $profile['entities_id'];
        if (!Session::haveAccessToEntity($profileEntity, (bool) ($profile['is_recursive'] ?? false))) {
            continue;
        }
        if ($entityId === null || $profileEntity === $entityId) {
            return true;
        }
        if (!empty($profile['is_recursive']) && in_array($entityId, getSonsOf('glpi_entities', $profileEntity), true)) {
            return true;
        }
    }
    return false;
}

function plugin_reservaplus_can_reserve_for_others(): bool
{
    return \GlpiPlugin\Reservaplus\Access::right('on_behalf');
}

/** Rótulo amigável de um item reservável (para os resumos de reserva em massa). */
function plugin_reservaplus_reservationitem_label(int $reservationItemsId): string
{
    global $DB;

    if ($reservationItemsId <= 0 || !$DB->tableExists('glpi_reservationitems')) {
        return '#' . $reservationItemsId;
    }
    $row = $DB->request([
        'SELECT' => ['itemtype', 'items_id'],
        'FROM'   => 'glpi_reservationitems',
        'WHERE'  => ['id' => $reservationItemsId],
        'LIMIT'  => 1,
    ])->current();
    if (!$row) {
        return '#' . $reservationItemsId;
    }
    return plugin_reservaplus_get_item_display_name((string) ($row['itemtype'] ?? ''), (int) ($row['items_id'] ?? 0));
}

/** itemtype do item reservável, ou string vazia se não existir. */
function plugin_reservaplus_reservationitem_itemtype(int $reservationItemsId): string
{
    global $DB;

    if ($reservationItemsId <= 0 || !$DB->tableExists('glpi_reservationitems')) {
        return '';
    }
    $row = $DB->request([
        'SELECT' => ['itemtype'],
        'FROM'   => 'glpi_reservationitems',
        'WHERE'  => ['id' => $reservationItemsId],
        'LIMIT'  => 1,
    ])->current();

    return $row ? (string) ($row['itemtype'] ?? '') : '';
}

/**
 * Gera as ocorrências (begin/end) de uma reserva conforme a recorrência, sempre
 * no mesmo horário do dia. Tipos: none, daily, weekly (dias da semana 0=Dom..6=Sáb),
 * monthly (mesmo dia do mês). Limitado por contagem, data final e teto de segurança.
 *
 * @param array{type:string,weekdays:array<int,int|string>,count:int,until:string} $rec
 * @return array<int,array{begin:string,end:string}>
 */
function plugin_reservaplus_occurrences(string $baseBegin, string $baseEnd, array $rec): array
{
    $startTs = strtotime($baseBegin);
    $endTs   = strtotime($baseEnd);
    if ($startTs === false || $endTs === false || $endTs <= $startTs) {
        return [];
    }

    $type = (string) ($rec['type'] ?? 'none');
    if (!in_array($type, ['daily', 'weekly', 'monthly'], true)) {
        return [['begin' => date('Y-m-d H:i:s', $startTs), 'end' => date('Y-m-d H:i:s', $endTs)]];
    }

    $durationSec = $endTs - $startTs;
    $timeOfDay   = date('H:i:s', $startTs);
    $baseDate    = (int) strtotime(date('Y-m-d', $startTs));

    $maxOcc   = 100;
    $count    = (int) ($rec['count'] ?? 0);
    $limit    = $count > 0 ? min($count, $maxOcc) : $maxOcc;
    $untilTs  = !empty($rec['until']) ? strtotime((string) $rec['until'] . ' 23:59:59') : 0;
    $hardStop = (int) strtotime('+366 days', $baseDate);

    $occ  = [];
    $push = static function (int $dayTs) use (&$occ, $timeOfDay, $durationSec): void {
        $b = (int) strtotime(date('Y-m-d', $dayTs) . ' ' . $timeOfDay);
        $occ[] = ['begin' => date('Y-m-d H:i:s', $b), 'end' => date('Y-m-d H:i:s', $b + $durationSec)];
    };

    if ($type === 'daily') {
        for ($d = $baseDate; $d <= $hardStop && count($occ) < $limit; $d = (int) strtotime('+1 day', $d)) {
            if ($untilTs && $d > $untilTs) {
                break;
            }
            $push($d);
        }
    } elseif ($type === 'weekly') {
        $weekdays = array_values(array_unique(array_filter(
            array_map('intval', (array) ($rec['weekdays'] ?? [])),
            static fn(int $w): bool => $w >= 0 && $w <= 6
        )));
        if ($weekdays === []) {
            $weekdays = [(int) date('w', $startTs)];
        }
        for ($d = $baseDate; $d <= $hardStop && count($occ) < $limit; $d = (int) strtotime('+1 day', $d)) {
            if ($untilTs && $d > $untilTs) {
                break;
            }
            if (in_array((int) date('w', $d), $weekdays, true)) {
                $push($d);
            }
        }
    } else { // monthly — mesmo dia do mês
        $dom = (int) date('j', $startTs);
        for ($m = 0; $m < 60 && count($occ) < $limit; $m++) {
            $monthTs = (int) strtotime("+{$m} month", (int) strtotime(date('Y-m-01', $baseDate)));
            if ((int) date('t', $monthTs) < $dom) {
                continue; // mês não tem esse dia (ex.: 31 em fevereiro)
            }
            $d = (int) strtotime(date('Y-m', $monthTs) . '-' . str_pad((string) $dom, 2, '0', STR_PAD_LEFT));
            if ($d < $baseDate) {
                continue;
            }
            if (($untilTs && $d > $untilTs) || $d > $hardStop) {
                break;
            }
            $push($d);
        }
    }

    return $occ;
}

// ── POST: reagendar/editar uma reserva existente ────────────────────────────
if (isset($_POST['update'])) {
    Session::checkRight(ReservationRequest::$rightname, UPDATE);
    global $DB;

    $requestId = (int) ($_POST['id'] ?? 0);
    $request   = $requestId > 0
        ? $DB->request([
            'FROM'  => ReservationRequest::getTable(),
            'WHERE' => ['id' => $requestId],
            'LIMIT' => 1,
        ])->current()
        : null;

    if (!$request || !ReservationRequest::canEditRow((array) $request)) {
        Session::addMessageAfterRedirect(__('Você não pode editar esta reserva.', 'reservaplus'), false, ERROR);
        Html::redirect(Dashboard::getUrl('reservation.php'));
    }
    $request = (array) $request;

    // Edição move UMA reserva: um item só (a tela usa seleção única).
    $rawItems = $_POST['reservationitems_id'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [$rawItems];
    }
    $itemIds = array_values(array_unique(array_filter(
        array_map('intval', $rawItems),
        static fn(int $v): bool => $v > 0
    )));

    $begin       = plugin_reservaplus_normalize_datetime((string) ($_POST['begin'] ?? ''));
    $end         = plugin_reservaplus_normalize_datetime((string) ($_POST['end'] ?? ''));
    $comment     = (string) ($_POST['comment'] ?? '');
    $peopleCount = (int) ($_POST['people_count'] ?? 0);

    if (count($itemIds) !== 1) {
        Session::addMessageAfterRedirect(__('Selecione exatamente uma sala ao editar a reserva.', 'reservaplus'), false, ERROR);
        Html::back();
    }
    if ($begin === '' || $end === '' || strtotime($begin) >= strtotime($end)) {
        Session::addMessageAfterRedirect(__('Informe um período válido.', 'reservaplus'), false, ERROR);
        Html::back();
    }
    if ($peopleCount < 1) {
        Session::addMessageAfterRedirect(__('Informe a quantidade de pessoas da reserva.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $itemId = $itemIds[0];
    if (!ItemGroup::isLegacyBookable($itemId)) {
        Session::addMessageAfterRedirect(__('Você não tem acesso a esta sala.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $hoursError = Config::businessHoursViolation($begin, $end);
    if ($hoursError !== null) {
        Session::addMessageAfterRedirect($hoursError, false, ERROR);
        Html::back();
    }

    // Capacidade da sala: bloqueia ou apenas avisa, conforme o modo da sala.
    $itemLabel     = plugin_reservaplus_reservationitem_label($itemId);
    $capacityWarn  = null;
    $capacityError = ItemSetting::capacityViolation($itemId, $peopleCount, $itemLabel);
    if ($capacityError !== null) {
        if ($capacityError['mode'] === ItemSetting::MODE_BLOCK) {
            Session::addMessageAfterRedirect($capacityError['message'], false, ERROR);
            Html::back();
        }
        $capacityWarn = $capacityError['message'];
    }

    // Conflitos ignorando a própria reserva nativa (senão ela colide consigo).
    $nativeIds    = ReservationRequest::getNativeReservationIds($request);
    $availability = new AvailabilityService();
    if ($availability->getConflicts($itemId, $begin, $end, $nativeIds) !== []) {
        Session::addMessageAfterRedirect(
            sprintf(__('%s já está ocupado neste horário.', 'reservaplus'), $itemLabel),
            false,
            ERROR
        );
        Html::back();
    }

    $profileId = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
    $entityId  = (int) ItemGroup::context($itemId)['_entities_id'];
    $ruleError = Rule::validateForCreate($profileId, $entityId, plugin_reservaplus_reservationitem_itemtype($itemId), $begin, $end);
    if ($ruleError !== null) {
        Session::addMessageAfterRedirect($ruleError, false, ERROR);
        Html::back();
    }

    // Beneficiário só muda para admin/gestor, e só para um usuário elegível.
    $usersIdFor = (int) ($request['users_id_for'] ?? 0);
    if (plugin_reservaplus_can_reserve_for_others()) {
        $forPosted = (int) ($_POST['users_id_for'] ?? 0);
        if ($forPosted > 0 && $forPosted !== $usersIdFor) {
            if (!plugin_reservaplus_is_eligible_user($forPosted)) {
                Session::addMessageAfterRedirect(__('Usuário selecionado para a reserva é inválido.', 'reservaplus'), false, ERROR);
                Html::back();
            }
            $usersIdFor = $forPosted;
        }
    }

    $peopleNote    = sprintf(__('Pessoas: %d', 'reservaplus'), $peopleCount);
    $nativeComment = trim($comment) === '' ? $peopleNote : trim($comment) . "\n" . $peopleNote;

    $DB->beginTransaction();
    try {
        $lockIds = array_unique([(int) $request['reservationitems_id'], $itemId]);
        sort($lockIds, SORT_NUMERIC);
        foreach ($lockIds as $lockId) {
            if (!$DB->doQuery('SELECT `id` FROM `glpi_reservationitems` WHERE `id`=' . (int) $lockId . ' FOR UPDATE')) {
                throw new \RuntimeException('Could not lock reservable item');
            }
        }
        if (!$DB->doQuery('SELECT `id` FROM `' . ReservationRequest::getTable() . '` WHERE `id`=' . $requestId . ' FOR UPDATE')) {
            throw new \RuntimeException('Could not lock reservation');
        }
        $fresh = $DB->request(['FROM' => ReservationRequest::getTable(), 'WHERE' => ['id' => $requestId], 'LIMIT' => 1])->current();
        if (!$fresh || !ReservationRequest::canEditRow($fresh) || $fresh != $request
            || !ItemGroup::isLegacyBookable($itemId) || $nativeIds === []
            || !plugin_reservaplus_is_eligible_user($usersIdFor, (int) ItemGroup::context($itemId)['_entities_id'])
            || $availability->getConflicts($itemId, $begin, $end, $nativeIds) !== []) {
            throw new \RuntimeException('Reservation changed or unavailable');
        }
        $targetReservation = new Reservation();
        if ($itemId !== (int) $request['reservationitems_id']
            && !$targetReservation->can(0, CREATE, ['reservationitems_id' => $itemId, 'users_id' => $usersIdFor])) {
            throw new \RuntimeException('Native target reservation permission denied');
        }
        foreach ($nativeIds as $nativeId) {
            $nativeValues = [
                'id'                  => $nativeId,
                'reservationitems_id' => $itemId,
                'begin'               => $begin,
                'end'                 => $end,
                'users_id'            => $usersIdFor,
                'comment'             => $nativeComment,
            ];
            $reservation = new Reservation();
            if (!$reservation->getFromDB($nativeId)
                || !$reservation->can($nativeId, UPDATE)
                || !$reservation->update($nativeValues)) {
                throw new \RuntimeException('Native reservation update failed');
            }
        }

        if (!$DB->update(ReservationRequest::getTable(), [
            'entities_id' => (int) ItemGroup::context($itemId)['_entities_id'],
            'reservationitems_id' => $itemId,
            'users_id_for'        => $usersIdFor,
            'begin'               => $begin,
            'end'                 => $end,
            'people_count'        => $peopleCount,
            'comment'             => $comment,
            'date_mod'            => date('Y-m-d H:i:s'),
        ], ['id' => $requestId])) {
            throw new \RuntimeException('Reservation metadata update failed');
        }

        $DB->commit();
    } catch (\Throwable $e) {
        $DB->rollBack();
        Session::addMessageAfterRedirect(__('Não foi possível alterar a reserva.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    NotificationService::reservationUpdated([
        'reservationitems_id' => $itemId,
        'users_id_requester'  => (int) ($request['users_id_requester'] ?? 0),
        'users_id_for'        => $usersIdFor,
        'begin'               => $begin,
        'end'                 => $end,
        'people_count'        => $peopleCount,
        'request_id'          => $requestId,
    ], $request);

    Session::addMessageAfterRedirect(__('Reserva alterada.', 'reservaplus'));
    if ($capacityWarn !== null) {
        Session::addMessageAfterRedirect($capacityWarn, false, WARNING);
    }
    if (trim((string) ($request['recurrence_group'] ?? '')) !== '') {
        Session::addMessageAfterRedirect(__('Só esta ocorrência foi alterada — as demais da série seguem no horário original.', 'reservaplus'), false, WARNING);
    }

    Html::redirect(Dashboard::getUrl('reservation.php'));
}

if (isset($_POST['add'])) {
    Session::checkRight(ReservationRequest::$rightname, CREATE);
    global $DB;

    // Reserva em massa: um ou vários itens no MESMO período. Cada item é validado
    // e reservado de forma independente; os itens livres são reservados e os
    // indisponíveis são apenas reportados (sucesso parcial).
    $rawItems = $_POST['reservationitems_id'] ?? [];
    if (!is_array($rawItems)) {
        $rawItems = [$rawItems];
    }
    $itemIds = array_values(array_unique(array_filter(
        array_map('intval', $rawItems),
        static fn(int $v): bool => $v > 0
    )));

    $begin       = plugin_reservaplus_normalize_datetime((string) ($_POST['begin'] ?? ''));
    $end         = plugin_reservaplus_normalize_datetime((string) ($_POST['end'] ?? ''));
    $comment     = (string) ($_POST['comment'] ?? '');
    $peopleCount = (int) ($_POST['people_count'] ?? 0);

    if ($itemIds === [] || $begin === '' || $end === '' || strtotime($begin) >= strtotime($end)) {
        Session::addMessageAfterRedirect(__('Selecione ao menos um item e um período válido.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    if ($peopleCount < 1) {
        Session::addMessageAfterRedirect(__('Informe a quantidade de pessoas da reserva.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    // Comentário da reserva NATIVA leva a quantidade de pessoas junto: assim o
    // e-mail nativo "Nova reserva" e as telas de reserva do GLPI mostram o dado.
    // A tabela do plugin guarda o comentário limpo + people_count em coluna própria.
    $peopleNote    = sprintf(__('Pessoas: %d', 'reservaplus'), $peopleCount);
    $nativeComment = trim($comment) === '' ? $peopleNote : trim($comment) . "\n" . $peopleNote;

    // Horário comercial (mesmo período para todos os itens) — valida uma vez.
    $hoursError = Config::businessHoursViolation($begin, $end);
    if ($hoursError !== null) {
        Session::addMessageAfterRedirect($hoursError, false, ERROR);
        Html::back();
    }

    // Reservar para outro usuário (somente admin/gestor). O ID postado é
    // validado contra a lista de usuários elegíveis para não aceitar qualquer
    // valor arbitrário vindo do formulário.
    $usersIdFor = (int) Session::getLoginUserID();
    if (plugin_reservaplus_can_reserve_for_others()) {
        $forPosted = (int) ($_POST['users_id_for'] ?? 0);
        if ($forPosted > 0 && $forPosted !== $usersIdFor) {
            if (!plugin_reservaplus_is_eligible_user($forPosted)) {
                Session::addMessageAfterRedirect(__('Usuário selecionado para a reserva é inválido.', 'reservaplus'), false, ERROR);
                Html::back();
            }
            $usersIdFor = $forPosted;
        }
    }

    $requester    = (int) Session::getLoginUserID();
    $profileId    = (int) ($_SESSION['glpiactiveprofile']['id'] ?? 0);
    $availability = new AvailabilityService();

    // Recorrência: gera as ocorrências (mesmo horário do dia) a reservar.
    $recType = (string) ($_POST['recurrence_type'] ?? 'none');
    if ($recType !== 'none' && empty(Config::getSingleton()->fields['allow_recurring'])) {
        Session::addMessageAfterRedirect(__('A recorrência está desativada.', 'reservaplus'), false, ERROR);
        Html::back();
    }
    $recEnd  = (string) ($_POST['recurrence_end'] ?? 'count');
    $rec = [
        'type'     => $recType,
        'weekdays' => (array) ($_POST['recurrence_weekdays'] ?? []),
        'count'    => $recEnd === 'count' ? (int) ($_POST['recurrence_count'] ?? 0) : 0,
        'until'    => $recEnd === 'until' ? (string) ($_POST['recurrence_until'] ?? '') : '',
    ];
    $occurrences = plugin_reservaplus_occurrences($begin, $end, $rec);
    if ($occurrences === []) {
        Session::addMessageAfterRedirect(__('Não foi possível gerar as datas da recorrência.', 'reservaplus'), false, ERROR);
        Html::back();
    }

    $isRecurring     = in_array($recType, ['daily', 'weekly', 'monthly'], true) && count($occurrences) > 1;
    $recurrenceGroup = $isRecurring ? bin2hex(random_bytes(16)) : null;
    $recurrenceJson  = $isRecurring
        ? json_encode(['type' => $recType, 'weekdays' => array_values(array_map('intval', $rec['weekdays'])), 'count' => $rec['count'], 'until' => $rec['until']])
        : null;

    // Metadados por item (permissão + itemtype + capacidade), resolvidos uma
    // única vez: capacidade não depende do horário, então não faz sentido
    // reavaliá-la a cada ocorrência da recorrência.
    $itemMeta = [];
    foreach ($itemIds as $iid) {
        $allowed = ItemGroup::isLegacyBookable($iid);
        $label = $allowed ? plugin_reservaplus_reservationitem_label($iid) : __('Item indisponível', 'reservaplus');
        $itemtype = '';
        if ($allowed) {
            $r = $DB->request(['SELECT' => ['itemtype'], 'FROM' => 'glpi_reservationitems', 'WHERE' => ['id' => $iid], 'LIMIT' => 1])->current();
            $itemtype = $r ? (string) ($r['itemtype'] ?? '') : '';
        }
        $itemMeta[$iid] = [
            'allowed'  => $allowed,
            'label'    => $label,
            'itemtype' => $itemtype,
            'capacity' => $allowed ? ItemSetting::capacityViolation($iid, $peopleCount, $label) : null,
            'entity' => $allowed ? (int) ItemGroup::context($iid)['_entities_id'] : -1,
        ];
    }

    $maxTotal       = 200; // teto de segurança por envio
    $created        = 0;
    $skipped        = [];
    $capacityWarns  = [];
    $datesHit       = [];
    $capped         = false;
    $lastPayload    = null;
    $createdPayloads = []; // acumula para notificar UMA vez no fim

    // Capacidade não depende do horário: salas que não cabem saem da lista uma
    // vez só, antes do laço — senão a recorrência repetiria o mesmo aviso em
    // cada ocorrência.
    foreach ($itemIds as $idx => $iid) {
        $capacity = $itemMeta[$iid]['capacity'];
        if ($capacity !== null && $capacity['mode'] === ItemSetting::MODE_BLOCK) {
            $skipped[] = $capacity['message'];
            unset($itemIds[$idx]);
        }
    }
    $itemIds = array_values($itemIds);

    if ($itemIds === []) {
        Session::addMessageAfterRedirect(
            __('Nenhuma sala comporta essa quantidade de pessoas.', 'reservaplus') . ' ' . implode(' · ', $skipped),
            false,
            ERROR
        );
        Html::back();
    }

    foreach ($occurrences as $occ) {
        $oBegin    = $occ['begin'];
        $oEnd      = $occ['end'];
        $dateLabel = date('d/m', (int) strtotime($oBegin));

        foreach ($itemIds as $iid) {
            if ($created >= $maxTotal) {
                $capped = true;
                break 2;
            }
            $meta  = $itemMeta[$iid];
            $label = $meta['label'];
            $tag   = $isRecurring ? sprintf('%s (%s)', $label, $dateLabel) : $label;

            if (!$meta['allowed']) {
                $skipped[] = sprintf(__('%s — sem permissão', 'reservaplus'), $tag);
                continue;
            }
            // Capacidade em modo "avisar": já filtramos as bloqueantes acima.
            $capacity = $meta['capacity'];
            if ($availability->getConflicts($iid, $oBegin, $oEnd) !== []) {
                $skipped[] = sprintf(__('%s — já ocupado', 'reservaplus'), $tag);
                continue;
            }
            $ruleError = Rule::validateForCreate($profileId, (int) $meta['entity'], $meta['itemtype'], $oBegin, $oEnd);
            if ($ruleError !== null) {
                $skipped[] = sprintf('%s — %s', $tag, $ruleError);
                continue;
            }

            // Reserva nativa + registro do plugin em transação.
            $DB->beginTransaction();
            try {
                if (!$DB->doQuery('SELECT `id` FROM `glpi_reservationitems` WHERE `id`=' . (int) $iid . ' FOR UPDATE')
                    || !ItemGroup::isLegacyBookable($iid)
                    || !plugin_reservaplus_is_eligible_user($usersIdFor, (int) $meta['entity'])
                    || $availability->getConflicts($iid, $oBegin, $oEnd) !== []) {
                    throw new \RuntimeException('Resource unavailable');
                }
                $reservation = new Reservation();
                if (!$reservation->can(0, CREATE, ['reservationitems_id' => $iid, 'users_id' => $usersIdFor])) {
                    throw new \RuntimeException('Native reservation permission denied');
                }
                $newId = $reservation->add([
                    'reservationitems_id' => $iid,
                    'begin'               => $oBegin,
                    'end'                 => $oEnd,
                    'users_id'            => $usersIdFor,
                    'comment'             => $nativeComment,
                ]);
                if (!$newId) {
                    throw new \RuntimeException('native reservation add failed');
                }
                if (!$DB->insert(ReservationRequest::getTable(), [
                    'entities_id'              => (int) $meta['entity'],
                    'reservationitems_id'      => $iid,
                    'users_id_requester'       => $requester,
                    'users_id_for'             => $usersIdFor,
                    'status'                   => ReservationRequest::STATUS_CREATED,
                    'begin'                    => $oBegin,
                    'end'                      => $oEnd,
                    'people_count'             => $peopleCount,
                    'comment'                  => $comment,
                    'recurrence_json'          => $recurrenceJson,
                    'recurrence_group'         => $recurrenceGroup,
                    'native_reservations_json' => json_encode([(int) $newId]),
                    'date_creation'            => date('Y-m-d H:i:s'),
                    'date_mod'                 => date('Y-m-d H:i:s'),
                ])) {
                    throw new \RuntimeException('Reservation metadata insert failed');
                }
                $DB->commit();
            } catch (\Throwable $e) {
                $DB->rollBack();
                $skipped[] = sprintf(__('%s — falha ao registrar', 'reservaplus'), $tag);
                continue;
            }

            // Sala em modo "avisar": reservou, mas o usuário precisa saber que
            // passou da capacidade. Um aviso por sala, não por ocorrência.
            if ($capacity !== null) {
                $capacityWarns[$label] = $capacity['message'];
            }

            $lastPayload = [
                'reservationitems_id' => $iid,
                'users_id_requester'  => $requester,
                'users_id_for'        => $usersIdFor,
                'begin'               => $oBegin,
                'end'                 => $oEnd,
                'people_count'        => $peopleCount,
                'comment'             => $comment,
                'entities_id'         => (int) $meta['entity'],
            ];
            // E-mail e webhook saem UMA vez no fim do envio: notificar aqui dentro
            // mandava um e-mail por ocorrência (3 meses de recorrência = 3 e-mails).
            $createdPayloads[] = $lastPayload;
            $created++;
            $datesHit[$dateLabel] = true;
        }
    }

    // Um e-mail por destinatário cobrindo tudo que foi criado neste envio.
    NotificationService::reservationSeriesCreated($createdPayloads);

    // Webhook: uma vez por envio (1 reserva = payload da reserva; lote = resumo).
    if ($created === 1 && $lastPayload !== null) {
        NotificationService::webhook('reservation.created', $lastPayload);
    } elseif ($created > 1) {
        NotificationService::webhook('reservation.created', [
            'batch'            => true,
            'count'            => $created,
            'recurrence_group' => $recurrenceGroup,
            'recurrence_type'  => $isRecurring ? $recType : 'none',
            'reservationitems' => array_values($itemIds),
            'from'             => $occurrences[0]['begin'] ?? null,
        ]);
    }

    if ($created > 0) {
        $msg = sprintf(_n('%d reserva criada.', '%d reservas criadas.', $created, 'reservaplus'), $created);
        if (count($datesHit) > 1) {
            $msg .= ' ' . sprintf(__('em %d datas.', 'reservaplus'), count($datesHit));
        }
        Session::addMessageAfterRedirect($msg);
    }
    if ($capacityWarns !== []) {
        Session::addMessageAfterRedirect(
            __('Acima da capacidade:', 'reservaplus') . ' ' . implode(' · ', $capacityWarns),
            false,
            WARNING
        );
    }
    if ($skipped !== []) {
        $shown = array_slice($skipped, 0, 15);
        $more  = count($skipped) - count($shown);
        Session::addMessageAfterRedirect(
            __('Não reservados:', 'reservaplus') . ' ' . implode(' · ', $shown) . ($more > 0 ? ' · +' . $more : ''),
            false,
            $created > 0 ? WARNING : ERROR
        );
    }
    if ($capped) {
        Session::addMessageAfterRedirect(sprintf(__('Limite de %d reservas por envio atingido — reduza o período/itens.', 'reservaplus'), $maxTotal), false, WARNING);
    }
    if ($created === 0 && $skipped === []) {
        Session::addMessageAfterRedirect(__('Nenhuma reserva criada.', 'reservaplus'), false, ERROR);
    }

    Html::redirect(Dashboard::getUrl('reservation.php'));
}

// No GLPI 11 este arquivo é incluído dentro de um método (LegacyFileLoadController),
// então o escopo não é global: é preciso declarar global $DB explicitamente.
global $DB;

$items        = plugin_reservaplus_get_reservable_items();
$canForOthers = plugin_reservaplus_can_reserve_for_others();
$users        = $canForOthers ? plugin_reservaplus_get_active_users() : [];

// Categorias distintas (para "selecionar todos da categoria") e horário
// comercial (para os atalhos de duração "manhã/tarde/dia todo").
$categoryLabels = [];
foreach ($items as $it) {
    $cl = (string) ($it['_typelabel'] ?? '');
    if ($cl !== '') {
        $categoryLabels[$cl] = true;
    }
}
$categoryLabels = array_keys($categoryLabels);

// Só oferece o filtro dos recursos que alguma sala realmente tem.
$featureOptions   = ItemSetting::getFeatureOptions();
$availableFeature = [];
foreach ($items as $it) {
    foreach ((array) ($it['_setting']['features'] ?? []) as $slug) {
        $availableFeature[(string) $slug] = true;
    }
}

$rpCfg   = Config::getSingleton();
$bhStart = substr((string) ($rpCfg->fields['business_hours_start'] ?? '08:00:00'), 0, 5);
$bhEnd   = substr((string) ($rpCfg->fields['business_hours_end'] ?? '18:00:00'), 0, 5);

// Carrega a reserva de origem: ?edit=ID reagenda a própria reserva,
// ?duplicate=ID só pré-preenche o formulário de criação.
$editId      = (int) ($_GET['edit'] ?? 0);
$duplicateId = (int) ($_GET['duplicate'] ?? 0);
$sourceId    = $editId > 0 ? $editId : $duplicateId;
$source      = null;
if ($sourceId > 0 && $DB->tableExists(ReservationRequest::getTable())) {
    $source = $DB->request([
        'FROM'  => ReservationRequest::getTable(),
        'WHERE' => ['id' => $sourceId],
        'LIMIT' => 1,
    ])->current() ?: null;
}

if ($sourceId > 0 && ($source === null || !ReservationRequest::canViewRow((array) $source))) {
    Session::addMessageAfterRedirect(__('Reserva não disponível para consulta.', 'reservaplus'), false, ERROR);
    Html::redirect(Dashboard::getUrl('reservation.php'));
}

$isEdit = false;
if ($editId > 0) {
    if ($source === null || !ReservationRequest::canEditRow((array) $source)) {
        Session::addMessageAfterRedirect(__('Você não pode editar esta reserva.', 'reservaplus'), false, ERROR);
        Html::redirect(Dashboard::getUrl('reservation.php'));
    }
    $isEdit = true;
}

$selectedItem = $source !== null ? (int) $source['reservationitems_id'] : (int) ($_GET['item'] ?? 0);
$comment      = $source !== null ? (string) ($source['comment'] ?? '') : '';
$selectedFor  = $source !== null ? (int) $source['users_id_for'] : (int) Session::getLoginUserID();
$peopleCount  = $source !== null ? max(1, (int) ($source['people_count'] ?? 1)) : 1;

$now = $source !== null
    ? date('Y-m-d\TH:i', strtotime((string) $source['begin']))
    : date('Y-m-d\TH:00');
$end = $source !== null
    ? date('Y-m-d\TH:i', strtotime((string) $source['end']))
    : date('Y-m-d\TH:00', strtotime('+1 hour'));

if ($isEdit) {
    $pageTitle = __('Editar reserva', 'reservaplus');
    $pageIntro = __('Mude a sala, o horário ou a quantidade de pessoas desta reserva.', 'reservaplus');
} elseif ($source !== null) {
    $pageTitle = __('Duplicar reserva', 'reservaplus');
    $pageIntro = __('Selecione os itens e configure o período da reserva.', 'reservaplus');
} else {
    $pageTitle = __('Nova reserva', 'reservaplus');
    $pageIntro = __('Selecione os itens e configure o período da reserva.', 'reservaplus');
}

Dashboard::renderHeader($isEdit ? __('Editar reserva do Reserva Plus', 'reservaplus') : __('Nova reserva do Reserva Plus', 'reservaplus'));
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<form method='post' action='" . Dashboard::getUrl('reservation.form.php') . "' class='rp-booking-form'>";
if ($isEdit) {
    echo "<input type='hidden' name='id' value='" . (int) $source['id'] . "'>";
}
echo "<section class='reservaplus-panel rp-booking-panel'>";

echo "<div class='reservaplus-panel-header'>";
echo "<div><h1>" . $pageTitle . "</h1><p>" . $pageIntro . "</p></div>";
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('reservation.php') . "'><i class='ti ti-arrow-left'></i> " . __('Voltar', 'reservaplus') . '</a>';
echo '</div>';

$availabilityUrl = Plugin::getWebDir('reservaplus') . '/ajax/availability.php';
echo "<div class='rp-booking-layout' data-reservaplus-availability-form"
    . " data-availability-url='" . $availabilityUrl . "'"
    . ($isEdit ? " data-editing-request-id='" . (int) $source['id'] . "'" : '')
    . " data-slots-url='" . Html::cleanInputText(Plugin::getWebDir('reservaplus') . '/ajax/freeslots.php') . "'>";

// ── COLUNA ESQUERDA: seleção de itens ──
echo "<div class='rp-item-col'>";
echo "<div class='rp-col-head'>";
echo "<span class='rp-col-title'><i class='ti ti-building'></i> " . ($isEdit ? __('Trocar de sala', 'reservaplus') : __('Itens disponíveis', 'reservaplus')) . '</span>';
echo "<span class='rp-col-hint' data-reservaplus-item-count>" . ($isEdit ? __('escolha uma sala', 'reservaplus') : __('clique para selecionar', 'reservaplus')) . '</span>';
echo '</div>';

// Painel de procura das salas: campo de texto + a lista já vem em ordem
// alfabética (por categoria, depois pelo nome). Vale também na edição, onde os
// botões de seleção múltipla não aparecem.
echo "<div class='rp-search-box rp-search-box--wide'>";
echo "<i class='ti ti-search'></i>";
echo "<input type='search' class='form-control form-control-sm' data-reservaplus-item-search placeholder='" . __('Procurar sala pelo nome…', 'reservaplus') . "' aria-label='" . __('Procurar sala', 'reservaplus') . "'>";
echo '</div>';

if ($categoryLabels !== [] && !$isEdit) {
    echo "<div class='rp-item-tools' data-reservaplus-item-tools>";
    echo "<select class='form-select form-select-sm' data-reservaplus-cat>";
    echo "<option value=''>" . __('Filtrar categoria…', 'reservaplus') . '</option>';
    foreach ($categoryLabels as $cl) {
        echo "<option value='" . Html::cleanInputText($cl) . "'>" . Html::cleanInputText($cl) . '</option>';
    }
    echo '</select>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-select-cat>" . __('Selecionar', 'reservaplus') . '</button>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-select-all>" . __('Todos', 'reservaplus') . '</button>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-clear-sel>" . __('Limpar', 'reservaplus') . '</button>';
    echo '</div>';
}

// Busca de sala: capacidade (segue a quantidade de pessoas) + recursos.
echo "<div class='rp-room-filter' data-reservaplus-room-filter>";
echo "<label class='rp-room-filter-fit'>";
echo "<input type='checkbox' data-reservaplus-fit-filter checked> ";
echo "<span><i class='ti ti-users'></i> " . __('só salas que cabem', 'reservaplus') . '</span>';
echo '</label>';
if ($availableFeature !== []) {
    echo "<div class='rp-room-filter-features'>";
    foreach ($featureOptions as $slug => $opt) {
        if (!isset($availableFeature[$slug])) {
            continue;
        }
        echo "<label class='rp-feature-chip'>";
        echo "<input type='checkbox' data-reservaplus-feature value='" . Html::cleanInputText((string) $slug) . "'>";
        echo "<span><i class='" . Html::cleanInputText((string) $opt['icon']) . "'></i> " . Html::cleanInputText((string) $opt['label']) . '</span>';
        echo '</label>';
    }
    echo '</div>';
}
echo "<p class='rp-room-filter-empty' data-reservaplus-filter-empty hidden><i class='ti ti-mood-empty'></i> " . __('Nenhuma sala atende a esses critérios.', 'reservaplus') . '</p>';
echo '</div>';

echo "<div class='rp-item-list' data-reservaplus-item-list" . ($isEdit ? " data-reservaplus-single='1'" : '') . '>';
$currentGroup = null;
foreach ($items as $item) {
    $itemId    = (int) ($item['id'] ?? 0);
    $typeLabel = (string) ($item['_typelabel'] ?? __('Outros', 'reservaplus'));
    $setting   = (array) ($item['_setting'] ?? ItemSetting::emptySetting());
    $capacity  = $setting['capacity'] ?? null;
    $features  = (array) ($setting['features'] ?? []);

    if ($typeLabel !== $currentGroup) {
        if ($currentGroup !== null) {
            echo '</div>';
        }
        echo "<div class='rp-item-cat' data-group='" . Html::cleanInputText($typeLabel) . "'>";
        echo "<div class='rp-item-cat-head'><i class='ti ti-tag'></i><span>" . Html::cleanInputText($typeLabel) . '</span></div>';
        $currentGroup = $typeLabel;
    }
    $checked = $itemId === $selectedItem ? ' checked' : '';
    echo "<label class='rp-item-card'"
        . " data-capacity='" . ($capacity !== null && $capacity > 0 ? (int) $capacity : '') . "'"
        . " data-features='" . Html::cleanInputText(implode(',', $features)) . "'>";
    echo "<input type='checkbox' name='reservationitems_id[]' value='" . $itemId . "'" . $checked . '>';
    echo "<span class='rp-item-name'>" . Html::cleanInputText((string) ($item['_label'] ?? '#' . $itemId)) . '</span>';
    if ($capacity !== null && $capacity > 0) {
        echo "<span class='rp-item-capacity' title='" . __('Capacidade máxima', 'reservaplus') . "'><i class='ti ti-users'></i>" . (int) $capacity . '</span>';
    }
    if ($features !== []) {
        echo "<span class='rp-item-features'>";
        foreach ($features as $slug) {
            echo "<i class='" . Html::cleanInputText(ItemSetting::getFeatureIcon((string) $slug)) . "' title='" . Html::cleanInputText(ItemSetting::getFeatureLabel((string) $slug)) . "'></i>";
        }
        echo '</span>';
    }
    echo "<span class='rp-item-check'><i class='ti ti-check'></i></span>";
    echo '</label>';
}
if ($currentGroup !== null) {
    echo '</div>';
}
echo '</div>'; // rp-item-list
echo '</div>'; // rp-item-col

// ── COLUNA DIREITA: opções de reserva ──
echo "<div class='rp-options-col'>";

// Período
echo "<div class='rp-section'>";
echo "<div class='rp-section-title'><i class='ti ti-calendar-event'></i> " . __('Período', 'reservaplus') . '</div>';
echo "<div class='rp-dt-row'>";
echo "<label class='rp-dt-label'><span>" . __('Início', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='begin' value='" . Html::cleanInputText($now) . "' required></label>";
echo "<i class='ti ti-arrow-right rp-dt-sep'></i>";
echo "<label class='rp-dt-label'><span>" . __('Fim', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='end' value='" . Html::cleanInputText($end) . "' required></label>";
echo '</div>';
echo "<div class='rp-presets' data-reservaplus-presets data-bh-start='" . Html::cleanInputText($bhStart) . "' data-bh-end='" . Html::cleanInputText($bhEnd) . "'>";
echo "<span class='rp-presets-label'>" . __('Duração rápida:', 'reservaplus') . '</span>';
echo "<div class='rp-presets-btns'>";
foreach (['60' => '1h', '120' => '2h', '240' => '4h'] as $min => $lbl) {
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-duration='" . $min . "'>" . $lbl . '</button>';
}
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-period='morning'>" . __('Manhã', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-period='afternoon'>" . __('Tarde', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-period='full'>" . __('Dia todo', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-success rp-slots-btn' data-reservaplus-find-slots><i class='ti ti-search'></i> " . __('Horários livres', 'reservaplus') . '</button>';
echo '</div>';
echo '</div>';
echo '</div>'; // rp-section período

echo "<div class='reservaplus-availability rp-availability' data-reservaplus-availability hidden></div>";
echo "<div class='rp-slots' data-reservaplus-slots hidden></div>";

// Recorrência — não se aplica ao editar (move só esta ocorrência).
if (!$isEdit) {
    echo "<div class='rp-section'>";
    echo "<div class='rp-section-title'><i class='ti ti-repeat'></i> " . __('Recorrência', 'reservaplus') . '</div>';
    echo "<div data-reservaplus-recurrence>";
    echo "<label class='rp-dt-label'><span>" . __('Repetir', 'reservaplus') . "</span><select name='recurrence_type' class='form-select' data-reservaplus-rec-type>";
    echo "<option value='none'>" . __('Não repete', 'reservaplus') . '</option>';
    echo "<option value='daily'>" . __('Diariamente', 'reservaplus') . '</option>';
    echo "<option value='weekly'>" . __('Semanalmente', 'reservaplus') . '</option>';
    echo "<option value='monthly'>" . __('Mensalmente (mesmo dia do mês)', 'reservaplus') . '</option>';
    echo '</select></label>';
    echo "<div class='reservaplus-rec-weekdays' data-reservaplus-rec-weekdays hidden>";
    echo "<span class='reservaplus-rec-label'>" . __('Dias da semana', 'reservaplus') . '</span>';
    foreach ([0 => 'Dom', 1 => 'Seg', 2 => 'Ter', 3 => 'Qua', 4 => 'Qui', 5 => 'Sex', 6 => 'Sáb'] as $val => $lbl) {
        echo "<label class='reservaplus-dow'><input type='checkbox' name='recurrence_weekdays[]' value='" . $val . "'><span>" . $lbl . '</span></label>';
    }
    echo '</div>';
    echo "<div class='reservaplus-rec-end' data-reservaplus-rec-end hidden>";
    echo "<label class='rp-dt-label'><span>" . __('Terminar', 'reservaplus') . "</span><select name='recurrence_end' class='form-select' data-reservaplus-rec-endmode>";
    echo "<option value='count'>" . __('Após N ocorrências', 'reservaplus') . '</option>';
    echo "<option value='until'>" . __('Em uma data', 'reservaplus') . '</option>';
    echo '</select></label>';
    echo "<label class='rp-dt-label' data-reservaplus-rec-count><span>" . __('Ocorrências', 'reservaplus') . "</span><input class='form-control' type='number' name='recurrence_count' min='1' max='100' value='4'></label>";
    echo "<label class='rp-dt-label' data-reservaplus-rec-until hidden><span>" . __('Até', 'reservaplus') . "</span><input class='form-control' type='date' name='recurrence_until'></label>";
    echo '</div>';
    echo '</div>'; // data-reservaplus-recurrence
    echo '</div>'; // rp-section recorrência
} elseif (trim((string) ($source['recurrence_group'] ?? '')) !== '') {
    echo "<div class='reservaplus-availability reservaplus-availability--info'><i class='ti ti-repeat'></i><span>"
        . __('Esta reserva faz parte de uma série. A alteração vale só para esta ocorrência.', 'reservaplus')
        . '</span></div>';
}

// Reservar para outro — com busca por nome, já que a lista tem a base inteira.
if ($canForOthers) {
    echo "<div class='rp-dt-label rp-for-others' data-reservaplus-user-picker>";
    echo "<span><i class='ti ti-user-share reservaplus-ico-accent'></i> " . __('Reservar para', 'reservaplus') . '</span>';
    echo "<div class='rp-search-box'>";
    echo "<i class='ti ti-search'></i>";
    echo "<input type='search' class='form-control form-control-sm' data-reservaplus-user-search placeholder='" . __('Procurar pessoa…', 'reservaplus') . "' aria-label='" . __('Procurar pessoa', 'reservaplus') . "'>";
    echo '</div>';
    echo "<select name='users_id_for' class='form-select' data-reservaplus-user-select>";
    echo "<option value='" . (int) Session::getLoginUserID() . "'" . ($selectedFor === (int) Session::getLoginUserID() ? ' selected' : '') . '>' . __('Eu mesmo', 'reservaplus') . '</option>';
    foreach ($users as $user) {
        $uid = (int) ($user['id'] ?? 0);
        if ($uid === (int) Session::getLoginUserID()) {
            continue;
        }
        $sel = $uid === $selectedFor ? ' selected' : '';
        echo "<option value='" . $uid . "'" . $sel . '>' . Html::cleanInputText((string) ($user['_label'] ?? '#' . $uid)) . '</option>';
    }
    echo '</select>';
    echo "<p class='rp-search-empty' data-reservaplus-user-empty hidden>" . __('Ninguém com esse nome.', 'reservaplus') . '</p>';
    echo '</div>';
}

// Quantidade de pessoas (obrigatório)
echo "<label class='rp-dt-label'>";
echo "<span><i class='ti ti-users reservaplus-ico-accent'></i> " . __('Quantidade de pessoas', 'reservaplus') . " <span class='rp-required'>*</span></span>";
echo "<input class='form-control' type='number' name='people_count' min='1' max='9999' step='1' value='" . $peopleCount . "' required>";
echo '</label>';

// Comentário
echo "<label class='rp-dt-label'><span><i class='ti ti-message'></i> " . __('Comentário', 'reservaplus') . "</span><textarea class='form-control' name='comment' rows='3' placeholder='" . __('Motivo, sala, observações...', 'reservaplus') . "'>" . Html::cleanInputText($comment) . '</textarea></label>';

// Botões de ação
echo "<div class='rp-submit-row'>";
if ($isEdit) {
    echo Html::submit(__('Salvar alterações', 'reservaplus'), ['name' => 'update', 'class' => 'btn btn-primary rp-btn-reservar']);
    echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('reservation.php') . "'>" . __('Cancelar', 'reservaplus') . '</a>';
} else {
    echo Html::submit(__('Reservar', 'reservaplus'), ['name' => 'add', 'class' => 'btn btn-primary rp-btn-reservar']);
    echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('calendar.php') . "'><i class='ti ti-calendar'></i> " . __('Ver calendário', 'reservaplus') . '</a>';
}
echo '</div>';

echo '</div>'; // rp-options-col
echo '</div>'; // rp-booking-layout
echo '</section>';
Html::closeForm();
echo '</div>';

Dashboard::renderFooter();
