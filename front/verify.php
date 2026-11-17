<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\BookingView;
use GlpiPlugin\Reservaplus\DocumentService;
use GlpiPlugin\Reservaplus\PdfRenderer;
use GlpiPlugin\Reservaplus\Store;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
$e = PdfRenderer::escape(...);
$opened = false;
try {
    $v = DocumentService::verify((string) ($_GET['code'] ?? ''));
    $returnTicket = BookingView::ticketContext(Store::row('bookings', (int) $v['bookings_id']), $_GET['_return_ticket'] ?? 0);
    BookingView::header(__('Verificar documento', 'reservaplus'), __('Confira a versão preservada e os aceites registrados.', 'reservaplus'));
    echo '<section class="rp-panel"><h2>Verificação interna de documento</h2>';
    $opened = true;
    echo '<div class="alert ' . ($v['integrity_valid'] ? 'alert-success' : 'alert-danger') . '">'
        . ($v['integrity_valid'] ? 'Os arquivos conferem com os hashes registrados.' : 'A integridade das evidências não confere.') . '</div>';
    echo '<p>Documento #' . $v['id'] . ' | Reserva #' . $v['bookings_id'] . ' | ' . $e($v['title']) . '</p>';
    echo '<p>Estado: <strong>' . $e(DocumentService::statusLabel($v['status'])) . '</strong>. Escopo: ' . ($v['is_current'] ? 'atual' : 'histórico / substituído') . '.</p>';
    echo '<p>SHA-256 original: <code>' . $e($v['source_sha256']) . '</code><br>SHA-256 final: <code>' . $e($v['final_sha256']) . '</code></p>';
    echo '<ul>';
    foreach ($v['acceptances'] as $a) {
        echo '<li>' . $e((string) $a['name']) . ' - ' . $e(implode(', ', array_map(DocumentService::roleLabel(...), $a['roles']))) . ' - ' . $e($a['event_utc']) . '</li>';
    }
    echo '</ul><p>A integridade confirma a correspondência dos bytes preservados. Documento histórico conserva seus aceites, mas não autoriza um escopo posteriormente alterado. O código de verificação não concede acesso sem autenticação e autorização.</p>';
    echo '<p><a class="btn btn-primary" href="' . $e(BookingView::url('agreement.php', ['id' => (int) $v['id']] + BookingView::ticketContextQuery($returnTicket))) . '">Abrir documento</a></p>';
} catch (Throwable $failure) {
    if (!$opened) {
        http_response_code(403);
        BookingView::header(__('Verificar documento', 'reservaplus'), __('Confira a versão preservada e os aceites registrados.', 'reservaplus'));
        echo '<section class="rp-panel"><h2>Verificação interna de documento</h2>';
    }
    echo '<div class="alert alert-danger">Não foi possível verificar este documento nesta sessão.</div>';
}
echo '</section>';
BookingView::footer();
