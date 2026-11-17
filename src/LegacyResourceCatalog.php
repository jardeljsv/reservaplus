<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Explicit enrollment of an existing reservable item; never an inventory copy. */
final class LegacyResourceCatalog
{
    /** Browse is scoped before pagination. A search term is optional. */
    public static function search(array $filters = [], int $limit = 25, int $offset = 0): array
    {
        global $DB;
        Access::assertRight('manage');
        $limit = max(1, min(100, $limit));
        $offset = max(0, $offset);
        $types = ResourceService::nativeTypes();
        $type = is_string($filters['itemtype'] ?? null) ? $filters['itemtype'] : '';
        if ($type !== '' && !isset($types[$type])) {
            throw new \DomainException(__('Tipo de item inválido.', 'reservaplus'));
        }
        if (Access::entityIds() === [] || $types === []) {
            return [];
        }
        $needle = self::searchText(is_scalar($filters['q'] ?? null) ? mb_substr(trim((string) $filters['q']), 0, 200) : '');
        $where = ['is_active' => 1, 'entities_id' => Access::entityIds(), 'itemtype' => $type !== '' ? $type : array_keys($types)];
        $rows = [];
        $seen = 0;
        $cursor = 0;
        do {
            $batch = [];
            foreach ($DB->request(['SELECT' => ['id'], 'FROM' => 'glpi_reservationitems',
                'WHERE' => $where, 'ORDER' => 'id ASC', 'START' => $cursor, 'LIMIT' => 100]) as $row) {
                $batch[] = (int) $row['id'];
            }
            foreach ($batch as $id) {
                try {
                    $source = self::source($id);
                } catch (\DomainException) {
                    // Unauthorized, retired and already-enrolled identities are not choices.
                    continue;
                }
                $haystack = self::searchText(implode(' ', [$source['name'], $source['native_type_label'],
                    $source['location_name'], $source['resource']['inventory_number'], $source['resource']['serial']]));
                if ($needle !== '' && !str_contains($haystack, $needle)) {
                    continue;
                }
                if ($seen++ < $offset) {
                    continue;
                }
                $rows[] = $source;
                if (count($rows) >= $limit) {
                    return $rows;
                }
            }
            $cursor += count($batch);
        } while (count($batch) === 100);
        return $rows;
    }

    /** A validated read-only source. Resource kind and responsibility must be chosen. */
    public static function source(int $reservationItemId): array
    {
        global $DB;
        Access::assertRight('manage');
        if ($reservationItemId < 1) {
            throw new \DomainException(__('Selecione um item existente.', 'reservaplus'));
        }
        $reservationItem = $DB->request(['FROM' => 'glpi_reservationitems', 'WHERE' => ['id' => $reservationItemId], 'LIMIT' => 1])->current();
        $types = ResourceService::nativeTypes();
        $type = (string) ($reservationItem['itemtype'] ?? '');
        if (!$reservationItem || empty($reservationItem['is_active']) || !isset($types[$type])) {
            throw new \DomainException(__('O item existente não está disponível para cadastro.', 'reservaplus'));
        }
        $nativeId = (int) ($reservationItem['items_id'] ?? 0);
        $native = new $type();
        if ($nativeId < 1 || !$native->can($nativeId, READ) || !$native->getFromDB($nativeId)
            || !empty($native->fields['is_deleted']) || !Access::entity((int) ($native->fields['entities_id'] ?? -1))
            || !ItemGroup::isAllowed($reservationItemId)) {
            throw new \DomainException(__('Sem acesso ao item existente nesta entidade.', 'reservaplus'));
        }
        if (Store::find('resources', ['OR' => [
            ['native_itemtype' => $type, 'native_items_id' => $nativeId],
            ['reservationitems_id' => $reservationItemId],
        ]], ['LIMIT' => 1])) {
            throw new \DomainException(__('Este item já foi cadastrado. Consulte a lista de recursos.', 'reservaplus'));
        }
        $setting = ItemSetting::emptySetting();
        if ($DB->tableExists(ItemSetting::getTable())) {
            $stored = $DB->request(['FROM' => ItemSetting::getTable(), 'WHERE' => ['reservationitems_id' => $reservationItemId], 'LIMIT' => 1])->current();
            if ($stored) {
                $setting = ['capacity' => !empty($stored['capacity']) ? (int) $stored['capacity'] : null,
                    'capacity_mode' => ItemSetting::normalizeMode((string) ($stored['capacity_mode'] ?? 'block')),
                    'features' => ItemSetting::normalizeFeatures(explode(',', (string) ($stored['features'] ?? '')))];
            }
        }
        $groups = array_values(array_unique(array_filter(array_map('intval', ItemGroup::getGroupsForItem($reservationItemId)), static fn(int $id): bool => $id > 0)));
        sort($groups, SORT_NUMERIC);
        $resource = self::defaultsFromSource($native->fields, $setting, $groups);
        $locationName = '';
        if ($resource['locations_id'] > 0) {
            $location = new \Location();
            if ($location->can($resource['locations_id'], READ) && $location->getFromDB($resource['locations_id'])) {
                $locationName = (string) ($location->fields['completename'] ?? $location->fields['name'] ?? '');
            }
        }
        $pendingCount = 0;
        $pendingEnd = null;
        foreach ($DB->request(['SELECT' => ['end'], 'FROM' => 'glpi_reservations',
            'WHERE' => ['reservationitems_id' => $reservationItemId, 'end' => ['>', Store::now()]]]) as $reservation) {
            ++$pendingCount;
            $pendingEnd = max((string) $pendingEnd, (string) $reservation['end']);
        }
        return [
            'source_reservationitems_id' => $reservationItemId,
            'native_itemtype' => $type, 'native_items_id' => $nativeId,
            'native_type_label' => (string) $types[$type], 'name' => $resource['name'],
            'location_name' => $locationName, 'resource' => $resource,
            'legacy_capacity_mode' => $setting['capacity_mode'],
            'can_enroll' => $pendingCount === 0, 'active_reservations_count' => $pendingCount,
            'active_reservations_end' => $pendingEnd,
        ];
    }

    /** Only descriptive, non-routing values cross the boundary. */
    public static function defaultsFromSource(array $native, array $setting, array $groups): array
    {
        $text = static fn(string $key): string => is_scalar($native[$key] ?? null) ? mb_substr(trim((string) $native[$key]), 0, 255) : '';
        return [
            'name' => $text('name'), 'entities_id' => (int) ($native['entities_id'] ?? 0),
            'publication' => 'draft', 'locations_id' => max(0, (int) ($native['locations_id'] ?? 0)),
            'serial' => $text('serial'), 'inventory_number' => $text('otherserial'),
            'capacity' => !empty($setting['capacity']) ? (int) $setting['capacity'] : null,
            'features' => implode(', ', array_map(ItemSetting::getFeatureLabel(...), ItemSetting::normalizeFeatures((array) ($setting['features'] ?? [])))),
            'policy_json' => ResourceService::normalizePolicy(['requester_groups_ids' => $groups]),
        ];
    }

    /** Import cannot turn a previously restricted item into a public resource. */
    public static function assertPreservedGroups(array $previous, array $requested): void
    {
        $previous = ResourceService::normalizePolicy(['requester_groups_ids' => $previous])['requester_groups_ids'];
        $requested = ResourceService::normalizePolicy(['requester_groups_ids' => $requested])['requester_groups_ids'];
        if ($previous !== [] && ($requested === [] || array_diff($requested, $previous) !== [])) {
            throw new \DomainException(__('Mantenha os grupos autorizados do item existente ou restrinja a seleção. O cadastro não pode ampliar esse acesso.', 'reservaplus'));
        }
    }

    /** One transaction creates the internal draft and associates the same inventory identity. */
    public static function saveAndLink(array $input, int $sourceReservationItemId): int
    {
        Access::assertRight('manage');
        if (!isset($input['kind']) || !in_array($input['kind'], ['equipment', 'room', 'other'], true)) {
            throw new \DomainException(__('Escolha se este item será uma sala, equipamento ou outro recurso.', 'reservaplus'));
        }
        return Store::transaction(static function () use ($input, $sourceReservationItemId): int {
            // Native identities are re-read inside the transaction. The unique native_identity
            // index on resources also rejects competing enrollments atomically.
            $source = self::source($sourceReservationItemId);
            if (!$source['can_enroll']) {
                throw new \DomainException(__('Este item possui reservas em andamento ou futuras. Conclua ou cancele essas reservas no fluxo anterior antes de cadastrá-lo.', 'reservaplus'));
            }
            $values = array_replace($source['resource'], $input);
            $values['entities_id'] = $source['resource']['entities_id'];
            $values['publication'] = 'draft';
            $policy = ResourceService::policy($values);
            self::assertPreservedGroups($source['resource']['policy_json']['requester_groups_ids'], $policy['requester_groups_ids']);
            $values['policy_json'] = $policy;
            $id = ResourceService::save($values);
            ResourceService::linkNative($id, $source['native_itemtype'], $source['native_items_id']);
            return $id;
        });
    }

    private static function searchText(string $value): string
    {
        return strtr(mb_strtolower($value, 'UTF-8'), ['á'=>'a', 'à'=>'a', 'â'=>'a', 'ã'=>'a', 'é'=>'e', 'ê'=>'e', 'í'=>'i', 'ó'=>'o', 'ô'=>'o', 'õ'=>'o', 'ú'=>'u', 'ç'=>'c']);
    }
}
