<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

final class CalendarBridge
{
    public static function interval(array $resource, string $begin, string $end): array
    {
        $policy = Store::decode($resource['policy_json'] ?? null);
        $before = max(0, (int) ($policy['buffer_before_minutes'] ?? 0));
        $after = max(0, (int) ($policy['buffer_after_minutes'] ?? 0));
        return [date('Y-m-d H:i:s', strtotime($begin) - $before * 60), date('Y-m-d H:i:s', strtotime($end) + $after * 60)];
    }

    /** Called under the resource row lock for every committing operation. */
    public static function conflicts(array $resource, string $begin, string $end, int $exclude = 0): array
    {
        global $DB;
        $reasons = [];
        if (($resource['publication'] ?? '') !== 'published' || !empty($resource['is_deleted'])) { $reasons[] = 'Recurso não publicado.'; }
        if (($resource['operational_state'] ?? 'available') !== 'available') { $reasons[] = 'Recurso em conferência ou manutenção.'; }
        [$start, $finish] = self::interval($resource, $begin, $end);
        $excludeNative = [];
        foreach (Store::find('bookinglines', ['resources_id' => (int) $resource['id']]) as $line) {
            if ((int) $line['bookings_id'] === $exclude) {
                if ((int) ($line['reservations_id'] ?? 0) > 0) { $excludeNative[] = (int) $line['reservations_id']; }
                continue;
            }
            $booking = Store::row('bookings', (int) $line['bookings_id']);
            if ($line['state'] === 'issued' && $booking['end'] < Store::now()) { $reasons[] = 'Existe devolução pendente após o prazo.'; }
            if (in_array($line['state'], ['allocated', 'issued', 'received', 'quarantine', 'maintenance'], true)
                && !in_array($booking['status'], ['cancelled', 'rejected', 'completed'], true)) {
                $snapshot = Store::decode($line['snapshot_json'] ?? null);
                $otherResource = $snapshot['resource'] ?? $snapshot;
                [$otherStart, $otherFinish] = self::interval($otherResource, $booking['begin'], $booking['end']);
                if (Booking::overlaps($start, $finish, $otherStart, $otherFinish)) { $reasons[] = 'O período já está reservado.'; }
            }
        }
        $nativeId = (int) ($resource['reservationitems_id'] ?? 0);
        if ($nativeId > 0) {
            foreach ($DB->request(['FROM' => 'glpi_reservations', 'WHERE' => [
                'reservationitems_id' => $nativeId, 'begin' => ['<', $finish], 'end' => ['>', $start],
            ]]) as $reservation) {
                if (!in_array((int) $reservation['id'], $excludeNative, true)) { $reasons[] = 'O período possui reserva no GLPI.'; }
            }
        }
        if ($DB->tableExists(Store::table('blocks'))) {
            foreach (Store::find('blocks', ['is_active' => 1, 'entities_id' => (int) $resource['entities_id'], 'begin' => ['<', $finish], 'end' => ['>', $start]]) as $block) {
                $blockItem = (int) ($block['reservationitems_id'] ?? 0);
                $blockType = (string) ($block['itemtype'] ?? '');
                if (($blockItem === 0 || ($nativeId > 0 && $blockItem === $nativeId))
                    && ($blockType === '' || $blockType === Resource::class || $blockType === ($resource['native_itemtype'] ?? ''))) {
                    $reasons[] = 'O período possui bloqueio operacional.';
                }
            }
        }
        return array_values(array_unique($reasons));
    }

    public static function allocate(array $booking, array $line, array $resource): void
    {
        $conflicts = self::conflicts($resource, $booking['begin'], $booking['end'], (int) $booking['id']);
        if ($conflicts) { throw new \DomainException(implode(' ', $conflicts)); }
        $nativeItem = WorkflowHooks::native(static fn() => ResourceService::calendarItem($resource));
        $nativeReservation = null;
        if ($nativeItem > 0) {
            [$begin, $end] = self::interval($resource, $booking['begin'], $booking['end']);
            $nativeReservation = WorkflowHooks::native(static function () use ($booking, $nativeItem, $begin, $end): int {
                $reservation = new \Reservation();
                $id = (int) $reservation->add([
                    'reservationitems_id' => $nativeItem, 'begin' => $begin, 'end' => $end,
                    'users_id' => (int) $booking['users_id_borrower'], 'comment' => 'ReservaPlus #' . (int) $booking['id'],
                    '_disablenotif' => true,
                ]);
                if ($id < 1) { throw new \DomainException('O GLPI recusou a reserva. Nenhuma alocação foi confirmada.'); }
                return $id;
            });
        }
        Store::update('bookinglines', (int) $line['id'], [
            'reservationitems_id' => $nativeItem, 'reservations_id' => $nativeReservation,
            'calendar_mode' => $nativeItem > 0 ? 'native' : 'plugin_only', 'state' => 'allocated',
        ]);
    }

    public static function release(array $line): void
    {
        $id = (int) ($line['reservations_id'] ?? 0);
        if ($id > 0) {
            WorkflowHooks::native(static function () use ($id): void {
                $model = new \Reservation();
                if (!$model->getFromDB($id)) { throw new \DomainException('Reserva nativa ausente; reconcilie o vínculo antes de liberar.'); }
                if (!$model->delete(['id' => $id, '_disablenotif' => true], true)) {
                    throw new \DomainException('Não foi possível liberar a reserva nativa.');
                }
            });
        }
        Store::update('bookinglines', (int) $line['id'], ['state' => 'released']);
    }

    public static function releaseTail(array $line): void
    {
        $id = (int) ($line['reservations_id'] ?? 0);
        if ($id < 1) { return; }
        WorkflowHooks::native(static function () use ($id): void {
            $native = new \Reservation();
            if (!$native->getFromDB($id)) { throw new \DomainException('Reserva nativa ausente.'); }
            $now = Store::now();
            if ($native->fields['begin'] < $now && $native->fields['end'] > $now
                && !$native->update(['id' => $id, 'end' => $now, '_disablenotif' => true])) {
                throw new \DomainException('Não foi possível atualizar a disponibilidade após a conferência.');
            }
        });
    }

    public static function move(array $booking, array $line, array $resource): void
    {
        $conflicts = self::conflicts($resource, $booking['begin'], $booking['end'], (int) $booking['id']);
        if ($conflicts) { throw new \DomainException(implode(' ', $conflicts)); }
        $id = (int) ($line['reservations_id'] ?? 0);
        if ($id < 1) { return; }
        [$begin, $end] = self::interval($resource, $booking['begin'], $booking['end']);
        WorkflowHooks::native(static function () use ($id, $begin, $end): void {
            $native = new \Reservation();
            if (!$native->update(['id' => $id, 'begin' => $begin, 'end' => $end, '_disablenotif' => true])) {
                throw new \DomainException('O GLPI recusou a alteração do período.');
            }
        });
    }
}
