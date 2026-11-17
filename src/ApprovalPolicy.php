<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Entity-scoped approval defaults and immutable per-request target rosters. */
final class ApprovalPolicy
{
    public const KINDS = ['equipment', 'room', 'other'];
    public const MAX_TARGETS = 100;

    public static function ids(mixed $values): array
    {
        if (!is_array($values) || count($values) > self::MAX_TARGETS) {
            throw new \InvalidArgumentException('Informe uma lista com até 100 grupos ou usuários aprovadores.');
        }
        $ids = [];
        foreach ($values as $value) {
            $id = filter_var($value, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 2147483647]]);
            if ($id === false) { throw new \InvalidArgumentException('Identificador de aprovador inválido.'); }
            $ids[] = $id;
        }
        $ids = array_values(array_unique($ids)); sort($ids);
        return $ids;
    }

    private static function roster(array $raw): array
    {
        if (!in_array($raw['mode'] ?? null, ['legacy', 'inherit', 'custom'], true)
            || !in_array($raw['source'] ?? null, ['legacy', 'resource', 'default'], true)
            || !isset($raw['groups_ids'], $raw['users_ids'])
            || filter_var($raw['revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]) === false) {
            throw new \InvalidArgumentException('A política de aprovação preservada está inválida.');
        }
        $source = ['legacy' => 'legacy', 'inherit' => 'default', 'custom' => 'resource'][$raw['mode']];
        if ($raw['source'] !== $source) { throw new \InvalidArgumentException('Origem da aprovação incompatível com o modo.'); }
        $groups = self::ids($raw['groups_ids']); $users = self::ids($raw['users_ids']);
        if (count($groups) + count($users) > self::MAX_TARGETS) { throw new \InvalidArgumentException('Selecione até 100 alvos de aprovação por recurso.'); }
        if ($raw['mode'] === 'legacy' && (count($groups) > 1 || $users !== [])) { throw new \InvalidArgumentException('A aprovação anterior deve preservar seu único grupo.'); }
        if ($raw['mode'] === 'inherit' && $users !== []) { throw new \InvalidArgumentException('O padrão por tipo aceita somente grupos.'); }
        return ['mode' => $raw['mode'], 'source' => $source, 'revision' => (int) $raw['revision'], 'groups_ids' => $groups, 'users_ids' => $users];
    }

    public static function resolve(array $resource): array
    {
        if (array_key_exists('_approval_policy', $resource)) {
            if (!is_array($resource['_approval_policy'])) { throw new \InvalidArgumentException('Política de aprovação preservada inválida.'); }
            return self::roster($resource['_approval_policy']);
        }
        $policy = ResourceService::policy($resource);
        $mode = $policy['approval_mode'];
        $revision = max(0, (int) ($resource['config_revision'] ?? 0));
        if ($mode === 'legacy') {
            $group = (int) ($resource['groups_id_approve'] ?? 0);
            return self::roster(['mode' => $mode, 'source' => 'legacy', 'revision' => $revision, 'groups_ids' => $group > 0 ? [$group] : [], 'users_ids' => []]);
        }
        if ($mode === 'custom') {
            return self::roster(['mode' => $mode, 'source' => 'resource', 'revision' => $revision,
                'groups_ids' => $policy['approver_groups_ids'], 'users_ids' => $policy['approver_users_ids']]);
        }
        $kind = (string) ($resource['kind'] ?? '');
        if (!in_array($kind, self::KINDS, true)) { throw new \InvalidArgumentException('Tipo de recurso sem padrão de aprovação.'); }
        $default = self::defaults((int) ($resource['entities_id'] ?? -1))[$kind];
        return self::roster(['mode' => $mode, 'source' => 'default', 'revision' => $default['revision'], 'groups_ids' => $default['groups_ids'], 'users_ids' => []]);
    }

    public static function defaults(int $entity): array
    {
        if (!Access::entity($entity)) { throw new \DomainException('Padrões de aprovação fora da entidade ativa.'); }
        $out = [];
        foreach (self::KINDS as $kind) { $out[$kind] = ['groups_ids' => [], 'revision' => 0]; }
        foreach (Store::find('approvaldefaults', ['entities_id' => $entity]) as $row) {
            if (!isset($out[$row['kind']])) { continue; }
            $out[$row['kind']] = ['groups_ids' => self::ids(Store::decode($row['groups_json'] ?? null)), 'revision' => (int) $row['revision']];
        }
        return $out;
    }

    private static function nativeRow(string $table, int $id): array
    {
        global $DB;
        $rows = $DB->request(['FROM' => $table, 'WHERE' => ['id' => $id], 'LIMIT' => 1]);
        foreach ($rows as $row) { return (array) $row; }
        throw new \DomainException('Grupo ou usuário aprovador não encontrado.');
    }

    private static function nativeEntity(array $row, int $entity): bool
    {
        if ((int) ($row['entities_id'] ?? -1) === $entity) { return true; }
        $ancestors = function_exists('getAncestorsOf') ? array_map('intval', array_values(\getAncestorsOf('glpi_entities', $entity))) : [];
        return !empty($row['is_recursive']) && in_array((int) ($row['entities_id'] ?? -1), $ancestors, true);
    }

    private static function checkGroup(int $id, int $entity): void
    {
        if (!self::nativeEntity(self::nativeRow('glpi_groups', $id), $entity)) {
            throw new \DomainException('Grupo aprovador fora da entidade do recurso.');
        }
    }

    private static function checkUser(int $id, int $entity): void
    {
        global $DB;
        $user = self::nativeRow('glpi_users', $id);
        if (empty($user['is_active']) || !empty($user['is_deleted'])) { throw new \DomainException('O usuário aprovador deve estar ativo.'); }
        foreach ($DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $id]]) as $membership) {
            if (self::nativeEntity((array) $membership, $entity)) { return; }
        }
        throw new \DomainException('O usuário aprovador não possui vínculo com a entidade do recurso.');
    }

    /** Validate retained selector IDs without disclosing names outside their scope. */
    public static function validTarget(string $type, int $id, int $entity): bool
    {
        if ($id < 1 || !Access::entity($entity)) { return false; }
        try {
            if ($type === \Group::class) { self::checkGroup($id, $entity); }
            elseif ($type === \User::class) { self::checkUser($id, $entity); }
            else { return false; }
            return true;
        } catch (\Throwable) { return false; }
    }

    /** Used when publishing and submitting; empty pools are allowed only for incomplete drafts or approval-free resources. */
    public static function validate(array $resource, bool $requireTargets = true): array
    {
        $pool = self::resolve($resource);
        if ($requireTargets && ResourceService::policy($resource)['approval_required'] && $pool['groups_ids'] === [] && $pool['users_ids'] === []) {
            throw new \DomainException($pool['mode'] === 'inherit'
                ? 'Configure os grupos aprovadores padrão deste tipo e entidade, ou escolha aprovadores específicos para o recurso.'
                : 'Selecione ao menos um grupo ou usuário autorizado a aprovar este recurso.');
        }
        $entity = (int) ($resource['entities_id'] ?? -1);
        foreach ($pool['groups_ids'] as $id) { self::checkGroup($id, $entity); }
        foreach ($pool['users_ids'] as $id) { self::checkUser($id, $entity); }
        return $pool;
    }

    public static function snapshot(array $resource): array
    {
        $resource['_approval_policy'] = self::validate($resource);
        return $resource;
    }

    public static function saveDefaults(int $entity, array $perKind): void
    {
        Access::assertRight('admin');
        if (!Access::entity($entity)) { throw new \DomainException('Padrões de aprovação fora da entidade ativa.'); }
        if (array_diff(array_keys($perKind), self::KINDS) !== [] || count($perKind) !== count(self::KINDS)) {
            throw new \InvalidArgumentException('Informe os padrões de equipamento, sala e outros recursos.');
        }
        $normalized = [];
        foreach (self::KINDS as $kind) {
            if (!is_array($perKind[$kind]) || !array_key_exists('groups_ids', $perKind[$kind])) { throw new \InvalidArgumentException('Padrão de aprovação inválido.'); }
            $revision = filter_var($perKind[$kind]['expected_revision'] ?? null, FILTER_VALIDATE_INT, ['options' => ['min_range' => 0]]);
            if ($revision === false) { throw new \InvalidArgumentException('Revisão dos padrões inválida.'); }
            $normalized[$kind] = ['groups_ids' => self::ids($perKind[$kind]['groups_ids']), 'expected_revision' => $revision];
        }
        Store::transaction(static function () use ($entity, $normalized): void {
            self::lockDefaults($entity);
            foreach (self::KINDS as $kind) {
                $input = $normalized[$kind];
                foreach ($input['groups_ids'] as $group) { self::checkGroup($group, $entity); }
                $rows = Store::find('approvaldefaults', ['entities_id' => $entity, 'kind' => $kind], ['LIMIT' => 1]);
                $before = $rows ? Store::lock('approvaldefaults', (int) $rows[0]['id']) : [];
                if ((int) ($before['revision'] ?? 0) !== $input['expected_revision']) {
                    throw new \DomainException('Os padrões foram alterados. Reabra a configuração antes de salvar.');
                }
                $after = ['entities_id' => $entity, 'kind' => $kind, 'groups_json' => Store::json($input['groups_ids']),
                    'revision' => (int) ($before['revision'] ?? 0) + 1, 'users_id_actor' => Access::userId(), 'date_mod' => Store::now()];
                if ($before) { $id = (int) $before['id']; Store::update('approvaldefaults', $id, $after); }
                else { $after['date_creation'] = Store::now(); $id = Store::insert('approvaldefaults', $after); }
                Store::insert('approvaldefaultchanges', ['approvaldefaults_id' => $id, 'entities_id' => $entity, 'kind' => $kind,
                    'users_id_actor' => Access::userId(), 'revision' => $after['revision'], 'before_json' => Store::json($before),
                    'after_json' => Store::json($after), 'date_creation' => Store::now()]);
            }
        });
    }

    /** Coordinate one submission's frozen defaults with the atomic defaults editor. */
    public static function lockDefaults(int $entity): void
    {
        global $DB;
        if (!Store::inTransaction()) { throw new \LogicException('A configuração exige uma transação ativa para ser bloqueada.'); }
        if (!Access::entity($entity)) { throw new \DomainException('Padrões de aprovação fora da entidade ativa.'); }
        // A native read lock also serializes first-time inserts into plugin defaults.
        $locked = $DB->doQuery('SELECT `id` FROM `glpi_entities` WHERE `id` = ' . $entity . ' FOR UPDATE');
        if ($locked === false || !$DB->fetchAssoc($locked)) { throw new \DomainException('Entidade indisponível para configurar aprovações.'); }
    }

    public static function matches(array $resource): bool
    {
        if (Access::userId() < 1 || !Access::entity((int) ($resource['entities_id'] ?? -1))) { return false; }
        try {
            $pool = self::resolve($resource);
            $personal = in_array(Access::userId(), $pool['users_ids'], true);
            $groups = array_intersect(Access::groups(), $pool['groups_ids']);
            if (!$personal && $groups === []) { return false; }
            $entity = (int) $resource['entities_id'];
            self::checkUser(Access::userId(), $entity);
            if ($personal) { return true; }
            foreach ($groups as $group) {
                try { self::checkGroup($group, $entity); return true; }
                catch (\DomainException) { /* A moved/deleted group no longer grants approval in this entity. */ }
            }
            return false;
        } catch (\Throwable) { return false; }
    }

    public static function canValidateTicket(array $booking): bool
    {
        if (Access::userId() < 1 || !Access::entity((int) ($booking['entities_id'] ?? -1)) || !Access::right('approve')) { return false; }
        try {
            self::checkUser(Access::userId(), (int) $booking['entities_id']);
            $ticket = new \Ticket(); $id = (int) ($booking['tickets_id'] ?? 0);
            if ($id < 1 || !$ticket->getFromDB($id) || (int) $ticket->fields['entities_id'] !== (int) $booking['entities_id'] || !$ticket->can($id, READ)) { return false; }
            $right = match ((int) $ticket->fields['type']) {
                \Ticket::DEMAND_TYPE => \TicketValidation::VALIDATEREQUEST,
                \Ticket::INCIDENT_TYPE => \TicketValidation::VALIDATEINCIDENT,
                default => 0,
            };
            return $right > 0 && \Session::haveRight(\TicketValidation::$rightname, $right);
        } catch (\Throwable) { return false; }
    }
}
