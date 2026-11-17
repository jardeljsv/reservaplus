<?php
declare(strict_types=1);

/** Independent real-GLPI approval checks. Writes only to an explicitly opted-in disposable DB. */
if (getenv('RP_TEST_ALLOW_WRITES') !== '1' || !getenv('RP_TEST_DATABASE') || !getenv('GLPI_ROOT')) {
    fwrite(STDERR, "Set GLPI_ROOT, RP_TEST_DATABASE and RP_TEST_ALLOW_WRITES=1 for disposable approval tests.\n");
    exit(2);
}
ob_start();
require __DIR__ . '/bootstrap.php';
global $DB;
rp_same(getenv('RP_TEST_DATABASE'), $DB->dbdefault, 'Disposable database guard');

use GlpiPlugin\Reservaplus\{Access, ApprovalPolicy, BookingService, ResourceService, Sector, Store};

function rpa_login(string $name, ?int $rights = null): int
{
    $user = new User(); rp_assert($user->getFromDBbyName($name), 'Fixture user missing: ' . $name);
    $auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user;
    Session::init($auth); Plugin::load('reservaplus');
    if ($rights !== null) { $_SESSION['glpiactiveprofile'][Access::RIGHT] = $rights; }
    return (int) $user->getID();
}
function rpa_admin(): int { return rpa_login(getenv('RP_TEST_ADMIN') ?: 'glpi', Access::ADMIN); }
function rpa_group(string $name, int $entity = 0): int
{
    $id = (int) (new Group())->add(['name'=>$name,'entities_id'=>$entity,'is_assign'=>1,'is_requester'=>1,'is_usergroup'=>1]);
    rp_assert($id > 0, 'Native group creation'); return $id;
}
function rpa_member(int $group, int $user): int
{
    $id = (int) (new Group_User())->add(['groups_id'=>$group,'users_id'=>$user]);
    rp_assert($id > 0, 'Native membership creation'); return $id;
}
function rpa_profile(string $name): int
{
    global $DB;
    $source = new Profile(); rp_assert($source->getFromDBByCrit(['name'=>'Technician']), 'Native Technician profile');
    $input = $source->prepareInputForClone($source->fields); unset($input['id']); $input['name']=$name; $input['is_default']=0;
    $id = (int) (new Profile())->add($input); rp_assert($id > 0, 'Dedicated fixture profile');
    $rights = ProfileRight::getProfileRights((int) $source->getID());
    $rights['ticket'] = ((int) $rights['ticket'] | READ | UPDATE | CREATE | Ticket::READGROUP | Ticket::READASSIGN) & ~Ticket::READALL & ~Ticket::READNEWTICKET;
    $rights['ticketvalidation'] = TicketValidation::CREATEREQUEST | TicketValidation::VALIDATEREQUEST;
    $rights[Access::RIGHT] = READ | Access::APPROVE;
    ProfileRight::updateProfileRights($id, $rights);
    return $id;
}
function rpa_user(string $name, int $profile): int
{
    $id = (int) (new User())->add(['name'=>$name,'realname'=>$name,'is_active'=>1,'profiles_id'=>$profile,'entities_id'=>0]);
    rp_assert($id > 0, 'Native fixture user');
    rp_assert((int) (new Profile_User())->add(['users_id'=>$id,'profiles_id'=>$profile,'entities_id'=>0,'is_recursive'=>0]) > 0, 'Fixture entity/profile association');
    return $id;
}
function rpa_defaults(array $groups): void
{
    $current = ApprovalPolicy::defaults(0); $input = [];
    foreach (['equipment','room','other'] as $kind) {
        $input[$kind] = ['groups_ids'=>$groups[$kind] ?? [], 'expected_revision'=>(int) $current[$kind]['revision']];
    }
    ApprovalPolicy::saveDefaults(0, $input);
}
function rpa_submit(array $resources, array $extra=[]): int
{
    static $slot=0; ++$slot;
    return BookingService::submit($extra + ['resources_ids'=>$resources,'begin'=>date('Y-m-d H:i:s', time()+86400+$slot*7200),
        'end'=>date('Y-m-d H:i:s',time()+90000+$slot*7200),'purpose'=>'Independent approval policy fixture','people_count'=>1],
        'rpa-submit-' . bin2hex(random_bytes(16)));
}
function rpa_act(int $id, string $action, array $input=[]): array
{
    $row=Store::row('bookings',$id);
    return BookingService::act($id,$action,['expected_revision'=>(int)$row['revision']] + $input,'rpa-action-' . bin2hex(random_bytes(16)));
}
function rpa_line(int $booking, int $resource): array { return Store::find('bookinglines',['bookings_id'=>$booking,'resources_id'=>$resource])[0]; }
function rpa_validations(int $ticket): array
{
    global $DB; return iterator_to_array($DB->request(['FROM'=>'glpi_ticketvalidations','WHERE'=>['tickets_id'=>$ticket]]));
}
function rpa_rows(int $booking, ?int $line=null): array
{
    return Store::find('bookingapprovals',['bookings_id'=>$booking]+($line===null?[]:['lines_id'=>$line]),['ORDER'=>'id ASC']);
}

// The disposable browser seed reuses only these guarded native fixture helpers.
if (getenv('RP_APPROVAL_HELPERS_ONLY') === '1') { ob_end_clean(); return; }

$admin = rpa_admin(); $tag = 'RP approvals ' . bin2hex(random_bytes(5));
$profile = rpa_profile($tag);
$nameA = 'rp-a-' . bin2hex(random_bytes(4)); $nameB = 'rp-b-' . bin2hex(random_bytes(4));
$userA = rpa_user($nameA, $profile); $userB = rpa_user($nameB, $profile);
$groupA = rpa_group($tag.' A'); $groupB = rpa_group($tag.' B'); $operations = rpa_group($tag.' operations');
$memberA = rpa_member($groupA,$userA); rpa_member($groupB,$userB); rpa_member($operations,$admin);
$sector = Sector::save(['name'=>$tag,'code'=>str_replace(' ','-',$tag),'entities_id'=>0,'is_active'=>1]);
$base = ['entities_id'=>0,'publication'=>'published','sectors_id'=>$sector,'groups_id_ticket'=>$operations,
    'groups_id_manage'=>$operations,'custodian_users_id'=>$admin];
$make = static function(string $label,string $kind='equipment',string $mode='inherit',array $policy=[],array $extra=[])use($base,$tag):int {
    return ResourceService::save($extra + $base + ['name'=>$tag.' '.$label,'kind'=>$kind,'capacity'=>$kind==='room'?12:null,
        'policy_json'=>$policy+['approval_mode'=>$mode,'approval_required'=>true,'signature_stage'=>'none','return_signature_required'=>false]]);
};
$employeeName=getenv('RP_TEST_EMPLOYEE')?:'post-only';
$employeeModel=new User();rp_assert($employeeModel->getFromDBbyName($employeeName));$employee=(int)$employeeModel->getID();

rp_test('entity-scoped default pools retain multiple groups and enforce revisions and administration',static function()use($groupA,$groupB,$nameA):void{
    rpa_defaults(['equipment'=>[$groupA,$groupB],'room'=>[$groupB],'other'=>[$groupA]]);
    $current=ApprovalPolicy::defaults(0);rp_same([$groupA,$groupB],$current['equipment']['groups_ids']);
    rp_same([$groupB],$current['room']['groups_ids']);
    $stale=[];foreach($current as $kind=>$row){$stale[$kind]=['groups_ids'=>$row['groups_ids'],'expected_revision'=>max(0,(int)$row['revision']-1)];}
    rp_throws(static fn()=>ApprovalPolicy::saveDefaults(0,$stale),DomainException::class);
    rpa_login($nameA);rp_throws(static fn()=>ApprovalPolicy::saveDefaults(0,$stale),DomainException::class);rpa_admin();
    $entity=(int)(new Entity())->add(['name'=>'RP approval isolated '.bin2hex(random_bytes(3)),'entities_id'=>0]);rp_assert($entity>0);
    $foreign=rpa_group('RP foreign approval '.bin2hex(random_bytes(3)),$entity);
    rp_throws(static fn()=>rpa_defaults(['equipment'=>[$foreign]]),DomainException::class);
    rp_same([$groupA,$groupB],ApprovalPolicy::defaults(0)['equipment']['groups_ids'],'Failed defaults update must not change earlier kinds');
    $_SESSION['glpiactiveentities']=[0];rp_throws(static fn()=>ApprovalPolicy::saveDefaults($entity,[]),DomainException::class);rpa_admin();
});

$inherited=$make('inherited');
rpa_login($employeeName,READ|CREATE);$inheritedBooking=rpa_submit([$inherited]);$inheritedTicket=(int)Store::row('bookings',$inheritedBooking)['tickets_id'];
rp_test('inherited pending pool freezes both groups and grants native group observers without fake validations',static function()use($inheritedBooking,$inheritedTicket,$inherited,$groupA,$groupB,$operations,$nameA):void{
    global $DB;
    rp_same(0,count(rpa_validations($inheritedTicket)));
    $targets=rpa_rows($inheritedBooking);rp_same(2,count($targets));
    foreach($targets as $row){rp_same('pending',$row['status']);rp_same(0,(int)$row['ticketvalidations_id']);}
    $resolved=ApprovalPolicy::resolve(Access::lineResource(rpa_line($inheritedBooking,$inherited)));
    rp_same([$groupA,$groupB],$resolved['groups_ids']);
    foreach([$groupA,$groupB]as$group){rp_same(1,countElementsInTable('glpi_groups_tickets',['tickets_id'=>$inheritedTicket,'groups_id'=>$group,'type'=>CommonITILActor::OBSERVER]));}
    rp_same(1,countElementsInTable('glpi_groups_tickets',['tickets_id'=>$inheritedTicket,'groups_id'=>$operations,'type'=>CommonITILActor::ASSIGN]));
    rpa_login($nameA);rp_assert(!Session::haveRight('ticket',Ticket::READALL),'Fixture must lack global ticket read');
    $ticket=new Ticket();rp_assert($ticket->can($inheritedTicket,READ),'Configured native group observer sees actual ticket');
    rp_assert(Access::booking(Store::row('bookings',$inheritedBooking),'approve'));rpa_admin();
});

$custom=$make('custom','equipment','custom',['approver_groups_ids'=>[$groupA,$groupB],'approver_users_ids'=>[$userB]],['groups_id_approve'=>$operations]);
rpa_login($employeeName,READ|CREATE);$customBooking=rpa_submit([$custom]);$customTicket=(int)Store::row('bookings',$customBooking)['tickets_id'];
rp_test('custom pools replace defaults and legacy group and materialize only the actual eligible native decision',static function()use($customBooking,$customTicket,$custom,$userB,$nameB,$groupA,$groupB,$operations):void{
    $policy=ApprovalPolicy::resolve(Access::lineResource(rpa_line($customBooking,$custom)));
    rp_same([$groupA,$groupB],$policy['groups_ids']);rp_same([$userB],$policy['users_ids']);
    rp_assert(!in_array($operations,$policy['groups_ids'],true));
    rp_same(1,countElementsInTable('glpi_tickets_users',['tickets_id'=>$customTicket,'users_id'=>$userB,'type'=>CommonITILActor::OBSERVER]));
    rpa_login($nameB);$ticket=new Ticket();rp_assert($ticket->can($customTicket,READ),'Individual native observer reads actual ticket');
    $approved=rpa_act($customBooking,'approve');rp_same('approved',$approved['status']);
    $native=rpa_validations($customTicket);rp_same(1,count($native));$native=array_values($native)[0];
    rp_same(User::class,$native['itemtype_target']);rp_same($userB,(int)$native['items_id_target']);
    rp_same($userB,(int)$native['users_id_validate']);rp_same(CommonITILValidation::ACCEPTED,(int)$native['status']);
    $rows=rpa_rows($customBooking);rp_same(1,count(array_filter($rows,static fn($r)=>$r['status']==='approved')));
    rp_same(2,count(array_filter($rows,static fn($r)=>$r['status']==='covered')));
    foreach($rows as $row){if($row['status']==='covered'){rp_same(0,(int)$row['ticketvalidations_id']);rp_same(0,(int)$row['users_id_decider']);}}
    $winner=array_values(array_filter($rows,static fn($r)=>$r['status']==='approved'))[0];
    rp_same(User::class,$winner['target_itemtype']);rp_same($userB,(int)$winner['target_id']);
    rp_throws(static fn()=>rpa_act($customBooking,'reject',['reason'=>'Late conflicting vote']),DomainException::class);
    rp_same(1,count(rpa_validations($customTicket)));rpa_admin();
});

$individual=$make('individual','equipment','custom',['approver_groups_ids'=>[],'approver_users_ids'=>[$userA]]);
rpa_login($employeeName,READ|CREATE);$individualBooking=rpa_submit([$individual]);
rp_test('selected individuals need plugin and native answer rights and nonmember administrators cannot vote',static function()use($individualBooking,$nameA):void{
    rpa_admin();$before=Store::row('bookings',$individualBooking);
    rp_throws(static fn()=>rpa_act($individualBooking,'approve'),DomainException::class);
    rpa_login($nameA);$native=$_SESSION['glpiactiveprofile']['ticketvalidation'];$_SESSION['glpiactiveprofile']['ticketvalidation']=0;
    rp_throws(static fn()=>rpa_act($individualBooking,'approve'),DomainException::class);
    $_SESSION['glpiactiveprofile']['ticketvalidation']=$native;$_SESSION['glpiactiveprofile'][Access::RIGHT]=READ;
    rp_throws(static fn()=>rpa_act($individualBooking,'approve'),DomainException::class);
    rp_same((int)$before['revision'],(int)Store::row('bookings',$individualBooking)['revision']);
    rpa_login($nameA);rp_same('approved',rpa_act($individualBooking,'approve')['status']);rpa_admin();
});

$onlyA=$make('A line','equipment','custom',['approver_groups_ids'=>[$groupA],'approver_users_ids'=>[]]);
$onlyB=$make('B line','room','custom',['approver_groups_ids'=>[$groupB],'approver_users_ids'=>[]]);
rpa_login($employeeName,READ|CREATE);$mixed=rpa_submit([$onlyA,$onlyB]);
rp_test('public mixed pools permit exact line approval while blocking other lines and partial rescheduling',static function()use($mixed,$onlyA,$onlyB,$nameA,$nameB):void{
    $lineA=rpa_line($mixed,$onlyA);$lineB=rpa_line($mixed,$onlyB);
    rpa_login($nameA);$booking=BookingService::get($mixed);rp_assert(Access::canApproveLine($booking,$lineA));rp_assert(!Access::canApproveLine($booking,$lineB));
    rp_assert(!Access::canReschedule($booking));
    rp_throws(static fn()=>rpa_act($mixed,'approve',['lines_ids'=>[(int)$lineB['id']]]),DomainException::class);
    rp_throws(static fn()=>rpa_act($mixed,'extend',['end'=>date('Y-m-d H:i:s',strtotime($booking['end'])+3600)]),DomainException::class);
    rp_same('submitted',rpa_act($mixed,'approve',['lines_ids'=>[(int)$lineA['id']]])['status']);
    rp_throws(static fn()=>rpa_act($mixed,'approve',['lines_ids'=>[(int)$lineA['id']]]),DomainException::class);
    rpa_login($nameB);rp_same('approved',rpa_act($mixed,'approve',['lines_ids'=>[(int)$lineB['id']]])['status']);
    rp_same(2,count(rpa_validations((int)Store::row('bookings',$mixed)['tickets_id'])));rpa_admin();
});

rp_test('default changes and date revisions retain the frozen roster and archive actual native decisions',static function()use($inheritedBooking,$inherited,$groupA,$groupB,$nameA):void{
    rpa_defaults(['equipment'=>[$groupB],'room'=>[$groupB],'other'=>[$groupA]]);
    $frozen=ApprovalPolicy::resolve(Access::lineResource(rpa_line($inheritedBooking,$inherited)));
    rp_same([$groupA,$groupB],$frozen['groups_ids']);
    rp_same([$groupB],ApprovalPolicy::resolve(Store::row('resources',$inherited))['groups_ids']);
    rpa_login($nameA);$approved=rpa_act($inheritedBooking,'approve');$old=array_values(rpa_validations((int)$approved['tickets_id']))[0];
    $revised=rpa_act($inheritedBooking,'extend',['begin'=>date('Y-m-d H:i:s',strtotime($approved['begin'])+10800),'end'=>date('Y-m-d H:i:s',strtotime($approved['end'])+10800)]);
    rp_same('submitted',$revised['status']);rp_same([$groupA,$groupB],ApprovalPolicy::resolve(Access::lineResource(rpa_line($inheritedBooking,$inherited)))['groups_ids']);
    foreach(rpa_rows($inheritedBooking)as$row){rp_same('pending',$row['status']);rp_same(0,(int)$row['ticketvalidations_id']);}
    rp_same(1,count(Store::find('bookingapprovalhistory',['bookings_id'=>$inheritedBooking,'ticketvalidations_id'=>(int)$old['id']])));
    rp_throws(static fn()=>(new TicketValidation())->update(['id'=>(int)$old['id'],'status'=>CommonITILValidation::REFUSED]),DomainException::class);
    rp_same('approved',rpa_act($inheritedBooking,'approve')['status']);rp_same(2,count(rpa_validations((int)$approved['tickets_id'])));rpa_admin();
});

rpa_login($employeeName,READ|CREATE);$revokedBooking=rpa_submit([$onlyA]);
rp_test('revoked memberships and deactivated actors lose approval despite stale sessions',static function()use($revokedBooking,$groupA,$userA,$nameA,&$memberA):void{
    rpa_login($nameA);$stale=$_SESSION;rp_assert(in_array($groupA,Access::groups(),true));
    rpa_admin();rp_assert((new Group_User())->delete(['id'=>$memberA],true));$_SESSION=$stale;
    rp_throws(static fn()=>rpa_act($revokedBooking,'approve'),DomainException::class);rp_same('submitted',Store::row('bookings',$revokedBooking)['status']);
    rpa_admin();$memberA=rpa_member($groupA,$userA);rpa_login($nameA);$stale=$_SESSION;
    rpa_admin();rp_assert((new User())->update(['id'=>$userA,'is_active'=>0]));$_SESSION=$stale;
    try { rp_throws(static fn()=>rpa_act($revokedBooking,'approve'),DomainException::class); }
    finally { rpa_admin();rp_assert((new User())->update(['id'=>$userA,'is_active'=>1])); }
    rpa_login($nameA);rp_same('approved',rpa_act($revokedBooking,'approve')['status']);rpa_admin();
});

$privateGroup=rpa_group($tag.' borrowers');rpa_member($privateGroup,$employee);
$privateA=$make('private A','equipment','custom',['approver_groups_ids'=>[$groupA],'requester_groups_ids'=>[$privateGroup]]);
$privateB=$make('private B','equipment','custom',['approver_groups_ids'=>[$groupB],'requester_groups_ids'=>[$privateGroup]]);
rp_test('disjoint private pools fail before any ticket or observer is committed',static function()use($privateA,$privateB,$employeeName):void{
    $bookings=countElementsInTable(Store::table('bookings'));$tickets=countElementsInTable('glpi_tickets');$observers=countElementsInTable('glpi_groups_tickets');
    rpa_login($employeeName,READ|CREATE);rp_throws(static fn()=>rpa_submit([$privateA,$privateB]),DomainException::class);
    rp_same($bookings,countElementsInTable(Store::table('bookings')));rp_same($tickets,countElementsInTable('glpi_tickets'));rp_same($observers,countElementsInTable('glpi_groups_tickets'));rpa_admin();
});
$privateCovered=$make('private covered','equipment','custom',['approver_groups_ids'=>[$groupA,$groupB],'requester_groups_ids'=>[$privateGroup]]);
rp_test('private pool explicitly covering every observer target permits safe shared approval context',static function()use($privateCovered,$privateGroup,$make,$groupA,$groupB,$employeeName,$nameA):void{
    $other=$make('private covered second','room','custom',['approver_groups_ids'=>[$groupA,$groupB],'requester_groups_ids'=>[$privateGroup]]);
    rpa_login($employeeName,READ|CREATE);$id=rpa_submit([$privateCovered,$other]);
    rpa_login($nameA);rp_assert(Access::booking(Store::row('bookings',$id),'read'));rp_same('approved',rpa_act($id,'approve')['status']);rpa_admin();
});

$legacy=$make('legacy','equipment','legacy',[],['groups_id_approve'=>$groupA]);
rp_test('legacy single-group resources retain pending native approvals and their original group',static function()use($legacy,$groupA,$employeeName,$nameA):void{
    rpa_login($employeeName,READ|CREATE);$id=rpa_submit([$legacy]);$ticket=(int)Store::row('bookings',$id)['tickets_id'];
    $rows=rpa_rows($id);rp_same(1,count($rows));rp_same(Group::class,$rows[0]['target_itemtype']);rp_same($groupA,(int)$rows[0]['target_id']);rp_assert((int)$rows[0]['ticketvalidations_id']>0);
    $native=array_values(rpa_validations($ticket));rp_same(1,count($native));rp_same(Group::class,$native[0]['itemtype_target']);rp_same(CommonITILValidation::WAITING,(int)$native[0]['status']);
    rpa_login($nameA);rp_same('approved',rpa_act($id,'approve')['status']);rpa_admin();
});

rp_test('active rc2 resources can be renamed without rewriting their preserved legacy approval policy',static function()use($legacy,$groupA):void{
    $row=Store::row('resources',$legacy);$oldPolicy=Store::decode($row['policy_json']);unset($oldPolicy['approval_mode']);
    Store::update('resources',$legacy,['policy_json'=>Store::json($oldPolicy)]);
    ResourceService::save(['expected_revision'=>(int)$row['config_revision'],'name'=>$row['name'].' renamed'],$legacy);
    $saved=Store::row('resources',$legacy);rp_same($groupA,(int)$saved['groups_id_approve']);
    rp_same('legacy',ApprovalPolicy::resolve($saved)['mode']);rp_same([$groupA],ApprovalPolicy::resolve($saved)['groups_ids']);
});

rp_test('failed native observer insertion rolls back the whole booking and native ticket',static function()use($onlyA,$groupA,$employeeName):void{
    global $PLUGIN_HOOKS;
    rpa_login($employeeName,READ|CREATE);
    $key=\Glpi\Plugin\Hooks::PRE_ITEM_ADD;
    $old=$PLUGIN_HOOKS[$key]['reservaplus'][Group_Ticket::class]??null;
    $PLUGIN_HOOKS[$key]['reservaplus'][Group_Ticket::class]=static function(Group_Ticket $item)use($groupA):void{
        if((int)($item->input['groups_id']??0)===$groupA&&(int)($item->input['type']??0)===CommonITILActor::OBSERVER){$item->input=[];}
    };
    $bookings=countElementsInTable(Store::table('bookings'));$tickets=countElementsInTable('glpi_tickets');$actors=countElementsInTable('glpi_groups_tickets');
    try{
        rp_throws(static fn()=>rpa_submit([$onlyA]),DomainException::class);
        rp_same($bookings,countElementsInTable(Store::table('bookings')));rp_same($tickets,countElementsInTable('glpi_tickets'));rp_same($actors,countElementsInTable('glpi_groups_tickets'));
    }finally{
        if($old===null){unset($PLUGIN_HOOKS[$key]['reservaplus'][Group_Ticket::class]);}else{$PLUGIN_HOOKS[$key]['reservaplus'][Group_Ticket::class]=$old;}
        rpa_admin();
    }
});

rp_test('an approval-free resource manager can reschedule without approval capability or native votes',static function()use($make,$operations,$userA,$nameA,$employeeName):void{
    rpa_member($operations,$userA);
    $resource=$make('approval-free managed','equipment','inherit',['approval_required'=>false]);
    rpa_login($employeeName,READ|CREATE);$id=rpa_submit([$resource]);$before=Store::row('bookings',$id);
    rpa_login($nameA,READ|Access::MANAGE);$_SESSION['glpiactiveprofile']['ticketvalidation']=0;
    $visible=BookingService::get($id);$nativeTicket=new Ticket();
    rp_assert(Access::canReschedule($visible),'Manager reschedule prerequisites: '.json_encode(['manage'=>Access::booking($visible,'manage'),'native_read'=>$nativeTicket->can((int)$visible['tickets_id'],READ),'groups'=>Access::groups(),'policy'=>ResourceService::policy(Access::lineResource($visible['lines'][0]))]));
    $after=rpa_act($id,'extend',['begin'=>date('Y-m-d H:i:s',strtotime($before['begin'])+7200),'end'=>date('Y-m-d H:i:s',strtotime($before['end'])+7200)]);
    rp_same('approved',$after['status']);rp_same((int)$before['scope_revision']+1,(int)$after['scope_revision']);
    rp_same(0,count(rpa_validations((int)$before['tickets_id'])));rpa_admin();
});

rp_test('native rules cannot solve a ticket during a partial resource approval and all attempted writes roll back',static function()use($onlyA,$onlyB,$employeeName,$nameA):void{
    rpa_login($employeeName,READ|CREATE);$id=rpa_submit([$onlyA,$onlyB]);$before=Store::row('bookings',$id);$tid=(int)$before['tickets_id'];$line=rpa_line($id,$onlyA);
    rpa_admin();$ticket=new Ticket();rp_assert($ticket->getFromDB($tid));$status=(int)$ticket->fields['status'];
    $rule=new RuleTicket();$ruleId=(int)$rule->add(['name'=>'RP rejected early solution '.bin2hex(random_bytes(4)),'entities_id'=>0,'is_recursive'=>1,'is_active'=>1,'match'=>'AND','condition'=>RuleTicket::ONUPDATE]);rp_assert($ruleId>0);
    rp_assert((int)(new RuleCriteria())->add(['rules_id'=>$ruleId,'criteria'=>'global_validation','condition'=>Rule::PATTERN_IS,'pattern'=>CommonITILValidation::ACCEPTED])>0);
    rp_assert((int)(new RuleAction())->add(['rules_id'=>$ruleId,'action_type'=>'assign','field'=>'status','value'=>CommonITILObject::SOLVED])>0);
    try{
        $control=new Ticket();$controlId=(int)$control->add(['name'=>'RP rule control '.bin2hex(random_bytes(3)),'content'=>'Disposable native rule control','entities_id'=>0,'type'=>Ticket::DEMAND_TYPE,'_users_id_requester'=>Access::userId()]);rp_assert($controlId>0);
        rp_assert($control->update(['id'=>$controlId,'global_validation'=>CommonITILValidation::ACCEPTED]));rp_assert($control->getFromDB($controlId));
        rp_same(CommonITILObject::SOLVED,(int)$control->fields['status'],'Control proves the actual native rule executes');
        $actors=countElementsInTable('glpi_groups_tickets');rpa_login($nameA);
        rp_throws(static fn()=>rpa_act($id,'approve',['lines_ids'=>[(int)$line['id']]]),DomainException::class);
        rp_same('submitted',Store::row('bookings',$id)['status']);rp_same((int)$before['revision'],(int)Store::row('bookings',$id)['revision']);
        rp_same(0,count(rpa_validations($tid)));foreach(rpa_rows($id)as$row){rp_same('pending',$row['status']);}
        rp_assert($ticket->getFromDB($tid));rp_same($status,(int)$ticket->fields['status']);rp_same($actors,countElementsInTable('glpi_groups_tickets'));
        rpa_admin();rp_throws(static fn()=>(new Ticket())->update(['id'=>$tid,'global_validation'=>CommonITILValidation::ACCEPTED]),DomainException::class);
        rp_assert($ticket->getFromDB($tid));rp_same($status,(int)$ticket->fields['status'],'An external native update must be rejected before autocommit writes');
    }finally{
        rpa_admin();rp_assert($rule->update(['id'=>$ruleId,'is_active'=>0]));
        // GLPI caches rules for the current PHP request; the next HTTP request
        // would naturally reload them. This CLI suite performs both requests.
        $cache=SingletonRuleList::getInstance(RuleTicket::class,0);$cache->list=[];$cache->load=0;
    }
    rpa_login($nameA);rp_same('submitted',rpa_act($id,'approve',['lines_ids'=>[(int)$line['id']]])['status']);rpa_admin();
});

rp_test('missing inherited defaults block publication and actual incident answer rights are enforced',static function()use($make,$groupA,$groupB,$individual,$nameA,$userA):void{
    rpa_defaults(['equipment'=>[$groupB],'room'=>[$groupB],'other'=>[]]);
    rp_throws(static fn()=>$make('missing default','other'),DomainException::class);
    $draft=$make('incomplete draft','other','inherit',[],['publication'=>'draft']);rp_assert($draft>0);
    $ticket=new Ticket();$tid=(int)$ticket->add(['name'=>'RP incident '.bin2hex(random_bytes(3)),'content'=>'Disposable type-right test','entities_id'=>0,'type'=>Ticket::INCIDENT_TYPE,'_users_id_requester'=>Access::userId()]);rp_assert($tid>0);
    $id=rpa_submit([$individual],['tickets_id'=>$tid]);rpa_login($nameA);
    rp_assert(!ApprovalPolicy::canValidateTicket(Store::row('bookings',$id)));
    rp_throws(static fn()=>rpa_act($id,'approve'),DomainException::class);
    $_SESSION['glpiactiveprofile']['ticketvalidation'] |= TicketValidation::VALIDATEINCIDENT;
    rp_same('approved',rpa_act($id,'approve')['status']);rpa_admin();
    rpa_defaults(['equipment'=>[$groupA,$groupB],'room'=>[$groupB],'other'=>[$groupA]]);
});

fwrite(STDOUT,'Approval integration fixtures: '.json_encode(['tag'=>$tag,'profile'=>$profile,'users'=>[$userA,$userB],'groups'=>[$groupA,$groupB],'inherited_booking'=>$inheritedBooking,'custom_booking'=>$customBooking],JSON_THROW_ON_ERROR).PHP_EOL);
ob_end_flush();
