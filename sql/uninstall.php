<?php

declare(strict_types=1);

function plugin_reservaplus_run_uninstall(): bool
{
    global $DB;

    $checks = [
        'glpi_reservationitems' => ['itemtype' => \GlpiPlugin\Reservaplus\Resource::class],
        'glpi_items_tickets' => ['itemtype' => \GlpiPlugin\Reservaplus\Resource::class],
        'glpi_plugin_reservaplus_bookings' => [],
        'glpi_plugin_reservaplus_agreements' => [],
    ];
    foreach ($checks as $table => $where) {
        if (!$DB->tableExists($table)) {
            continue;
        }
        if ($DB->request(['FROM' => $table, 'WHERE' => $where, 'LIMIT' => 1])->current()) {
            Session::addMessageAfterRedirect(
                __('Existem solicitações, documentos ou vínculos nativos. Faça a desativação planejada antes de desinstalar. Nenhum histórico foi removido.', 'reservaplus'),
                false,
                ERROR
            );
            return false;
        }
    }
    // Retain plugin tables/files and profile settings, including legacy requests.
    // Removing code while native class references remain is unsafe.
    return true;
}
