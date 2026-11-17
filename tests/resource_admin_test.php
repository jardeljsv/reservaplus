<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use GlpiPlugin\Reservaplus\{ResourceAdminView,ResourceService,Store};

rp_test('readable duration converts exactly without changing stored policy',function():void{
    rp_same(['value'=>30,'unit'=>'days'],ResourceAdminView::duration(43200));
    rp_same(['value'=>2,'unit'=>'hours'],ResourceAdminView::duration(120));
    rp_same(['value'=>95,'unit'=>'minutes'],ResourceAdminView::duration(95));
    $input=ResourceAdminView::input(['policy_included'=>1,'approval_required'=>1,'return_signature_required'=>1,'duration_value'=>'2','duration_unit'=>'hours','signature_stage'=>'approval']);
    rp_same(120,$input['policy_json']['max_duration_minutes']);
    $native=ResourceAdminView::input(['policy_included'=>1,'approval_required'=>1,'return_signature_required'=>1,'requester_groups_ids'=>['',0,'0','9'],'duration_value'=>30,'duration_unit'=>'days']);
    rp_same([9],ResourceService::normalizePolicy($native['policy_json'])['requester_groups_ids']);
    rp_throws(fn()=>ResourceAdminView::input(['policy_included'=>1,'duration_value'=>'1.01','duration_unit'=>'minutes']),InvalidArgumentException::class);
});
rp_test('structured accessories preserve names and counts without pipe syntax',function():void{
    $input=ResourceAdminView::input(['accessories_included'=>1,'accessory_names'=>['Carregador','','Cabo HDMI'],'accessory_quantities'=>['1','1','2']]);
    rp_same([['name'=>'Carregador','quantity'=>1],['name'=>'Cabo HDMI','quantity'=>2]],ResourceService::normalizeAccessories($input['accessories_json']));
    rp_throws(fn()=>ResourceAdminView::input(['accessories_included'=>1,'accessory_names'=>[['bad']]]),InvalidArgumentException::class);
});
rp_test('failed form values preserve unrelated hidden resource metadata',function():void{
    $base=['name'=>'Old','kind'=>'equipment','capacity'=>7,'serial'=>'SER-42','policy_json'=>Store::json(['approval_required'=>true]),'entities_id'=>3];
    $draft=ResourceAdminView::submitted(['name'=>'Novo','kind'=>'room','capacity'=>'','entities_id'=>99,'native_items_id'=>99],$base);
    rp_same('Novo',$draft['name']);rp_same('',$draft['capacity']);rp_same('SER-42',$draft['serial']);rp_same(3,$draft['entities_id']);rp_assert(!isset($draft['native_items_id']));
    $input=ResourceAdminView::input(['name'=>'Novo'],$base);
    rp_assert($input['policy_json']['approval_required']);rp_assert(!array_key_exists('accessories_json',$input));
});
rp_test('form helpers escape attributes and give accessory rows distinct labels',function():void{
    ob_start();ResourceAdminView::field('accessory_names[]','Acessório','"><script>bad</script>');ResourceAdminView::field('accessory_names[]','Acessório','Cabo');$html=ob_get_clean();
    rp_assert(!str_contains($html,'<script>'));rp_assert(str_contains($html,'&lt;script&gt;'));
    preg_match_all('/<input[^>]*id="([^"]+)"/',$html,$ids);rp_same(2,count(array_unique($ids[1])));
    foreach($ids[1]as$id)rp_assert(str_contains($html,'for="'.$id.'"'));
});

if (!class_exists('Group',false)) {
    if(!class_exists('Session',false)){class Session{public static function getLoginUserID():int{return 2;}}}
    $_SESSION['glpiactiveentities']=[5];
    $DB=new class{
        public function request(array $query):\ArrayIterator{
            $id=(int)($query['WHERE']['id']??0);
            return new \ArrayIterator(in_array($id,[31,42,99],true)?[['id'=>$id,'entities_id'=>$id===99?6:5]]:[]);
        }
    };
    class Group
    {
        public static array $selected=[];
        public static int $entity=-1;
        public static string $name='';
        public static function dropdown(array $options): void
        {
            // Match GLPI 11 Dropdown::show's public value-to-internal-values handoff.
            self::$selected=$options['multiple']?($options['value']??[]):[$options['value']??0];
            self::$entity=(int)$options['entity'];
            self::$name=(string)$options['name'];
        }
    }
    rp_test('native multiple requester-group widget retains restrictions through an ordinary form save',function():void{
        ResourceAdminView::requesterGroupDropdown([31,42],5);
        rp_same([31,42],Group::$selected);rp_same(5,Group::$entity);
        // Exercise PHP's real form decoding: without [] only the last selected
        // group survives, even though the browser showed both selections.
        $wire=implode('&',array_map(static fn(int $id):string=>rawurlencode(Group::$name).'='.$id,Group::$selected));
        parse_str($wire,$posted);
        rp_same([31,42],array_map('intval',$posted['requester_groups_ids']));
        $submitted=ResourceAdminView::input(['policy_included'=>1,'approval_required'=>1,'return_signature_required'=>1,'groups_id_approve'=>30]+$posted);
        rp_same([31,42],ResourceService::normalizePolicy($submitted['policy_json'])['requester_groups_ids']);
        rp_same(30,$submitted['groups_id_approve']);
        ResourceAdminView::requesterGroupDropdown([],5);rp_same([],Group::$selected);
        ob_start();ResourceAdminView::requesterGroupDropdown([31,99],5);$html=ob_get_clean();
        rp_same([31],Group::$selected);rp_assert(str_contains($html,'Seleção indisponível nesta entidade'));
    });
}
