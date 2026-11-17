<?php
declare(strict_types=1);

/** Real GLPI fixtures: run only against an explicitly named disposable database. */
if (getenv('RP_TEST_ALLOW_WRITES')!=='1' || !getenv('RP_TEST_DATABASE') || !getenv('GLPI_ROOT')) {
    fwrite(STDERR,"Set GLPI_ROOT, RP_TEST_DATABASE and RP_TEST_ALLOW_WRITES=1 for a disposable installation.\n");
    exit(2);
}
ob_start();
require __DIR__.'/bootstrap.php';
global $DB;
rp_same(getenv('RP_TEST_DATABASE'),$DB->dbdefault,'Disposable database guard');

use GlpiPlugin\Reservaplus\{Access,Resource,ResourceService,Sector,Store};

function rpr_login(string $name,int $rights): int
{
    $user=new User(); rp_assert($user->getFromDBbyName($name),'Fixture user missing: '.$name);
    $auth=new Auth(); $auth->auth_succeded=true; $auth->user=$user; Session::init($auth);
    Plugin::load('reservaplus'); $_SESSION['glpiactiveprofile'][Access::RIGHT]=$rights;
    return (int)$user->getID();
}

$tag='RP Resources '.bin2hex(random_bytes(5));
$adminName=getenv('RP_TEST_ADMIN')?:'glpi'; $employeeName=getenv('RP_TEST_EMPLOYEE')?:'post-only';
$admin=rpr_login($adminName,Access::ADMIN);
$employeeModel=new User();rp_assert($employeeModel->getFromDBbyName($employeeName));$employee=(int)$employeeModel->getID();
$group=new Group();$groupId=(int)$group->add(['name'=>$tag,'entities_id'=>0,'is_assign'=>1,'is_usergroup'=>1,'is_requester'=>1]);
rp_assert($groupId>0);
rp_assert((bool)(new Group_User())->add(['groups_id'=>$groupId,'users_id'=>$admin]));
rpr_login($adminName,Access::ADMIN);
$sectorId=Sector::save(['name'=>$tag,'code'=>str_replace(' ','-',$tag),'entities_id'=>0,'is_active'=>1]);
$inventoryCount=countElementsInTable('glpi_computers');
$nativeCalendarCount=countElementsInTable('glpi_reservationitems');
$input=['name'=>$tag.' private room','kind'=>'room','entities_id'=>0,'publication'=>'published',
    'sectors_id'=>$sectorId,'groups_id_ticket'=>$groupId,'groups_id_manage'=>$groupId,
    'groups_id_approve'=>$groupId,'custodian_users_id'=>$admin,'building'=>'Bloco B',
    'floor'=>'B1','room_number'=>'02A','capacity'=>12,'area_m2'=>24.5,
    'policy_json'=>['requester_groups_ids'=>[$groupId]]];
$resourceId=ResourceService::save($input);

rp_test('private internal room retains plugin identity and suppresses native calendar exposure',static function()use($resourceId,$inventoryCount,$nativeCalendarCount):void{
    $row=Resource::getRow($resourceId);
    rp_same('B1',$row['floor']);rp_same('02A',$row['room_number']);rp_same(12,(int)$row['capacity']);
    rp_same(null,$row['native_items_id']);rp_same(null,$row['native_itemtype']);
    rp_same(0,ResourceService::calendarItem($row));
    rp_same($nativeCalendarCount,countElementsInTable('glpi_reservationitems'));
    rp_same($inventoryCount,countElementsInTable('glpi_computers'));
    $rows=ResourceService::search(['id'=>$resourceId]);rp_same(1,count($rows));
    rp_same('plugin_only',$rows[0]['_calendar_mode']);rp_assert($rows[0]['_sector_name']!=='');
});

rpr_login($adminName,READ|Access::MANAGE);
rp_test('management can strengthen policy but cannot weaken controls or replay stale revisions',static function()use($resourceId,$input):void{
    $row=Resource::getRow($resourceId);$policy=ResourceService::policy($row);
    rp_throws(static fn()=>ResourceService::save(['expected_revision'=>$row['config_revision'],'policy_json'=>array_replace($policy,['approval_required'=>false])],$resourceId),DomainException::class,'Somente administradores');
    rp_throws(static fn()=>ResourceService::save(array_replace($input,['name'=>$input['name'].' unsafe','policy_json'=>['signature_stage'=>'none']])),DomainException::class,'Somente administradores');
    ResourceService::save(['expected_revision'=>$row['config_revision'],'policy_json'=>array_replace($policy,['signature_stage'=>'submission'])],$resourceId);
    rp_throws(static fn()=>ResourceService::save(['expected_revision'=>$row['config_revision'],'name'=>'Stale overwrite'],$resourceId),DomainException::class,'Reabra');
    rp_same('submission',ResourceService::policy(Resource::getRow($resourceId))['signature_stage']);
});

rpr_login($employeeName,READ|CREATE);
rp_test('unrelated self-service user cannot discover or directly load a restricted resource',static function()use($resourceId):void{
    rp_same([],ResourceService::search(['resources_id'=>$resourceId]));
    rp_assert(!Resource::canView());
    $native=new Resource();rp_assert(!$native->getFromDB($resourceId));rp_same([],$native->fields);
    rp_throws(static fn()=>Resource::getRow($resourceId),DomainException::class);
});

rpr_login($adminName,Access::ADMIN);
$membershipId=(int)(new Group_User())->add(['groups_id'=>$groupId,'users_id'=>$employee]);rp_assert($membershipId>0);
rpr_login($employeeName,READ|CREATE);
rp_test('eligible requester reads scoped plugin and native calendar parent without unscoped search rights',static function()use($resourceId):void{
    rp_same(1,count(ResourceService::search(['resources_id'=>$resourceId])));
    rp_assert(!Resource::canView());
    $native=new Resource();rp_assert($native->getFromDB($resourceId));rp_same($resourceId,(int)$native->fields['id']);
});
$staleSession=$_SESSION;
rpr_login($adminName,Access::ADMIN);
rp_assert((bool)(new Group_User())->delete(['id'=>$membershipId],true));
$_SESSION=$staleSession;
rp_test('membership revocation immediately denies access despite stale GLPI session groups',static function()use($resourceId,$groupId):void{
    rp_assert(in_array($groupId,array_map('intval',$_SESSION['glpigroups']),true),'Fixture must preserve stale session membership');
    rp_assert(!in_array($groupId,Access::groups(),true));
    rp_same([],ResourceService::search(['id'=>$resourceId]));
    $native=new Resource();rp_assert(!$native->getFromDB($resourceId));rp_same([],$native->fields);
});

rpr_login($adminName,Access::ADMIN);
$_SESSION['glpiactiveentities']=[999999];
rp_test('plugin administrator cannot read a resource outside active entity scope',static function()use($resourceId):void{
    rp_same([],ResourceService::search(['id'=>$resourceId]));
    rp_throws(static fn()=>Resource::getRow($resourceId),DomainException::class);
    $native=new Resource();rp_assert(!$native->getFromDB($resourceId));
});
fwrite(STDOUT,'Resource integration fixtures: '.json_encode(['tag'=>$tag,'resource'=>$resourceId,'sector'=>$sectorId,'group'=>$groupId]).PHP_EOL);
ob_end_flush();
