<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Shared privacy projection for initial HTML and subsequent AJAX refreshes. */
final class LegacyCalendar
{
    public static function events(string $start, string $end): array
    {
        global $DB;
        $beginTs = strtotime($start);
        $endTs = strtotime($end);
        if (!ReservationRequest::canView() || $beginTs === false || $endTs === false
            || $endTs <= $beginTs || $endTs - $beginTs > 93 * 86400) {
            throw new \RuntimeException(__('Período de calendário inválido.', 'reservaplus'));
        }
        $start = date('Y-m-d H:i:s', $beginTs);
        $end = date('Y-m-d H:i:s', $endTs);
        $allowed = ItemGroup::getAllowedItemIds() ?? [];
        if ($allowed === []) {
            return [];
        }
        $events = [];
        $linked = [];
        $contexts = [];
        foreach ($allowed as $id) {
            $contexts[$id] = ItemGroup::context($id);
        }
        if ($DB->tableExists(ReservationRequest::getTable())) {
            foreach ($DB->request([
                'FROM' => ReservationRequest::getTable(),
                'WHERE' => [
                    'entities_id' => ReservationRequest::entityIds() ?: [-1],
                    'reservationitems_id' => $allowed,
                    'status' => ReservationRequest::STATUS_CREATED,
                    'begin' => ['<', $end], 'end' => ['>', $start],
                ],
            ]) as $row) {
                $id = (int) $row['id'];
                foreach (ReservationRequest::getNativeReservationIds($row) as $nativeId) {
                    $linked[$nativeId] = true;
                }
                $event = [
                    'id' => 'request-' . $id, 'title' => __('Reservado', 'reservaplus'),
                    'start' => (string) $row['begin'], 'end' => (string) $row['end'],
                    'type' => 'native', 'readonly' => true,
                ];
                if (ReservationRequest::canViewRow($row)) {
                    $context = $contexts[(int) $row['reservationitems_id']];
                    $event['title'] = ReservationRequest::getItemDisplayName([
                        '_itemtype' => $context['itemtype'], '_items_id' => $context['items_id'],
                        'reservationitems_id' => $row['reservationitems_id'],
                    ]);
                    $event['type'] = 'request';
                    $event['requestId'] = $id;
                    $event['status'] = (string) $row['status'];
                    $event['canCancel'] = ReservationRequest::canCancelRow($row);
                    $event['canDelete'] = ReservationRequest::canDeleteRow($row);
                    $event['readonly'] = !ReservationRequest::canEditRow($row);
                    if (ReservationRequest::canEditRow($row)) {
                        $event['url'] = Dashboard::getUrl('reservation.form.php') . '?edit=' . $id;
                    } elseif (ReservationRequest::canCreate() && !ItemGroup::isManaged((int) $row['reservationitems_id'])) {
                        $event['url'] = Dashboard::getUrl('reservation.form.php') . '?duplicate=' . $id;
                    }
                }
                $events[] = $event;
            }
        }
        if ($DB->tableExists('glpi_reservations')) {
            foreach ($DB->request([
                'FROM' => 'glpi_reservations',
                'WHERE' => ['reservationitems_id' => $allowed, 'begin' => ['<', $end], 'end' => ['>', $start]],
            ]) as $row) {
                if (isset($linked[(int) $row['id']])) {
                    continue;
                }
                $events[] = [
                    'id' => 'native-' . (int) $row['id'],
                    'title' => __('Reservado', 'reservaplus'),
                    'start' => (string) $row['begin'], 'end' => (string) $row['end'],
                    'type' => 'native', 'readonly' => true,
                ];
            }
        }
        if ($DB->tableExists(Block::getTable())) {
            foreach ($DB->request([
                'FROM' => Block::getTable(),
                'WHERE' => ['is_active' => 1, 'begin' => ['<', $end], 'end' => ['>', $start]],
            ]) as $row) {
                foreach ($contexts as $context) {
                    if (!AvailabilityService::blockApplies($row, $context)) {
                        continue;
                    }
                    $events[] = [
                        'id' => 'block-' . (int) $row['id'], 'title' => __('Bloqueado', 'reservaplus'),
                        'start' => (string) $row['begin'], 'end' => (string) $row['end'],
                        'type' => 'block', 'readonly' => true,
                    ];
                    break;
                }
            }
        }
        return $events;
    }
}
