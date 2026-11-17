<?php
declare(strict_types=1);

use GlpiPlugin\Reservaplus\{Access,BookingView,WorkflowCalendarView};

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
$opened=false;
try {
    BookingView::requireReady();Access::assertRight('read');
    $state=WorkflowCalendarView::state($_GET);
    $data=WorkflowCalendarView::data($state);
    BookingView::header(__('Agenda de reservas','reservaplus'),__('Veja a ocupação por dia, semana ou mês e escolha quando usar os recursos.','reservaplus'));
    $opened=true;
    WorkflowCalendarView::render($data);
} catch (Throwable $error) {
    if (!$opened) { http_response_code(403);BookingView::header(__('Agenda de reservas','reservaplus')); }
    BookingView::notice(BookingView::publicError($error),true);
    BookingView::link('workflow.calendar.php',__('Voltar à agenda','reservaplus'));
}
BookingView::footer();
