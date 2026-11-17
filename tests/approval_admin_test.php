<?php
declare(strict_types=1);
require_once __DIR__.'/bootstrap.php';
use GlpiPlugin\Reservaplus\{ApprovalDefaultsView,ResourceAdminView};

if(!class_exists('Session',false)){
    class Session{public static function getLoginUserID():int{return 2;}}
}
$_SESSION['glpiactiveentities']=[7];
$DB=new class{
    public function request(array $query):\ArrayIterator{
        $rows=match($query['FROM']){
            'glpi_groups'=>[['id'=>31,'entities_id'=>7],['id'=>42,'entities_id'=>7],['id'=>54,'entities_id'=>7],['id'=>99,'entities_id'=>9]],
            'glpi_users'=>[['id'=>8,'is_active'=>1,'is_deleted'=>0],['id'=>9,'is_active'=>1,'is_deleted'=>0],['id'=>99,'is_active'=>1,'is_deleted'=>0]],
            'glpi_profiles_users'=>[['users_id'=>8,'entities_id'=>7],['users_id'=>9,'entities_id'=>7],['users_id'=>99,'entities_id'=>9]],
            default=>[],
        };
        return new \ArrayIterator(array_values(array_filter($rows,static function(array $row)use($query):bool{foreach($query['WHERE']??[]as$key=>$value)if(($row[$key]??null)!=$value)return false;return true;})));
    }
};

if(!class_exists('Group',false)){
    class Group
    {
        public static array $options=[];
        public static function dropdown(array $options):void{self::$options=$options;}
    }
}
if(!class_exists('User',false)){
    class User
    {
        public static array $options=[];
        public static function dropdown(array $options):void{self::$options=$options;}
    }
}

rp_test('type defaults preserve multiple groups and optimistic revisions through PHP form decoding',function():void{
    $wire=[];
    foreach(['equipment'=>[31,42],'room'=>[54],'other'=>[]]as$kind=>$ids){
        ob_start();ResourceAdminView::approvalDropdown(Group::class,'approval_defaults['.$kind.'][groups_ids]','Aprovadores',$ids,7);ob_end_clean();
        rp_same($ids,Group::$options['value']);rp_same(7,Group::$options['entity']);rp_same(false,Group::$options['entity_sons']);
        foreach(Group::$options['value']as$id)$wire[]=rawurlencode(Group::$options['name']).'='.$id;
        $wire[]=rawurlencode('approval_defaults['.$kind.'][expected_revision]').'=3';
    }
    parse_str(implode('&',$wire),$posted);
    $result=ApprovalDefaultsView::submission($posted);
    rp_same(['31','42'],$result['equipment']['groups_ids']);rp_same(['54'],$result['room']['groups_ids']);rp_same([],$result['other']['groups_ids']);
    rp_same('3',$result['equipment']['expected_revision']);
    $posted['approval_defaults']['other']['groups_ids']='';
    rp_same([],ApprovalDefaultsView::submission($posted)['other']['groups_ids']);
});

rp_test('custom resource approvers retain independent multiple group and user targets',function():void{
    $wire=[];
    foreach([[Group::class,'approver_groups_ids',[31,42]],[User::class,'approver_users_ids',[8,9]]]as[$type,$name,$ids]){
        ob_start();ResourceAdminView::approvalDropdown($type,$name,'Aprovadores',$ids,7);ob_end_clean();
        $options=$type::$options;rp_same($ids,$options['value']);rp_same($name.'[]',$options['name']);
        foreach($ids as$id)$wire[]=rawurlencode($options['name']).'='.$id;
    }
    parse_str(implode('&',$wire),$posted);
    $input=ResourceAdminView::input(['policy_included'=>1,'approval_required'=>1,'return_signature_required'=>1,'approval_mode'=>'custom','requester_groups_ids'=>['5']]+$posted);
    rp_same('custom',$input['policy_json']['approval_mode']);
    rp_same(['31','42'],$input['policy_json']['approver_groups_ids']);rp_same(['8','9'],$input['policy_json']['approver_users_ids']);rp_same(['5'],$input['policy_json']['requester_groups_ids']);
    rp_same('all',User::$options['right']);
});

rp_test('policy presentation retains selected approvers after unrelated invalid duration',function():void{
    $post=['policy_included'=>1,'approval_required'=>1,'return_signature_required'=>1,'approval_mode'=>'custom','approver_groups_ids'=>['',0,'0','31','42'],'approver_users_ids'=>['8','9'],'duration_value'=>'invalid','duration_unit'=>'hours'];
    rp_throws(fn()=>ResourceAdminView::input($post),InvalidArgumentException::class);
    $retained=ResourceAdminView::submittedPolicy($post,[]);
    rp_same(['31','42'],$retained['approver_groups_ids']);rp_same(['8','9'],$retained['approver_users_ids']);rp_same('custom',$retained['approval_mode']);
    $inherit=ResourceAdminView::submittedPolicy(['policy_included'=>1,'approval_required'=>1,'approval_mode'=>'inherit'],[]);
    rp_same('inherit',$inherit['approval_mode']);rp_same([],$inherit['approver_groups_ids']);
    $empty=ResourceAdminView::submittedPolicy(['policy_included'=>1,'approval_mode'=>'custom','requester_groups_ids'=>'','approver_groups_ids'=>'','approver_users_ids'=>''],[]);
    rp_same([],$empty['requester_groups_ids']);rp_same([],$empty['approver_groups_ids']);rp_same([],$empty['approver_users_ids']);
});

rp_test('malformed selector input fails instead of silently widening while display values remain safe',function():void{
    rp_throws(fn()=>ApprovalDefaultsView::submission(['approval_defaults'=>'bad']),InvalidArgumentException::class);
    rp_throws(fn()=>ApprovalDefaultsView::submission(['approval_defaults'=>['equipment'=>['groups_ids'=>'31']]]),InvalidArgumentException::class);
    rp_throws(fn()=>ResourceAdminView::submittedPolicy(['policy_included'=>1,'approval_mode'=>'custom','approver_users_ids'=>'8'],[]),InvalidArgumentException::class);
    rp_same([31,42],ApprovalDefaultsView::displayIds(['',0,'31',['unsafe'],'42','wrong','31']));
});

rp_test('foreign selected identities never reach native label preload and remain explicit invalid controls',function():void{
    foreach([[Group::class,'approver_groups_ids',[31,99]],[User::class,'approver_users_ids',[8,99]]]as[$type,$name,$ids]){
        ob_start();ResourceAdminView::approvalDropdown($type,$name,'Aprovadores',$ids,7);$html=ob_get_clean();
        rp_same([$ids[0]],$type::$options['value']);
        rp_assert(str_contains($html,'Seleção indisponível nesta entidade'));
        rp_assert(str_contains($html,'name="'.$name.'[]" value="99" checked'));
    }
});
