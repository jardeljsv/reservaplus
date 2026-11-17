<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

final class Sector
{
    public static function search(int $entityId, bool $activeOnly = true): array
    {
        if (!Access::entity($entityId)) { return []; }
        $where=['entities_id'=>$entityId];
        if ($activeOnly) { $where['is_active']=1; }
        return Store::find('sectors',$where,['ORDER'=>['name ASC','id ASC']]);
    }

    public static function save(array $input, int $id = 0): int
    {
        Access::assertRight('admin');
        return Store::transaction(function () use ($input,$id): int {
            $old=$id>0 ? Store::lock('sectors',$id) : [];
            $entity=(int)($input['entities_id'] ?? ($old['entities_id'] ?? 0));
            if (!Access::entity($entity) || ($old && !Access::entity((int)$old['entities_id']))) {
                throw new \DomainException(__('Sem acesso à entidade.','reservaplus'));
            }
            if ($old && $entity!==(int)$old['entities_id']) {
                throw new \DomainException(__('Crie outro setor para uma transferência de entidade.','reservaplus'));
            }
            $name=trim((string)($input['name'] ?? ''));
            $code=trim((string)($input['code'] ?? ''));
            if ($name==='' || mb_strlen($name)>255 || !preg_match('/^[\pL\pN_. -]{1,64}$/u',$code)) {
                throw new \DomainException(__('Informe nome e código válidos para o setor.','reservaplus'));
            }
            $parent=(int)($input['parent_id'] ?? 0);
            $seen=[$id]; $cursor=$parent;
            while ($cursor>0) {
                if (in_array($cursor,$seen,true)) { throw new \DomainException(__('A hierarquia do setor contém um ciclo.','reservaplus')); }
                $seen[]=$cursor;
                $parentRow=Store::row('sectors',$cursor);
                if ((int)$parentRow['entities_id']!==$entity || !(int)$parentRow['is_active']) {
                    throw new \DomainException(__('O setor superior deve estar ativo na mesma entidade.','reservaplus'));
                }
                $cursor=(int)$parentRow['parent_id'];
                if (count($seen)>100) { throw new \DomainException(__('Hierarquia excessiva.','reservaplus')); }
            }
            $values=['name'=>$name,'code'=>$code,'entities_id'=>$entity,'parent_id'=>$parent,'is_active'=>!empty($input['is_active'])?1:0,'date_mod'=>Store::now()];
            $duplicate=Store::find('sectors',['entities_id'=>$entity,'code'=>$code]);
            foreach ($duplicate as $row) { if ((int)$row['id']!==$id) { throw new \DomainException(__('Código de setor já utilizado.','reservaplus')); } }
            if ($id>0) {
                if (!$values['is_active'] && Store::find('resources',['sectors_id'=>$id,'publication'=>'published'],['LIMIT'=>1])) {
                    throw new \DomainException(__('Transfira os recursos publicados antes de desativar o setor.','reservaplus'));
                }
                Store::update('sectors',$id,$values);
                return $id;
            }
            $values['date_creation']=Store::now();
            return Store::insert('sectors',$values);
        });
    }
}
