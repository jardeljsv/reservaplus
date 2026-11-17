<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\{Access,ApprovalDefaultsView,ApprovalPolicy,BookingView,Config,ResourceAdminView,Schema};

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkLoginUser();
$canDefaults=Access::right('admin');
$canLegacy=Config::canView();
if(!$canDefaults&&!$canLegacy)Session::checkRight(Config::$rightname,READ);
$method=(string)($_SERVER['REQUEST_METHOD']??'GET');
if(!in_array($method,['GET','POST'],true)){http_response_code(405);header('Allow: GET, POST');exit;}
$entity=(int)($_SESSION['glpiactive_entity']??-1);
$config = Config::getSingleton();
$post=$method==='POST'?$_POST:[];$error='';$defaults=[];

try{
    if($post){
        if(($post['action']??'')==='save_approval_defaults'){
            Access::assertRight('admin');
            if(!Schema::ready())throw new DomainException('Conclua a atualização do plugin antes de configurar os aprovadores.');
            if((int)($post['entities_id']??-1)!==$entity||!Access::entity($entity))throw new DomainException('A entidade ativa mudou. Reabra as configurações antes de salvar.');
            ApprovalPolicy::saveDefaults($entity,ApprovalDefaultsView::submission($post));
            Session::addMessageAfterRedirect('Grupos padrão de aprovação salvos para esta entidade.');
        }elseif(isset($post['update'])){
            Session::checkRight(Config::$rightname,UPDATE);
            $input=$post;$input['id']=(int)($config->fields['id']??0);
            $saved=$input['id']>0?$config->update($input):$config->add($input);
            if(!$saved)throw new DomainException('Não foi possível salvar as configurações anteriores. Confira os campos e tente novamente.');
            Session::addMessageAfterRedirect('Configurações do fluxo anterior salvas.');
        }else{throw new DomainException('Ação inválida.');}
        Html::redirect(BookingView::url('config.form.php'));
    }
    if($canDefaults&&Schema::ready()&&Access::entity($entity))$defaults=ApprovalPolicy::defaults($entity);
}catch(\Glpi\Exception\RedirectException $redirect){throw $redirect;}catch(Throwable $failure){
    $error=BookingView::publicError($failure);
    if($canDefaults&&Schema::ready()&&Access::entity($entity))$defaults=ApprovalPolicy::defaults($entity);
}
ResourceAdminView::header('Configurações do Reserva Plus','Defina os padrões de aprovação e preserve as regras do fluxo anterior.');
if($error!=='')ResourceAdminView::notice($error,true);
if($canDefaults){
    if(!Schema::ready())ResourceAdminView::notice('Conclua a atualização do plugin para configurar os aprovadores padrão.',true);
    elseif(!Access::entity($entity))ResourceAdminView::notice('Selecione uma entidade ativa para configurar os aprovadores.',true);
    else ApprovalDefaultsView::render($entity,$defaults,($post['action']??'')==='save_approval_defaults'?$post:[]);
}
if($canLegacy){
    echo '<details class="reservaplus-panel rp-ra-legacy-config"'.(isset($post['update'])?' open':'').'><summary><strong>Configurações do fluxo anterior</strong><span>Recorrência, horários e notificações das reservas anteriores</span></summary>';
    echo '<p class="rp-ra-subtle">Estas opções continuam aplicáveis ao fluxo de reservas anterior. As novas reservas usam as regras de cada recurso e os padrões de aprovação acima.</p>';
    $config->showForm((int)($config->fields['id']??0),['submitted'=>isset($post['update'])?$post:[]]);
    echo '</details>';
}
ResourceAdminView::footer();
