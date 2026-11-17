<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\{Access,ApprovalPolicy,BookingView,LegacyResourceCatalog,Resource,ResourceAdminView as View,ResourceService,Schema,Sector};

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();
Access::assertRight('read');
$id=max(0,(int)($_POST['id']??$_GET['id']??0));
$sourceId=$id===0?max(0,(int)($_POST['source_reservationitems_id']??$_GET['source_reservationitems_id']??0)):0;
$post=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[];
$error='';$saved=[];$source=[];$kind='';$editable=false;$step=max(1,min(4,(int)($post['_step']??$_GET['step']??1)));
try{
    if(!Schema::ready())throw new DomainException('Conclua a atualização do plugin antes de cadastrar recursos.');
    $saved=$id>0?Resource::getRow($id):[];
    if($sourceId>0)$source=LegacyResourceCatalog::source($sourceId);
    $base=$saved?:($source['resource']??[]);
    if($id===0){$newPolicy=ResourceService::policy($base);$newPolicy['approval_mode']='inherit';$base['policy_json']=$newPolicy;}
    $entity=(int)($saved['entities_id']??$source['resource']['entities_id']??$_SESSION['glpiactive_entity']??0);
    if(!Access::entity($entity))throw new DomainException('Entidade indisponível.');
    if($id===0)Access::assertRight('manage');
    $editable=$id===0||Access::resource($saved,'manage');
    $row=View::submitted($post,$base);
    $kind=BookingView::text($row['kind']??$_GET['kind']??'');
    if(!isset(View::KINDS[$kind]))$kind='';
    if($kind!=='')$row['kind']=$kind;
    if($post){
        if(!$editable)throw new DomainException('Sem permissão para alterar este recurso.');
        $action=BookingView::text($post['action']??'');
        if($action==='save'){
            $input=View::input($post,$base);$input['entities_id']=$entity;
            if(!empty($post['save_draft']))$input['publication']='draft';
            if(($input['publication']??'draft')==='published'&&!$sourceId){
                $missing=[];foreach(['sectors_id'=>'setor responsável','groups_id_ticket'=>'equipe dos chamados','groups_id_manage'=>'equipe de operação','custodian_users_id'=>'responsável pela guarda']as$field=>$label)if((int)($input[$field]??$base[$field]??0)<1)$missing[]=$label;
                if($missing){$step=2;throw new DomainException('Para publicar, preencha em Responsáveis: '.implode(', ',$missing).'.');}
                if($input['policy_json']['approval_required']){
                    try{ApprovalPolicy::validate(array_replace($base,$input));}
                    catch(DomainException|InvalidArgumentException $failure){$step=3;throw $failure;}
                }
                if(($input['kind']??'')==='room'&&trim(BookingView::text($input['capacity']??''))===''){$step=1;throw new DomainException('Informe a capacidade máxima de pessoas na identificação da sala.');}
            }
            $id=$sourceId>0?LegacyResourceCatalog::saveAndLink($input,$sourceId):ResourceService::save($input,$id);
            Session::addMessageAfterRedirect($sourceId>0?'Recurso incorporado como rascunho. Confira os responsáveis e as regras antes de publicar.':'Recurso salvo.');
            Html::redirect(View::url('resource.form.php',['id'=>$id,'step'=>4]));return;
        }elseif($action==='link'){
            ResourceService::linkNative($id,BookingView::text($post['native_itemtype']??''),(int)($post['native_items_id']??0));
            Session::addMessageAfterRedirect('Ativo vinculado ao recurso.');Html::redirect(View::url('resource.form.php',['id'=>$id,'step'=>4]));return;
        }elseif($action==='export'){
            if(($post['confirm_export']??'')!=='yes')throw new DomainException('Confirme a criação do ativo no inventário.');
            ResourceService::exportNative($id,BookingView::text($post['export_itemtype']??''));
            Session::addMessageAfterRedirect('Ativo criado e vinculado.');Html::redirect(View::url('resource.form.php',['id'=>$id,'step'=>4]));return;
        }elseif($action==='change_approval'){
            $step=3; // Select a policy presentation without saving the resource.
        }elseif(in_array($action,['change_kind','add_accessory'],true)||str_starts_with($action,'remove_accessory:')){
            $step=1; // Pure no-JavaScript form interaction; nothing is saved.
        }else{throw new DomainException('Ação inválida.');}
    }
}catch(\Glpi\Exception\RedirectException $redirect){throw $redirect;}catch(Throwable $failure){
    $error=BookingView::publicError($failure);
    if(!isset($row))$row=$saved?:[];
    if(!isset($entity))$entity=(int)($_SESSION['glpiactive_entity']??0);
}
View::header($id>0?($editable?'Cadastro do recurso':'Detalhes do recurso'):'Cadastrar recurso','Organize os recursos que sua equipe disponibiliza para reserva.');
if($error!=='')View::notice($error,true);
if(!Schema::ready()||!Access::entity($entity)||($id>0&&!$saved)||($sourceId>0&&!$source)){
    http_response_code(403);View::footer();return;
}
if($id>0&&!$editable){View::readonly($saved);View::footer();return;}
if(!$editable){http_response_code(403);View::footer();return;}
if($kind===''){
    echo '<section class="reservaplus-panel"><div class="rp-ra-section-heading"><div><h2>O que você quer cadastrar?</h2><p>Escolha o tipo para preencher apenas as informações que fazem sentido para ele.</p></div></div>';
    if($source)View::notice('Incorporar: '.$source['name'].'. Escolha como este recurso será apresentado aos usuários.');
    View::kinds('',$sourceId);
    echo '</section><p class="rp-ra-subtle">Já existe no GLPI? <a href="'.View::e(View::url('resource.import.php')).'">Escolher um item reservável existente</a>.</p>';
    View::footer();return;
}
try{$policy=View::submittedPolicy($post,$base??[]);}catch(Throwable){$policy=ResourceService::policy($base??[]);}
$duration=View::duration((int)$policy['max_duration_minutes']);
if(isset($post['duration_value']))$duration=['value'=>BookingView::text($post['duration_value']),'unit'=>BookingView::text($post['duration_unit']??'minutes')];
$value=static fn(string $key,mixed $default='')=>$row[$key]??$default;
$parts=View::accessoryRows($post,$base??[]);
if(($post['action']??'')==='add_accessory'&&count($parts)<100)$parts[]=['name'=>'','quantity'=>1];
if(str_starts_with((string)($post['action']??''),'remove_accessory:')){
    $index=substr((string)$post['action'],17);if(ctype_digit($index))unset($parts[(int)$index]);$parts=array_values($parts)?:[['name'=>'','quantity'=>1]];
}
$sectors=[0=>'Selecione o setor'];foreach(Sector::search($entity)as$sector)$sectors[(int)$sector['id']]=$sector['name'];
if($source){
    View::notice('Você está incorporando “'.$source['name'].'”. O ativo original será mantido e este cadastro será salvo como rascunho.');
    if(isset($source['can_enroll'])&&!$source['can_enroll'])View::notice('Este item ainda possui reservas em andamento ou futuras. Aguarde o encerramento dessas reservas antes de incorporá-lo ao novo fluxo.',true);
    if(($source['legacy_capacity_mode']??'block')!=='block'&&$kind==='room')View::notice('Confira a capacidade: no novo fluxo, a lotação da sala é um limite obrigatório para a reserva.');
}
echo '<div class="rp-ra-editor-layout"><form method="post" action="'.View::e(View::url('resource.form.php')).'" class="rp-ra-editor" data-resource-editor data-initial-kind="'.View::e($kind).'" data-original-condition="'.View::e($saved['operational_state']??'available').'">';
View::hidden('action','save');View::hidden('id',$id);View::hidden('entities_id',$entity);View::hidden('expected_revision',(int)($saved['config_revision']??0));View::hidden('_step',$step);View::hidden('policy_included',1);
if($sourceId)View::hidden('source_reservationitems_id',$sourceId);
echo '<nav class="rp-ra-step-nav" aria-label="Etapas do cadastro" data-ra-enhanced>';
foreach([1=>'Identificação',2=>'Responsáveis',3=>'Regras da reserva',4=>'Revisão']as$i=>$label)echo '<button type="button" data-ra-go="'.$i.'"'.($step===$i?' aria-current="step"':'').'><span>'.$i.'</span>'.View::e($label).'</button>';
echo '</nav>';
View::step(1,'Identificação','Tipo, identificação e informações úteis para quem vai reservar.',$step===1);
echo '<div class="rp-ra-grid">';
View::select('kind','Tipo de recurso',View::KINDS,$kind);
View::field('name','Nome para apresentar aos usuários',$value('name'),'text','required maxlength="255" placeholder="Ex.: Notebook Dell 04 ou Sala de reuniões 204"');
echo '</div><button class="btn btn-outline-secondary rp-ra-nojs" type="submit" name="action" value="change_kind" formnovalidate>Aplicar tipo escolhido</button>';
echo '<fieldset class="rp-ra-kind-fields" data-ra-kind="equipment other"'.($kind==='room'?' hidden disabled':'').'><legend>Identificação do item</legend><div class="rp-ra-grid">';
View::field('inventory_number','Patrimônio ou código interno',$value('inventory_number'),'text','maxlength="255"');
View::field('serial','Número de série',$value('serial'),'text','maxlength="255"');
View::field('manufacturer','Fabricante',$value('manufacturer'),'text','maxlength="255"');
View::field('model','Modelo',$value('model'),'text','maxlength="255"');
echo '</div>';
View::textarea('directions','Onde e como retirar',$value('directions'),'Informe o ponto de retirada e as orientações para o usuário.');
View::textarea('features','Características do equipamento ou recurso',$value('features'),'Ajude o usuário a escolher: finalidade, compatibilidade e características importantes.');
echo '<details class="rp-ra-advanced"'.(array_filter($parts,static fn(array $p):bool=>$p['name']!=='')?' open':'').'><summary>Acessórios entregues junto com o item <span>Opcional</span></summary><div class="rp-ra-advanced-body">';
View::hidden('accessories_included',1);
echo '<p>Liste os componentes que deverão ser conferidos na retirada e na devolução.</p><div data-ra-accessories>';
foreach($parts as$index=>$part){echo '<div class="rp-ra-accessory" data-ra-accessory>';View::field('accessory_names[]','Acessório',$part['name'],'text','maxlength="255" placeholder="Ex.: Carregador"');View::field('accessory_quantities[]','Quantidade',$part['quantity'],'number','min="1" max="1000000" step="1"');echo '<button class="btn btn-outline-secondary" type="submit" name="action" value="remove_accessory:'.$index.'" data-ra-remove formnovalidate aria-label="Remover acessório">Remover</button></div>';}
echo '</div><button class="btn btn-outline-primary" type="submit" name="action" value="add_accessory" data-ra-add formnovalidate><i class="ti ti-plus" aria-hidden="true"></i> Adicionar acessório</button></div></details></fieldset>';
echo '<fieldset class="rp-ra-kind-fields" data-ra-kind="room"'.($kind!=='room'?' hidden disabled':'').'><legend>Localização e capacidade da sala</legend><div class="rp-ra-grid">';
foreach(['building'=>'Prédio ou bloco','floor'=>'Andar','room_number'=>'Número da sala','wing'=>'Ala']as$key=>$label)View::field($key,$label,$value($key),'text','maxlength="255"');
View::field('capacity','Capacidade máxima de pessoas',$value('capacity'),'number','min="1" max="1000000" step="1"','Necessária para publicar a sala.');
View::field('area_m2','Área da sala (m²)',$value('area_m2'),'number','min="0.01" step="0.01"');
echo '</div>';View::textarea('address','Endereço ou campus',$value('address'));View::textarea('directions','Como chegar e acessar a sala',$value('directions'),'Inclua acessibilidade e retirada de chaves, quando necessário.');View::textarea('features','Estrutura disponível',$value('features'),'Ex.: projetor, videoconferência, quadro, ar-condicionado e acessibilidade.');
echo '<details class="rp-ra-advanced"><summary>Mapa e coordenadas <span>Opcional</span></summary><div class="rp-ra-advanced-body"><div class="rp-ra-grid">';View::field('latitude','Latitude',$value('latitude'),'number','min="-90" max="90" step="0.0000001"');View::field('longitude','Longitude',$value('longitude'),'number','min="-180" max="180" step="0.0000001"');echo '</div><p class="rp-ra-subtle">Preencha as duas coordenadas para registrar a posição exata.</p></div></details></fieldset>';
echo '<details class="rp-ra-advanced"><summary>Localização já cadastrada no GLPI <span>Opcional</span></summary><div class="rp-ra-advanced-body">';View::nativeDropdown(Location::class,'locations_id','Localização GLPI',(int)$value('locations_id',0),$entity);echo '</div></details>';
View::textarea('comment','Orientações adicionais',$value('comment'),'Visíveis aos usuários que podem consultar este recurso.');
View::endStep(1);
View::step(2,'Responsáveis','Defina o setor e a equipe que cuidam deste recurso.',$step===2);
echo '<div class="rp-ra-grid">';View::select('sectors_id','Setor responsável',$sectors,$value('sectors_id',0),'Unidade organizacional responsável pelo equipamento ou pela sala.');
View::nativeDropdown(Group::class,'groups_id_ticket','Equipe que recebe os chamados',(int)$value('groups_id_ticket',0),$entity,['condition'=>['is_assign'=>1]],'As solicitações de reserva serão encaminhadas a este grupo.');
View::nativeDropdown(Group::class,'groups_id_manage','Equipe que administra e opera o recurso',(int)$value('groups_id_manage',0),$entity,Access::right('admin')?[]:['condition'=>['id'=>Access::groups()?:[-1]]],'Equipe autorizada a entregar, receber e conferir, conforme o perfil de cada pessoa.');
View::nativeDropdown(User::class,'custodian_users_id','Responsável pela guarda',(int)$value('custodian_users_id',0),$entity,['right'=>'all'],'Pessoa de referência pela guarda; não se torna o único aprovador.');echo '</div>';
if(count($sectors)===1&&Access::right('admin'))echo '<div class="rp-ra-inline-help">Cadastre o setor responsável para poder publicar. <a target="_blank" rel="noopener" href="'.View::e(View::url('sector.php',['new'=>1])).'">Cadastrar setor</a></div>';
echo '<details class="rp-ra-advanced"><summary>Direcionar o chamado a uma pessoa específica <span>Opcional</span></summary><div class="rp-ra-advanced-body">';View::nativeDropdown(User::class,'default_assignee_users_id','Técnico padrão',(int)$value('default_assignee_users_id',0),$entity,['right'=>'all'],'Se não definido, o sistema tenta o responsável pela guarda. Sem técnico elegível, o chamado permanece com a equipe.');echo '</div></details>';
View::endStep(2);
View::step(3,'Regras da reserva','Escolha quem pode solicitar, aprovar e assinar.',$step===3);
echo '<div class="rp-ra-policy-block">';View::check('approval_required','Exigir aprovação para reservar',(bool)$policy['approval_required'],'Uma pessoa elegível da lista de aprovadores poderá decidir.');echo '<div data-ra-approval>';
View::approvalEditor($row,$policy,$entity,$id>0);echo '</div></div><div class="rp-ra-grid">';
View::select('signature_stage','Quando o usuário aceita o termo',['submission'=>'Ao enviar a solicitação','approval'=>'Após a aprovação','handover'=>'Antes da retirada','none'=>'Sem aceite antecipado'],$policy['signature_stage']);
echo '<div class="rp-ra-field">';View::check('return_signature_required','Pedir ciência do usuário na devolução',(bool)$policy['return_signature_required'],'A conferência do responsável continua registrada separadamente.');echo '</div></div>';
echo '<details class="rp-ra-advanced"'.($policy['requester_groups_ids']?' open':'').'><summary>Restringir quem pode solicitar <span>'.($policy['requester_groups_ids']?'Acesso por grupo':'Todos da entidade').'</span></summary><div class="rp-ra-advanced-body"><label class="form-label">Grupos autorizados a solicitar</label>';
View::requesterGroupDropdown($policy['requester_groups_ids'],$entity);echo '<p class="rp-ra-subtle">Sem grupos selecionados, todos os usuários elegíveis da entidade podem solicitar. Recursos restritos aparecem somente para os grupos autorizados.</p></div></details>';
echo '<details class="rp-ra-advanced"><summary>Duração e intervalos de preparação <span>Até '.View::e($duration['value']).' '.View::e(['minutes'=>'minutos','hours'=>'horas','days'=>'dias'][$duration['unit']]??'minutos').'</span></summary><div class="rp-ra-advanced-body"><div class="rp-ra-grid">';
View::field('duration_value','Duração máxima por reserva',$duration['value'],'number','min="1" max="525600" step="1"');View::select('duration_unit','Unidade',['minutes'=>'Minutos','hours'=>'Horas','days'=>'Dias'],$duration['unit']);
View::field('buffer_before_minutes','Preparação antes (minutos)',$policy['buffer_before_minutes'],'number','min="0" max="525600" step="1"');View::field('buffer_after_minutes','Preparação depois (minutos)',$policy['buffer_after_minutes'],'number','min="0" max="525600" step="1"');
echo '</div></div></details><p class="rp-ra-subtle">Dispensar aprovação ou assinatura requer a permissão de administração do fluxo.</p>';
View::endStep(3);
View::step(4,'Revisão e publicação','Confira as informações e escolha quando disponibilizar para reserva.',$step===4);
echo '<div class="rp-ra-review"><span class="rp-ra-type-icon"><i class="'.View::icon($kind).'" aria-hidden="true"></i></span><div><h3 data-ra-preview="name">'.View::e($value('name')?:'Novo recurso').'</h3><p><span data-ra-preview="kind">'.View::e(View::KINDS[$kind]).'</span> · <span data-ra-preview="sectors_id">'.View::e($sectors[(int)$value('sectors_id',0)]??'Setor não definido').'</span></p></div></div>';
echo '<p>Para publicar, informe o nome, setor, equipes, responsável pela guarda e, quando exigida, uma lista válida de aprovadores. Salas também precisam de capacidade.</p>';
if($sourceId){View::hidden('publication','draft');View::notice('A incorporação será salva como rascunho. Depois, confira o cadastro e publique quando estiver pronto.');}
else View::select('publication','Disponibilidade para os usuários',View::PUBLICATIONS,$value('publication','draft'),'Rascunhos ficam disponíveis à equipe responsável; somente recursos publicados podem receber novas solicitações.');
if($id>0){echo '<details class="rp-ra-advanced"><summary>Condição operacional <span>'.View::e(View::CONDITIONS[(string)$value('operational_state','available')]??'').'</span></summary><div class="rp-ra-advanced-body">';View::select('operational_state','Condição atual',View::CONDITIONS,$value('operational_state','available'));echo '<div data-ra-condition-reason>';View::field('operational_reason','Motivo da mudança',BookingView::text($post['operational_reason']??''),'text','maxlength="4000"');echo '</div><p class="rp-ra-subtle">Se houver uma reserva em andamento, registre a condição pela conferência da devolução.</p></div></details>';}
else View::hidden('operational_state','available');
View::endStep(4);
echo '<div class="rp-ra-savebar"><div><span class="rp-ra-subtle">'.($id>0?'As alterações serão aplicadas ao salvar.':'Você pode salvar um rascunho e terminar o cadastro depois.').'</span></div><div><a class="btn btn-outline-secondary" href="'.View::e(View::url('resource.php')).'">Voltar aos recursos</a> ';
if(!$id||($saved['publication']??'draft')==='draft')echo '<button class="btn btn-outline-primary" type="submit" name="save_draft" value="1"'.($source&&!($source['can_enroll']??true)?' disabled':'').'>Salvar rascunho</button> ';
echo '<button class="btn btn-primary" type="submit"'.($source&&!($source['can_enroll']??true)?' disabled':'').'>'.($sourceId?'Incorporar como rascunho':'Salvar recurso').'</button></div></div>';
Html::closeForm();echo '</div>';
if($id>0){
    echo '<details class="reservaplus-panel rp-ra-inventory"'.(in_array($post['action']??'',['link','export'],true)?' open':'').'><summary><span><strong>Integração com o inventário GLPI</strong><small>Opcional · configure quando precisar vincular um ativo.</small></span><i class="ti ti-chevron-down" aria-hidden="true"></i></summary><div class="rp-ra-inventory-body">';
    if(!empty($saved['native_items_id']))echo '<p>Vinculado a <strong>'.View::e($saved['native_itemtype']).' #'.(int)$saved['native_items_id'].'</strong>.</p><p class="rp-ra-subtle">As informações e responsabilidades deste recurso continuam independentes do inventário.</p>';
    elseif(ResourceService::hasActiveCommitments($id)||!empty($saved['reservationitems_id']))View::notice('Este recurso já participa do fluxo de reservas. Uma mudança de vínculo precisa preservar seu histórico; solicite uma migração assistida.');
    else{
        echo '<p>Vincule um ativo existente ou crie um novo, se necessário. O cadastro interno já pode ser usado sem essa integração.</p><form method="post" action="'.View::e(View::url('resource.form.php')).'"><div class="rp-ra-grid">';View::hidden('id',$id);View::hidden('action','link');
        View::select('native_itemtype','Tipo do ativo existente',ResourceService::nativeTypes(),BookingView::text($post['native_itemtype']??''));View::field('native_items_id','Identificador do ativo no GLPI',BookingView::text($post['native_items_id']??''),'number','required min="1"','Consulte o identificador na ficha do ativo.');echo '</div><button class="btn btn-outline-primary" type="submit">Vincular ativo existente</button>';Html::closeForm();
        if($kind!=='room'){echo '<details class="rp-ra-advanced"><summary>Criar um ativo novo no inventário</summary><div class="rp-ra-advanced-body"><form method="post" action="'.View::e(View::url('resource.form.php')).'">';View::hidden('id',$id);View::hidden('action','export');View::select('export_itemtype','Tipo do novo ativo',['Computer'=>'Computador','Peripheral'=>'Periférico','Monitor'=>'Monitor','Printer'=>'Impressora','Phone'=>'Telefone'],'Peripheral');echo '<p>Serão copiados nome, entidade, série, patrimônio e localização.</p><label class="rp-ra-confirm"><input type="checkbox" name="confirm_export" value="yes" required> Confirmo a criação de um novo ativo no inventário GLPI.</label><button class="btn btn-outline-primary" type="submit">Criar e vincular ativo</button>';Html::closeForm();echo '</div></details>';}
    }
    echo '</div></details>';
}
View::footer();
