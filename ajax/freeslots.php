<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ReservationRequest;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

$raw = $_GET['reservationitems_id'] ?? [];
if (!is_array($raw)) {
    $raw = [$raw];
}
$itemIds = array_values(array_unique(array_filter(
    array_map('intval', $raw),
    static fn(int $v): bool => $v > 0
)));

// A mixed authorized/unauthorized request must not produce a misleading partial suggestion.
foreach ($itemIds as $id) {
    if (!ItemGroup::isLegacyBookable($id)) {
        http_response_code(403);
        header('Content-Type: application/json; charset=UTF-8');
        echo json_encode(['slots' => [], 'error' => __('Item indisponível.', 'reservaplus')], JSON_THROW_ON_ERROR);
        exit;
    }
}
header('Cache-Control: private, no-store');

$date  = (string) ($_GET['date'] ?? date('Y-m-d'));
$slots = $itemIds === [] ? [] : (new AvailabilityService())->freeSlots($itemIds, $date);

header('Content-Type: application/json; charset=UTF-8');
echo json_encode(['slots' => $slots], JSON_THROW_ON_ERROR);
