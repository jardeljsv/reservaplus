<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

function plugin_reservaplus_calendar_events(string $start, string $end): array
{
    return \GlpiPlugin\Reservaplus\LegacyCalendar::events($start, $end);
}

function plugin_reservaplus_calendar_item_name(string $itemtype, int $itemsId): string
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

function plugin_reservaplus_calendar_reservable_items(): array
{
    global $DB;

    if (!$DB->tableExists('glpi_reservationitems')) {
        return [];
    }

    $items = [];
    foreach ($DB->request([
        'FROM'  => 'glpi_reservationitems',
        'WHERE' => ['is_active' => 1],
        'ORDER' => ['id ASC'],
    ]) as $row) {
        if (!\GlpiPlugin\Reservaplus\ItemGroup::isLegacyBookable((int) $row['id'])) {
            continue;
        }
        $row['_label'] = plugin_reservaplus_calendar_item_name(
            (string) ($row['itemtype'] ?? ''),
            (int) ($row['items_id'] ?? 0)
        );
        $items[] = $row;
    }

    // Ordem alfabética: a reserva rápida lista as salas para escolher, e o id de
    // cadastro não ajuda a encontrar nenhuma delas.
    usort($items, static fn(array $a, array $b): int => strnatcasecmp(
        ReservationRequest::normalizeForSearch((string) ($a['_label'] ?? '')),
        ReservationRequest::normalizeForSearch((string) ($b['_label'] ?? ''))
    ));

    return $items;
}

function plugin_reservaplus_calendar_active_users(): array
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
        'WHERE' => ['glpi_users.is_deleted' => 0, 'glpi_users.is_active' => 1, 'glpi_profiles_users.entities_id' => ReservationRequest::entityIds() ?: [-1]],
        'ORDER'  => ['realname ASC', 'firstname ASC'],
        'LIMIT'  => 500,
    ]) as $row) {
        $first = trim((string) ($row['firstname'] ?? ''));
        $last  = trim((string) ($row['realname'] ?? ''));
        $full  = trim($first . ' ' . $last);
        $row['_label'] = $full !== '' ? $full : (string) ($row['name'] ?? '#' . $row['id']);
        $users[] = $row;
    }

    // Alfabético pelo nome exibido (o ORDER do banco usa realname, então "Ana
    // Beatriz" aparecia depois de "Nodielly Borim" só por causa do sobrenome).
    usort($users, static fn(array $a, array $b): int => strnatcasecmp(
        ReservationRequest::normalizeForSearch((string) ($a['_label'] ?? '')),
        ReservationRequest::normalizeForSearch((string) ($b['_label'] ?? ''))
    ));

    return $users;
}

function plugin_reservaplus_render_month(DateTimeImmutable $month, array $events): void
{
    $weekdays = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    $months = [
        1  => 'janeiro',
        2  => 'fevereiro',
        3  => 'março',
        4  => 'abril',
        5  => 'maio',
        6  => 'junho',
        7  => 'julho',
        8  => 'agosto',
        9  => 'setembro',
        10 => 'outubro',
        11 => 'novembro',
        12 => 'dezembro',
    ];
    $first = $month->modify('first day of this month');
    $start = $first->modify('-' . (int) $first->format('w') . ' days');
    $eventMap = [];

    foreach ($events as $event) {
        $eventStart = strtotime((string) ($event['start'] ?? ''));
        $eventEnd = strtotime((string) ($event['end'] ?? ''));
        for ($day = $start, $i = 0; $i < 42; $day = $day->modify('+1 day'), $i++) {
            if ($eventStart !== false && $eventEnd !== false
                && $eventStart < $day->modify('+1 day')->getTimestamp() && $eventEnd > $day->getTimestamp()) {
                $eventMap[$day->format('Y-m-d')][] = $event;
            }
        }
    }

    $monthLabel = ($months[(int) $first->format('n')] ?? $first->format('m')) . ' de ' . $first->format('Y');
    echo "<div class='reservaplus-calendar-title'>" . Html::cleanInputText(ucfirst($monthLabel)) . '</div>';
    echo "<div class='reservaplus-calendar-grid'>";
    foreach ($weekdays as $weekday) {
        echo "<div class='reservaplus-calendar-weekday'>" . Html::cleanInputText($weekday) . '</div>';
    }

    for ($index = 0; $index < 42; $index++) {
        $day = $start->modify('+' . $index . ' days');
        $key = $day->format('Y-m-d');
        $classes = ['reservaplus-calendar-day'];
        if ($day->format('m') !== $month->format('m')) {
            $classes[] = 'is-muted';
        }
        if ($key === date('Y-m-d')) {
            $classes[] = 'is-today';
        }

        echo "<div class='" . implode(' ', $classes) . "' data-reservaplus-day='" . Html::cleanInputText($key) . "' role='button' tabindex='0' aria-label='" . sprintf(__('Criar reserva em %s', 'reservaplus'), Html::cleanInputText($day->format('d/m/Y'))) . "'>";
        echo "<div class='reservaplus-calendar-day-number'>" . (int) $day->format('j') . '</div>';

        foreach (array_slice($eventMap[$key] ?? [], 0, 3) as $event) {
            $type = (string) preg_replace('/[^a-z0-9_-]/i', '', (string) ($event['type'] ?? 'request'));
            echo "<div class='reservaplus-calendar-event " . Html::cleanInputText($type) . "' title='" . Html::cleanInputText((string) ($event['title'] ?? '')) . "'>";
            echo Html::cleanInputText((string) ($event['title'] ?? ''));
            echo '</div>';
        }

        echo '</div>';
    }
    echo '</div>';
}

$month = new DateTimeImmutable(date('Y-m-01'));
$events = plugin_reservaplus_calendar_events(
    $month->format('Y-m-01 00:00:00'),
    $month->modify('first day of next month')->format('Y-m-d 00:00:00')
);
$reservableItems  = plugin_reservaplus_calendar_reservable_items();
$canForOthers     = \GlpiPlugin\Reservaplus\Access::right('on_behalf');
$calendarUsers    = $canForOthers ? plugin_reservaplus_calendar_active_users() : [];
$csrfToken        = Session::getNewCSRFToken();
$currentUserId    = (int) Session::getLoginUserID();
$isAdmin          = ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests();
$canCreate        = ReservationRequest::canCreate();
$deleteActionUrl  = Dashboard::getUrl('reservation.action.php');

Dashboard::renderHeader(__('Calendário do Reserva Plus', 'reservaplus'));
Dashboard::includeAssets();

echo "<div class='reservaplus-shell'>";
echo "<section class='reservaplus-panel reservaplus-calendar-panel'>";
echo "<div class='reservaplus-panel-header'>";
echo '<div>';
echo '<h1>' . __('Calendário', 'reservaplus') . '</h1>';
echo '<p>' . __('Visualize e gerencie reservas. Clique em um dia para reservar rapidamente.', 'reservaplus') . '</p>';
echo '</div>';
echo "<div class='reservaplus-actions'>";
// Saída do calendário: sem isto a única forma de voltar era sair do plugin e
// entrar de novo — o calendário virava um beco sem saída.
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('reservation.php') . "'><i class='ti ti-arrow-left'></i> " . __('Minhas reservas', 'reservaplus') . '</a>';
echo "<div class='reservaplus-view-switcher'>";
echo "<button type='button' class='btn btn-sm btn-secondary' data-reservaplus-view='month'>" . __('Mês', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-view='week'>" . __('Semana', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-view='day'>" . __('Dia', 'reservaplus') . '</button>';
echo '</div>';
echo "<button type='button' class='btn btn-outline-secondary reservaplus-calendar-prev' title='" . __('Anterior', 'reservaplus') . "'><i class='ti ti-chevron-left'></i></button>";
echo "<button type='button' class='btn btn-outline-secondary reservaplus-calendar-today'>" . __('Hoje', 'reservaplus') . '</button>';
echo "<button type='button' class='btn btn-outline-secondary reservaplus-calendar-next' title='" . __('Próximo', 'reservaplus') . "'><i class='ti ti-chevron-right'></i></button>";
// Ir direto para uma data, em vez de avançar mês a mês até chegar lá.
echo "<div class='reservaplus-date-jump'>";
echo "<i class='ti ti-calendar-search'></i>";
echo "<input type='date' class='form-control form-control-sm' data-reservaplus-date-jump value='" . Html::cleanInputText(date('Y-m-d')) . "' aria-label='" . __('Ir para a data', 'reservaplus') . "' title='" . __('Ir para a data', 'reservaplus') . "'>";
echo "<button type='button' class='btn btn-sm btn-outline-primary' data-reservaplus-date-go>" . __('Ir', 'reservaplus') . '</button>';
echo '</div>';
if (ReservationRequest::canCreate()) {
    echo "<button type='button' class='btn btn-primary' data-reservaplus-open-today><i class='ti ti-plus'></i> " . __('Reservar', 'reservaplus') . '</button>';
}
echo '</div>';
echo '</div>';
echo "<div class='reservaplus-calendar' data-events-url='" . Html::cleanInputText(Plugin::getWebDir('reservaplus') . '/ajax/events.php') . "'>";
plugin_reservaplus_render_month($month, $events);
echo '</div>';
echo "<div class='reservaplus-calendar-legend'>";
echo "<div class='reservaplus-calendar-legend-item'><span class='reservaplus-calendar-legend-dot reservaplus-legend-native'></span>" . __('Reserva confirmada (GLPI)', 'reservaplus') . '</div>';
echo "<div class='reservaplus-calendar-legend-item'><span class='reservaplus-calendar-legend-dot reservaplus-legend-active'></span>" . __('Reserva ativa', 'reservaplus') . '</div>';
echo "<div class='reservaplus-calendar-legend-item'><span class='reservaplus-calendar-legend-dot reservaplus-legend-block'></span>" . __('Bloqueio de horário', 'reservaplus') . '</div>';
echo '</div>';
echo '</section>';
if (ReservationRequest::canCreate()) {
    echo "<div class='reservaplus-modal' data-reservaplus-modal hidden>";
    echo "<div class='reservaplus-modal-backdrop' data-reservaplus-modal-close></div>";
    echo "<div class='reservaplus-modal-dialog' role='dialog' aria-modal='true' aria-labelledby='reservaplus-modal-title'>";
    echo "<form method='post' action='" . Dashboard::getUrl('reservation.form.php') . "' data-reservaplus-availability-form data-availability-url='" . Html::cleanInputText(Plugin::getWebDir('reservaplus') . '/ajax/availability.php') . "'>";
    echo "<div class='reservaplus-modal-header'>";
    echo '<div>';
    echo "<span class='reservaplus-kicker'>" . __('Reserva rápida', 'reservaplus') . '</span>';
    echo "<h2 id='reservaplus-modal-title'>" . __('Nova reserva', 'reservaplus') . '</h2>';
    echo "<p data-reservaplus-modal-date>" . __('Selecione um dia no calendário.', 'reservaplus') . '</p>';
    echo '</div>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-modal-close aria-label='" . __('Fechar', 'reservaplus') . "'><i class='ti ti-x'></i></button>";
    echo '</div>';
    echo "<div class='reservaplus-modal-body'>";

    // Itens reserváveis (um ou vários — reserva em massa)
    echo "<div class='reservaplus-field-wide reservaplus-item-tools' data-reservaplus-item-tools>";
    echo "<div class='rp-search-box'>";
    echo "<i class='ti ti-search'></i>";
    echo "<input type='search' class='form-control form-control-sm' data-reservaplus-item-search placeholder='" . __('Procurar sala…', 'reservaplus') . "' aria-label='" . __('Procurar sala', 'reservaplus') . "'>";
    echo '</div>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-select-all>" . __('Todos', 'reservaplus') . '</button>';
    echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-clear-sel>" . __('Limpar', 'reservaplus') . '</button>';
    echo '</div>';
    echo "<div class='reservaplus-field-wide'>";
    echo '<span class="rp-modal-field-label">' . __('Itens reserváveis', 'reservaplus') . ' <small class="text-muted">' . __('(marque um ou vários — em ordem alfabética)', 'reservaplus') . '</small></span>';
    echo "<div class='rp-item-list rp-item-list--modal' data-reservaplus-item-list>";
    foreach ($reservableItems as $item) {
        $itemId = (int) ($item['id'] ?? 0);
        echo "<label class='rp-item-card'>";
        echo "<input type='checkbox' name='reservationitems_id[]' value='" . $itemId . "'>";
        echo "<span class='rp-item-name'>" . Html::cleanInputText((string) ($item['_label'] ?? '#' . $itemId)) . '</span>';
        echo "<span class='rp-item-check'><i class='ti ti-check'></i></span>";
        echo '</label>';
    }
    echo '</div>';
    echo '</div>';

    // Início e Fim
    echo '<label><span>' . __('Início', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='begin' data-reservaplus-begin required></label>";
    echo '<label><span>' . __('Fim', 'reservaplus') . "</span><input class='form-control' type='datetime-local' name='end' data-reservaplus-end required></label>";

    // Quantidade de pessoas (obrigatório)
    echo '<label><span><i class="ti ti-users reservaplus-ico-accent"></i> ' . __('Quantidade de pessoas', 'reservaplus') . " <span class='rp-required'>*</span></span><input class='form-control' type='number' name='people_count' min='1' max='9999' step='1' value='1' required></label>";

    // Indicador de disponibilidade em tempo real
    echo "<div class='reservaplus-availability reservaplus-field-wide' data-reservaplus-availability hidden></div>";

    // Reservar para (somente admin/gestor). Com centenas de usuários o <select>
    // puro é impraticável — daí o campo de busca que filtra as opções.
    if ($canForOthers && $calendarUsers !== []) {
        echo "<div class='reservaplus-field-wide reservaplus-for-others' data-reservaplus-user-picker>";
        echo "<span class='rp-modal-field-label'><i class='ti ti-user-share reservaplus-ico-accent'></i> " . __('Reservar para', 'reservaplus') . '</span>';
        echo "<div class='rp-search-box'>";
        echo "<i class='ti ti-search'></i>";
        echo "<input type='search' class='form-control form-control-sm' data-reservaplus-user-search placeholder='" . __('Procurar pessoa…', 'reservaplus') . "' aria-label='" . __('Procurar pessoa', 'reservaplus') . "'>";
        echo '</div>';
        echo "<select name='users_id_for' class='form-select' data-reservaplus-user-select size='6'>";
        echo "<option value='" . (int) Session::getLoginUserID() . "' selected>" . __('Eu mesmo', 'reservaplus') . '</option>';
        foreach ($calendarUsers as $user) {
            if ((int) ($user['id'] ?? 0) === (int) Session::getLoginUserID()) {
                continue;
            }
            echo "<option value='" . (int) ($user['id'] ?? 0) . "'>" . Html::cleanInputText((string) ($user['_label'] ?? '#' . $user['id'])) . '</option>';
        }
        echo '</select>';
        echo "<p class='rp-search-empty' data-reservaplus-user-empty hidden>" . __('Ninguém com esse nome.', 'reservaplus') . '</p>';
        echo '</div>';
    }

    // Comentário
    echo '<label class="reservaplus-field-wide"><span>' . __('Comentário', 'reservaplus') . "</span><textarea class='form-control' name='comment' rows='3' placeholder='" . __('Motivo, sala, observações...', 'reservaplus') . "'></textarea></label>";
    echo '</div>';
    echo "<div class='reservaplus-modal-footer'>";
    echo "<button type='button' class='btn btn-outline-secondary' data-reservaplus-modal-close>" . __('Cancelar', 'reservaplus') . '</button>';
    echo Html::submit(__('Criar reserva', 'reservaplus'), ['name' => 'add', 'class' => 'btn btn-primary']);
    echo '</div>';
    Html::closeForm();
    echo '</div>';
    echo '</div>';
}

echo "<div class='reservaplus-daydetail' data-reservaplus-daydetail hidden>";
echo "<div class='reservaplus-modal-backdrop' data-reservaplus-daydetail-close></div>";
echo "<div class='reservaplus-daydetail-dialog' role='dialog' aria-modal='true' aria-labelledby='reservaplus-daydetail-title'>";
echo "<div class='reservaplus-modal-header'>";
echo '<div>';
echo "<span class='reservaplus-kicker'>" . __('Reservas do dia', 'reservaplus') . '</span>';
echo "<h2 id='reservaplus-daydetail-title' data-reservaplus-daydetail-date>-</h2>";
echo '</div>';
echo "<button type='button' class='btn btn-sm btn-outline-secondary' data-reservaplus-daydetail-close aria-label='" . __('Fechar', 'reservaplus') . "'><i class='ti ti-x'></i></button>";
echo '</div>';
echo "<div class='reservaplus-daydetail-body' data-reservaplus-daydetail-body></div>";
echo "<div class='reservaplus-modal-footer' data-reservaplus-daydetail-footer>";
echo "<button type='button' class='btn btn-outline-secondary' data-reservaplus-daydetail-close>" . __('Fechar', 'reservaplus') . '</button>';
if ($canCreate) {
    echo "<button type='button' class='btn btn-primary' data-reservaplus-daydetail-reserve><i class='ti ti-plus'></i> " . __('Nova reserva', 'reservaplus') . '</button>';
}
echo '</div>';
echo '</div>';
echo '</div>';

$jsConfig = json_encode([
    'csrfToken'       => $csrfToken,
    'currentUserId'   => $currentUserId,
    'isAdmin'         => $isAdmin,
    'canCreate'       => $canCreate,
    'deleteActionUrl' => $deleteActionUrl,
], JSON_THROW_ON_ERROR);
echo Html::scriptBlock('window.reservaplusConfig = ' . $jsConfig . ';');
echo '</div>';

Dashboard::renderFooter();
