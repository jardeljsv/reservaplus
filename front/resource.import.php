<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\BookingView;
use GlpiPlugin\Reservaplus\LegacyResourceCatalog;
use GlpiPlugin\Reservaplus\ResourceService;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

$opened = false;
try {
    BookingView::requireReady();
    Access::assertRight('manage');
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'GET') {
        http_response_code(405);
        header('Allow: GET');
        exit;
    }
    $filters = ['itemtype' => BookingView::text($_GET['itemtype'] ?? ''),
        'q' => mb_substr(trim(BookingView::text($_GET['q'] ?? '')), 0, 200)];
    $page = max(1, min(100000, (int) ($_GET['page'] ?? 1)));
    $rows = LegacyResourceCatalog::search($filters, 25, ($page - 1) * 24);
    $more = count($rows) > 24;
    $rows = array_slice($rows, 0, 24);
    BookingView::header(__('Aproveitar itens existentes', 'reservaplus'), __('Escolha um item já reservável no GLPI para organizar sua gestão no Reserva Plus.', 'reservaplus'));
    $opened = true;
    echo '<section class="rp-panel"><div class="rp-heading"><div><h2>' . BookingView::e(__('Selecione o item', 'reservaplus')) . '</h2><p class="rp-muted">' . BookingView::e(__('Você não precisa informar um código. Escolha na lista, revise os dados e defina os responsáveis antes de publicar.', 'reservaplus')) . '</p></div>';
    BookingView::link('resource.php', __('Voltar aos recursos', 'reservaplus'));
    echo '</div><form method="get" action="' . BookingView::e(BookingView::url('resource.import.php')) . '"><div class="rp-grid">';
    BookingView::select('itemtype', __('Categoria no GLPI', 'reservaplus'), ['' => __('Todas as categorias', 'reservaplus')] + ResourceService::nativeTypes(), $filters['itemtype']);
    BookingView::input('q', __('Pesquisar nesta lista (opcional)', 'reservaplus'), $filters['q'], 'search', false, 'maxlength="200" placeholder="Nome, local, patrimônio ou série"');
    echo '<div class="rp-actions">';
    BookingView::submit(__('Aplicar filtros', 'reservaplus'));
    echo '</div></div></form></section>';
    if ($rows === []) {
        echo '<section class="rp-panel rp-empty"><h2>' . BookingView::e(__('Nenhum item para cadastrar neste filtro', 'reservaplus')) . '</h2><p>' . BookingView::e(__('A lista mostra itens reserváveis ativos que você pode acessar e que ainda não estão no novo cadastro. Seus registros anteriores continuam disponíveis.', 'reservaplus')) . '</p><div class="rp-actions">';
        BookingView::link('resource.form.php', __('Cadastrar recurso interno', 'reservaplus'), [], 'btn-primary');
        BookingView::link('reservation.php', __('Consultar reservas anteriores', 'reservaplus'));
        echo '</div></section>';
    } else {
        echo '<section class="rp-panel"><div class="rp-grid">';
        foreach ($rows as $row) {
            echo '<article class="rp-resource"><p class="rp-muted">' . BookingView::e($row['native_type_label']) . '</p><h3>' . BookingView::e($row['name'] ?: __('Item sem nome', 'reservaplus')) . '</h3>';
            if ($row['location_name'] !== '') {
                echo '<p><i class="ti ti-map-pin" aria-hidden="true"></i> ' . BookingView::e($row['location_name']) . '</p>';
            }
            if ($row['resource']['inventory_number'] !== '') {
                echo '<p class="rp-muted">' . BookingView::e(__('Patrimônio:', 'reservaplus') . ' ' . $row['resource']['inventory_number']) . '</p>';
            }
            if ($row['resource']['capacity'] !== null) {
                echo '<p>' . BookingView::e(sprintf(__('Capacidade cadastrada: %d pessoas', 'reservaplus'), $row['resource']['capacity'])) . '</p>';
            }
            if ($row['resource']['features'] !== '') {
                echo '<p class="rp-muted">' . BookingView::e($row['resource']['features']) . '</p>';
            }
            echo '<p class="rp-muted">' . BookingView::e($row['resource']['policy_json']['requester_groups_ids'] !== []
                ? __('Grupos autorizados serão preservados.', 'reservaplus')
                : __('Sem restrição de grupos no cadastro anterior.', 'reservaplus')) . '</p>';
            if ($row['can_enroll']) {
                BookingView::link('resource.form.php', __('Escolher e revisar', 'reservaplus'), ['source_reservationitems_id' => $row['source_reservationitems_id']], 'btn-primary');
            } else {
                echo '<p class="rp-warning">' . BookingView::e(sprintf(__('%d reserva(s) em andamento ou futuras. Conclua ou cancele no fluxo anterior antes de cadastrar este item.', 'reservaplus'), $row['active_reservations_count'])) . '</p>';
            }
            echo '</article>';
        }
        echo '</div>';
        BookingView::pagination('resource.import.php', $filters, $page, $more);
        echo '</section>';
    }
} catch (Throwable $error) {
    if (!$opened) {
        http_response_code(403);
        BookingView::header(__('Aproveitar itens existentes', 'reservaplus'));
    }
    BookingView::notice(BookingView::publicError($error), true);
}
BookingView::footer();
