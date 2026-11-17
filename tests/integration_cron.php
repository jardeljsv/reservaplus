<?php
declare(strict_types=1);
/** Actual GLPI CronTask launch without a logged-in user; disposable database only. */
if (getenv('RP_TEST_ALLOW_WRITES') !== '1' || !getenv('RP_TEST_DATABASE') || !getenv('GLPI_ROOT')) {
    fwrite(STDERR,"Disposable database guards required.\n");exit(2);
}
ob_start();require __DIR__.'/bootstrap.php';global $DB;
rp_same(getenv('RP_TEST_DATABASE'),$DB->dbdefault);
use GlpiPlugin\Reservaplus\{Access,Booking,BookingService,DocumentService,ResourceService,Sector,Store,TicketBridge};
if(($argv[1]??'')==='--cron-worker'){
    $name=(string)($argv[2]??'');rp_assert(in_array($name,['ExpireHolds','DeliverOutbox'],true));
    rp_same(0,Access::userId());rp_assert(!Session::isCron());
    rp_throws(static fn()=>BookingService::expireHolds(),DomainException::class);
    rp_same($name,CronTask::launch(-CronTask::MODE_EXTERNAL,1,$name));
    rp_assert(Session::isCron());rp_same(0,Access::userId());
    fwrite(STDOUT,"PASS unauthenticated native scheduler worker ".$name."\n");ob_end_clean();exit(0);
}
function rp_cron_worker(string $name):void{
    $process=proc_open([PHP_BINARY,'-c',php_ini_loaded_file(),__FILE__,'--cron-worker',$name],[['pipe','r'],['pipe','w'],['pipe','w']],$pipes);
    rp_assert(is_resource($process));fclose($pipes[0]);$out=stream_get_contents($pipes[1]);$errors=stream_get_contents($pipes[2]);fclose($pipes[1]);fclose($pipes[2]);
    rp_same(0,proc_close($process),'Native scheduler worker: '.$out.$errors);
    rp_assert(str_contains($out,'PASS unauthenticated native scheduler worker '.$name));
}
$user=new User();rp_assert($user->getFromDBbyName(getenv('RP_TEST_ADMIN')?:'glpi'));
$auth=new Auth();$auth->auth_succeded=true;$auth->user=$user;Session::init($auth);Plugin::load('reservaplus');
$_SESSION['glpiactiveprofile'][Access::RIGHT]=Access::ADMIN;
$tag='RP Cron '.bin2hex(random_bytes(5));$group=new Group();$groupId=(int)$group->add(['name'=>$tag,'entities_id'=>0,'is_assign'=>1]);rp_assert($groupId>0);
$sector=Sector::save(['name'=>$tag,'code'=>str_replace(' ','-',$tag),'entities_id'=>0,'is_active'=>1]);
$create=static function(string $label)use($tag,$groupId,$sector,$user):int{
    $resource=ResourceService::save(['name'=>$tag.' '.$label,'entities_id'=>0,'publication'=>'published','sectors_id'=>$sector,'groups_id_ticket'=>$groupId,'groups_id_manage'=>$groupId,'custodian_users_id'=>(int)$user->getID(),'policy_json'=>['approval_required'=>false,'signature_stage'=>'handover','return_signature_required'=>false]]);
    return BookingService::submit(['resources_ids'=>[$resource],'begin'=>date('Y-m-d H:i:s',time()+7200),'end'=>date('Y-m-d H:i:s',time()+10800),'purpose'=>'Native scheduler context fixture'],'cron-'.bin2hex(random_bytes(16)));
};
$unsigned=$create('unsigned');$signed=$create('signed');
$document=DocumentService::prepare($signed,'handover');$doc=DocumentService::get($document);DocumentService::artifact($document,'source');
DocumentService::accept($document,DocumentService::issueChallenge($document),hash('sha256',DocumentService::consent($doc,(int)$user->getID())),'cron_'.bin2hex(random_bytes(16)));
rp_assert(DocumentService::isSatisfied($signed,'handover'));
foreach([$unsigned,$signed]as$id)Store::update('bookings',$id,['hold_expires_at'=>date('Y-m-d H:i:s',time()-1)]);
$unsignedLine=Store::find('bookinglines',['bookings_id'=>$unsigned])[0];$signedLine=Store::find('bookinglines',['bookings_id'=>$signed])[0];
$unsignedTicket=(int)Store::row('bookings',$unsigned)['tickets_id'];
$followupsBefore=countElementsInTable('glpi_itilfollowups',['itemtype'=>Ticket::class,'items_id'=>$unsignedTicket]);
foreach(['ExpireHolds','DeliverOutbox']as$name){$task=new CronTask();rp_assert($task->getFromDBbyName(Booking::class,$name),'Registered native task '.$name);}
rp_cron_worker('ExpireHolds');
rp_same('cancelled',Store::row('bookings',$unsigned)['status'],'Unsigned native hold expired without login');
rp_same('released',Store::find('bookinglines',['bookings_id'=>$unsigned])[0]['state']);
rp_same(0,countElementsInTable('glpi_reservations',['id'=>(int)$unsignedLine['reservations_id']]),'Native calendar allocation released');
rp_same('approved',Store::row('bookings',$signed)['status'],'Signed hold retained without login');
rp_same(null,Store::row('bookings',$signed)['hold_expires_at']);rp_same(1,countElementsInTable('glpi_reservations',['id'=>(int)$signedLine['reservations_id']]));
rp_test('native scheduler expires unsigned allocation and retains signed allocation with no authenticated user',static fn()=>rp_assert(true));
rp_cron_worker('DeliverOutbox');
rp_same([],Store::find('bookingoutbox',['bookings_id'=>$unsigned,'status'=>'pending']),'Scheduler delivered followup');
rp_same('synced',Store::row('bookings',$unsigned)['integration_status']);
rp_same($followupsBefore+1,countElementsInTable('glpi_itilfollowups',['itemtype'=>Ticket::class,'items_id'=>$unsignedTicket]));
rp_test('native scheduler delivers queued native ticket followup without authenticated user',static fn()=>rp_assert(true));
fwrite(STDOUT,'Scheduler fixtures '.json_encode(['unsigned'=>$unsigned,'signed'=>$signed]).PHP_EOL);ob_end_flush();
