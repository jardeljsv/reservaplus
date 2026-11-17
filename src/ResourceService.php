<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

final class ResourceService
{
    public static function policy(array $row): array
    {
        $raw=$row['policy_json'] ?? [];
        if (is_string($raw)) { $raw=Store::decode($raw); }
        if (!is_array($raw)) { throw new \InvalidArgumentException('Política inválida.'); }
        return self::normalizePolicy($raw);
    }

    public static function normalizePolicy(array $input): array
    {
        $defaults=[
            'approval_required'=>true,'approval_mode'=>'legacy','signature_stage'=>'approval','return_signature_required'=>true,
            'requester_groups_ids'=>[],'approver_users_ids'=>[],'approver_groups_ids'=>[],
            'max_duration_minutes'=>43200,'buffer_before_minutes'=>0,'buffer_after_minutes'=>0,
        ];
        $policy=array_intersect_key($input,$defaults)+$defaults;
        foreach (['approval_required','return_signature_required'] as $key) {
            $value=filter_var($policy[$key],FILTER_VALIDATE_BOOLEAN,FILTER_NULL_ON_FAILURE);
            if ($value===null) { throw new \InvalidArgumentException('Política booleana inválida.'); }
            $policy[$key]=$value;
        }
        if (!in_array($policy['signature_stage'],['submission','approval','handover','none'],true)) {
            throw new \InvalidArgumentException('Etapa de assinatura inválida.');
        }
        foreach (['requester_groups_ids','approver_users_ids','approver_groups_ids'] as $key) {
            if (!is_array($policy[$key])) { throw new \InvalidArgumentException('Lista de destinatários inválida.'); }
            $ids=[];
            foreach ($policy[$key] as $value) {
                $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
                if ($id===false) { throw new \InvalidArgumentException('Identificador de grupo/usuário inválido.'); }
                $ids[]=$id;
            }
            $policy[$key]=array_values(array_unique($ids)); sort($policy[$key]);
        }
        if (!in_array($policy['approval_mode'], ['legacy','inherit','custom'], true)) { throw new \InvalidArgumentException('Modo de aprovação inválido.'); }
        if (count($policy['approver_users_ids']) + count($policy['approver_groups_ids']) > ApprovalPolicy::MAX_TARGETS) { throw new \InvalidArgumentException('Selecione até 100 alvos de aprovação por recurso.'); }
        foreach (['max_duration_minutes','buffer_before_minutes','buffer_after_minutes'] as $key) {
            $min=$key==='max_duration_minutes'?1:0;
            $v=filter_var($policy[$key],FILTER_VALIDATE_INT,['options'=>['min_range'=>$min,'max_range'=>525600]]);
            if ($v===false) { throw new \InvalidArgumentException('Duração ou intervalo inválido.'); }
            $policy[$key]=$v;
        }
        return $policy;
    }

    public static function normalizeAccessories(mixed $input): array
    {
        if (is_string($input)) { $input=trim($input)==='' ? [] : Store::decode($input); }
        if (!is_array($input) || !array_is_list($input) || count($input)>100) {
            throw new \InvalidArgumentException('Acessórios devem ser uma lista com até 100 itens.');
        }
        $out=[]; $seen=[];
        foreach ($input as $part) {
            if (!is_array($part)) { throw new \InvalidArgumentException('Acessório inválido.'); }
            $name=trim((string)($part['name'] ?? ''));
            $qty=filter_var($part['quantity'] ?? 1,FILTER_VALIDATE_INT,['options'=>['min_range'=>1,'max_range'=>10000]]);
            $key=mb_strtolower($name);
            if ($name==='' || mb_strlen($name)>255 || $qty===false || isset($seen[$key])) {
                throw new \InvalidArgumentException('Informe acessórios com nomes distintos e quantidades positivas.');
            }
            $seen[$key]=true; $out[]=['name'=>$name,'quantity'=>$qty];
        }
        return $out;
    }

    /** Default policy is the minimum baseline for a newly created resource. */
    public static function weakensControls(array $policy, array $previous = []): bool
    {
        $policy=self::normalizePolicy($policy);
        $previous=self::normalizePolicy($previous);
        $stageOrder=['submission'=>0,'approval'=>1,'handover'=>2,'none'=>3];
        return ($previous['approval_required'] && !$policy['approval_required'])
            || ($previous['return_signature_required'] && !$policy['return_signature_required'])
            || $stageOrder[$policy['signature_stage']]>$stageOrder[$previous['signature_stage']];
    }

    /** Pure validated field transformation, independently testable without GLPI. */
    public static function normalize(array $input, array $existing = []): array
    {
        $defaults=['kind'=>'equipment','publication'=>'draft','operational_state'=>'available','entities_id'=>0,'is_recursive'=>0,'is_deleted'=>0,'locations_id'=>0,'states_id'=>0,'sectors_id'=>0,'groups_id_ticket'=>0,'groups_id_manage'=>0,'groups_id_approve'=>0,'custodian_users_id'=>0,'default_assignee_users_id'=>0];
        $values=[];
        foreach (['name'=>255,'serial'=>255,'inventory_number'=>255,'manufacturer'=>255,'model'=>255,'building'=>255,'floor'=>64,'room_number'=>64,'wing'=>255,'address'=>4000,'directions'=>10000,'features'=>10000,'comment'=>10000] as $key=>$max) {
            $raw=$input[$key] ?? $existing[$key] ?? '';
            if (!is_scalar($raw)) { throw new \InvalidArgumentException('Campo textual inválido: '.$key); }
            $value=trim((string)$raw);
            if (mb_strlen($value)>$max || str_contains($value,"\0")) { throw new \InvalidArgumentException('Campo muito longo ou inválido: '.$key); }
            $values[$key]=$value;
        }
        if ($values['name']==='') { throw new \InvalidArgumentException('Informe o nome do recurso.'); }
        foreach ($defaults as $key=>$default) {
            $value=$input[$key] ?? $existing[$key] ?? $default;
            if (is_int($default)) {
                $v=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>0,'max_range'=>2147483647]]);
                if ($v===false) { throw new \InvalidArgumentException('Identificador inválido: '.$key); }
                $values[$key]=$v;
            } else { $values[$key]=(string)$value; }
        }
        if (!in_array($values['kind'],['equipment','room','other'],true) || !in_array($values['publication'],['draft','published','unavailable','retired'],true)) {
            throw new \InvalidArgumentException('Tipo ou publicação inválidos.');
        }
        if (!in_array($values['operational_state'],['available','inspection','quarantine','maintenance'],true)) { throw new \InvalidArgumentException('Condição operacional inválida.'); }
        // Native recursive resource reads require additional policy; explicit entities only initially.
        $values['is_recursive']=0; $values['is_deleted']=0;
        foreach (['capacity'=>[1,1000000],'area_m2'=>[0.01,999999999.99],'latitude'=>[-90,90],'longitude'=>[-180,180]] as $key=>[$min,$max]) {
            $value=$input[$key] ?? $existing[$key] ?? null;
            if ($value===null || $value==='') { $values[$key]=null; continue; }
            if (!is_numeric($value) || !is_finite((float)$value) || (float)$value<$min || (float)$value>$max || ($key==='capacity' && floor((float)$value)!==(float)$value)) {
                throw new \InvalidArgumentException('Valor inválido: '.$key);
            }
            $values[$key]=$key==='capacity'?(int)$value:round((float)$value,$key==='area_m2'?2:7);
        }
        if (($values['latitude']===null)!==($values['longitude']===null)) { throw new \InvalidArgumentException('Informe latitude e longitude juntas.'); }
        $policy=$input['policy_json'] ?? $existing['policy_json'] ?? [];
        if (is_string($policy)) { $policy=Store::decode($policy); }
        $values['policy_json']=Store::json(self::normalizePolicy($policy));
        $values['accessories_json']=Store::json(self::normalizeAccessories($input['accessories_json'] ?? $existing['accessories_json'] ?? []));
        return $values;
    }

    private static function ancestors(int $entity): array
    {
        $ancestors=function_exists('getAncestorsOf') ? array_values(\getAncestorsOf('glpi_entities',$entity)) : [];
        return array_values(array_unique(array_merge([$entity],array_map('intval',$ancestors))));
    }

    private static function nativeRow(string $table, int $id): array
    {
        global $DB;
        if ($id<1) { throw new \DomainException('Referência nativa inválida.'); }
        $row=$DB->request(['FROM'=>$table,'WHERE'=>['id'=>$id],'LIMIT'=>1])->current();
        if (!$row) { throw new \DomainException('Referência nativa não encontrada.'); }
        return (array)$row;
    }

    private static function nativeEntity(array $row, int $entity): bool
    {
        return (int)($row['entities_id'] ?? -1)===$entity
            || (!empty($row['is_recursive']) && in_array((int)$row['entities_id'],self::ancestors($entity),true));
    }

    private static function validateUser(int $id, int $entity): void
    {
        global $DB;
        if ($id===0) { return; }
        $user=self::nativeRow('glpi_users',$id);
        if (empty($user['is_active']) || !empty($user['is_deleted'])) { throw new \DomainException('O usuário responsável deve estar ativo.'); }
        foreach ($DB->request(['FROM'=>'glpi_profiles_users','WHERE'=>['users_id'=>$id]]) as $membership) {
            if (self::nativeEntity($membership,$entity)) { return; }
        }
        throw new \DomainException('O usuário não possui vínculo com a entidade do recurso.');
    }

    private static function validateReferences(array $values): void
    {
        $entity=(int)$values['entities_id'];
        foreach (['groups_id_ticket','groups_id_manage','groups_id_approve'] as $key) {
            if ($key === 'groups_id_approve' && self::policy($values)['approval_mode'] !== 'legacy') { continue; }
            if ((int)$values[$key]===0) { continue; }
            $group=self::nativeRow('glpi_groups',(int)$values[$key]);
            if (!self::nativeEntity($group,$entity) || ($key==='groups_id_ticket' && empty($group['is_assign']))) {
                throw new \DomainException('Grupo incompatível com a entidade ou atribuição do recurso.');
            }
        }
        foreach (self::policy($values)['requester_groups_ids'] as $groupId) {
            if (!self::nativeEntity(self::nativeRow('glpi_groups',$groupId),$entity)) { throw new \DomainException('Grupo solicitante fora da entidade.'); }
        }
        ApprovalPolicy::validate($values, $values['publication'] === 'published');
        foreach (['custodian_users_id','default_assignee_users_id'] as $key) { self::validateUser((int)$values[$key],$entity); }
        if ((int)$values['sectors_id']>0) {
            $sector=Store::row('sectors',(int)$values['sectors_id']);
            if ((int)$sector['entities_id']!==$entity || empty($sector['is_active'])) { throw new \DomainException('Setor inválido para esta entidade.'); }
        }
        if ((int)$values['locations_id']>0) {
            $location=self::nativeRow('glpi_locations',(int)$values['locations_id']);
            if (!self::nativeEntity($location,$entity)) { throw new \DomainException('Localização fora da entidade.'); }
        }
        if ($values['publication']==='published') {
            foreach (['sectors_id','groups_id_ticket','groups_id_manage','custodian_users_id'] as $field) {
                if ((int)$values[$field]<1) { throw new \DomainException('Antes de publicar, defina setor, grupo de chamados, grupo gestor e responsável pela guarda.'); }
            }
            if ($values['kind']==='room' && $values['capacity']===null) { throw new \DomainException('Informe a capacidade da sala antes de publicar.'); }
        }
    }

    public static function save(array $input, int $id = 0): int
    {
        Access::assertRight('manage');
        return Store::transaction(function () use ($input,$id): int {
            $old=$id>0?Store::lock('resources',$id):[];
            if ($old) {
                Access::assertResource($old,'manage');
                if ((int)($input['expected_revision'] ?? 0)!==(int)$old['config_revision']) { throw new \DomainException('O recurso foi alterado. Reabra o formulário antes de salvar.'); }
            }
            $values=self::normalize($input,$old);
            Access::assertResource($values,'manage');
            if (self::weakensControls(self::policy($values),$old?self::policy($old):[]) && !Access::right('admin')) {
                throw new \DomainException('Somente administradores podem dispensar aprovação/assinaturas ou adiar a etapa de assinatura.');
            }
            if ($old && (int)$values['entities_id']!==(int)$old['entities_id']) { throw new \DomainException('A transferência de entidade exige migração assistida; mantenha a entidade atual.'); }
            self::validateReferences($values);
            if ($old) {
                // Identity/policy changes cannot silently alter a submitted or active loan.
                $active=self::hasActiveCommitments($id);
                $protected=['kind','serial','inventory_number','accessories_json','policy_json','locations_id','building','floor','room_number','capacity','groups_id_approve','sectors_id','groups_id_ticket','groups_id_manage','custodian_users_id','default_assignee_users_id'];
                foreach ($protected as $key) {
                    // Adding an explicit default mode during a normal rc2 edit
                    // must not look like a policy change on an active request.
                    $changed = $key === 'policy_json'
                        ? self::policy($values) != self::policy($old)
                        : (string)($values[$key] ?? '') !== (string)($old[$key] ?? '');
                    if ($active && $changed) {
                        throw new \DomainException('Há solicitações abertas. A transferência de responsabilidade, identidade, local ou política exige uma migração assistida nesta versão.');
                    }
                }
                // Receiving/inspection services own state changes during physical custody.
                if ($active && $values['operational_state']!==$old['operational_state']) { throw new \DomainException('Altere a condição operacional pela conferência da devolução.'); }
                if ($active && $values['publication']==='retired' && $old['publication']!=='retired') { throw new \DomainException('Conclua as solicitações abertas antes de retirar o recurso de uso.'); }
                if ($values['operational_state']!==$old['operational_state']) {
                    if ($values['operational_state']==='available') { Access::assertResource($old,'inspect'); }
                    $reason=trim((string)($input['operational_reason'] ?? ''));
                    if ($reason==='' || mb_strlen($reason)>4000) { throw new \DomainException('Informe o motivo da alteração da condição operacional.'); }
                }
                if ((int)$old['reservationitems_id']>0 && self::policy($values)['requester_groups_ids']!==self::policy($old)['requester_groups_ids']) {
                    throw new \DomainException('Uma mudança de visibilidade com calendário nativo publicado exige migração assistida.');
                }
            }
            $values['config_revision']=(int)($old['config_revision'] ?? 0)+1;
            $values['date_mod']=Store::now();
            if ($id>0) { Store::update('resources',$id,$values); }
            else { $values['date_creation']=Store::now(); $id=Store::insert('resources',$values); }
            $audit=$values;
            if ($old && $values['operational_state']!==$old['operational_state']) { $audit['_operational_reason']=trim((string)$input['operational_reason']); }
            Store::insert('resourcechanges',['resources_id'=>$id,'users_id_actor'=>Access::userId(),'revision'=>$values['config_revision'],'before_json'=>Store::json($old),'after_json'=>Store::json($audit),'date_creation'=>Store::now()]);
            return $id;
        });
    }

    public static function hasActiveCommitments(int $id): bool
    {
        foreach (Store::find('bookinglines',['resources_id'=>$id]) as $line) {
            $booking=Store::row('bookings',(int)$line['bookings_id']);
            if (!in_array($booking['status'],['completed','cancelled','rejected'],true)) { return true; }
        }
        return false;
    }

    public static function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        if (!Access::right('read') || !Access::entityIds()) { return []; }
        $limit=max(1,min(200,$limit)); $offset=max(0,$offset);
        $where=['entities_id'=>Access::entityIds(),'is_deleted'=>0];
        if (array_key_exists('id',$filters) || array_key_exists('resources_id',$filters)) {
            $id=filter_var($filters['id'] ?? $filters['resources_id'],FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if ($id===false) { return []; }
            $where['id']=$id;
        }
        foreach (['kind','publication','sectors_id','entities_id'] as $key) {
            if (isset($filters[$key]) && $filters[$key]!=='') {
                if ($key==='entities_id' && !Access::entity((int)$filters[$key])) { return []; }
                $where[$key]=in_array($key,['sectors_id','entities_id'],true)?(int)$filters[$key]:(string)$filters[$key];
            }
        }
        foreach (['building','floor','wing'] as $key) {
            if (isset($filters[$key]) && trim((string)$filters[$key])!=='') { $where[$key]=trim((string)$filters[$key]); }
        }
        if (!empty($filters['min_capacity'])) { $where['capacity']=['>=',max(1,(int)$filters['min_capacity'])]; }
        $query=trim((string)($filters['q'] ?? ''));
        if ($query!=='') { $where['OR']=['name'=>['LIKE','%'.$query.'%'],'inventory_number'=>['LIKE','%'.$query.'%'],'room_number'=>['LIKE','%'.$query.'%'],'building'=>['LIKE','%'.$query.'%'],'floor'=>['LIKE','%'.$query.'%'],'wing'=>['LIKE','%'.$query.'%']]; }
        // Apply row ACL before visible pagination; do not truncate permitted rows at 200/500.
        $out=[]; $visible=0; $scan=0;
        do {
            $rows=Store::find('resources',$where,['ORDER'=>['name ASC','id ASC'],'LIMIT'=>200,'START'=>$scan]);
            foreach ($rows as $row) {
                if (!Access::resource($row,'read')) { continue; }
                if ($visible++<$offset) { continue; }
                $out[]=self::decorate($row);
                if (count($out)>=$limit) { return $out; }
            }
            $scan+=count($rows);
        } while (count($rows)===200);
        return $out;
    }

    public static function decorate(array $row): array
    {
        static $labels=[];
        $row['_calendar_mode']=empty($row['native_items_id']) && self::policy($row)['requester_groups_ids'] ? 'plugin_only':'native';
        $row['_calendar_label']=$row['_calendar_mode']==='plugin_only' ? __('Calendário restrito ao ReservaPlus','reservaplus') : __('Calendário GLPI','reservaplus');
        foreach (['_sector_name'=>['sectors','sectors_id'],'_custodian_name'=>['glpi_users','custodian_users_id'],'_ticket_group_name'=>['glpi_groups','groups_id_ticket'],'_location_name'=>['glpi_locations','locations_id']] as $label=>[$table,$field]) {
            $row[$label]='';
            if ((int)$row[$field]<1) { continue; }
            $cacheKey=$table.':'.(int)$row[$field];
            if (isset($labels[$cacheKey])) { $row[$label]=$labels[$cacheKey]; continue; }
            try {
                $target=$table==='sectors'?Store::row('sectors',(int)$row[$field]):self::nativeRow($table,(int)$row[$field]);
                $row[$label]=(string)($target['completename'] ?? $target['name'] ?? '');
                if ($table==='glpi_users') { $row[$label]=trim(($target['firstname'] ?? '').' '.($target['realname'] ?? '')) ?: (string)$target['name']; }
            } catch (\Throwable) { $row[$label]='#'.(int)$row[$field]; }
            $labels[$cacheKey]=$row[$label];
        }
        return $row;
    }

    /** Native item creation is serialized by the Resource row, and never creates inventory. */
    public static function calendarItem(array $resource): int
    {
        if (!Store::inTransaction()) { return Store::transaction(fn(): int=>self::calendarItem(Store::lock('resources',(int)$resource['id']))); }
        $resource=Store::lock('resources',(int)$resource['id']);
        Access::assertResource($resource,'read');
        if (empty($resource['native_items_id']) && self::policy($resource)['requester_groups_ids']) { return 0; }
        $type=empty($resource['native_items_id'])?Resource::class:(string)$resource['native_itemtype'];
        $itemId=empty($resource['native_items_id'])?(int)$resource['id']:(int)$resource['native_items_id'];
        global $DB;
        $rows=[];
        foreach ($DB->request(['FROM'=>'glpi_reservationitems','WHERE'=>['itemtype'=>$type,'items_id'=>$itemId]]) as $row) { $rows[]=$row; }
        if (count($rows)>1) { throw new \DomainException('Identidade duplicada no calendário nativo. Solicite reconciliação.'); }
        if ($rows) {
            $row=$rows[0];
            if (empty($row['is_active'])) { throw new \DomainException('O recurso está indisponível no calendário nativo.'); }
            $id=(int)$row['id'];
        } else {
            if ($type!==Resource::class && !\Session::haveRight('reservation',UPDATE)) { throw new \DomainException('Um administrador deve habilitar a reserva do ativo nativo antes da solicitação.'); }
            $model=new \ReservationItem();
            $id=(int)$model->add(['itemtype'=>$type,'items_id'=>$itemId,'entities_id'=>(int)$resource['entities_id'],'is_recursive'=>0,'is_active'=>1,'comment'=>'ReservaPlus #'.(int)$resource['id']]);
            if ($id<1) { throw new \RuntimeException('Falha ao publicar o recurso no calendário nativo.'); }
        }
        if ((int)$resource['reservationitems_id']!==$id) { Store::update('resources',(int)$resource['id'],['reservationitems_id'=>$id,'date_mod'=>Store::now()]); }
        return $id;
    }

    public static function nativeTypes(): array
    {
        global $CFG_GLPI;
        $types=[];
        foreach (($CFG_GLPI['reservation_types'] ?? []) as $type) {
            if ($type!==Resource::class && is_string($type) && class_exists($type) && is_subclass_of($type,\CommonDBTM::class)) { $types[$type]=$type::getTypeName(1); }
        }
        return $types;
    }

    public static function linkNative(int $resourceId, string $itemtype, int $nativeId): void
    {
        Store::transaction(function () use ($resourceId,$itemtype,$nativeId): void {
            $resource=Store::lock('resources',$resourceId); Access::assertResource($resource,'manage');
            if (!isset(self::nativeTypes()[$itemtype]) || $nativeId<1) { throw new \DomainException('Tipo/identificador nativo não permitido.'); }
            if ((string)$resource['native_itemtype']===$itemtype && (int)$resource['native_items_id']===$nativeId) { return; }
            if (!empty($resource['native_items_id']) || (int)$resource['reservationitems_id']>0 || Store::find('bookinglines',['resources_id'=>$resourceId],['LIMIT'=>1])) {
                throw new \DomainException('Este recurso já possui vínculo ou histórico. A troca da identidade exige migração assistida.');
            }
            $native=new $itemtype();
            if (!$native->can($nativeId,READ) || !$native->getFromDB($nativeId) || !self::nativeEntity($native->fields,(int)$resource['entities_id']) || !empty($native->fields['is_deleted'])) {
                throw new \DomainException('Ativo nativo indisponível nesta entidade.');
            }
            if (Store::find('resources',['native_itemtype'=>$itemtype,'native_items_id'=>$nativeId],['LIMIT'=>1])) { throw new \DomainException('O ativo já representa outro recurso.'); }
            global $DB;
            $nativeCalendar=[];
            foreach ($DB->request(['FROM'=>'glpi_reservationitems','WHERE'=>['itemtype'=>$itemtype,'items_id'=>$nativeId]]) as $ri) { $nativeCalendar[]=$ri; }
            if (count($nativeCalendar)>1) { throw new \DomainException('O ativo possui identidades de calendário duplicadas. Reconcilie antes de vincular.'); }
            if ($nativeCalendar) {
                // Enrollment changes who owns writes to this calendar identity.
                // Never strand current/future legacy bookings behind workflow guards.
                $reservationItemId=(int)$nativeCalendar[0]['id'];
                if ($DB->doQuery('SELECT `id` FROM `glpi_reservationitems` WHERE `id` = '.$reservationItemId.' FOR UPDATE')===false) {
                    throw new \RuntimeException('Não foi possível verificar o calendário existente.');
                }
                $pending=$DB->request(['SELECT'=>['id'],'FROM'=>'glpi_reservations',
                    'WHERE'=>['reservationitems_id'=>$reservationItemId,'end'=>['>',Store::now()]],'LIMIT'=>1])->current();
                if ($pending) { throw new \DomainException('Este item possui reservas em andamento ou futuras. Conclua ou cancele essas reservas no fluxo anterior antes de vinculá-lo.'); }
            }
            $changes=['native_itemtype'=>$itemtype,'native_items_id'=>$nativeId,'reservationitems_id'=>(int)($nativeCalendar[0]['id'] ?? 0),'config_revision'=>(int)$resource['config_revision']+1,'date_mod'=>Store::now()];
            foreach (['serial'=>'serial','inventory_number'=>'otherserial'] as $local=>$field) {
                if (trim((string)$resource[$local])==='' && is_scalar($native->fields[$field] ?? null)) {
                    $changes[$local]=mb_substr(trim((string)$native->fields[$field]),0,255);
                }
            }
            if (trim((string)$resource['manufacturer'])==='' && !empty($native->fields['manufacturers_id'])) {
                $maker=self::nativeRow('glpi_manufacturers',(int)$native->fields['manufacturers_id']);
                $changes['manufacturer']=mb_substr((string)$maker['name'],0,255);
            }
            $modelFields=['Computer'=>['computermodels_id','glpi_computermodels'],'Peripheral'=>['peripheralmodels_id','glpi_peripheralmodels'],'Monitor'=>['monitormodels_id','glpi_monitormodels'],'Printer'=>['printermodels_id','glpi_printermodels'],'Phone'=>['phonemodels_id','glpi_phonemodels'],'NetworkEquipment'=>['networkequipmentmodels_id','glpi_networkequipmentmodels']];
            if (trim((string)$resource['model'])==='' && isset($modelFields[$itemtype])) {
                [$field,$table]=$modelFields[$itemtype];
                if (!empty($native->fields[$field])) { $model=self::nativeRow($table,(int)$native->fields[$field]); $changes['model']=mb_substr((string)$model['name'],0,255); }
            }
            Store::update('resources',$resourceId,$changes);
            Store::insert('resourcechanges',['resources_id'=>$resourceId,'users_id_actor'=>Access::userId(),'revision'=>$changes['config_revision'],'before_json'=>Store::json($resource),'after_json'=>Store::json($changes),'date_creation'=>Store::now()]);
        });
    }

    /** Explicit export of equipment only. Room/custom-type export is not guessed. */
    public static function exportNative(int $resourceId, string $itemtype): int
    {
        return Store::transaction(function () use ($resourceId,$itemtype): int {
            $resource=Store::lock('resources',$resourceId); Access::assertResource($resource,'manage');
            if (!in_array($itemtype,['Computer','Peripheral','Monitor','Printer','Phone'],true) || $resource['kind']==='room') { throw new \DomainException('Exportação disponível para equipamentos nos tipos listados. Salas podem vincular um ativo existente.'); }
            if (!empty($resource['native_items_id'])) {
                if ($resource['native_itemtype']!==$itemtype) { throw new \DomainException('O recurso já está vinculado a outro tipo.'); }
                return (int)$resource['native_items_id'];
            }
            if ((int)$resource['reservationitems_id']>0 || Store::find('bookinglines',['resources_id'=>$resourceId],['LIMIT'=>1])) { throw new \DomainException('Exporte antes da primeira reserva; um recurso com histórico exige migração assistida.'); }
            $native=new $itemtype();
            $input=['name'=>$resource['name'],'entities_id'=>(int)$resource['entities_id'],'is_recursive'=>0,'serial'=>$resource['serial'],'otherserial'=>$resource['inventory_number'],'locations_id'=>(int)$resource['locations_id'],'comment'=>'Criado explicitamente pelo ReservaPlus #'.$resourceId];
            if (!$native->can(-1,CREATE,$input)) { throw new \DomainException('Sem permissão nativa para criar este ativo.'); }
            $nativeId=(int)$native->add($input);
            if ($nativeId<1) { throw new \RuntimeException('O GLPI não confirmou a criação do ativo.'); }
            self::linkNative($resourceId,$itemtype,$nativeId);
            return $nativeId;
        });
    }
}
