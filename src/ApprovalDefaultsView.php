<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Approval administration presentation. Policy writes stay in ApprovalPolicy. */
final class ApprovalDefaultsView
{
    public static function submission(array $input): array
    {
        $raw=$input['approval_defaults']??null;
        if(!is_array($raw))throw new \InvalidArgumentException('Informe os grupos padrão para cada tipo de recurso.');
        $out=[];
        foreach(array_keys(ResourceAdminView::KINDS)as$kind){
            if(!is_array($raw[$kind]??null))throw new \InvalidArgumentException('A configuração de um tipo de recurso está incompleta. Reabra a página.');
            $entry=$raw[$kind];$groups=$entry['groups_ids']??[];
            if(in_array($groups,['',0,'0',null],true))$groups=[]; // Native empty multi-select placeholder.
            if(!is_array($groups))throw new \InvalidArgumentException('Selecione os grupos na lista de aprovadores.');
            $out[$kind]=[
                'groups_ids'=>array_values(array_filter($groups,static fn(mixed $id):bool=>!in_array($id,['',0,'0'],true))),
                'expected_revision'=>$entry['expected_revision']??null,
            ];
        }
        return $out;
    }

    /** Safe rendering only; domain writes validate the original selection. */
    public static function displayIds(mixed $raw): array
    {
        if(!is_array($raw))return [];
        $ids=[];
        foreach($raw as$value){
            $id=filter_var($value,FILTER_VALIDATE_INT,['options'=>['min_range'=>1]]);
            if($id!==false)$ids[]=$id;
        }
        return array_values(array_unique($ids));
    }

    public static function targets(array $groups,array $users,int $entity): void
    {
        $groups=self::displayIds($groups);$users=self::displayIds($users);
        if($groups===[]&&$users===[]){echo '<p class="rp-ra-subtle">Nenhum aprovador configurado.</p>';return;}
        echo '<ul class="rp-ra-approval-targets">';
        foreach([[\Group::class,$groups,'ti-users','Grupo'],[\User::class,$users,'ti-user','Pessoa']]as[$type,$ids,$icon,$label]){
            foreach($ids as$id){
                if(!ApprovalPolicy::validTarget($type,$id,$entity)){echo '<li>Seleção indisponível nesta entidade.</li>';continue;}
                $model=new $type();$name=$label.' #'.$id;
                if($model->getFromDB($id)){
                    $f=$model->fields;
                    $name=$type===\User::class?(trim(($f['firstname']??'').' '.($f['realname']??''))?:($f['name']??$name)):($f['completename']??$f['name']??$name);
                }
                echo '<li><i class="ti '.ResourceAdminView::e($icon).'" aria-hidden="true"></i> '.ResourceAdminView::e($name).'</li>';
            }
        }
        echo '</ul>';
    }

    public static function render(int $entity,array $stored,array $post=[]): void
    {
        $entityModel=new \Entity();$name='Entidade #'.$entity;
        if($entityModel->getFromDB($entity))$name=(string)($entityModel->fields['completename']??$entityModel->fields['name']??$name);
        echo '<section class="reservaplus-panel"><div class="rp-ra-section-heading"><div><h2>Aprovadores padrão por tipo de recurso</h2><p>Entidade: <strong>'.ResourceAdminView::e($name).'</strong></p></div><i class="ti ti-checklist rp-ra-type-icon" aria-hidden="true"></i></div>';
        echo '<p>Defina quais grupos podem aprovar equipamentos, salas e outros recursos. Cada recurso pode herdar estes grupos ou ter uma lista própria de grupos e pessoas.</p><form method="post" action="'.ResourceAdminView::e(BookingView::url('config.form.php')).'">';
        ResourceAdminView::hidden('action','save_approval_defaults');ResourceAdminView::hidden('entities_id',$entity);
        echo '<div class="rp-ra-default-grid">';
        foreach(ResourceAdminView::KINDS as$kind=>$label){
            $current=$stored[$kind]??['groups_ids'=>[],'revision'=>0];
            $submitted=is_array($post['approval_defaults'][$kind]??null)?$post['approval_defaults'][$kind]:null;
            $groups=self::displayIds($submitted!==null?($submitted['groups_ids']??[]):$current['groups_ids']);
            $revision=$submitted['expected_revision']??$current['revision'];
            echo '<section class="rp-ra-default-card"><div class="rp-ra-default-title"><i class="'.ResourceAdminView::icon($kind).'" aria-hidden="true"></i><h3>'.ResourceAdminView::e($label).'</h3></div>';
            ResourceAdminView::approvalDropdown(\Group::class,'approval_defaults['.$kind.'][groups_ids]','Grupos aprovadores',$groups,$entity);
            ResourceAdminView::hidden('approval_defaults['.$kind.'][expected_revision]',is_scalar($revision)?$revision:'');
            echo '<p class="rp-ra-subtle">Qualquer membro elegível de um dos grupos pode decidir.</p></section>';
        }
        echo '</div><div class="rp-ra-inline-help"><strong>Aplicação dos padrões</strong><p>Estas regras são usadas em novas solicitações de recursos que escolheram herdar o padrão. Listas específicas e grupos preservados do cadastro anterior continuam independentes. Solicitações já registradas mantêm seus aprovadores.</p><p>Deixar uma categoria sem grupos impede novas solicitações que dependam desse padrão; isso não dispensa a aprovação.</p></div><p class="rp-ra-subtle">Os grupos precisam estar disponíveis nesta entidade. Seus membros precisam das permissões de aprovação do Reserva Plus, de validação nativa e de visualização do chamado no GLPI.</p><button type="submit" class="btn btn-primary">Salvar aprovadores padrão</button>';
        \Html::closeForm();echo '</section>';
    }
}
