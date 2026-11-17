<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Native writes to managed resources must enter through the domain service. */
final class WorkflowHooks
{
    private static int $nativeDepth = 0;

    public static function native(callable $work): mixed
    {
        ++self::$nativeDepth;
        try { return $work(); } finally { --self::$nativeDepth; }
    }

    public static function trusted(): bool { return self::$nativeDepth > 0; }

    private static function ready(): bool
    {
        global $DB;
        return isset($DB) && $DB->tableExists(Store::table('resources')) && $DB->tableExists(Store::table('bookings'));
    }

    public static function managedReservationItem(int $id): bool
    {
        if ($id < 1 || !self::ready()) { return false; }
        if (Store::find('resources', ['reservationitems_id' => $id], ['LIMIT' => 1])) { return true; }
        $native = new \ReservationItem();
        if (!$native->getFromDB($id)) { return false; }
        if (($native->fields['itemtype'] ?? '') === Resource::class) { return true; }
        return Store::find('resources', ['native_itemtype' => (string) $native->fields['itemtype'], 'native_items_id' => (int) $native->fields['items_id']], ['LIMIT' => 1]) !== [];
    }

    private static function deny(object $item, string $message): void
    {
        $item->input = false;
        throw new \DomainException($message);
    }

    /** Register for pre_item_add, pre_item_update, pre_item_delete and pre_item_purge. */
    public static function guardReservation($item): void
    {
        if (self::trusted() || !self::ready()) { return; }
        $input = is_array($item->input ?? null) ? $item->input : [];
        $old = (array) ($item->fields ?? []);
        if (isset($input['id']) && empty($old['reservationitems_id'])) {
            $probe = new \Reservation();
            if ($probe->getFromDB((int) $input['id'])) { $old = $probe->fields; }
        }
        if (self::managedReservationItem((int) ($input['reservationitems_id'] ?? 0))
            || self::managedReservationItem((int) ($old['reservationitems_id'] ?? 0))) {
            self::deny($item, 'Esta reserva é gerenciada pelo ReservaPlus. Use a solicitação vinculada para alterar ou cancelar.');
        }
    }

    public static function guardReservationItem($item): void
    {
        if (self::trusted() || !self::ready()) { return; }
        $input = is_array($item->input ?? null) ? $item->input : [];
        $old = (array) ($item->fields ?? []);
        $type = (string) ($input['itemtype'] ?? $old['itemtype'] ?? '');
        $parentId = (int) ($input['items_id'] ?? $old['items_id'] ?? 0);
        if ($type === Resource::class || self::managedReservationItem((int) ($input['id'] ?? $old['id'] ?? 0))
            || ($type !== '' && $parentId > 0 && Store::find('resources', ['native_itemtype' => $type, 'native_items_id' => $parentId], ['LIMIT' => 1]))) {
            self::deny($item, 'Altere a disponibilidade do recurso pela administração do ReservaPlus.');
        }
    }

    public static function guardValidation($item): void
    {
        if (self::trusted() || !self::ready()) { return; }
        $id = (int) ($item->input['id'] ?? $item->fields['id'] ?? 0);
        if ($id > 0 && (Store::find('bookingapprovals', ['ticketvalidations_id' => $id], ['LIMIT' => 1])
            || Store::find('bookingapprovalhistory', ['ticketvalidations_id' => $id], ['LIMIT' => 1]))) {
            self::deny($item, 'Responda esta aprovação pela aba ReservaPlus do chamado para validar a disponibilidade e a revisão.');
        }
    }

    public static function guardTicket($item): void
    {
        if (self::trusted() || !self::ready()) { return; }
        $input = is_array($item->input ?? null) ? $item->input : [];
        $id = (int) ($input['id'] ?? $item->fields['id'] ?? 0);
        if ($id < 1) { return; }
        $rows = Store::find('bookings', ['tickets_id' => $id]);
        foreach ($rows as $booking) {
            if (isset($input['entities_id']) && (int) $input['entities_id'] !== (int) $booking['entities_id']) {
                self::deny($item, 'O chamado de reserva não pode mudar de entidade sem uma migração controlada.');
            }
            if (!in_array($booking['status'], ['completed', 'cancelled', 'rejected'], true)
                && isset($input['status']) && in_array((int) $input['status'], [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED], true)) {
                self::deny($item, 'Conclua a devolução, conferência e documentos no ReservaPlus antes de solucionar o chamado.');
            }
        }
    }

    public static function guardTicketDelete($item): void
    {
        if (self::trusted() || !self::ready()) { return; }
        $id = (int) ($item->input['id'] ?? $item->fields['id'] ?? 0);
        if ($id > 0 && Store::find('bookings', ['tickets_id' => $id], ['LIMIT' => 1])) {
            self::deny($item, 'Este chamado possui histórico de reserva e custódia. Exclusão bloqueada para preservar os registros.');
        }
    }

    /**
     * Ticket rules execute after PRE_ITEM_UPDATE. This hook is called after a
     * matching rule's actions but before the resulting native ticket write.
     * A completion command has already marked its booking completed, so it may
     * legitimately solve; native rules cannot solve an active reservation.
     */
    public static function guardTicketRule($context): void
    {
        if (!is_array($context) || ($context['sub_type'] ?? '') !== \RuleTicket::class || !self::ready()) { return; }
        $input = is_array($context['input'] ?? null) ? $context['input'] : [];
        $output = is_array($context['output'] ?? null) ? $context['output'] : [];
        $id = (int) ($input['id'] ?? 0);
        // Creation has no persisted booking-ticket link yet. The native adapter
        // verifies its postconditions before committing that new aggregate.
        if ($id < 1) { return; }
        foreach (Store::find('bookings', ['tickets_id' => $id]) as $booking) {
            if ((isset($output['entities_id']) && (int) $output['entities_id'] !== (int) $booking['entities_id'])
                || !empty($output['is_deleted'])) {
                throw new \DomainException('A regra do GLPI tentou mover ou excluir um chamado com histórico de reserva. Revise o roteamento da regra; a alteração foi interrompida.');
            }
            if (!in_array($booking['status'], ['completed', 'cancelled', 'rejected'], true)
                && isset($output['status']) && in_array((int) $output['status'], [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED], true)) {
                throw new \DomainException('A regra do GLPI tentou encerrar o chamado antes de concluir a reserva, a devolução e os documentos. Revise a regra; a alteração foi interrompida.');
            }
        }
    }

    /** Register for native asset types supported by ResourceService linking/export. */
    public static function guardAssetDelete($item): void
    {
        if (self::trusted() || !self::ready()) { return; }
        $id = (int) ($item->input['id'] ?? $item->fields['id'] ?? 0);
        if ($id > 0 && Store::find('resources', ['native_itemtype' => get_class($item), 'native_items_id' => $id], ['LIMIT' => 1])) {
            self::deny($item, 'Este item está vinculado ao ReservaPlus. Preserve o histórico e retire o recurso pelo fluxo controlado.');
        }
    }
}
