<?php
declare(strict_types=1);

use GlpiPlugin\Reservaplus\{Access,Booking,BookingView,WorkflowCalendarView};

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');header('X-Content-Type-Options: nosniff');
if (($_SERVER['REQUEST_METHOD']??'')!=='GET') { http_response_code(405);header('Allow: GET');echo json_encode(['error'=>'Método não permitido.']);exit; }
try {
    BookingView::requireReady();Access::assertRight('read');
    $state=WorkflowCalendarView::state($_GET);
    if (isset($_GET['start']) || isset($_GET['end'])) {
        $from=BookingView::text($_GET['start']??'');$to=BookingView::text($_GET['end']??'');
        if (strlen($from)===10) { $from.=' 00:00:00'; }
        if (strlen($to)===10) { $to.=' 00:00:00'; }
        [$state['start'],$state['end']]=Booking::period($from,$to);
    }
    $data=WorkflowCalendarView::data($state);
    echo json_encode(['events'=>$data['events'],'timezone'=>$data['timezone'],'has_more'=>false,'next_offset'=>null,'range'=>['start'=>$state['start'],'end'=>$state['end']]],JSON_UNESCAPED_UNICODE|JSON_THROW_ON_ERROR);
} catch (Throwable $error) {
    http_response_code($error instanceof DomainException||$error instanceof InvalidArgumentException?400:403);
    echo json_encode(['error'=>BookingView::publicError($error)],JSON_UNESCAPED_UNICODE);
}
