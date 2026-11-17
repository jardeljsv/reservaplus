<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ReservationRequest;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
$reservationItemsId = (int) ($_GET['reservationitems_id'] ?? 0);
$beginTs = strtotime(str_replace('T', ' ', (string) ($_GET['begin'] ?? '')));
$endTs = strtotime(str_replace('T', ' ', (string) ($_GET['end'] ?? '')));
if (!ItemGroup::isLegacyBookable($reservationItemsId)) {
    http_response_code(403);
    echo json_encode(['available' => false, 'error' => __('Item indisponível.', 'reservaplus')], JSON_THROW_ON_ERROR);
    exit;
}
if ($beginTs === false || $endTs === false || $endTs <= $beginTs) {
    http_response_code(400);
    echo json_encode(['available' => false, 'error' => __('Período inválido.', 'reservaplus')], JSON_THROW_ON_ERROR);
    exit;
}
$begin = date('Y-m-d H:i:s', $beginTs);
$end = date('Y-m-d H:i:s', $endTs);

// Ao REAGENDAR, a própria reserva não pode contar como conflito consigo mesma.
// O request é resolvido no servidor (não confiamos em IDs de reserva nativa
// vindos da query) e só vale se o usuário puder editá-lo.
$ignore = [];
$editingRequestId = (int) ($_GET['editing_request_id'] ?? 0);
if ($editingRequestId > 0) {
    global $DB;
    $editingRow = $DB->request([
        'FROM'  => ReservationRequest::getTable(),
        'WHERE' => ['id' => $editingRequestId],
        'LIMIT' => 1,
    ])->current();
    if ($editingRow && ReservationRequest::canEditRow((array) $editingRow)) {
        $ignore = ReservationRequest::getNativeReservationIds((array) $editingRow);
    }
}

$conflicts = (new AvailabilityService())->getConflicts($reservationItemsId, $begin, $end, $ignore);

echo json_encode([
    'available' => $conflicts === [],
    'conflicts' => $conflicts,
], JSON_THROW_ON_ERROR);
