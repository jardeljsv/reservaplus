<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\{Access,BookingView,ResourceAdminView as View,ResourceService,Schema};

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();Access::assertRight('read');
View::header('Recursos','Consulte equipamentos, salas e outros recursos disponíveis para reserva.');
if(!Schema::ready()){View::notice('Conclua a atualização do plugin para consultar os recursos.');View::footer();return;}
$kind=BookingView::text($_GET['kind']??'');if(!isset(View::KINDS[$kind]))$kind='';
$publication=BookingView::text($_GET['publication']??'');if(!isset(View::PUBLICATIONS[$publication]))$publication='';
$filters=['q'=>trim(BookingView::text($_GET['q']??'')),'kind'=>$kind];
if(Access::right('manage'))$filters['publication']=$publication;
$page=max(0,(int)($_GET['page']??0));
echo '<section class="reservaplus-panel"><div class="rp-ra-section-heading"><div><h2>'.(Access::right('manage')?'Catálogo de recursos':'Encontre um recurso').'</h2><p>'.(Access::right('manage')?'Organize o cadastro, a equipe responsável e a disponibilidade.':'Escolha uma categoria para explorar as opções, mesmo sem saber o nome.').'</p></div><div class="rp-ra-inline-actions">';
if(Access::right('manage')){echo '<a class="btn btn-outline-primary" href="'.View::e(View::url('resource.import.php')).'"><i class="ti ti-download" aria-hidden="true"></i> Incorporar do GLPI</a><a class="btn btn-primary" href="'.View::e(View::url('resource.form.php')).'"><i class="ti ti-plus" aria-hidden="true"></i> Cadastrar recurso</a>';}
echo '</div></div><nav class="rp-ra-tabs" aria-label="Categorias de recursos">';
foreach([''=>'Todos']+View::KINDS as$value=>$label){$label=match($value){'equipment'=>'Equipamentos','room'=>'Salas','other'=>'Outros recursos',default=>$label};echo '<a href="'.View::e(View::url('resource.php',array_replace($filters,['kind'=>$value]))).'"'.($kind===$value?' aria-current="page" class="is-active"':'').'><i class="'.($value===''?'ti ti-layout-grid':View::icon($value)).'" aria-hidden="true"></i> '.View::e($label).'</a>';}
echo '</nav><form method="get" class="rp-ra-filter"><input type="hidden" name="kind" value="'.View::e($kind).'">';
View::field('q','Refinar a lista (opcional)',$filters['q'],'search','placeholder="Nome, patrimônio, prédio ou sala"');
if(Access::right('manage'))View::select('publication','Disponibilidade',[''=>'Todas']+View::PUBLICATIONS,$publication);
echo '<button class="btn btn-outline-primary" type="submit">Filtrar</button>';
if(Access::right('admin'))echo '<a class="btn btn-outline-secondary" href="'.View::e(View::url('sector.php')).'">Setores responsáveis</a>';
echo '</form></section>';
try{$rows=ResourceService::search($filters,25,$page*24);$more=count($rows)>24;$rows=array_slice($rows,0,24);}catch(Throwable$failure){View::notice(BookingView::publicError($failure),true);View::footer();return;}
if(!$rows){
    echo '<section class="reservaplus-panel"><div class="reservaplus-empty"><i class="ti ti-building-warehouse" aria-hidden="true"></i><strong>'.($filters['q']!==''||$kind!==''||$publication!==''?'Nenhum recurso neste filtro':'Seu catálogo ainda não tem recursos disponíveis').'</strong><span>'.(Access::right('manage')?'Cadastre um recurso interno ou incorpore um item reservável que já existe no GLPI.':'A equipe responsável precisa publicar os recursos disponíveis para seu perfil.').'</span><div class="rp-ra-inline-actions">';
    if(Access::right('manage'))echo '<a class="btn btn-primary" href="'.View::e(View::url('resource.form.php')).'">Cadastrar recurso</a><a class="btn btn-outline-primary" href="'.View::e(View::url('resource.import.php')).'">Ver itens existentes no GLPI</a>';
    if($filters['q']!==''||$kind!==''||$publication!=='')echo '<a class="btn btn-outline-secondary" href="'.View::e(View::url('resource.php')).'">Limpar filtros</a>';
    echo '</div></div></section>';
}else{
    echo '<div class="rp-ra-resource-grid">';
    foreach($rows as$row){
        $location=implode(' · ',array_filter([$row['_location_name'],$row['building'],$row['floor']!==''?'Andar '.$row['floor']:'',$row['room_number']!==''?'Sala '.$row['room_number']:'']));
        $status=(string)$row['publication'];$condition=(string)$row['operational_state'];
        echo '<article class="rp-ra-resource-card"><header><span class="rp-ra-type-icon"><i class="'.View::icon((string)$row['kind']).'" aria-hidden="true"></i></span><div><span class="rp-ra-eyebrow">'.View::e(View::KINDS[(string)$row['kind']]??'Recurso').'</span><h2><a href="'.View::e(View::url('resource.form.php',['id'=>$row['id']])).'">'.View::e($row['name']).'</a></h2></div></header><div class="rp-ra-card-content">';
        if($location!=='')echo '<p><i class="ti ti-map-pin" aria-hidden="true"></i> '.View::e($location).'</p>';
        elseif($row['directions']!=='')echo '<p><i class="ti ti-map-pin" aria-hidden="true"></i> '.View::e(mb_strimwidth((string)$row['directions'],0,110,'…')).'</p>';
        if($row['kind']==='room'&&$row['capacity']!==null)echo '<p><i class="ti ti-users" aria-hidden="true"></i> Até '.(int)$row['capacity'].' pessoas'.($row['area_m2']!==null?' · '.View::e($row['area_m2']).' m²':'').'</p>';
        elseif($row['inventory_number']!=='')echo '<p><i class="ti ti-tag" aria-hidden="true"></i> '.View::e($row['inventory_number']).'</p>';
        if($row['features']!=='')echo '<p class="rp-ra-subtle">'.View::e(mb_strimwidth((string)$row['features'],0,150,'…')).'</p>';
        echo '<dl class="rp-ra-card-facts">';foreach(['Setor'=>$row['_sector_name'],'Responsável'=>$row['_custodian_name']]as$label=>$value)if($value!=='')echo '<div><dt>'.View::e($label).'</dt><dd>'.View::e($value).'</dd></div>';echo '</dl></div><footer><div class="rp-ra-badges"><span class="rp-ra-badge rp-ra-'.View::e($status).'">'.View::e(View::PUBLICATIONS[$status]??$status).'</span>';
        if($condition!=='available')echo '<span class="rp-ra-badge rp-ra-unavailable">'.View::e(View::CONDITIONS[$condition]??$condition).'</span>';
        echo '</div><a class="btn btn-outline-primary" href="'.View::e(View::url('resource.form.php',['id'=>$row['id']])).'">'.(Access::resource($row,'manage')?'Gerenciar':'Ver detalhes').'</a></footer></article>';
    }
    echo '</div>';
}
echo '<nav class="rp-ra-pagination" aria-label="Páginas de recursos">';
if($page>0)echo '<a class="btn btn-outline-secondary" href="'.View::e(View::url('resource.php',$filters+['page'=>$page-1])).'">Anterior</a>';
echo '<span>Página '.($page+1).'</span>';
if($more)echo '<a class="btn btn-outline-secondary" href="'.View::e(View::url('resource.php',$filters+['page'=>$page+1])).'">Próxima</a>';
echo '</nav>';View::footer();
