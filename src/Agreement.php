<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Native discovery only. DocumentService is the sole mutation boundary. */
class Agreement extends \CommonDBTM
{
    public static $rightname = 'plugin_reservaplus_workflow';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reservaplus_agreements';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Documento da reserva', 'reservaplus');
    }

    public static function canCreate(): bool
    {
        return false;
    }

    public static function canView(): bool
    {
        // Native generic Search/REST cannot apply our booking ACL or private evidence projection.
        // Scoped discovery is exclusively provided by DocumentService.
        return false;
    }

    public function getFromDB($ID)
    {
        if (!parent::getFromDB($ID)) {
            return false;
        }
        if (!$this->canViewItem()) {
            $this->fields = [];
            return false;
        }
        return true;
    }

    public static function canUpdate(): bool
    {
        return false;
    }

    public static function canDelete(): bool
    {
        return false;
    }

    public static function canPurge(): bool
    {
        return false;
    }

    public function canViewItem(): bool
    {
        try {
            return Access::booking(Store::row('bookings', (int) ($this->fields['bookings_id'] ?? 0)), 'read');
        } catch (\Throwable) {
            return false;
        }
    }

    public function prepareInputForAdd($input)
    {
        return false;
    }

    public function prepareInputForUpdate($input)
    {
        return false;
    }

    public function pre_deleteItem(): bool
    {
        return false;
    }
}
