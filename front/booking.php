<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\{Access,Booking,BookingQuery,BookingService,BookingView,TechnicianDashboard};

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
$opened = false;
try {
    BookingView::requireReady();
    Access::assertRight('read');
    $id = max(0, (int) ($_GET['id'] ?? 0));
    if ($id > 0) {
        $booking = BookingService::get($id);
        BookingView::header(__('Acompanhar reserva', 'reservaplus'), __('Recursos, próximos passos e registros desta solicitação.', 'reservaplus'));
        $opened = true;
        BookingView::renderDetail($booking);
    } else {
        $staff = TechnicianDashboard::isStaff();
        $query = [];
        foreach (['q','status','kind','scope','queue','date_from','date_to','sort'] as $key) {
            $query[$key] = mb_substr(trim(BookingView::text($_GET[$key] ?? '')), 0, 200);
        }
        $query['scope'] = $staff && $query['scope'] !== 'mine' ? 'team' : 'mine';
        $query['sort'] = in_array($query['sort'], ['newest','start','latest_end'], true) ? $query['sort'] : 'newest';
        $kinds = ['' => __('Todos os tipos', 'reservaplus'), 'room' => __('Salas', 'reservaplus'), 'equipment' => __('Equipamentos', 'reservaplus'), 'other' => __('Outros recursos', 'reservaplus')];
        if (!isset($kinds[$query['kind']])) { $query['kind'] = ''; }
        $statusOptions = array_intersect_key(BookingView::statuses(), array_flip(['submitted','approved','issued','returning','completed','rejected','cancelled']));
        if (!isset($statusOptions[$query['status']])) { $query['status'] = ''; }
        $queues = TechnicianDashboard::queues();
        if (!isset($queues[$query['queue']])) { $query['queue'] = ''; }
        $page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
        $filters = $query;
        $filterError = '';
        try {
            foreach (['date_from','date_to'] as $key) {
                if ($query[$key] !== '') {
                    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/D', $query[$key])) { throw new InvalidArgumentException('Informe uma data válida.'); }
                    Booking::date($query[$key] . ' 00:00:00');
                }
            }
            if ($query['date_from'] !== '' && $query['date_to'] !== '' && $query['date_from'] > $query['date_to']) {
                throw new InvalidArgumentException('O fim do período deve ser igual ou posterior ao início.');
            }
            if ($query['date_from'] !== '') { $filters['from'] = $query['date_from'] . ' 00:00:00'; }
            if ($query['date_to'] !== '') { $filters['to'] = (new DateTimeImmutable($query['date_to']))->modify('+1 day')->format('Y-m-d 00:00:00'); }
            $rows = BookingQuery::search($filters, BookingView::PAGE_SIZE + 1, ($page - 1) * BookingView::PAGE_SIZE);
        } catch (Throwable $error) {
            $filterError = BookingView::publicError($error); $rows = [];
        }
        $more = count($rows) > BookingView::PAGE_SIZE;
        $rows = array_slice($rows, 0, BookingView::PAGE_SIZE);
        BookingView::header($staff ? __('Solicitações de reserva', 'reservaplus') : __('Minhas solicitações', 'reservaplus'), __('Acompanhe os recursos e encontre as solicitações que precisam de atenção.', 'reservaplus'));
        $opened = true;
        if ($query['queue'] !== '') {
            echo '<section class="rp-panel"><div class="rp-heading"><div><h2>' . BookingView::e($queues[$query['queue']]['label']) . '</h2><p class="rp-muted">' . BookingView::e($queues[$query['queue']]['hint']) . '</p></div>';
            BookingView::link('booking.php', __('Ver todas as solicitações', 'reservaplus'), ['scope'=>$query['scope']]);
            echo '</div></section>';
        }
        echo '<section class="rp-panel"><form method="get" action="' . BookingView::e(BookingView::url('booking.php')) . '" class="rp-list-filters" data-rp-request-filters>';
        BookingView::input('q', __('Buscar nas solicitações', 'reservaplus'), $query['q'], 'search', false, 'placeholder="Recurso, local, pessoa ou número" maxlength="200"');
        BookingView::select('status', __('Situação', 'reservaplus'), [''=>__('Todas as situações','reservaplus')]+$statusOptions, $query['status']);
        BookingView::select('kind', __('Tipo de recurso', 'reservaplus'), $kinds, $query['kind']);
        if ($staff) { BookingView::select('scope', __('Visualização', 'reservaplus'), ['team'=>__('Da minha equipe','reservaplus'),'mine'=>__('Minhas solicitações','reservaplus')], $query['scope']); }
        else { BookingView::hidden('scope', 'mine'); }
        if ($query['queue'] !== '') { BookingView::hidden('queue', $query['queue']); }
        $expanded = $query['date_from'] !== '' || $query['date_to'] !== '' || $query['sort'] !== 'newest';
        echo '<details class="rp-filter-extra"' . ($expanded ? ' open' : '') . '><summary>' . BookingView::e(__('Período e ordenação', 'reservaplus')) . '</summary><div class="rp-grid">';
        BookingView::input('date_from', __('Em uso a partir de', 'reservaplus'), $query['date_from'], 'date');
        BookingView::input('date_to', __('Em uso até', 'reservaplus'), $query['date_to'], 'date');
        BookingView::select('sort', __('Ordenar por', 'reservaplus'), ['newest'=>__('Solicitações mais recentes','reservaplus'),'start'=>__('Início do uso','reservaplus'),'latest_end'=>__('Devolução mais distante','reservaplus')], $query['sort']);
        echo '</div></details><div class="rp-actions"><div class="rp-actions">';
        BookingView::submit(__('Aplicar filtros', 'reservaplus'));
        BookingView::link('booking.php', __('Limpar filtros', 'reservaplus'), ['scope'=>$query['scope']]);
        echo '</div><span class="rp-muted">' . BookingView::e($staff && $query['scope']==='team' ? __('Somente solicitações sob sua responsabilidade.', 'reservaplus') : __('Solicitações feitas por você ou para seu uso.', 'reservaplus')) . '</span></div></form>';
        echo '<div class="rp-result-heading"><h2>' . BookingView::e(__('Reservas encontradas', 'reservaplus')) . '</h2>';
        if ($rows !== []) { echo '<span class="rp-muted">' . BookingView::e(sprintf(__('Exibindo %1$d–%2$d', 'reservaplus'), ($page-1)*BookingView::PAGE_SIZE+1, ($page-1)*BookingView::PAGE_SIZE+count($rows))) . '</span>'; }
        echo '</div>';
        if ($filterError !== '') { BookingView::notice($filterError, true); }
        else { BookingView::renderList($rows); }
        BookingView::pagination('booking.php', $query, $page, $more);
        echo '</section>';
        BookingView::timezoneNote();
    }
} catch (Throwable $error) {
    if (!$opened) { http_response_code(403); BookingView::header(__('Reserva Plus','reservaplus')); }
    BookingView::notice(BookingView::publicError($error), true);
}
BookingView::footer();
