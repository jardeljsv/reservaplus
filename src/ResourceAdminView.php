<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Resource administration presentation; mutations remain in the domain services. */
final class ResourceAdminView
{
    public const KINDS = ['equipment'=>'Equipamento','room'=>'Sala','other'=>'Outro recurso'];
    public const PUBLICATIONS = ['draft'=>'Rascunho','published'=>'Publicado','unavailable'=>'Indisponível','retired'=>'Retirado de uso'];
    public const CONDITIONS = ['available'=>'Disponível','inspection'=>'Em conferência','quarantine'=>'Quarentena','maintenance'=>'Manutenção'];
    private static int $sequence = 0;

    public static function e(mixed $value): string { return BookingView::e($value); }
    public static function url(string $file, array $query=[]): string { return BookingView::url($file,$query); }
    public static function header(string $title, string $subtitle): void
    {
        BookingView::header($title,$subtitle);
        global $CFG_GLPI;
        $root=rtrim((string)($CFG_GLPI['root_doc']??''),'/').'/plugins/reservaplus/';
        $version=defined('PLUGIN_RESERVAPLUS_VERSION')?PLUGIN_RESERVAPLUS_VERSION:'0.7';
        echo '<link rel="stylesheet" href="'.self::e($root.'css/resource-admin.css?v='.$version).'">';
        echo '<script defer src="'.self::e($root.'js/resource-admin.js?v='.$version).'"></script><div class="rp-resource-admin">';
    }
    public static function footer(): void { echo '</div>'; BookingView::footer(); }
    public static function icon(string $kind): string { return match($kind){'room'=>'ti ti-door','equipment'=>'ti ti-device-laptop',default=>'ti ti-box'}; }
    public static function kinds(string $selected='', int $sourceId=0): void
    {
        echo '<div class="rp-ra-type-grid">';
        foreach(self::KINDS as $kind=>$label){
            $description=match($kind){'room'=>'Espaço com localização, capacidade e estrutura para uso.','equipment'=>'Um equipamento identificado e os acessórios que o acompanham.',default=>'Outros itens que sua equipe disponibiliza para reserva.'};
            echo '<a class="rp-ra-type'.($selected===$kind?' is-selected':'').'" href="'.self::e(self::url('resource.form.php',['kind'=>$kind]+($sourceId?['source_reservationitems_id'=>$sourceId]:[]))).'"><span class="rp-ra-type-icon"><i class="'.self::icon($kind).'" aria-hidden="true"></i></span><strong>'.self::e($label).'</strong><span>'.self::e($description).'</span><span class="rp-ra-type-action">Cadastrar '.self::e(mb_strtolower($label)).' <i class="ti ti-arrow-right" aria-hidden="true"></i></span></a>';
        }
        echo '</div>';
    }
    public static function notice(string $message, bool $error=false): void
    {
        echo '<div class="alert '.($error?'alert-danger':'alert-info').'" role="'.($error?'alert':'status').'">'.self::e($message).'</div>';
    }
    public static function field(string $name,string $label,mixed $value='',string $type='text',string $attributes='',string $hint=''): void
    {
        $id='rp-ra-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$name).'-'.++self::$sequence;
        echo '<div class="rp-ra-field"><label for="'.$id.'">'.self::e($label).'</label><input class="form-control" id="'.$id.'" name="'.self::e($name).'" type="'.self::e($type).'" value="'.self::e($value).'" '.$attributes.'>';
        if($hint!=='')echo '<small>'.self::e($hint).'</small>';
        echo '</div>';
    }
    public static function textarea(string $name,string $label,mixed $value='',string $hint=''): void
    {
        $id='rp-ra-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$name).'-'.++self::$sequence;
        echo '<div class="rp-ra-field"><label for="'.$id.'">'.self::e($label).'</label><textarea class="form-control" id="'.$id.'" name="'.self::e($name).'" rows="3" maxlength="10000">'.self::e($value).'</textarea>';
        if($hint!=='')echo '<small>'.self::e($hint).'</small>';
        echo '</div>';
    }
    public static function select(string $name,string $label,array $options,mixed $selected='',string $hint=''): void
    {
        $id='rp-ra-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$name).'-'.++self::$sequence;
        echo '<div class="rp-ra-field"><label for="'.$id.'">'.self::e($label).'</label><select id="'.$id.'" class="form-select" name="'.self::e($name).'">';
        foreach($options as $value=>$text)echo '<option value="'.self::e($value).'"'.((string)$selected===(string)$value?' selected':'').'>'.self::e($text).'</option>';
        echo '</select>';if($hint!=='')echo '<small>'.self::e($hint).'</small>';echo '</div>';
    }
    public static function nativeDropdown(string $type,string $name,string $label,int $value,int $entity,array $extra=[],string $hint=''): void
    {
        $invalid=$value>0&&in_array($type,[\Group::class,\User::class],true)&&!ApprovalPolicy::validTarget($type,$value,$entity);
        $original=$value;if($invalid)$value=0;
        $rand=++self::$sequence;
        echo '<div class="rp-ra-field"><label for="dropdown_'.self::e($name).$rand.'">'.self::e($label).'</label>';
        $type::dropdown(['name'=>$name,'value'=>$value,'entity'=>$entity,'width'=>'100%','rand'=>$rand]+$extra);
        if($invalid)self::unavailableSelection($name,$original);
        if($hint!=='')echo '<small>'.self::e($hint).'</small>';echo '</div>';
    }
    public static function requesterGroupDropdown(array $selected,int $entity): void
    {
        // GLPI 11's public Dropdown API takes `value`, including for multiple
        // selections. Its internal `values` parameter is overwritten from it.
        $selected=ApprovalDefaultsView::displayIds($selected);
        $available=array_values(array_filter($selected,static fn(int $id):bool=>ApprovalPolicy::validTarget(\Group::class,$id,$entity)));
        \Group::dropdown(['name'=>'requester_groups_ids[]','value'=>$available,'multiple'=>true,'entity'=>$entity,'width'=>'100%']);
        foreach(array_diff($selected,$available)as$id)self::unavailableSelection('requester_groups_ids[]',$id);
    }
    public static function approvalDropdown(string $type,string $name,string $label,array $selected,int $entity): void
    {
        $selected=ApprovalDefaultsView::displayIds($selected);
        $available=array_values(array_filter($selected,static fn(int $id):bool=>ApprovalPolicy::validTarget($type,$id,$entity)));
        $unavailable=array_values(array_diff($selected,$available));
        $rand=++self::$sequence;
        echo '<div class="rp-ra-field"><label for="dropdown_'.self::e($name.'[]').$rand.'">'.self::e($label).'</label>';
        $options=['name'=>$name.'[]','value'=>$available,'multiple'=>true,'entity'=>$entity,'entity_sons'=>false,'width'=>'100%','rand'=>$rand];
        if($type===\User::class)$options['right']='all';
        $type::dropdown($options);
        foreach($unavailable as$id)self::unavailableSelection($name.'[]',$id);
        echo '</div>';
    }
    private static function unavailableSelection(string $name,int $value): void
    {
        echo '<label class="rp-ra-unavailable-target"><input type="checkbox" name="'.self::e($name).'" value="'.$value.'" checked> <span>Seleção indisponível nesta entidade. Desmarque para removê-la antes de salvar.</span></label>';
    }
    /** Retain every policy selection when another field fails validation. */
    public static function submittedPolicy(array $post,array $base): array
    {
        $policy=ResourceService::policy($base);
        if(!isset($post['policy_included']))return $policy;
        $policy['approval_required']=isset($post['approval_required']);
        $policy['return_signature_required']=isset($post['return_signature_required']);
        $policy['signature_stage']=BookingView::text($post['signature_stage']??'approval');
        if(array_key_exists('approval_mode',$post))$policy['approval_mode']=BookingView::text($post['approval_mode']);
        foreach(['requester_groups_ids','approver_groups_ids','approver_users_ids']as$key){
            // Controls in an inactive mode are disabled; retain their saved values.
            if($key!=='requester_groups_ids'&&!array_key_exists($key,$post)&&($policy['approval_mode']??'legacy')!=='custom')continue;
            $selected=$post[$key]??[];
            if(in_array($selected,['',0,'0',null],true))$selected=[]; // GLPI's native empty multi-select placeholder.
            if(!is_array($selected))throw new \InvalidArgumentException('Selecione grupos e pessoas usando as listas disponíveis.');
            $policy[$key]=array_values(array_filter($selected,static fn(mixed $id):bool=>!in_array($id,['',0,'0'],true)));
        }
        foreach(['buffer_before_minutes','buffer_after_minutes']as$key)$policy[$key]=$post[$key]??0;
        return $policy;
    }
    public static function approvalEditor(array $row,array $policy,int $entity,bool $existing): void
    {
        $mode=(string)($policy['approval_mode']??'legacy');
        if(!in_array($mode,['legacy','inherit','custom'],true))$mode='custom';
        $options=['inherit'=>'Usar padrão do tipo','custom'=>'Escolher grupos e pessoas'];
        if($existing||$mode==='legacy')$options=['legacy'=>'Manter grupo anterior']+$options;
        self::select('approval_mode','Quem pode aprovar',$options,$mode,'Qualquer pessoa elegível da lista pode decidir. Não é necessário obter o aceite de todos.');
        echo '<button class="btn btn-outline-secondary rp-ra-nojs" type="submit" name="action" value="change_approval" formnovalidate>Aplicar opção de aprovação</button>';
        echo '<div class="rp-ra-approval-mode" data-ra-approval-mode="inherit"'.($mode!=='inherit'?' hidden':'').'>';
        $defaults=ApprovalPolicy::defaults($entity);
        foreach(self::KINDS as$kind=>$label){
            $groups=$defaults[$kind]['groups_ids']??[];
            echo '<div class="rp-ra-approval-preview" data-ra-default-kind="'.self::e($kind).'"'.(($row['kind']??'')!==$kind?' hidden':'').'><h3>Padrão para '.self::e(mb_strtolower($label)).'</h3>';
            ApprovalDefaultsView::targets($groups,[],$entity);
            if($groups===[])echo '<p class="rp-ra-subtle">Ainda não há grupos definidos para este tipo nesta entidade. Salve um rascunho ou escolha uma lista específica antes de publicar.</p>';
            echo '</div>';
        }
        if(Access::right('admin'))echo '<p><a target="_blank" rel="noopener" href="'.self::e(self::url('config.form.php')).'">Configurar grupos padrão da entidade</a></p>';
        echo '<p class="rp-ra-subtle">O padrão vigente é usado ao enviar uma nova solicitação. Mudanças futuras não alteram quem aprova solicitações já registradas.</p></div>';
        echo '<fieldset class="rp-ra-approval-mode" data-ra-approval-mode="custom"'.($mode!=='custom'?' hidden disabled':'').'><legend>Lista específica deste recurso</legend><p>Esta lista substitui os grupos padrão. Combine grupos e pessoas ou selecione apenas um deles.</p><div class="rp-ra-grid">';
        self::approvalDropdown(\Group::class,'approver_groups_ids','Grupos que podem aprovar',(array)($policy['approver_groups_ids']??[]),$entity);
        self::approvalDropdown(\User::class,'approver_users_ids','Pessoas que podem aprovar',(array)($policy['approver_users_ids']??[]),$entity);
        echo '</div></fieldset>';
        echo '<fieldset class="rp-ra-approval-mode" data-ra-approval-mode="legacy"'.($mode!=='legacy'?' hidden disabled':'').'><legend>Grupo preservado do cadastro anterior</legend>';
        self::nativeDropdown(\Group::class,'groups_id_approve','Grupo autorizado a aprovar',(int)($row['groups_id_approve']??0),$entity);
        echo '<p class="rp-ra-subtle">O cadastro anterior continua com este grupo até você escolher explicitamente outro modo de aprovação.</p></fieldset>';
        echo '<p class="rp-ra-subtle">Os aprovadores precisam da permissão de aprovação do Reserva Plus e da permissão nativa correspondente no GLPI. O responsável pela guarda e o técnico atribuído não recebem aprovação automaticamente. Nos modos padrão e específico, os aprovadores são incluídos como observadores do chamado, respeitando as permissões nativas de visualização.</p>';
    }
    public static function check(string $name,string $label,bool $value,string $hint=''): void
    {
        $id='rp-ra-'.preg_replace('/[^a-zA-Z0-9_-]/','-',$name).'-'.++self::$sequence;
        echo '<div class="rp-ra-check"><label for="'.$id.'"><input id="'.$id.'" type="checkbox" name="'.self::e($name).'" value="1"'.($value?' checked':'').'> <span>'.self::e($label).'</span></label>';
        if($hint!=='')echo '<small>'.self::e($hint).'</small>';echo '</div>';
    }
    public static function hidden(string $name,mixed $value): void { BookingView::hidden($name,$value); }
    public static function step(int $index,string $title,string $description,bool $open): void
    {
        echo '<details class="rp-ra-step" data-resource-step="'.$index.'"'.($open?' open':'').'><summary><span class="rp-ra-step-number">'.$index.'</span><span><strong>'.self::e($title).'</strong><small>'.self::e($description).'</small></span><i class="ti ti-chevron-down" aria-hidden="true"></i></summary><div class="rp-ra-step-body">';
    }
    public static function endStep(int $index): void
    {
        echo '<div class="rp-ra-step-actions" data-ra-enhanced>';
        if($index>1)echo '<button class="btn btn-outline-secondary" type="button" data-ra-go="'.($index-1).'">Voltar</button>';
        if($index<4)echo '<button class="btn btn-outline-primary" type="button" data-ra-go="'.($index+1).'">Continuar <i class="ti ti-arrow-right" aria-hidden="true"></i></button>';
        echo '</div></div></details>';
    }
    public static function duration(int $minutes): array
    {
        if($minutes%1440===0)return ['value'=>intdiv($minutes,1440),'unit'=>'days'];
        if($minutes%60===0)return ['value'=>intdiv($minutes,60),'unit'=>'hours'];
        return ['value'=>$minutes,'unit'=>'minutes'];
    }
    /** Preserve safe scalar form values after validation errors or no-JS section actions. */
    public static function submitted(array $input,array $base): array
    {
        $allowed=['name','kind','publication','operational_state','sectors_id','groups_id_ticket','groups_id_manage','groups_id_approve','custodian_users_id','default_assignee_users_id','locations_id','inventory_number','serial','manufacturer','model','building','wing','floor','room_number','capacity','area_m2','latitude','longitude','address','directions','features','comment'];
        foreach($allowed as $key){if(array_key_exists($key,$input)&&is_scalar($input[$key]))$base[$key]=$input[$key];}
        return $base;
    }
    public static function input(array $post,array $base=[]): array
    {
        $input=$post;
        $policy=self::submittedPolicy($post,$base);
        if(isset($post['policy_included'])){
            if(isset($post['duration_value'])){
                $factor=['minutes'=>1,'hours'=>60,'days'=>1440][BookingView::text($post['duration_unit']??'')]??0;
                $value=$post['duration_value'];
                if(!is_scalar($value)||!is_numeric($value)||!$factor||!is_finite((float)$value)||(float)$value<=0||floor((float)$value*$factor)!==(float)$value*$factor)throw new \InvalidArgumentException('Informe uma duração máxima válida em minutos, horas ou dias.');
                $policy['max_duration_minutes']=(int)((float)$value*$factor);
            }else{$policy['max_duration_minutes']=$post['max_duration_minutes']??$policy['max_duration_minutes'];}
        }
        $input['policy_json']=$policy;
        if(isset($post['accessories_included'])){
            $names=(array)($post['accessory_names']??[]);$quantities=(array)($post['accessory_quantities']??[]);$parts=[];
            if(count($names)>100)throw new \InvalidArgumentException('Cadastre até 100 acessórios.');
            foreach($names as $index=>$name){
                if(!is_scalar($name))throw new \InvalidArgumentException('Nome de acessório inválido.');
                if(trim((string)$name)==='')continue;
                $parts[]=['name'=>trim((string)$name),'quantity'=>$quantities[$index]??1];
            }
            $input['accessories_json']=$parts;
        }
        return $input;
    }
    public static function accessoryRows(array $post,array $row): array
    {
        if(isset($post['accessories_included'])){
            $rows=[];$quantities=(array)($post['accessory_quantities']??[]);
            foreach(array_slice((array)($post['accessory_names']??[]),0,100,true)as$index=>$name)$rows[]=['name'=>BookingView::text($name),'quantity'=>BookingView::text($quantities[$index]??1)];
            return $rows?:[['name'=>'','quantity'=>1]];
        }
        return ResourceService::normalizeAccessories($row['accessories_json']??[])?:[['name'=>'','quantity'=>1]];
    }
    public static function readonly(array $row): void
    {
        $data=ResourceService::decorate($row);
        echo '<section class="reservaplus-panel rp-ra-readonly"><span class="rp-ra-type-icon"><i class="'.self::icon((string)$row['kind']).'" aria-hidden="true"></i></span><div><h2>'.self::e($row['name']).'</h2><p>'.self::e(implode(' · ',array_filter([$data['_location_name'],$row['building'],$row['floor']!==''?'Andar '.$row['floor']:'',$row['room_number']!==''?'Sala '.$row['room_number']:'']))).'</p><dl class="rp-ra-facts">';
        foreach(['Setor'=>$data['_sector_name'],'Responsável pela guarda'=>$data['_custodian_name'],'Equipe responsável'=>$data['_ticket_group_name'],'Capacidade'=>$row['capacity']!==null?$row['capacity'].' pessoas':'','Área'=>$row['area_m2']!==null?$row['area_m2'].' m²':'']as$label=>$value)if($value!=='')echo '<div><dt>'.self::e($label).'</dt><dd>'.self::e($value).'</dd></div>';
        echo '</dl>';
        foreach(['directions'=>'Orientações de acesso ou retirada','features'=>'Estrutura e recursos','comment'=>'Informações adicionais']as$key=>$label)if(trim((string)$row[$key])!=='')echo '<h3>'.self::e($label).'</h3><p class="rp-ra-preline">'.self::e($row[$key]).'</p>';
        if((int)$row['locations_id']>0){$location=new \Location();if($location->can((int)$row['locations_id'],READ))echo '<p><a class="btn btn-outline-secondary" href="'.self::e(\Location::getFormURLWithID((int)$row['locations_id'])).'">Ver localização no GLPI</a></p>';}
        if(Access::resource($row,'request'))echo '<a class="btn btn-primary" href="'.self::e(self::url('booking.form.php',['resources_id'=>$row['id']])).'">Adicionar à reserva</a>';
        echo '</div></section>';
    }
}
