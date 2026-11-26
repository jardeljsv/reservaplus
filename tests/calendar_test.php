<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
if(!class_exists('Session',false)){
    class Session { public static function getLoginUserID():int{return 7;} }
}
use GlpiPlugin\Reservaplus\{WorkflowCalendarView,BookingView,CalendarBridge};

rp_test('calendar views use exact half-open days and reject impossible dates',function():void{
    $month=WorkflowCalendarView::state(['date'=>'2026-10-31','view'=>'month']);
    rp_same('2026-09-27 00:00:00',$month['start']);rp_same('2026-11-08 00:00:00',$month['end']);
    $week=WorkflowCalendarView::state(['date'=>'2026-10-28','view'=>'week']);
    rp_same('2026-10-25 00:00:00',$week['start']);rp_same('2026-11-01 00:00:00',$week['end']);
    $day=WorkflowCalendarView::state(['date'=>'2026-12-31','view'=>'day']);rp_same('2027-01-01 00:00:00',$day['end']);
    rp_throws(fn()=>WorkflowCalendarView::state(['date'=>'2026-02-30']),DomainException::class);
    rp_throws(fn()=>WorkflowCalendarView::state(['resources_id'=>'1 OR 1=1']),DomainException::class);
});
rp_test('multi-day occupancy is visible on every occupied day but not the midnight ending',function():void{
    $event=['start'=>'2026-10-28 12:00:00','end'=>'2026-10-30 00:00:00'];
    rp_assert(WorkflowCalendarView::onDay($event,'2026-10-28'));rp_assert(WorkflowCalendarView::onDay($event,'2026-10-29'));
    rp_assert(!WorkflowCalendarView::onDay($event,'2026-10-30'));rp_assert(!WorkflowCalendarView::onDay($event,'2026-10-27'));
});
rp_test('anonymous occupancy has no booking identity, purpose, actors or action link',function():void{
    $_SESSION=[];
    $booking=['id'=>918,'status'=>'approved','users_id_requester'=>8,'users_id_borrower'=>9,'purpose'=>'Private reason'];
    $line=['id'=>34,'state'=>'allocated'];$resource=['id'=>2,'name'=>'Projetor A','kind'=>'equipment'];
    $event=WorkflowCalendarView::bookingEvent($booking,$line,$resource,false,'2026-10-28 08:00:00','2026-10-28 10:00:00');
    rp_same('Projetor A',$event['title']);rp_same('Ocupado',$event['label']);rp_same('',$event['url']);rp_assert(!isset($event['booking_id']));
    rp_assert(!str_contains(json_encode($event),'Private reason'));rp_assert((bool)preg_match('/^[a-f0-9]{24}$/D',$event['id']));
    rp_assert(!isset($event['users_id_borrower']));rp_assert($event['readonly']);
    $authorized=WorkflowCalendarView::bookingEvent($booking,$line,$resource,true,$event['start'],$event['end']);
    rp_same(918,$authorized['booking_id']);rp_assert(str_contains($authorized['url'],'id=918'));
});
rp_test('native calendar release handles return before scheduled start without stale future hold',function():void{
    $begin='2026-11-26 10:15:00';$end='2026-11-26 11:15:00';
    rp_same('delete',CalendarBridge::tailReleaseAction($begin,$end,'2026-11-26 10:04:03'));
    rp_same('delete',CalendarBridge::tailReleaseAction($begin,$end,$begin));
    rp_same('shorten',CalendarBridge::tailReleaseAction($begin,$end,'2026-11-26 10:45:00'));
    rp_same('none',CalendarBridge::tailReleaseAction($begin,$end,$end));
    rp_same('none',CalendarBridge::tailReleaseAction($begin,$end,'2026-11-26 11:45:00'));
});
rp_test('calendar links prefill one period and selected resource without creating a request',function():void{
    global $CFG_GLPI;$CFG_GLPI['root_doc']='/support';
    $state=WorkflowCalendarView::state(['date'=>'2026-10-28','kind'=>'room','resources_id'=>'42']);
    $url=WorkflowCalendarView::requestUrl($state,'2026-10-30');parse_str((string)parse_url($url,PHP_URL_QUERY),$query);
    rp_same('2026-10-30T08:00',$query['begin']);rp_same('2026-10-30T09:00',$query['end']);rp_same('42',$query['resources_id']);rp_same('room',$query['kind']);
    rp_assert(str_starts_with($url,'/support/plugins/reservaplus/front/booking.form.php?'));
});
