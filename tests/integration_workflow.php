<?php
declare(strict_types=1);

/**
 * Destructive fixture integration test for an installed, disposable GLPI 11 database.
 * Does not run in tools/run-tests.sh. Leaves uniquely named fixtures for inspection.
 * Required: GLPI_ROOT, RP_TEST_ALLOW_WRITES=1, RP_TEST_DATABASE (must match configured DB).
 * Optional: RP_TEST_ADMIN (default glpi), RP_TEST_EMPLOYEE (default post-only).
 */
if (getenv('RP_TEST_ALLOW_WRITES') !== '1' || !getenv('RP_TEST_DATABASE') || !getenv('GLPI_ROOT')) {
    fwrite(STDERR, "Refusing integration writes: set GLPI_ROOT, RP_TEST_DATABASE and RP_TEST_ALLOW_WRITES=1 for a disposable installation.\n");
    exit(2);
}
ob_start();
require __DIR__ . '/bootstrap.php';
global $DB;
rp_same(getenv('RP_TEST_DATABASE'), $DB->dbdefault, 'Disposable database name guard');

use GlpiPlugin\Reservaplus\{Access,Agreement,Booking,BookingService,DocumentService,Resource,ResourceService,Sector,Store};

function rp_login(string $name, ?int $rights = null): int {
    $user = new User(); rp_assert($user->getFromDBbyName($name), 'Fixture user missing: '.$name);
    $auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user; Session::init($auth);
    Plugin::load('reservaplus');
    if ($rights !== null) { $_SESSION['glpiactiveprofile'][Access::RIGHT] = $rights; }
    return (int)$user->getID();
}
function rp_action(int $id, string $action, array $input = []): array {
    $booking=BookingService::get($id);
    if(in_array($action,['receive','inspect'],true) && !array_key_exists('lines_ids',$input)) {
        $input['lines_ids']=array_map(static fn(array $line):int=>(int)$line['id'],$booking['lines']);
    }
    return BookingService::act($id,$action,['expected_revision'=>(int)$booking['revision']]+$input,'integration-'.bin2hex(random_bytes(16)));
}
function rp_sign(int $bookingId,string $purpose): int {
    $id=DocumentService::prepare($bookingId,$purpose);
    $doc=DocumentService::get($id);
    $artifact=DocumentService::artifact($id,'source');
    rp_assert(str_starts_with($artifact['bytes'],'%PDF-'),'Source PDF rendered');
    $challenge=DocumentService::issueChallenge($id);
    $doc=DocumentService::accept($id,$challenge,hash('sha256',DocumentService::consent($doc,Access::userId())),'integration_'.bin2hex(random_bytes(16)));
    rp_same('complete',$doc['status'],'Final PDF acceptance');
    return $id;
}

$tag='RP Integration '.bin2hex(random_bytes(5));
$adminName=getenv('RP_TEST_ADMIN')?:'glpi'; $employeeName=getenv('RP_TEST_EMPLOYEE')?:'post-only';
$admin=rp_login($adminName,Access::ADMIN);
$group=new Group();$groupId=(int)$group->add(['name'=>$tag,'entities_id'=>0,'is_assign'=>1,'is_usergroup'=>1,'is_requester'=>1]);
rp_assert($groupId>0,'Native group fixture');
$membership=new Group_User();rp_assert((bool)$membership->add(['groups_id'=>$groupId,'users_id'=>$admin]),'Native group membership');
rp_login($adminName,Access::ADMIN);
$sector=Sector::save(['name'=>$tag,'code'=>str_replace(' ','-',$tag),'entities_id'=>0,'is_active'=>1]);
$computersBefore=countElementsInTable('glpi_computers');
$resourceId=ResourceService::save([
    'name'=>$tag.' equipment','entities_id'=>0,'publication'=>'published','sectors_id'=>$sector,
    'groups_id_ticket'=>$groupId,'groups_id_manage'=>$groupId,'groups_id_approve'=>$groupId,'custodian_users_id'=>$admin,
    'accessories_json'=>[['name'=>'Charger','quantity'=>1]],
    'policy_json'=>['approval_required'=>true,'signature_stage'=>'none','return_signature_required'=>false],
]);
rp_same($computersBefore,countElementsInTable('glpi_computers'),'Internal resources do not create inventory assets');
$resource=Store::row('resources',$resourceId);rp_same(0,(int)$resource['native_items_id']);
rp_test('native GLPI resource creation and internal-only inventory identity',static function()use($resourceId):void{
    $model=new Resource();rp_assert($model->getFromDB($resourceId),'Native parent model loads authorized resource');
});

$employee=rp_login($employeeName,READ|CREATE);
$input=['resources_ids'=>[$resourceId],'begin'=>date('Y-m-d H:i:s',time()-60),'end'=>date('Y-m-d H:i:s',time()+3600),'purpose'=>'Integration loan and complete physical return','people_count'=>1];
$key='integration-submit-'.bin2hex(random_bytes(12));
$bookingId=BookingService::submit($input,$key);
$booking=BookingService::get($bookingId);
rp_same('submitted',$booking['status']);rp_assert((int)$booking['tickets_id']>0,'Native ticket created');
rp_same($bookingId,BookingService::submit($input,$key),'Submit retry must reuse booking');
rp_throws(static fn()=>BookingService::submit(array_replace($input,['purpose'=>'Changed payload']),$key),DomainException::class);
rp_throws(static fn()=>rp_action($bookingId,'approve'),DomainException::class);
rp_test('self-service request creates one ticket and rejects unauthorized approval and altered retries',static function()use($booking):void{
    rp_same(1,count($booking['approvals']),'One approval per resource');
});

rp_login($adminName,Access::ADMIN);
$approval=$booking['approvals'][0];$nativeApproval=new TicketValidation();
rp_assert($nativeApproval->getFromDB((int)$approval['ticketvalidations_id']),'Native approval exists');
rp_assert($nativeApproval->canAnswer(),'Actual native group target resolves approver');
$booking=rp_action($bookingId,'approve');rp_same('approved',$booking['status']);
$line=$booking['lines'][0];rp_same('native',$line['calendar_mode']);rp_assert((int)$line['reservations_id']>0,'Native calendar allocation');
$nativeItem=new ReservationItem();rp_assert($nativeItem->getFromDB((int)$line['reservationitems_id']));rp_same(Resource::class,$nativeItem->fields['itemtype']);
rp_same($computersBefore,countElementsInTable('glpi_computers'),'Native calendar identity does not create an asset');
rp_assert(!BookingService::availability($resourceId,$input['begin'],$input['end'])['available'],'Calendar sees committed conflict');
rp_test('real group approval allocates native calendar without duplicating inventory',static function()use($nativeApproval):void{
    $nativeApproval->getFromDB($nativeApproval->getID());rp_same(CommonITILValidation::ACCEPTED,(int)$nativeApproval->fields['status']);
});

rp_test('native API cannot bypass active workflow',static function()use($booking,$line):void{
    rp_throws(static fn()=>(new Ticket())->update(['id'=>(int)$booking['tickets_id'],'status'=>CommonITILObject::SOLVED]),DomainException::class);
    rp_throws(static fn()=>(new Reservation())->update(['id'=>(int)$line['reservations_id'],'comment'=>'Unmanaged modification']),DomainException::class);
    rp_throws(static fn()=>(new Reservation())->delete(['id'=>(int)$line['reservations_id']],true),DomainException::class);
});
$issueKey='integration-'.bin2hex(random_bytes(16));
$issueInput=['expected_revision'=>(int)$booking['revision'],'condition'=>'Good condition; charger counted.'];
$booking=BookingService::act($bookingId,'issue',$issueInput,$issueKey);rp_same('issued',$booking['status']);
$issuedRevision=(int)$booking['revision'];
rp_same($issuedRevision,(int)BookingService::act($bookingId,'issue',$issueInput,$issueKey)['revision'],'Command retry does not repeat physical issue');
rp_throws(static fn()=>BookingService::act($bookingId,'issue',array_replace($issueInput,['condition'=>'Changed issue record']),$issueKey),DomainException::class);
rp_throws(static fn()=>rp_action($bookingId,'cancel'),DomainException::class);
rp_throws(static fn()=>BookingService::act($bookingId,'receive',['expected_revision'=>$issuedRevision],'integration-'.bin2hex(random_bytes(16))),DomainException::class);
rp_throws(static fn()=>BookingService::act($bookingId,'receive',['expected_revision'=>$issuedRevision-1,'lines_ids'=>[(int)$line['id']]],'integration-'.bin2hex(random_bytes(16))),DomainException::class);
$booking=rp_action($bookingId,'receive',['deliverer'=>'Borrower','note'=>'Equipment received; charger pending.']);
rp_same('returning',$booking['status']);rp_same('inspection',Store::row('resources',$resourceId)['operational_state']);
rp_throws(static fn()=>rp_action($bookingId,'inspect',['result'=>'pass','findings'=>'Function tested.']),DomainException::class);
rp_throws(static fn()=>rp_action($bookingId,'complete'),DomainException::class);
$booking=rp_action($bookingId,'receive',['accessories'=>[(int)$line['id']=>[0=>1]],'note'=>'Charger now returned.']);
$booking=rp_action($bookingId,'inspect',['result'=>'pass','findings'=>'Equipment and charger tested, all functions normal.']);
rp_same('ready',$booking['lines'][0]['state']);rp_same('available',Store::row('resources',$resourceId)['operational_state']);
rp_throws(static fn()=>rp_action($bookingId,'complete'),DomainException::class);
rp_test('physical receipt, accessory reconciliation and inspection gates',static function()use($booking):void{rp_same(1,(int)Store::decode($booking['lines'][0]['accessories_json'])[0]['received']);});

$documentId=rp_sign($bookingId,'return_inspection');
rp_test('native aggregate models cannot bypass service state or immutable evidence',static function()use($bookingId,$documentId):void{
    foreach([[new Booking(),$bookingId],[new Agreement(),$documentId]] as [$model,$id]) {
        rp_assert(!$model->can(-1,CREATE));rp_assert(!$model->can($id,UPDATE));rp_assert(!$model->can($id,DELETE));rp_assert(!$model->can($id,PURGE));
        rp_assert($model->update(['id'=>$id,'status'=>'forged'])===false,'Direct model update denied');
        rp_assert($model->delete(['id'=>$id],true)===false,'Direct model purge denied');
    }
    rp_same('returning',Store::row('bookings',$bookingId)['status']);rp_same('complete',Store::row('agreements',$documentId)['status']);
});
$booking=rp_action($bookingId,'complete');rp_same('completed',$booking['status']);
$ticket=new Ticket();rp_assert($ticket->getFromDB((int)$booking['tickets_id']));rp_same(CommonITILObject::SOLVED,(int)$ticket->fields['status']);
rp_test('signed return inspection permits native ticket solution',static function()use($bookingId):void{rp_assert(DocumentService::isSatisfied($bookingId,'return_inspection'));});

// Public internal rooms project to GLPI even when the requesting profile has no
// generic Resource search permission; inventory remains plugin-owned.
$room=ResourceService::save([
    'name'=>$tag.' room','kind'=>'room','capacity'=>8,'building'=>'Building A','floor'=>'02','room_number'=>'0203',
    'entities_id'=>0,'publication'=>'published','sectors_id'=>$sector,'groups_id_ticket'=>$groupId,
    'groups_id_manage'=>$groupId,'custodian_users_id'=>$admin,
    'policy_json'=>['approval_required'=>false,'signature_stage'=>'none','return_signature_required'=>false],
]);
rp_login($employeeName,READ|CREATE);
$roomInput=['resources_ids'=>[$room],'begin'=>date('Y-m-d H:i:s',time()+7200),'end'=>date('Y-m-d H:i:s',time()+10800),'purpose'=>'Room booking'];
rp_throws(static fn()=>BookingService::submit($roomInput+['people_count'=>9],'integration-'.bin2hex(random_bytes(16))),DomainException::class);
$roomBooking=BookingService::submit($roomInput+['people_count'=>8],'integration-'.bin2hex(random_bytes(16)));
$auto=BookingService::get($roomBooking);rp_same('approved',$auto['status']);rp_same('native',$auto['lines'][0]['calendar_mode']);
rp_assert(!Resource::canView(),'Employee has no generic raw native resource search');
rp_login($adminName,Access::ADMIN);
// A requester-capable user who does not own the booking may not cancel it.
$_SESSION['glpiactiveprofile'][Access::RIGHT]=READ|CREATE;
rp_throws(static fn()=>rp_action($roomBooking,'cancel'),DomainException::class);
$_SESSION['glpiactiveprofile'][Access::RIGHT]=Access::ADMIN;
$auto=rp_action($roomBooking,'cancel');rp_same('cancelled',$auto['status']);
rp_test('self-service room capacity, automatic native allocation and nonowner cancellation protection',static function()use($auto):void{rp_same('released',$auto['lines'][0]['state']);});

$signedResource=ResourceService::save([
    'name'=>$tag.' signed equipment','entities_id'=>0,'publication'=>'published','sectors_id'=>$sector,
    'groups_id_ticket'=>$groupId,'groups_id_manage'=>$groupId,'groups_id_approve'=>$groupId,'custodian_users_id'=>$admin,
    'policy_json'=>['approval_required'=>true,'signature_stage'=>'approval','return_signature_required'=>true],
]);
$signedInput=['resources_ids'=>[$signedResource],'begin'=>date('Y-m-d H:i:s',time()+14400),'end'=>date('Y-m-d H:i:s',time()+18000),'purpose'=>'Signed request revision'];
$signedBooking=BookingService::submit($signedInput,'integration-'.bin2hex(random_bytes(16)));
rp_throws(static fn()=>DocumentService::prepare($signedBooking,'handover'),DomainException::class);
$signed=rp_action($signedBooking,'approve');$oldApproval=(int)$signed['approvals'][0]['ticketvalidations_id'];
$oldDocument=rp_sign($signedBooking,'handover');rp_assert(DocumentService::isSatisfied($signedBooking,'handover'));
// Force only the disposable clock fixture due date, leaving signed facts intact.
Store::update('bookings',$signedBooking,['hold_expires_at'=>date('Y-m-d H:i:s',time()-1)]);
BookingService::expireHolds();rp_same('approved',Store::row('bookings',$signedBooking)['status'],'Signed holds retained');
rp_same(null,Store::row('bookings',$signedBooking)['hold_expires_at']);
$signed=rp_action($signedBooking,'extend',['end'=>date('Y-m-d H:i:s',time()+21600)]);
rp_same('submitted',$signed['status']);rp_same(2,(int)$signed['scope_revision']);
rp_assert(!DocumentService::isSatisfied($signedBooking,'handover'),'Material period change invalidates prior signature');
rp_assert(!DocumentService::get($oldDocument)['is_current']);
rp_assert(count(Store::find('bookingapprovalhistory',['bookings_id'=>$signedBooking]))===1,'Historical approval persisted');
rp_throws(static fn()=>(new TicketValidation())->update(['id'=>$oldApproval,'status'=>CommonITILValidation::REFUSED]),DomainException::class);
$signed=rp_action($signedBooking,'approve');
Store::update('bookings',$signedBooking,['hold_expires_at'=>date('Y-m-d H:i:s',time()-1)]);
BookingService::expireHolds();rp_same('cancelled',Store::row('bookings',$signedBooking)['status'],'Unsigned revised hold expires');
rp_test('material revision invalidates signatures, protects historical approvals and expires only unsigned holds',static function()use($signedBooking):void{rp_same('released',Store::find('bookinglines',['bookings_id'=>$signedBooking])[0]['state']);});

// Technician ticket-origin intake reuses the native ticket instead of duplicating it.
$nativeTicket=new Ticket();$ticketInput=['name'=>$tag.' existing ticket','content'=>'Reserve request from ticket','entities_id'=>0,'type'=>Ticket::DEMAND_TYPE,'_users_id_requester'=>$admin];
$existingTicket=(int)$nativeTicket->add($ticketInput);rp_assert($existingTicket>0);
$ticketsBefore=countElementsInTable('glpi_tickets');
$ticketBooking=BookingService::submit($roomInput+['tickets_id'=>$existingTicket],'integration-'.bin2hex(random_bytes(16)));
rp_same($existingTicket,(int)BookingService::get($ticketBooking)['tickets_id']);rp_same($ticketsBefore,countElementsInTable('glpi_tickets'));
rp_action($ticketBooking,'cancel');
rp_test('technician ticket intake reuses its authorized existing ticket',static function()use($ticketBooking):void{rp_same('cancelled',BookingService::get($ticketBooking)['status']);});

$roomUse=BookingService::submit(['resources_ids'=>[$room],'begin'=>date('Y-m-d H:i:s',time()-60),'end'=>date('Y-m-d H:i:s',time()+3600),'purpose'=>'Room use and inspection'],'integration-'.bin2hex(random_bytes(16)));
$roomReturned=rp_action($roomUse,'inspect',['result'=>'pass','findings'=>'Room and installed facilities checked after use.']);
rp_same('ready',$roomReturned['lines'][0]['state']);
rp_sign($roomUse,'return_inspection');$roomReturned=rp_action($roomUse,'complete');
rp_test('room use closes through inspection without inventing physical equipment receipt',static function()use($roomReturned):void{
    rp_same('completed',$roomReturned['status']);rp_same(null,$roomReturned['lines'][0]['received_at']);
});
fwrite(STDOUT,'Integration fixtures: '.json_encode(['tag'=>$tag,'resource'=>$resourceId,'booking'=>$bookingId,'ticket'=>(int)$booking['tickets_id'],'agreement'=>$documentId]).PHP_EOL);
ob_end_flush();
