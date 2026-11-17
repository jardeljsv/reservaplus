<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Stable plugin-owned identity; native inventory is an optional association. */
class Resource extends \CommonDBTM
{
    public static $rightname = 'plugin_reservaplus_workflow';
    public $dohistory = true;

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_reservaplus_resources';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Recurso do Reserva Plus', 'reservaplus');
    }

    public static function getIcon(): string
    {
        return 'ti ti-building-warehouse';
    }

    public static function getFormURL($full = true): string
    {
        global $CFG_GLPI;
        return ($full ? ($CFG_GLPI['root_doc'] ?? '') : '').'/plugins/reservaplus/front/resource.form.php';
    }

    public static function getSearchURL($full = true): string
    {
        global $CFG_GLPI;
        return ($full ? ($CFG_GLPI['root_doc'] ?? '') : '').'/plugins/reservaplus/front/resource.php';
    }

    public static function canView(): bool
    {
        // Generic native Search/REST uses raw SQL and cannot apply plugin group ACLs.
        // Non-admin discovery is served by ResourceService::search instead.
        return Access::right('admin');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool { return false; }
    public static function canPurge(): bool { return false; }
    public function canViewItem(): bool { return Access::resource($this->fields, 'read'); }
    public function canCreateItem(): bool { return Access::resource($this->fields, 'manage'); }
    public function canUpdateItem(): bool { return Access::resource($this->fields, 'manage'); }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }

    /** Native calendar resolves the parent directly, without calling canViewItem(). */
    public function getFromDB($ID)
    {
        if (!parent::getFromDB($ID)) {
            return false;
        }
        if (!Access::resource($this->fields, 'read')) {
            $this->fields=[];
            return false;
        }
        return true;
    }

    public static function getRow(int $id): array
    {
        $row=Store::row('resources',$id);
        Access::assertResource($row,'read');
        return $row;
    }

    // Generic native REST/mass-actions must not bypass the resource service.
    public function prepareInputForAdd($input) { return false; }
    public function prepareInputForUpdate($input) { return false; }

    public function rawSearchOptions(): array
    {
        return [
            ['id'=>'common','name'=>self::getTypeName()],
            ['id'=>1,'table'=>self::getTable(),'field'=>'name','name'=>__('Nome','reservaplus'),'datatype'=>'itemlink','massiveaction'=>false],
            ['id'=>2,'table'=>self::getTable(),'field'=>'id','name'=>'ID','datatype'=>'number','massiveaction'=>false],
            ['id'=>80,'table'=>'glpi_entities','field'=>'completename','name'=>__('Entidade','reservaplus'),'datatype'=>'dropdown','massiveaction'=>false],
            ['id'=>3,'table'=>'glpi_locations','field'=>'completename','name'=>__('Localização','reservaplus'),'datatype'=>'dropdown','massiveaction'=>false],
        ];
    }
}
