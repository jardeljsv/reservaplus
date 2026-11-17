<?php
declare(strict_types=1);

/** Enrollment writes real fixtures. Use only an explicitly named disposable GLPI database. */
if (getenv('RP_TEST_ALLOW_WRITES') !== '1' || !getenv('RP_TEST_DATABASE') || !getenv('GLPI_ROOT')) {
    fwrite(STDERR, "Set GLPI_ROOT, RP_TEST_DATABASE and RP_TEST_ALLOW_WRITES=1 for a disposable installation.\n");
    exit(2);
}
ob_start();
require __DIR__ . '/bootstrap.php';
global $DB;
rp_same(getenv('RP_TEST_DATABASE'), $DB->dbdefault, 'Disposable database guard');

use GlpiPlugin\Reservaplus\{Access, ItemGroup, ItemSetting, LegacyResourceCatalog, Resource, Store};

$user = new User();rp_assert($user->getFromDBbyName(getenv('RP_TEST_ADMIN') ?: 'glpi'));
$auth = new Auth();$auth->auth_succeded = true;$auth->user = $user;
Session::init($auth);Plugin::load('reservaplus');
$_SESSION['glpiactiveprofile'][Access::RIGHT] = Access::ADMIN;
$tag = 'RP Enrollment ' . bin2hex(random_bytes(5));
$group = new Group();$groupId = (int) $group->add(['name'=>$tag,'entities_id'=>0,'is_assign'=>1,'is_usergroup'=>1]);rp_assert($groupId>0);
$asset = new Computer();$assetId = (int) $asset->add(['name'=>$tag,'entities_id'=>0,'serial'=>'S-'.$tag,'otherserial'=>'P-'.$tag]);rp_assert($assetId>0);
$native = new ReservationItem();$nativeId = (int) $native->add(['itemtype'=>Computer::class,'items_id'=>$assetId,'entities_id'=>0,'is_active'=>1]);rp_assert($nativeId>0);
ItemSetting::save($nativeId,18,ItemSetting::MODE_WARN,['projector','accessible']);
ItemGroup::setGroupsForItem($nativeId,[$groupId]);
$reservation = new Reservation();$reservationId=(int)$reservation->add(['reservationitems_id'=>$nativeId,'users_id'=>$user->getID(),
    'begin'=>date('Y-m-d H:i:s',time()+86400),'end'=>date('Y-m-d H:i:s',time()+90000),'comment'=>$tag,'_disablenotif'=>true]);rp_assert($reservationId>0);
$inventoryCount=countElementsInTable('glpi_computers');$itemCount=countElementsInTable('glpi_reservationitems');
$resourceCount=countElementsInTable(Resource::getTable());

rp_test('existing reservable browse reads names and settings without creating resources',static function()use($tag,$nativeId,$groupId,$resourceCount):void{
    $found=LegacyResourceCatalog::search(['q'=>$tag]);rp_same(1,count($found));
    $source=$found[0];rp_same($nativeId,$source['source_reservationitems_id']);rp_same($tag,$source['name']);
    rp_same(18,$source['resource']['capacity']);rp_same('warn',$source['legacy_capacity_mode']);
    rp_same([$groupId],$source['resource']['policy_json']['requester_groups_ids']);
    rp_assert(str_contains($source['resource']['features'],'Projetor'));
    rp_assert(!$source['can_enroll']);rp_same(1,$source['active_reservations_count']);
    rp_same($resourceCount,countElementsInTable(Resource::getTable()));
});

rp_test('adoption does not strand an existing future reservation',static function()use($nativeId,$resourceCount):void{
    rp_throws(static fn()=>LegacyResourceCatalog::saveAndLink(['kind'=>'room'],$nativeId),DomainException::class,'reservas em andamento');
    rp_same($resourceCount,countElementsInTable(Resource::getTable()));
});
rp_assert($reservation->update(['id'=>$reservationId,'begin'=>date('Y-m-d H:i:s',time()-90000),'end'=>date('Y-m-d H:i:s',time()-86400),'_disablenotif'=>true]));
$nativeBefore=$DB->request(['FROM'=>'glpi_reservations','WHERE'=>['id'=>$reservationId]])->current();

rp_test('native-read and entity restrictions are checked independently of plugin administration',static function()use($nativeId):void{
    $old=$_SESSION['glpiactiveprofile']['computer']??0;$_SESSION['glpiactiveprofile']['computer']=0;
    try{rp_throws(static fn()=>LegacyResourceCatalog::source($nativeId),DomainException::class);}
    finally{$_SESSION['glpiactiveprofile']['computer']=$old;}
    $entities=$_SESSION['glpiactiveentities'];$_SESSION['glpiactiveentities']=[999999];
    try{rp_throws(static fn()=>LegacyResourceCatalog::source($nativeId),DomainException::class);}
    finally{$_SESSION['glpiactiveentities']=$entities;}
});

rp_test('explicit enrollment requires a chosen type and preserves requester restrictions',static function()use($nativeId,$resourceCount):void{
    rp_throws(static fn()=>LegacyResourceCatalog::saveAndLink([],$nativeId),DomainException::class,'Escolha');
    rp_throws(static fn()=>LegacyResourceCatalog::saveAndLink(['kind'=>'room','policy_json'=>['requester_groups_ids'=>[]]],$nativeId),DomainException::class,'ampliar');
    rp_same($resourceCount,countElementsInTable(Resource::getTable()));
});

rp_test('failed native association rolls back the newly created draft',static function()use($nativeId,$assetId,$resourceCount):void{
    global $DB;
    // A dangling native manufacturer is discovered by linkNative after the
    // internal draft has been inserted. No production table/schema is modified.
    rp_assert($DB->update('glpi_computers',['manufacturers_id'=>2147483000],['id'=>$assetId]));
    try{
        rp_throws(static fn()=>LegacyResourceCatalog::saveAndLink(['kind'=>'room'],$nativeId),DomainException::class,'Referência nativa não encontrada');
        rp_same($resourceCount,countElementsInTable(Resource::getTable()));
    }finally{rp_assert($DB->update('glpi_computers',['manufacturers_id'=>0],['id'=>$assetId]));}
});

$resourceId=LegacyResourceCatalog::saveAndLink(['kind'=>'room','publication'=>'published','entities_id'=>999999,'native_itemtype'=>'Phone','native_items_id'=>999999],$nativeId);
rp_test('explicit enrollment links one internal draft to the same native inventory and calendar',static function()use($resourceId,$nativeId,$assetId,$groupId,$inventoryCount,$itemCount,$nativeBefore,$reservationId):void{
    global $DB;
    $resource=Store::row('resources',$resourceId);
    rp_same('draft',$resource['publication']);rp_same('room',$resource['kind']);rp_same(0,(int)$resource['entities_id']);
    rp_same(Computer::class,$resource['native_itemtype']);rp_same($assetId,(int)$resource['native_items_id']);rp_same($nativeId,(int)$resource['reservationitems_id']);
    rp_same([$groupId],Store::decode($resource['policy_json'])['requester_groups_ids']);
    rp_same(0,(int)$resource['groups_id_ticket']);rp_same(0,(int)$resource['groups_id_approve']);rp_same(0,(int)$resource['custodian_users_id']);
    rp_same($inventoryCount,countElementsInTable('glpi_computers'));rp_same($itemCount,countElementsInTable('glpi_reservationitems'));
    rp_same($nativeBefore,$DB->request(['FROM'=>'glpi_reservations','WHERE'=>['id'=>$reservationId]])->current());
});

rp_test('already enrolled native identities are not offered or duplicated',static function()use($tag,$nativeId):void{
    $count=countElementsInTable(Resource::getTable());
    rp_same([],LegacyResourceCatalog::search(['q'=>$tag]));
    rp_throws(static fn()=>LegacyResourceCatalog::saveAndLink(['kind'=>'room'],$nativeId),DomainException::class,'já foi cadastrado');
    rp_same($count,countElementsInTable(Resource::getTable()));
});
echo 'Enrollment fixtures: '.json_encode(['resource'=>$resourceId,'reservationitem'=>$nativeId,'asset'=>$assetId,'reservation'=>$reservationId]).PHP_EOL;
ob_end_flush();
