<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\{Access,BookingView,ResourceAdminView as View,Sector,Store};
include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();Access::assertRight('admin');
$id=max(0,(int)($_POST['id']??$_GET['id']??0));$error='';$row=[];$post=$_SERVER['REQUEST_METHOD']==='POST'?$_POST:[];
try{
    $saved=$id>0?Store::row('sectors',$id):[];
    $entity=(int)($saved['entities_id']??$_SESSION['glpiactive_entity']??0);
    if(!Access::entity($entity))throw new DomainException('Entidade indisponível.');
    $row=$saved;
    if($post){
        foreach(['name','code','parent_id']as$key)if(isset($post[$key])&&is_scalar($post[$key]))$row[$key]=$post[$key];
        $row['is_active']=isset($post['is_active'])?1:0;
        $id=Sector::save(array_replace($post,['entities_id'=>$entity]),$id);
        Session::addMessageAfterRedirect('Setor salvo.');Html::redirect(View::url('sector.php'));return;
    }
}catch(\Glpi\Exception\RedirectException $redirect){throw $redirect;}catch(Throwable$failure){$error=BookingView::publicError($failure);}
View::header('Setores responsáveis','Organize as unidades que cuidam dos equipamentos e das salas.');
if($error!=='')View::notice($error,true);
if(!isset($entity)||!Access::entity($entity)||($id>0&&!isset($saved['id']))){http_response_code(403);View::footer();return;}
$editing=$id>0||!empty($_GET['new'])||$post!==[];
if($editing){
    echo '<section class="reservaplus-panel rp-ra-sector-editor"><div class="rp-ra-section-heading"><div><h2>'.($id?'Editar setor':'Cadastrar setor').'</h2><p>O setor identifica a unidade organizacional responsável pelo recurso.</p></div><a class="btn btn-outline-secondary" href="'.View::e(View::url('sector.php')).'">Voltar aos setores</a></div><form method="post" action="'.View::e(View::url('sector.php')).'"><div class="rp-ra-grid">';View::hidden('id',$id);View::hidden('entities_id',$entity);
    View::field('name','Nome do setor',$row['name']??'','text','required maxlength="255" placeholder="Ex.: Coordenação de tecnologia"');View::field('code','Sigla ou código',$row['code']??'','text','required maxlength="64" placeholder="Ex.: CTI"');
    $parents=[0=>'Sem setor superior'];foreach(Sector::search($entity)as$sector)if((int)$sector['id']!==$id)$parents[(int)$sector['id']]=$sector['name'];View::select('parent_id','Setor superior',$parents,$row['parent_id']??0);echo '</div>';View::check('is_active','Setor ativo',!$row||!empty($row['is_active']));echo '<div class="rp-ra-inline-actions"><button class="btn btn-primary" type="submit">Salvar setor</button><a class="btn btn-outline-secondary" href="'.View::e(View::url('sector.php')).'">Cancelar</a></div>';Html::closeForm();echo '</section>';
}else{
    $q=trim(BookingView::text($_GET['q']??''));$rows=Sector::search($entity,false);$byId=[];foreach($rows as$r)$byId[(int)$r['id']]=$r;
    if($q!=='')$rows=array_values(array_filter($rows,static fn(array$r):bool=>mb_stripos($r['name'].' '.$r['code'],$q)!==false));
    echo '<section class="reservaplus-panel"><div class="rp-ra-section-heading"><div><h2>Unidades responsáveis</h2><p>Defina a estrutura uma vez e selecione o setor no cadastro de cada recurso.</p></div><a class="btn btn-primary" href="'.View::e(View::url('sector.php',['new'=>1])).'"><i class="ti ti-plus" aria-hidden="true"></i> Cadastrar setor</a></div><form method="get" class="rp-ra-filter">';View::field('q','Buscar setor',$q,'search','placeholder="Nome ou sigla"');echo '<button class="btn btn-outline-primary" type="submit">Filtrar</button><a class="btn btn-outline-secondary" href="'.View::e(View::url('resource.php')).'">Recursos</a></form></section>';
    if(!$rows){echo '<section class="reservaplus-panel"><div class="reservaplus-empty"><i class="ti ti-building-community" aria-hidden="true"></i><strong>'.($q!==''?'Nenhum setor encontrado':'Cadastre o primeiro setor responsável').'</strong><span>'.($q!==''?'Tente buscar pelo nome ou pela sigla.':'Depois, associe equipamentos e salas a essa unidade.').'</span><a class="btn btn-primary" href="'.View::e(View::url('sector.php',['new'=>1])).'">Cadastrar setor</a></div></section>';}
    else{echo '<section class="reservaplus-panel"><div class="table-responsive"><table class="table rp-ra-sector-table"><thead><tr><th>Setor</th><th>Setor superior</th><th>Situação</th><th><span class="visually-hidden">Ações</span></th></tr></thead><tbody>';foreach($rows as$sector)echo '<tr><td><strong>'.View::e($sector['name']).'</strong><small>'.View::e($sector['code']).'</small></td><td>'.View::e($byId[(int)$sector['parent_id']]['name']??'—').'</td><td><span class="rp-ra-badge '.((int)$sector['is_active']?'rp-ra-published':'rp-ra-retired').'">'.((int)$sector['is_active']?'Ativo':'Inativo').'</span></td><td><a class="btn btn-outline-primary" href="'.View::e(View::url('sector.php',['id'=>$sector['id']])).'">Editar</a></td></tr>';echo '</tbody></table></div></section>';}
}
View::footer();
