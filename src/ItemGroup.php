<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;

class ItemGroup extends CommonDBTM
{
    public static $table    = 'glpi_plugin_reservaplus_item_groups';
    public static $rightname = 'plugin_reservaplus_reservation';

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_reservaplus_item_groups';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Grupo por sala — Reserva Plus', 'reservaplus');
    }

    /** Creates the table on first use (idempotent). */
    public static function ensureTable(): void
    {
        global $DB;

        if ($DB->tableExists(static::getTable())) {
            return;
        }

        $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_item_groups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reservationitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_group` (`reservationitems_id`, `groups_id`),
            KEY `reservationitems_id` (`reservationitems_id`),
            KEY `groups_id` (`groups_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /** Group IDs the current user belongs to (from GLPI session). */
    public static function getUserGroupIds(): array
    {
        return array_values(array_map('intval', $_SESSION['glpigroups'] ?? []));
    }

    /** Resolve only existing, entity-visible parents; group rights never bypass entities. */
    public static function context(int $reservationItemsId, bool $requireActive = true): ?array
    {
        global $DB;
        if ($reservationItemsId <= 0 || !$DB->tableExists('glpi_reservationitems')) {
            return null;
        }
        $row = $DB->request([
            'FROM' => 'glpi_reservationitems', 'WHERE' => ['id' => $reservationItemsId], 'LIMIT' => 1,
        ])->current();
        if (!$row || ($requireActive && empty($row['is_active']))) {
            return null;
        }
        $type = (string) ($row['itemtype'] ?? '');
        if ($type === '' || !class_exists($type) || !is_subclass_of($type, \CommonDBTM::class)) {
            return null;
        }
        $item = new $type();
        if (!$item->getFromDB((int) ($row['items_id'] ?? 0))) {
            return null;
        }
        $entity = (int) ($item->fields['entities_id'] ?? $row['entities_id'] ?? 0);
        $recursive = (bool) ($item->fields['is_recursive'] ?? $row['is_recursive'] ?? false);
        if (!\Session::haveAccessToEntity($entity, $recursive)
            || ($requireActive && !empty($item->fields['is_deleted']))) {
            return null;
        }
        $row['_entities_id'] = $entity;
        $row['_is_recursive'] = $recursive;
        return $row;
    }

    public static function isManaged(int $reservationItemsId): bool
    {
        global $DB;
        $row = $DB->request([
            'FROM' => 'glpi_reservationitems', 'WHERE' => ['id' => $reservationItemsId], 'LIMIT' => 1,
        ])->current();
        if (!$row) {
            return false;
        }
        if (ltrim((string) $row['itemtype'], '\\') === Resource::class) {
            return true;
        }
        return self::managedResource($row) !== null;
    }

    private static function managedResource(array $item): ?array
    {
        global $DB;
        $table = 'glpi_plugin_reservaplus_resources';
        if (!$DB->tableExists($table)) {
            return null;
        }
        $matches = [
            ['reservationitems_id' => (int) $item['id']],
            ['native_itemtype' => (string) $item['itemtype'], 'native_items_id' => (int) $item['items_id']],
        ];
        if (ltrim((string) $item['itemtype'], '\\') === Resource::class) {
            $matches[] = ['id' => (int) $item['items_id']];
        }
        $resource = $DB->request(['FROM' => $table, 'WHERE' => ['OR' => $matches], 'LIMIT' => 1])->current();
        return $resource ? (array) $resource : null;
    }

    public static function isLegacyBookable(int $reservationItemsId): bool
    {
        return self::isAllowed($reservationItemsId) && !self::isManaged($reservationItemsId);
    }

    public static function isAllowed(int $reservationItemsId, bool $requireActive = true): bool
    {
        $context = self::context($reservationItemsId, $requireActive);
        if ($context === null) {
            return false;
        }
        $managedResource = self::managedResource($context);
        if ($managedResource !== null && !Access::resource($managedResource, 'read')) {
            return false;
        }
        if (ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests()) {
            return true;
        }
        $groups = self::getGroupsForItem($reservationItemsId);
        return $groups === [] || array_intersect($groups, self::getUserGroupIds()) !== [];
    }

    /** Always returns explicit entity/group-scoped IDs, including for administrators. */
    public static function getAllowedItemIds(bool $requireActive = true): ?array
    {
        global $DB;
        if (!$DB->tableExists('glpi_reservationitems')) {
            return [];
        }
        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['id'], 'FROM' => 'glpi_reservationitems', 'WHERE' => $requireActive ? ['is_active' => 1] : [],
        ]) as $row) {
            $id = (int) $row['id'];
            if (self::isAllowed($id, $requireActive)) {
                $ids[] = $id;
            }
        }
        return $ids;
    }

    /** Group IDs assigned to the item. Empty array means no restriction. */
    public static function getGroupsForItem(int $reservationItemsId): array
    {
        global $DB;

        if (!$DB->tableExists(static::getTable())) {
            return [];
        }

        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['groups_id'],
            'FROM'   => static::getTable(),
            'WHERE'  => ['reservationitems_id' => $reservationItemsId],
        ]) as $row) {
            $ids[] = (int) $row['groups_id'];
        }

        return $ids;
    }

    /** Replaces all group assignments for the item atomically. */
    public static function setGroupsForItem(int $reservationItemsId, array $groupIds): void
    {
        global $DB;

        if (!(\Session::haveRight(Config::$rightname, UPDATE)
                || ReservationRequest::isGlpiAdmin())
            || self::context($reservationItemsId, false) === null
            || self::isManaged($reservationItemsId)) {
            throw new \RuntimeException(__('Sem permissão para alterar os grupos deste item.', 'reservaplus'));
        }
        $groupIds = array_values(array_unique(array_filter(array_map('intval', $groupIds), static fn(int $id): bool => $id > 0)));
        foreach ($groupIds as $groupId) {
            $group = new \Group();
            if (!$group->getFromDB($groupId)
                || !\Session::haveAccessToEntity((int) ($group->fields['entities_id'] ?? 0), (bool) ($group->fields['is_recursive'] ?? false))) {
                throw new \RuntimeException(__('Grupo fora do escopo permitido.', 'reservaplus'));
            }
        }
        self::ensureTable();
        $DB->beginTransaction();
        try {
            if (!$DB->delete(static::getTable(), ['reservationitems_id' => $reservationItemsId])) {
                throw new \RuntimeException('Could not update item groups');
            }
            foreach ($groupIds as $groupId) {
                if (!$DB->insert(static::getTable(), [
                    'reservationitems_id' => $reservationItemsId,
                    'groups_id' => $groupId, 'date_creation' => date('Y-m-d H:i:s'),
                ])) {
                    throw new \RuntimeException('Could not save item group');
                }
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            throw $e;
        }
    }
}
