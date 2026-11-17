<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

class AvailabilityService
{
    /**
     * Itens reserváveis livres AGORA (sem reserva nem bloqueio nos próximos
     * $minutes minutos), respeitando o acesso de grupo do usuário. Usa poucas
     * consultas (não uma por item) montando os conjuntos de ocupados/bloqueados.
     *
     * @return array<int,array{reservationitems_id:int,itemtype:string,items_id:int,label:string,typelabel:string}>
     */
    public function availableNow(int $minutes = 60): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_reservationitems')) {
            return [];
        }

        $now = date('Y-m-d H:i:s');
        $end = date('Y-m-d H:i:s', strtotime('+' . max(1, $minutes) . ' minutes'));

        $allowed = ItemGroup::getAllowedItemIds(); // null = todos, [] = nenhum
        if ($allowed === []) {
            return [];
        }

        // Itens ocupados por reserva nativa no intervalo.
        $busy = [];
        if ($DB->tableExists('glpi_reservations')) {
            foreach ($DB->request([
                'SELECT' => ['reservationitems_id'],
                'FROM'   => 'glpi_reservations',
                'WHERE'  => ['end' => ['>', $now], 'begin' => ['<', $end]],
            ]) as $r) {
                $busy[(int) ($r['reservationitems_id'] ?? 0)] = true;
            }
        }

        $blocks = [];
        if ($DB->tableExists(Block::getTable())) {
            foreach ($DB->request([
                'FROM' => Block::getTable(),
                'WHERE' => ['is_active' => 1, 'end' => ['>', $now], 'begin' => ['<', $end]],
            ]) as $row) {
                $blocks[] = $row;
            }
        }

        $where = ['is_active' => 1];
        if ($allowed !== null) {
            $where['id'] = $allowed;
        }

        $available = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'itemtype', 'items_id'],
            'FROM'   => 'glpi_reservationitems',
            'WHERE'  => $where,
            'ORDER'  => ['id ASC'],
        ]) as $row) {
            $id = (int) ($row['id'] ?? 0);
            if (isset($busy[$id]) || !ItemGroup::isLegacyBookable($id)) {
                continue;
            }
            $context = ItemGroup::context($id);
            $blocked = false;
            foreach ($blocks as $block) {
                if (self::blockApplies($block, $context)) {
                    $blocked = true;
                    break;
                }
            }
            if ($blocked) {
                continue;
            }
            $itemtype = (string) ($row['itemtype'] ?? '');
            $itemsId  = (int) ($row['items_id'] ?? 0);
            $available[] = [
                'reservationitems_id' => $id,
                'itemtype'            => $itemtype,
                'items_id'            => $itemsId,
                'label'               => self::itemLabel($itemtype, $itemsId),
                'typelabel'           => self::typeLabel($itemtype),
            ];
        }

        // Ordem alfabética pelo nome da sala — a ordem de cadastro (id) não
        // ajuda ninguém a achar "Espaço Mindfulness" numa lista de 10 cartões.
        usort($available, static fn(array $a, array $b): int => strnatcasecmp(
            ReservationRequest::normalizeForSearch((string) $a['label']),
            ReservationRequest::normalizeForSearch((string) $b['label'])
        ));

        return $available;
    }

    /** Blocks apply only to their declared entity, item and optional item type. */
    public static function blockApplies(array $block, ?array $context): bool
    {
        if ($context === null || (int) ($block['entities_id'] ?? -1) !== (int) ($context['_entities_id'] ?? -2)) {
            return false;
        }
        $itemId = (int) ($block['reservationitems_id'] ?? 0);
        $type = trim((string) ($block['itemtype'] ?? ''));
        return ($itemId === 0 || $itemId === (int) ($context['id'] ?? -1))
            && ($type === '' || $type === (string) ($context['itemtype'] ?? ''));
    }

    private static function itemLabel(string $itemtype, int $itemsId): string
    {
        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
            return $itemtype !== '' ? $itemtype . ' #' . $itemsId : '#' . $itemsId;
        }
        $obj = new $itemtype();
        if (!$obj->getFromDB($itemsId)) {
            return $itemtype . ' #' . $itemsId;
        }
        $name = method_exists($obj, 'getName') ? (string) $obj->getName() : '';
        return $name !== '' ? $name : $itemtype . ' #' . $itemsId;
    }

    private static function typeLabel(string $itemtype): string
    {
        if ($itemtype !== '' && class_exists($itemtype) && method_exists($itemtype, 'getTypeName')) {
            return (string) $itemtype::getTypeName(2);
        }
        return $itemtype !== '' ? $itemtype : __('Outros', 'reservaplus');
    }

    /**
     * Janelas de horário livres em um dia, dentro do horário comercial, para um
     * ou mais itens. Com vários itens, retorna as janelas em que TODOS estão
     * livres ao mesmo tempo (interseção) — útil para a reserva em massa.
     *
     * @param array<int,int> $itemIds
     * @return array<int,array{begin:string,end:string,begin_local:string,end_local:string,label:string}>
     */
    public function freeSlots(array $itemIds, string $date, int $minSlotMinutes = 30): array
    {
        global $DB;

        $itemIds = array_values(array_unique(array_filter(
            array_map('intval', $itemIds),
            static fn(int $v): bool => $v > 0
        )));
        if ($itemIds === [] || !$DB->tableExists('glpi_reservationitems')) {
            return [];
        }

        $contexts = [];
        foreach ($itemIds as $itemId) {
            if (!ItemGroup::isLegacyBookable($itemId)) {
                return [];
            }
            $contexts[$itemId] = ItemGroup::context($itemId);
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) !== 1
            || date('Y-m-d', (int) strtotime($date)) !== $date) {
            return [];
        }

        $cfg     = Config::getSingleton();
        $bhStart = self::clockTime((string) ($cfg->fields['business_hours_start'] ?? ''), '08:00:00');
        $bhEnd   = self::clockTime((string) ($cfg->fields['business_hours_end'] ?? ''), '18:00:00');

        $dayTs = strtotime($date);
        if ($dayTs === false) {
            return [];
        }
        $day      = date('Y-m-d', $dayTs);
        $winStart = strtotime($day . ' ' . $bhStart);
        $winEnd   = strtotime($day . ' ' . $bhEnd);
        if ($winStart === false || $winEnd === false || $winEnd <= $winStart) {
            return [];
        }

        $winStartStr = date('Y-m-d H:i:s', $winStart);
        $winEndStr   = date('Y-m-d H:i:s', $winEnd);

        // Intervalos ocupados (reservas dos itens + bloqueios global/dos itens),
        // recortados à janela comercial.
        $busy = [];
        $clip = static function ($beginStr, $endStr) use ($winStart, $winEnd, &$busy): void {
            $b = strtotime((string) $beginStr);
            $e = strtotime((string) $endStr);
            if ($b === false || $e === false) {
                return;
            }
            $b = max($winStart, $b);
            $e = min($winEnd, $e);
            if ($e > $b) {
                $busy[] = [$b, $e];
            }
        };

        if ($DB->tableExists('glpi_reservations')) {
            foreach ($DB->request([
                'SELECT' => ['begin', 'end'],
                'FROM'   => 'glpi_reservations',
                'WHERE'  => [
                    'reservationitems_id' => $itemIds,
                    'end'                 => ['>', $winStartStr],
                    'begin'               => ['<', $winEndStr],
                ],
            ]) as $r) {
                $clip($r['begin'] ?? '', $r['end'] ?? '');
            }
        }

        if ($DB->tableExists(Block::getTable())) {
            foreach ($DB->request([
                'SELECT' => ['begin', 'end', 'entities_id', 'itemtype', 'reservationitems_id'],
                'FROM'   => Block::getTable(),
                'WHERE'  => [
                    'is_active'           => 1,
                    'reservationitems_id' => array_merge([0], $itemIds),
                    'end'                 => ['>', $winStartStr],
                    'begin'               => ['<', $winEndStr],
                ],
            ]) as $r) {
                foreach ($contexts as $context) {
                    if (self::blockApplies($r, $context)) {
                        $clip($r['begin'] ?? '', $r['end'] ?? '');
                        break;
                    }
                }
            }
        }

        // Une os intervalos ocupados.
        usort($busy, static fn(array $a, array $b): int => $a[0] <=> $b[0]);
        $merged = [];
        foreach ($busy as $iv) {
            $n = count($merged);
            if ($n === 0 || $iv[0] > $merged[$n - 1][1]) {
                $merged[] = $iv;
            } else {
                $merged[$n - 1][1] = max($merged[$n - 1][1], $iv[1]);
            }
        }

        // Complemento dentro da janela = horários livres.
        $slots  = [];
        $cursor = $winStart;
        foreach ($merged as $iv) {
            if ($iv[0] > $cursor) {
                $slots[] = [$cursor, $iv[0]];
            }
            $cursor = max($cursor, $iv[1]);
        }
        if ($cursor < $winEnd) {
            $slots[] = [$cursor, $winEnd];
        }

        $out = [];
        foreach ($slots as $s) {
            if (($s[1] - $s[0]) < $minSlotMinutes * 60) {
                continue;
            }
            $out[] = [
                'begin'       => date('Y-m-d H:i:s', $s[0]),
                'end'         => date('Y-m-d H:i:s', $s[1]),
                'begin_local' => date('Y-m-d\TH:i', $s[0]),
                'end_local'   => date('Y-m-d\TH:i', $s[1]),
                'label'       => date('H:i', $s[0]) . '–' . date('H:i', $s[1]),
            ];
        }

        return $out;
    }

    private static function clockTime(string $value, string $fallback): string
    {
        $value = trim($value);

        return preg_match('/^\d{1,2}:\d{2}/', $value) === 1 ? substr($value, 0, 8) : $fallback;
    }

    /**
     * Conflitos (reservas nativas + bloqueios) de um item num período.
     *
     * $ignoreReservationIds permite ignorar reservas nativas específicas — usado
     * ao REAGENDAR: a própria reserva sendo editada não pode contar como
     * conflito consigo mesma.
     *
     * @param array<int,int> $ignoreReservationIds
     */
    public function getConflicts(int $reservationItemsId, string $begin, string $end, array $ignoreReservationIds = []): array
    {
        global $DB;

        $conflicts = [];

        if (!ItemGroup::isAllowed($reservationItemsId)
            || strtotime($begin) === false || strtotime($end) === false || strtotime($end) <= strtotime($begin)) {
            throw new \RuntimeException(__('Item ou período indisponível.', 'reservaplus'));
        }
        $context = ItemGroup::context($reservationItemsId);

        $ignoreReservationIds = array_values(array_unique(array_filter(
            array_map('intval', $ignoreReservationIds),
            static fn(int $v): bool => $v > 0
        )));

        if ($DB->tableExists('glpi_reservations')) {
            $where = [
                'reservationitems_id' => $reservationItemsId,
                'end'                 => ['>', $begin],
                'begin'               => ['<', $end],
            ];
            if ($ignoreReservationIds !== []) {
                $where[] = ['NOT' => ['id' => $ignoreReservationIds]];
            }

            foreach ($DB->request([
                'FROM'  => 'glpi_reservations',
                'WHERE' => $where,
            ]) as $row) {
                $conflicts[] = [
                    'type'  => 'reservation',
                    'id'    => (int) ($row['id'] ?? 0),
                    'begin' => (string) ($row['begin'] ?? ''),
                    'end'   => (string) ($row['end'] ?? ''),
                ];
            }
        }

        if ($DB->tableExists(Block::getTable())) {
            foreach ($DB->request([
                'FROM'  => Block::getTable(),
                'WHERE' => [
                    'is_active'           => 1,
                    'reservationitems_id' => [0, $reservationItemsId],
                    'end'                 => ['>', $begin],
                    'begin'               => ['<', $end],
                ],
            ]) as $row) {
                if (!self::blockApplies($row, $context)) {
                    continue;
                }
                $conflicts[] = [
                    'type'   => 'block',
                    'id'     => (int) ($row['id'] ?? 0),
                    'begin'  => (string) ($row['begin'] ?? ''),
                    'end'    => (string) ($row['end'] ?? ''),
                    'reason' => (ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests())
                        ? (string) ($row['reason'] ?? '') : __('Indisponível', 'reservaplus'),
                ];
            }
        }

        return $conflicts;
    }
}
