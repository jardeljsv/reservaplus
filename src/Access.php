<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use Session;

/** Explicit capability, current entity and resource responsibility checks. */
final class Access
{
    public const RIGHT = 'plugin_reservaplus_workflow';
    public const MANAGE = 1024;
    public const APPROVE = 2048;
    public const ISSUE = 4096;
    public const RECEIVE = 8192;
    public const INSPECT = 16384;
    public const AUDIT = 32768;
    public const ADMIN = 65536;
    public const ON_BEHALF = 131072;

    public static function userId(): int
    {
        return (int) Session::getLoginUserID();
    }

    public static function entityIds(): array
    {
        if (self::userId() < 1) {
            return [];
        }
        $ids = $_SESSION['glpiactiveentities'] ?? [$_SESSION['glpiactive_entity'] ?? -1];
        if (!is_array($ids)) {
            return [];
        }
        return array_values(array_unique(array_filter(array_map('intval', $ids), static fn(int $id): bool => $id >= 0)));
    }

    public static function entity(int $id): bool
    {
        return $id >= 0 && in_array($id, self::entityIds(), true);
    }

    public static function groups(): array
    {
        global $DB;
        $scoped = array_values(array_unique(array_filter(array_map('intval', (array) ($_SESSION['glpigroups'] ?? [])), static fn(int $id): bool => $id > 0)));
        if ($scoped === [] || self::userId() < 1) {
            return [];
        }
        // Keep GLPI's current entity/profile scope, but honor membership revocations
        // without requiring an existing authenticated session to log in again.
        if (isset($DB)) {
            $current = [];
            foreach ($DB->request(['SELECT' => ['groups_id'], 'FROM' => 'glpi_groups_users',
                'WHERE' => ['users_id' => self::userId(), 'groups_id' => $scoped]]) as $membership) {
                $current[] = (int) $membership['groups_id'];
            }
            return array_values(array_unique($current));
        }
        return $scoped;
    }

    public static function right(string $cap): bool
    {
        if (self::userId() < 1) {
            return false;
        }
        $rights = [
            'read' => READ, 'request' => CREATE, 'manage' => self::MANAGE,
            'approve' => self::APPROVE, 'issue' => self::ISSUE,
            'receive' => self::RECEIVE, 'inspect' => self::INSPECT,
            'audit' => self::AUDIT, 'admin' => self::ADMIN, 'on_behalf' => self::ON_BEHALF,
        ];
        if (!isset($rights[$cap])) {
            return false;
        }
        if (Session::haveRight(self::RIGHT, self::ADMIN)) {
            return true;
        }
        if (Session::haveRight(self::RIGHT, $rights[$cap])) {
            return true;
        }
        if ($cap === 'read') {
            if (Session::haveRight(ReservationRequest::$rightname, READ)) {
                return true;
            }
            foreach (['manage', 'approve', 'issue', 'receive', 'inspect', 'audit'] as $operation) {
                if (Session::haveRight(self::RIGHT, $rights[$operation])) {
                    return true;
                }
            }
        }
        return $cap === 'request' && Session::haveRight(ReservationRequest::$rightname, CREATE) > 0;
    }

    private static function policy(array $row): array
    {
        try {
            $policy = Store::decode($row['policy_json'] ?? null);
            foreach (['requester_groups_ids', 'approver_groups_ids', 'approver_users_ids'] as $field) {
                if (!array_key_exists($field, $policy)) {
                    continue;
                }
                if (!is_array($policy[$field])) {
                    return ['_invalid' => true];
                }
                foreach ($policy[$field] as $id) {
                    if (filter_var($id, FILTER_VALIDATE_INT) === false || (int) $id < 1) {
                        return ['_invalid' => true];
                    }
                }
            }
            return $policy;
        } catch (\Throwable) {
            // Corrupt policy must never turn a restriction into unrestricted access.
            return ['_invalid' => true];
        }
    }

    public static function resource(array $row, string $cap = 'read'): bool
    {
        if (!self::entity((int) ($row['entities_id'] ?? -1)) || !self::right($cap)) {
            return false;
        }
        if ($cap === 'approve') {
            $policy = self::policy($row);
            return !isset($policy['_invalid']) && (int) ($row['is_deleted'] ?? 0) !== 1 && ApprovalPolicy::matches($row);
        }
        if (self::right('admin')) {
            return true;
        }
        $policy = self::policy($row);
        if (isset($policy['_invalid']) || (int) ($row['is_deleted'] ?? 0) === 1) {
            return false;
        }
        $groups = self::groups();
        $managementGroup = (int) ($row['groups_id_manage'] ?? 0);
        $ticketGroup = (int) ($row['groups_id_ticket'] ?? 0);
        $operationsGroup = $managementGroup > 0 ? $managementGroup : $ticketGroup;
        $isOperator = $operationsGroup > 0 && in_array($operationsGroup, $groups, true);
        $isTicketTeam = $ticketGroup > 0 && in_array($ticketGroup, $groups, true);
        $isApprover = self::right('approve') && ApprovalPolicy::matches($row);
        if (in_array($cap, ['manage', 'issue', 'receive', 'inspect', 'on_behalf'], true)) {
            return $isOperator;
        }
        if ($cap === 'audit') {
            return $isOperator || $isTicketTeam;
        }
        if ($cap === 'read') {
            if (($isOperator && (self::right('manage') || self::right('issue') || self::right('receive') || self::right('inspect')))
                || ($isApprover && self::right('approve'))
                || ($isTicketTeam && self::right('audit'))) {
                return true;
            }
        }
        if (!in_array($cap, ['read', 'request'], true) || ($row['publication'] ?? 'draft') !== 'published') {
            return false;
        }
        $eligibleGroups = array_values(array_filter(array_map('intval', (array) ($policy['requester_groups_ids'] ?? []))));
        return $eligibleGroups === [] || count(array_intersect($groups, $eligibleGroups)) > 0;
    }

    public static function booking(array $row, string $cap = 'read'): bool
    {
        if (!self::entity((int) ($row['entities_id'] ?? -1)) || !self::right($cap)) {
            return false;
        }
        if ($cap === 'approve') {
            if (!self::booking($row, 'read') || !ApprovalPolicy::canValidateTicket($row)) { return false; }
            foreach (Store::find('bookinglines', ['bookings_id' => (int) ($row['id'] ?? 0)]) as $line) {
                if (self::matchesApprovalLine($line)) { return true; }
            }
            return false;
        }
        if (self::right('admin')) {
            return true;
        }
        $own = in_array(self::userId(), [(int) ($row['users_id_requester'] ?? 0), (int) ($row['users_id_borrower'] ?? 0)], true);
        if ($cap === 'request') {
            return $own;
        }
        if ($cap === 'read' && $own) {
            return true;
        }
        $id = (int) ($row['id'] ?? 0);
        if ($id < 1) {
            return false;
        }
        $lines = Store::find('bookinglines', ['bookings_id' => $id]);
        if (!$lines) {
            return false;
        }
        // Existing operations roles retain their all-lines responsibility requirement.
        $allOperations = true;
        foreach ($lines as $line) {
            try {
                $resource = Store::row('resources', (int) $line['resources_id']);
            } catch (\Throwable) {
                return false;
            }
            if ($cap === 'read') {
                $allowed = false;
                foreach (['manage', 'issue', 'receive', 'inspect', 'audit'] as $operation) {
                    if (self::resource($resource, $operation)) {
                        $allowed = true;
                        break;
                    }
                }
                if (!$allowed) { $allOperations = false; }
            } elseif (!self::resource($resource, $cap)) {
                return false;
            }
        }
        if ($cap !== 'read' || $allOperations) { return true; }

        // A partial approver can open an aggregate only when its other lines are
        // independently readable and GLPI itself permits reading the ticket.
        if (!self::right('approve') || !self::canReadTicket($row)) { return false; }
        $matches = false;
        foreach ($lines as $line) {
            try {
                $frozen = self::lineResource($line);
                $current = Store::row('resources', (int) $line['resources_id']);
                $target = self::resource($frozen, 'approve');
                $matches = $matches || $target;
                if (!$target && !self::resource($current, 'read')) { return false; }
            } catch (\Throwable) { return false; }
        }
        return $matches;
    }

    private static function canReadTicket(array $booking): bool
    {
        try {
            $ticket = new \Ticket(); $id = (int) ($booking['tickets_id'] ?? 0);
            return $id > 0 && $ticket->getFromDB($id)
                && (int) $ticket->fields['entities_id'] === (int) $booking['entities_id'] && $ticket->can($id, READ);
        } catch (\Throwable) { return false; }
    }

    public static function lineResource(array $line): array
    {
        $raw = $line['snapshot_json'] ?? null;
        $snapshot = is_array($raw) ? $raw : Store::decode($raw);
        $resource = is_array($snapshot['resource'] ?? null) ? $snapshot['resource'] : $snapshot;
        if (isset($resource['entities_id'], $resource['policy_json'])) {
            if (isset($resource['id']) && (int) $resource['id'] !== (int) $line['resources_id']) {
                throw new \DomainException('A identidade preservada do recurso não corresponde à solicitação.');
            }
            $resource['id'] = (int) $line['resources_id'];
            return $resource;
        }
        // Compatibility for records predating resource snapshots only.
        if ($snapshot !== []) { throw new \DomainException('A configuração preservada do recurso está incompleta.'); }
        return Store::row('resources', (int) $line['resources_id']);
    }

    private static function matchesApprovalLine(array $line): bool
    {
        try {
            $resource = self::lineResource($line);
            return ResourceService::policy($resource)['approval_required'] && self::resource($resource, 'approve');
        } catch (\Throwable) { return false; }
    }

    public static function canApproveLine(array $booking, array $line): bool
    {
        if (isset($line['bookings_id']) && (int) $line['bookings_id'] !== (int) ($booking['id'] ?? 0)) { return false; }
        return self::right('approve') && self::entity((int) ($booking['entities_id'] ?? -1))
            && self::booking($booking, 'read') && ApprovalPolicy::canValidateTicket($booking) && self::matchesApprovalLine($line);
    }

    public static function canReschedule(array $booking): bool
    {
        if (!self::entity((int) ($booking['entities_id'] ?? -1)) || !self::booking($booking, 'read') || !self::canReadTicket($booking)) { return false; }
        $required = [];
        $lines = Store::find('bookinglines', ['bookings_id' => (int) ($booking['id'] ?? 0)]);
        if ($lines === []) { return false; }
        foreach ($lines as $line) {
            try {
                if (!ResourceService::policy(self::lineResource($line))['approval_required']) { continue; }
                $required[] = $line;
            } catch (\Throwable) { return false; }
        }
        // No approval roster exists for approval-free bookings: only scoped
        // resource managers can reschedule, without unrelated validation rights.
        if ($required === []) {
            return self::booking($booking, 'manage')
                && ApprovalPolicy::validTarget(\User::class, self::userId(), (int) $booking['entities_id']);
        }
        if (!self::right('approve') || !ApprovalPolicy::canValidateTicket($booking)) { return false; }
        foreach ($required as $line) { if (!self::matchesApprovalLine($line)) { return false; } }
        return true;
    }

    public static function assertRight(string $cap): void
    {
        if (!self::right($cap)) {
            throw new \DomainException('Sem permissão para esta operação.');
        }
    }

    public static function assertResource(array $row, string $cap = 'read'): void
    {
        if (!self::resource($row, $cap)) {
            throw new \DomainException('Recurso indisponível para esta operação.');
        }
    }

    public static function assertBooking(array $row, string $cap = 'read'): void
    {
        if (!self::booking($row, $cap)) {
            throw new \DomainException('Solicitação indisponível para esta operação.');
        }
    }
}
