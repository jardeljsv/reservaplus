<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\LegacyCalendar;
use GlpiPlugin\Reservaplus\ReservationRequest;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkRight(ReservationRequest::$rightname, READ);
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: private, no-store');
try {
    $start = (string) ($_GET['start'] ?? date('Y-m-01 00:00:00'));
    $end = (string) ($_GET['end'] ?? date('Y-m-01 00:00:00', strtotime('first day of next month')));
    echo json_encode(['events' => LegacyCalendar::events($start, $end)], JSON_THROW_ON_ERROR);
} catch (\Throwable $e) {
    http_response_code(400);
    echo json_encode(['error' => __('Não foi possível consultar este período.', 'reservaplus')], JSON_THROW_ON_ERROR);
}
