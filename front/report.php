<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\Store;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Report;
use GlpiPlugin\Reservaplus\ReservationRequest;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkRight(Report::$rightname, READ);

global $DB;

$isAdmin = Session::haveRight(Report::$rightname, READ) && Session::haveRight('plugin_reservaplus_config', UPDATE);

$view = (string) ($_GET['view'] ?? 'week');
$view = in_array($view, ['week', 'month'], true) ? $view : 'week';

$offsetParam = (int) ($_GET['offset'] ?? 0);

if ($view === 'week') {
    $monday = new DateTimeImmutable(date('Y-m-d', strtotime('monday this week')));
    if ($offsetParam !== 0) {
        $monday = $monday->modify(($offsetParam > 0 ? '+' : '') . $offsetParam . ' weeks');
    }
    $periodStart = $monday->format('Y-m-d 00:00:00');
    $periodEnd   = $monday->modify('+7 days')->format('Y-m-d 00:00:00');
    $periodLabel = $monday->format('d/m/Y') . ' – ' . $monday->modify('+6 days')->format('d/m/Y');
} else {
    $firstDay = new DateTimeImmutable(date('Y-m-01'));
    if ($offsetParam !== 0) {
        $firstDay = $firstDay->modify(($offsetParam > 0 ? '+' : '') . $offsetParam . ' months');
    }
    $periodStart = $firstDay->format('Y-m-d 00:00:00');
    $periodEnd   = $firstDay->modify('first day of next month')->format('Y-m-d 00:00:00');
    $monthNames  = [
        1 => 'Janeiro', 2 => 'Fevereiro', 3 => 'Março', 4 => 'Abril',
        5 => 'Maio', 6 => 'Junho', 7 => 'Julho', 8 => 'Agosto',
        9 => 'Setembro', 10 => 'Outubro', 11 => 'Novembro', 12 => 'Dezembro',
    ];
    $periodLabel = ($monthNames[(int) $firstDay->format('n')] ?? $firstDay->format('m')) . ' de ' . $firstDay->format('Y');
}

$rows = [];
// Current workflow is authoritative; legacy requests remain a separate history.
$source = (string) ($_GET['source'] ?? 'workflow');
$source = $source === 'legacy' ? 'legacy' : 'workflow';
if ($source === 'workflow' && Access::right('read') && Access::entityIds() !== []
    && $DB->tableExists(Store::table('bookings'))) {
    foreach (Store::find('bookings', [
        'entities_id' => Access::entityIds(),
        'end' => ['>', $periodStart],
        'begin' => ['<', $periodEnd],
    ], ['ORDER' => ['begin DESC', 'id DESC']]) as $booking) {
        if (!Access::booking($booking)) { continue; }
        $lines = Store::find('bookinglines', ['bookings_id' => (int) $booking['id']]);
        $items = [];
        $actualSeconds = 0;
        foreach ($lines as $line) {
            $resourceId = (int) $line['resources_id'];
            $resource = Store::find('resources', ['id' => $resourceId], ['LIMIT' => 1])[0] ?? [];
            $items[$resourceId] = (string) ($resource['name'] ?? ('#' . $resourceId));
            if (!empty($line['issued_at']) && !empty($line['received_at'])) {
                $actualSeconds += max(0, min(strtotime($line['received_at']), strtotime($periodEnd))
                    - max(strtotime($line['issued_at']), strtotime($periodStart)));
            }
        }
        $snapshot = Store::decode($booking['borrower_snapshot_json'] ?? null);
        $booking['_items'] = $items;
        $booking['_actual_seconds'] = $actualSeconds;
        $booking['_borrower'] = (string) ($snapshot['name'] ?? ('#' . $booking['users_id_borrower']));
        $booking['comment'] = $booking['purpose'] ?? '';
        $rows[] = $booking;
    }
}
if ($source === 'legacy' && $DB->tableExists(ReservationRequest::getTable())) {
    $where = [
        ReservationRequest::getTable() . '.end'   => ['>', $periodStart],
        ReservationRequest::getTable() . '.begin' => ['<', $periodEnd],
    ];
    if (!$isAdmin) {
        $where[] = ['OR' => [
            [ReservationRequest::getTable() . '.users_id_requester' => (int) Session::getLoginUserID()],
            [ReservationRequest::getTable() . '.users_id_for' => (int) Session::getLoginUserID()],
        ]];
    }
    for ($offset = 0; ; $offset += 500) {
        $batch = ReservationRequest::getFiltered($where, 500, $offset);
        array_push($rows, ...$batch);
        if (count($batch) < 500) {
            break;
        }
    }
}

$statusLabels = ['submitted' => 'Aguardando autorização', 'approved' => 'Autorizada',
    'issued' => 'Em empréstimo', 'returning' => 'Em devolução', 'completed' => 'Concluída',
    'cancelled' => 'Cancelada', 'rejected' => 'Rejeitada'];
$statusCounts = array_count_values(array_column($rows, 'status'));
$borrowers = [];
$actualSeconds = 0;
$totalHours     = 0.0;
$totalPeople    = 0;
$cancelledCount = 0;
$itemAgg        = []; // label => ['count' => n, 'hours' => h, 'people' => p]
foreach ($rows as $r) {
    $b = strtotime((string) ($r['begin'] ?? ''));
    $e = strtotime((string) ($r['end'] ?? ''));
    $hours = ($b !== false && $e !== false)
        ? max(0, min($e, (int) strtotime($periodEnd)) - max($b, (int) strtotime($periodStart))) / 3600 : 0.0;
    $people = max(1, (int) ($r['people_count'] ?? 1));
    if ($source === 'workflow') { $borrowers[(int) $r['users_id_borrower']] = true; $actualSeconds += $r['_actual_seconds']; }
    if ((string) ($r['status'] ?? '') === ReservationRequest::STATUS_CANCELLED) {
        $cancelledCount++;
        continue;
    }
    if (($r['status'] ?? '') === 'rejected') { continue; }
    $totalHours += $hours;
    $totalPeople += $people;
    $items = $source === 'workflow' ? $r['_items'] : [(int) $r['reservationitems_id'] => ReservationRequest::getItemDisplayName($r)];
    foreach ($items as $itemId => $label) {
        if (!isset($itemAgg[$itemId])) {
            $itemAgg[$itemId] = ['label' => $label, 'count' => 0, 'hours' => 0.0, 'people' => 0];
        }
        $itemAgg[$itemId]['count']++;
        $itemAgg[$itemId]['hours'] += $hours;
        $itemAgg[$itemId]['people'] += $people;
    }
}
uasort($itemAgg, static fn(array $a, array $b): int => [$b['count'], $b['hours']] <=> [$a['count'], $a['hours']]);
$topItems = array_slice($itemAgg, 0, 5, true);

$prevOffset = $offsetParam - 1;
$nextOffset = $offsetParam + 1;

function plugin_reservaplus_report_url(string $view, int $offset, string $source): string
{
    return Dashboard::getUrl('report.php') . '?view=' . urlencode($view) . '&offset=' . $offset . '&source=' . urlencode($source);
}

Html::header(__('Relatórios do Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

echo "<style>
@media print {
  .reservaplus-no-print, .reservaplus-hero, .reservaplus-stats { display: none !important; }
  .reservaplus-shell { padding: 0 !important; max-width: 100% !important; }
  .reservaplus-panel { box-shadow: none !important; border: none !important; }
  .reservaplus-print-header { display: block !important; }
  body { font-size: 12px; }
  table { page-break-inside: auto; }
  tr { page-break-inside: avoid; }
  thead { display: table-header-group; }
}
/* Cabeçalho que só existe no PDF/impressão */
.reservaplus-print-header { display: none; border-bottom: 3px solid #2563eb; padding-bottom: 10px; margin-bottom: 14px; }
.reservaplus-print-header h2 { margin: 0; font-size: 1.25rem; color: #111827; }
.reservaplus-print-header p { margin: 3px 0 0; font-size: 0.8rem; color: #475467; }
.reservaplus-print-kpis { display: flex; gap: 20px; margin-top: 8px; font-size: 0.88rem; color: #1f2937; font-weight: 600; flex-wrap: wrap; }

.reservaplus-period-bar { display: flex; align-items: center; justify-content: space-between; gap: 12px; background: #fff; border: 1px solid #e4e9f0; border-radius: 14px; padding: 10px 16px; margin-bottom: 16px; flex-wrap: wrap; box-shadow: 0 1px 2px rgba(16,24,40,.04); }
.reservaplus-view-tabs { display: flex; gap: 6px; }
.reservaplus-period-nav { display: flex; align-items: center; gap: 8px; }
.reservaplus-period-nav strong { font-size: 1.02rem; color: #1f2937; min-width: 200px; text-align: center; }

.reservaplus-report-table th { background: #f8fafc; font-size: 0.78rem; text-transform: uppercase; color: #475467; }
.reservaplus-report-table td { vertical-align: middle; }
.reservaplus-duration { font-size: 0.82rem; color: #667085; }
.reservaplus-report-meta { margin: 0; font-size: 0.78rem; color: #667085; }
.reservaplus-top-items { padding: 14px 20px 8px; }
.reservaplus-top-items-title { font-weight: 700; font-size: 0.85rem; color: #344054; margin: 0 0 8px; display: flex; align-items: center; gap: 6px; }
.reservaplus-top-items-title i { color: #d97706; }
.reservaplus-top-items-table { max-width: 760px; }
.reservaplus-top-items-table th { background: #f8fafc; font-size: 0.72rem; text-transform: uppercase; color: #475467; }
.reservaplus-top-item-name { font-weight: 600; font-size: 0.85rem; }
.reservaplus-top-item-bar { height: 6px; background: #eef2f6; border-radius: 3px; margin-top: 4px; max-width: 280px; }
.reservaplus-top-item-bar > div { height: 100%; border-radius: 3px; background: linear-gradient(90deg, #2563eb, #7c3aed); }
</style>";

echo "<div class='reservaplus-shell'>";

$generatedBy = sprintf(
    __('Gerado em %1$s por %2$s', 'reservaplus'),
    date('d/m/Y H:i'),
    (string) ($_SESSION['glpifriendlyname'] ?? $_SESSION['glpiname'] ?? '')
);

// ── Hero da marca (some na impressão) ──
echo "<div class='reservaplus-hero reservaplus-no-print'>";
echo "<div class='reservaplus-hero-content'>";
echo "<span class='reservaplus-brand-mark'><i class='ti ti-chart-bar'></i></span>";
echo "<div class='reservaplus-brand-text'>";
echo '<span class="reservaplus-kicker">' . __('Reserva Plus', 'reservaplus') . '</span>';
echo '<h1>' . __('Relatório de reservas', 'reservaplus') . '</h1>';
echo '<p>' . __('Uso por período, pessoas e itens mais reservados — pronto para imprimir ou salvar em PDF.', 'reservaplus') . '</p>';
echo '</div>';
echo '</div>';
echo "<div class='reservaplus-actions'>";
echo "<a class='btn btn-outline-primary' href='" . Dashboard::getUrl('dashboard.php') . "'><i class='ti ti-arrow-left'></i> " . __('Central', 'reservaplus') . '</a>';
echo "<button type='button' class='btn btn-primary' onclick='window.print()'><i class='ti ti-printer'></i> " . __('Exportar PDF', 'reservaplus') . '</button>';
echo '</div>';
echo '</div>';

// ── Barra de período: semana/mês + navegação ──
echo "<div class='reservaplus-period-bar reservaplus-no-print'>";
echo "<div class='reservaplus-view-tabs'>";
echo "<a class='btn btn-sm " . ($view === 'week' ? 'btn-secondary' : 'btn-outline-secondary') . "' href='" . Html::cleanInputText(plugin_reservaplus_report_url('week', 0, $source)) . "'><i class='ti ti-calendar-week'></i> " . __('Semana', 'reservaplus') . '</a>';
echo "<a class='btn btn-sm " . ($view === 'month' ? 'btn-secondary' : 'btn-outline-secondary') . "' href='" . Html::cleanInputText(plugin_reservaplus_report_url('month', 0, $source)) . "'><i class='ti ti-calendar-month'></i> " . __('Mês', 'reservaplus') . '</a>';
echo '</div>';
echo "<div class='reservaplus-period-nav'>";
echo "<a class='btn btn-sm btn-outline-secondary' href='" . Html::cleanInputText(plugin_reservaplus_report_url($view, $prevOffset, $source)) . "' title='" . __('Período anterior', 'reservaplus') . "'><i class='ti ti-chevron-left'></i></a>";
echo '<strong>' . Html::cleanInputText($periodLabel) . '</strong>';
echo "<a class='btn btn-sm btn-outline-secondary' href='" . Html::cleanInputText(plugin_reservaplus_report_url($view, $nextOffset, $source)) . "' title='" . __('Próximo período', 'reservaplus') . "'><i class='ti ti-chevron-right'></i></a>";
if ($offsetParam !== 0) {
    echo "<a class='btn btn-sm btn-outline-primary' href='" . Html::cleanInputText(plugin_reservaplus_report_url($view, 0, $source)) . "'>" . __('Hoje', 'reservaplus') . '</a>';
}
echo '</div>';
echo '</div>';

echo "<div class='reservaplus-period-bar reservaplus-no-print'>";
foreach (['workflow' => 'Reservas e empréstimos', 'legacy' => 'Histórico legado'] as $key => $label) {
    $url = Dashboard::getUrl('report.php') . '?view=' . urlencode($view) . '&offset=' . $offsetParam . '&source=' . $key;
    echo "<a class='btn btn-sm " . ($source === $key ? 'btn-primary' : 'btn-outline-secondary') . "' href='" . Html::cleanInputText($url) . "'>" . Html::cleanInputText($label) . "</a>";
}
echo '</div>';
// ── KPIs no padrão da Central ──
echo "<div class='reservaplus-grid reservaplus-stats reservaplus-no-print'>";
$kpis = [
    [__('Reservas', 'reservaplus'), (string) count($rows), $periodLabel, 'ti ti-calendar-check', 'primary'],
    [__('Horas reservadas', 'reservaplus'), number_format($totalHours, 1, ',', '.') . 'h', __('Tempo total do período', 'reservaplus'), 'ti ti-clock-hour-4', 'accent'],
    [__('Pessoas', 'reservaplus'), number_format($totalPeople, 0, ',', '.'), __('Nas reservas ativas', 'reservaplus'), 'ti ti-users', 'violet'],
    [__('Canceladas', 'reservaplus'), (string) $cancelledCount, __('No período', 'reservaplus'), 'ti ti-ban', 'rose'],
];
if ($source === 'workflow') {
    $kpis[2] = ['Beneficiários únicos', (string) count($borrowers), 'No período (todos os status)', 'ti ti-users', 'violet'];
    $kpis[] = ['Concluídas', (string) ($statusCounts['completed'] ?? 0), 'No período', 'ti ti-check', 'primary'];
    $kpis[] = ['Rejeitadas', (string) ($statusCounts['rejected'] ?? 0), 'No período', 'ti ti-ban', 'rose'];
    foreach (['submitted', 'approved', 'issued', 'returning'] as $status) {
        $kpis[] = [$statusLabels[$status], (string) ($statusCounts[$status] ?? 0), 'No período', 'ti ti-clock', 'accent'];
    }
    $kpis[] = ['Horas efetivas de equipamentos devolvidos', number_format($actualSeconds / 3600, 2, ',', '.') . 'h', 'Entrega até recebimento, dentro do período', 'ti ti-clock', 'accent'];
}
foreach ($kpis as [$kTitle, $kValue, $kSub, $kIcon, $kVariant]) {
    echo "<div class='reservaplus-stat reservaplus-stat--" . $kVariant . "'>";
    echo "<span class='reservaplus-stat-icon'><i class='" . $kIcon . "'></i></span>";
    echo '<strong>' . Html::cleanInputText($kValue) . '</strong>';
    echo '<span>' . Html::cleanInputText($kTitle) . '</span>';
    echo '<small>' . Html::cleanInputText($kSub) . '</small>';
    echo '</div>';
}
echo '</div>';

echo "<section class='reservaplus-panel'>";

// ── Cabeçalho que aparece somente no PDF/impressão ──
echo "<div class='reservaplus-print-header'>";
echo '<h2>' . __('Relatório de reservas', 'reservaplus') . ' — ' . Html::cleanInputText($periodLabel) . '</h2>';
echo '<p>' . Html::cleanInputText($generatedBy) . '</p>';
echo "<div class='reservaplus-print-kpis'>";
echo '<span>' . __('Reservas', 'reservaplus') . ': ' . count($rows) . '</span>';
echo '<span>' . __('Horas', 'reservaplus') . ': ' . number_format($totalHours, 1, ',', '.') . 'h</span>';
echo '<span>' . ($source === 'workflow' ? 'Beneficiários únicos: ' . count($borrowers) : __('Pessoas', 'reservaplus') . ': ' . number_format($totalPeople, 0, ',', '.')) . '</span>';
if ($source === 'workflow') { foreach ($statusLabels as $key => $label) { if ($key !== 'cancelled') { echo '<span>' . Html::cleanInputText($label) . ': ' . ($statusCounts[$key] ?? 0) . '</span>'; } } echo '<span>Horas efetivas: ' . number_format($actualSeconds / 3600, 2, ',', '.') . 'h</span>'; }
echo '<span>' . __('Canceladas', 'reservaplus') . ': ' . $cancelledCount . '</span>';
echo '</div>';
echo '</div>';

// ── Cabeçalho do painel na tela ──
echo "<div class='reservaplus-panel-header reservaplus-no-print'>";
echo '<div>';
echo '<h2><i class="ti ti-list-details"></i> ' . sprintf(__('Reservas do período (%d)', 'reservaplus'), count($rows)) . '</h2>';
echo "<p class='reservaplus-report-meta'>" . Html::cleanInputText($generatedBy) . '</p>';
echo '</div>';
echo '</div>';

// Ranking de itens mais reservados no período (visão gerencial)
if ($topItems !== [] && count($itemAgg) > 0) {
    echo "<div class='reservaplus-top-items'>";
    echo "<div class='reservaplus-top-items-title'><i class='ti ti-trophy'></i> " . __('Itens mais reservados no período', 'reservaplus') . '</div>';
    echo "<table class='table table-sm reservaplus-top-items-table'>";
    echo '<thead><tr><th style="width:32px">#</th><th>' . __('Item', 'reservaplus') . '</th><th class="text-end">' . __('Reservas', 'reservaplus') . '</th><th class="text-end">' . __('Horas', 'reservaplus') . '</th><th class="text-end">' . __('Pessoas', 'reservaplus') . '</th></tr></thead><tbody>';
    $rank     = 0;
    $maxCount = max(array_column($topItems, 'count'));
    foreach ($topItems as $itemId => $agg) {
        $label = (string) $agg['label'];
        $rank++;
        $pct = $maxCount > 0 ? (int) round($agg['count'] / $maxCount * 100) : 0;
        echo '<tr>';
        echo '<td>' . $rank . '</td>';
        echo '<td><div class="reservaplus-top-item-name">' . Html::cleanInputText((string) $label) . '</div><div class="reservaplus-top-item-bar"><div style="width:' . $pct . '%"></div></div></td>';
        echo '<td class="text-end"><strong>' . (int) $agg['count'] . '</strong></td>';
        echo '<td class="text-end">' . number_format($agg['hours'], 1, ',', '.') . 'h</td>';
        echo '<td class="text-end">' . number_format($agg['people'], 0, ',', '.') . '</td>';
        echo '</tr>';
    }
    echo '</tbody></table>';
    echo '</div>';
}

if ($rows === []) {
    echo "<div class='reservaplus-empty'>";
    echo "<i class='ti ti-calendar-off'></i>";
    echo '<strong>' . __('Nenhuma reserva no período.', 'reservaplus') . '</strong>';
    echo '</div>';
} else {
    echo "<div class='table-responsive'>";
    echo "<table class='table table-hover reservaplus-table reservaplus-report-table'>";
    echo '<thead><tr>';
    echo '<th>ID</th>';
    echo '<th>' . __('Item', 'reservaplus') . '</th>';
    if ($source === 'workflow') {
        echo '<th>Beneficiário</th><th>Solicitante</th><th>Chamado</th>';
    } elseif ($isAdmin) {
        echo '<th>' . __('Solicitante', 'reservaplus') . '</th>';
    }
    echo '<th>' . __('Início', 'reservaplus') . '</th>';
    echo '<th>' . __('Fim', 'reservaplus') . '</th>';
    echo '<th>' . __('Duração', 'reservaplus') . '</th>';
    echo '<th>' . __('Pessoas', 'reservaplus') . '</th>';
    echo '<th>' . __('Status', 'reservaplus') . '</th>';
    echo '<th>' . __('Comentário', 'reservaplus') . '</th>';
    echo '</tr></thead><tbody>';

    foreach ($rows as $row) {
        $b = strtotime((string) ($row['begin'] ?? ''));
        $e = strtotime((string) ($row['end'] ?? ''));
        $durMins = ($b && $e && $e > $b) ? (int) round(($e - $b) / 60) : 0;
        $durH    = intdiv($durMins, 60);
        $durM    = $durMins % 60;
        $durStr  = $durH > 0 ? "{$durH}h" . ($durM > 0 ? "{$durM}min" : '') : ($durM > 0 ? "{$durM}min" : '-');
        $beginFmt = $b ? date('d/m/Y H:i', $b) : '-';
        $endFmt   = $e ? date('d/m/Y H:i', $e) : '-';
        $comment = Html::cleanInputText((string) ($row['comment'] ?? ''));

        echo '<tr>';
        echo '<td>#' . (int) ($row['id'] ?? 0) . '</td>';
        echo '<td>' . Html::cleanInputText($source === 'workflow' ? implode(', ', $row['_items']) : ReservationRequest::getItemDisplayName($row)) . '</td>';
        if ($source === 'workflow') {
            echo '<td>' . Html::cleanInputText($row['_borrower']) . '</td>';
            echo '<td>' . Html::cleanInputText((string) getUserName((int) $row['users_id_requester'])) . '</td>';
            $ticketId = (int) ($row['tickets_id'] ?? 0);
            $ticket = new Ticket();
            echo '<td>';
            if ($ticketId > 0 && $ticket->can($ticketId, READ)) {
                echo '<a href="' . Html::cleanInputText(Ticket::getFormURLWithID($ticketId)) . '">#' . $ticketId . '</a>';
            } else { echo '—'; }
            echo '</td>';
        } elseif ($isAdmin) {
            echo '<td>' . Html::cleanInputText(ReservationRequest::getUserDisplayName($row)) . '</td>';
        }
        echo '<td>' . Html::cleanInputText($beginFmt) . '</td>';
        echo '<td>' . Html::cleanInputText($endFmt) . '</td>';
        echo "<td class='reservaplus-duration'>" . Html::cleanInputText($durStr) . '</td>';
        echo '<td><i class="ti ti-users" style="font-size:0.8rem;opacity:.6"></i> ' . max(1, (int) ($row['people_count'] ?? 1)) . '</td>';
        $rowStatus = (string) ($row['status'] ?? ReservationRequest::STATUS_CREATED);
        echo '<td><span class="reservaplus-badge ' . Html::cleanInputText(($source === 'workflow' ? '' : ReservationRequest::getStatusClass($rowStatus))) . '">' . Html::cleanInputText(($source === 'workflow' ? ($statusLabels[$rowStatus] ?? $rowStatus) : ReservationRequest::getStatusLabel($rowStatus))) . '</span></td>';
        echo '<td>' . ($comment !== '' ? $comment : '<span style="color:#98a2b3">—</span>') . '</td>';
        echo '</tr>';
    }

    echo '</tbody></table>';
    echo '</div>';
}

echo '</section>';
echo '</div>';

Html::footer();
