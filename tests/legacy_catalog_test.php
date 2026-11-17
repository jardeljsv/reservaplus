<?php
declare(strict_types=1);
require __DIR__ . '/bootstrap.php';

use GlpiPlugin\Reservaplus\LegacyResourceCatalog;

rp_test('native enrollment copies description but never guesses type or responsibility', static function(): void {
    $source = LegacyResourceCatalog::defaultsFromSource([
        'name'=>'Sala de reunião A','entities_id'=>3,'locations_id'=>12,'serial'=>'S1','otherserial'=>'P1',
        'users_id_tech'=>9,'groups_id_tech'=>42,'is_recursive'=>1,
    ], ['capacity'=>20,'capacity_mode'=>'warn','features'=>['projector','accessible']], [11, 12]);
    rp_same('Sala de reunião A',$source['name']);rp_same(3,$source['entities_id']);rp_same(12,$source['locations_id']);
    rp_same('P1',$source['inventory_number']);rp_same(20,$source['capacity']);rp_same('draft',$source['publication']);
    rp_same([11,12],$source['policy_json']['requester_groups_ids']);
    foreach (['kind','groups_id_ticket','groups_id_manage','groups_id_approve','custodian_users_id','default_assignee_users_id'] as $field) {
        rp_assert(!array_key_exists($field,$source),'Must not infer '.$field);
    }
    rp_assert($source['policy_json']['approval_required']);rp_same('approval',$source['policy_json']['signature_stage']);
    rp_assert(str_contains($source['features'],'Projetor'));
});

rp_test('enrollment cannot widen an existing requester restriction', static function(): void {
    LegacyResourceCatalog::assertPreservedGroups([10,20],[20,10]);
    LegacyResourceCatalog::assertPreservedGroups([10,20],[20]);
    LegacyResourceCatalog::assertPreservedGroups([],[]);
    LegacyResourceCatalog::assertPreservedGroups([],[20]);
    rp_throws(static fn()=>LegacyResourceCatalog::assertPreservedGroups([10],[]),DomainException::class);
    rp_throws(static fn()=>LegacyResourceCatalog::assertPreservedGroups([10],[20]),DomainException::class);
    rp_throws(static fn()=>LegacyResourceCatalog::assertPreservedGroups([10],[10,20]),DomainException::class);
});

rp_test('unknown room capacity remains unknown rather than becoming zero', static function(): void {
    $source=LegacyResourceCatalog::defaultsFromSource(['name'=>'Notebook'],['features'=>[]],[]);
    rp_same(null,$source['capacity']);rp_same('',$source['features']);
    rp_same('',$source['serial']);rp_same('',$source['inventory_number']);
});
