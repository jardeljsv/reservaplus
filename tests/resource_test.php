<?php

declare(strict_types=1);

require_once __DIR__.'/bootstrap.php';

use GlpiPlugin\Reservaplus\ResourceService;
use GlpiPlugin\Reservaplus\Store;
use GlpiPlugin\Reservaplus\Schema;
use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\Resource;

$standaloneResourceRights=!class_exists('Session');
if ($standaloneResourceRights) {
    class Session
    {
        public static array $rights=[];
        public static function getLoginUserID(): int { return 27; }
        public static function haveRight(string $name,int $mask): bool { return ((self::$rights[$name] ?? 0)&$mask)===$mask; }
    }
}

rp_test('internal rooms preserve textual identifiers and do not infer native inventory',function (): void {
    $row=ResourceService::normalize(['name'=>'Sala 02A','kind'=>'room','floor'=>'Subsolo B1','room_number'=>'02A','building'=>'Bloco B','capacity'=>'12','area_m2'=>'28.75','native_itemtype'=>'Computer','native_items_id'=>999]);
    rp_same('Subsolo B1',$row['floor']); rp_same('02A',$row['room_number']);
    rp_same(12,$row['capacity']); rp_same(28.75,$row['area_m2']);
    rp_assert(!array_key_exists('native_itemtype',$row),'Native mapping is not accepted through generic resource save');
    rp_assert(!array_key_exists('native_items_id',$row),'Native identity requires explicit link action');
});
rp_test('capacity and geographic points reject invalid values',function (): void {
    foreach ([['capacity'=>'3.5'],['capacity'=>0],['area_m2'=>-1],['latitude'=>91,'longitude'=>0],['latitude'=>0],['longitude'=>181,'latitude'=>0]] as $bad) {
        rp_throws(fn()=>ResourceService::normalize(['name'=>'R']+$bad),InvalidArgumentException::class);
    }
    $origin=ResourceService::normalize(['name'=>'R','latitude'=>'0','longitude'=>'0']);
    rp_same(0.0,$origin['latitude']); rp_same(0.0,$origin['longitude']);
});
rp_test('policy denies unsupported approval stages and malformed restrictions',function (): void {
    rp_throws(fn()=>ResourceService::normalizePolicy(['signature_stage'=>'magic']),InvalidArgumentException::class);
    rp_throws(fn()=>ResourceService::normalizePolicy(['requester_groups_ids'=>'2,3']),InvalidArgumentException::class);
    rp_throws(fn()=>ResourceService::normalizePolicy(['requester_groups_ids'=>[0]]),InvalidArgumentException::class);
    rp_throws(fn()=>ResourceService::normalizePolicy(['approval_mode'=>'everyone']),InvalidArgumentException::class);
    rp_same([4],ResourceService::normalizePolicy(['approval_mode'=>'custom','approver_users_ids'=>[4]])['approver_users_ids']);
    rp_throws(fn()=>ResourceService::normalizePolicy(['max_duration_minutes'=>0]),InvalidArgumentException::class);
    $policy=ResourceService::normalizePolicy(['requester_groups_ids'=>['8',2,8],'approval_required'=>'0','return_signature_required'=>'1']);
    rp_same([2,8],$policy['requester_groups_ids']); rp_same(false,$policy['approval_required']); rp_same(true,$policy['return_signature_required']);
});
rp_test('accessory accounting baseline rejects duplicates and nonpositive quantities',function (): void {
    rp_same([['name'=>'Carregador','quantity'=>1]],ResourceService::normalizeAccessories('[{"name":"Carregador","quantity":1}]'));
    rp_throws(fn()=>ResourceService::normalizeAccessories([['name'=>'Cabo','quantity'=>1],['name'=>'cabo','quantity'=>2]]),InvalidArgumentException::class);
    rp_throws(fn()=>ResourceService::normalizeAccessories([['name'=>'Cabo','quantity'=>0]]),InvalidArgumentException::class);
    rp_throws(fn()=>ResourceService::normalizeAccessories(['name'=>'Cabo','quantity'=>1]),InvalidArgumentException::class);
});
rp_test('weakening controls requires administrative capability even on new resources',function (): void {
    rp_assert(ResourceService::weakensControls(['approval_required'=>false]));
    rp_assert(ResourceService::weakensControls(['return_signature_required'=>false]));
    rp_assert(ResourceService::weakensControls(['signature_stage'=>'handover']));
    rp_assert(ResourceService::weakensControls(['signature_stage'=>'none']));
    rp_assert(!ResourceService::weakensControls(['signature_stage'=>'submission']));
    rp_assert(!ResourceService::weakensControls(['approval_required'=>true,'signature_stage'=>'handover'],['approval_required'=>false,'signature_stage'=>'none']));
    rp_assert(!ResourceService::weakensControls(['approval_required'=>false,'signature_stage'=>'none'],['approval_required'=>false,'signature_stage'=>'none']));
});
rp_test('responsibility and borrower identities remain separate resource fields',function (): void {
    $row=ResourceService::normalize(['name'=>'Notebook','sectors_id'=>3,'groups_id_ticket'=>7,'groups_id_manage'=>8,'groups_id_approve'=>9,'custodian_users_id'=>11,'default_assignee_users_id'=>12,'users_id_borrower'=>99]);
    rp_same(3,$row['sectors_id']); rp_same(11,$row['custodian_users_id']); rp_same(12,$row['default_assignee_users_id']);
    rp_assert(!isset($row['users_id_borrower']),'A resource save must not create/change a borrower');
});
rp_test('new schema preserves optional inventory and immutable evidence keys',function (): void {
    $schema=Schema::definitions();
    rp_assert(str_contains($schema['resources']['columns']['native_items_id'],'DEFAULT NULL'));
    rp_assert(in_array('UNIQUE KEY `native_identity` (`native_itemtype`,`native_items_id`)',$schema['resources']['keys'],true));
    rp_assert(isset($schema['bookings']['columns']['scope_revision']));
    rp_assert(isset($schema['bookinglines']['columns']['calendar_mode']));
    rp_assert(in_array('UNIQUE KEY `signer` (`agreements_id`,`users_id`)',$schema['acceptances']['keys'],true));
});

if ($standaloneResourceRights) {
    rp_test('generic native discovery is admin-only while plugin reads remain row-scoped',function (): void {
        $_SESSION=['glpiactiveentities'=>[4],'glpigroups'=>[7]];
        Session::$rights=[Access::RIGHT=>READ];
        $row=['id'=>1,'entities_id'=>4,'publication'=>'published','is_deleted'=>0,'groups_id_ticket'=>7,'groups_id_manage'=>7,'custodian_users_id'=>27,'policy_json'=>Store::json(['requester_groups_ids'=>[9]])];
        rp_assert(!Resource::canView(),'Generic native search cannot expose group-private resource names');
        rp_assert(!Access::resource($row,'read'),'Custodianship and ticket-group membership alone do not grant group-private read');
        $_SESSION['glpigroups']=[9];
        rp_assert(Access::resource($row,'read'),'Eligible requester can use the scoped plugin resource view');
        rp_assert(!Resource::canView(),'Eligible requester still cannot query unscoped native resource search');
        Session::$rights=[Access::RIGHT=>Access::ADMIN];
        rp_assert(Resource::canView());
        $row['entities_id']=8;
        rp_assert(!Access::resource($row,'read'),'Admin capability cannot bypass active entity scope');
    });
}
