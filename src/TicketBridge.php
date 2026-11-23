<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

final class TicketBridge
{
    public static function connect(array $booking, array $resources, int $existing = 0): int
    {
        global $DB;
        $ticket = new \Ticket();
        if ($existing > 0) {
            if (!$ticket->getFromDB($existing) || !$ticket->can($existing, UPDATE)
                || (int) $ticket->fields['entities_id'] !== (int) $booking['entities_id']
                || in_array((int) $ticket->fields['status'], [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED], true)) {
                throw new \DomainException('Chamado indisponível para conversão em reserva.');
            }
            if (Store::find('bookings', ['tickets_id' => $existing], ['LIMIT' => 1])) {
                throw new \DomainException('Este chamado já possui uma solicitação de reserva.');
            }
            return $existing; // Preserve intentional assignment on ticket-origin intake.
        }
        $groups = array_values(array_unique(array_map(static fn($r) => (int) $r['groups_id_ticket'], $resources)));
        if (count($groups) !== 1 || $groups[0] < 1) {
            throw new \DomainException('Selecione recursos do mesmo grupo responsável; combinações entre equipes exigem solicitações separadas nesta versão.');
        }
        $group = new \Group();
        if (!$group->getFromDB($groups[0]) || empty($group->fields['is_assign'])
            || !\Session::haveAccessToEntity((int) $group->fields['entities_id'], (bool) ($group->fields['is_recursive'] ?? false))) {
            throw new \DomainException('O grupo responsável não está disponível para atribuição de chamados.');
        }
        $assignees = array_values(array_unique(array_map(static fn($r) => (int) ($r['default_assignee_users_id'] ?: $r['custodian_users_id']), $resources)));
        $assignee = count($assignees) === 1 ? $assignees[0] : 0;
        if ($assignee > 0) {
            $eligible = false;
            $user = new \User();
            if ($user->getFromDB($assignee) && !empty($user->fields['is_active']) && empty($user->fields['is_deleted'])) {
                foreach ($DB->request(['FROM' => 'glpi_profiles_users', 'WHERE' => ['users_id' => $assignee, 'entities_id' => (int) $booking['entities_id']]]) as $profileUser) {
                    foreach ($DB->request(['FROM' => 'glpi_profilerights', 'WHERE' => ['profiles_id' => (int) $profileUser['profiles_id'], 'name' => 'ticket']]) as $right) {
                        if (((int) $right['rights'] & \Ticket::OWN) !== 0) { $eligible = true; }
                    }
                }
            }
            if (!$eligible) { $assignee = 0; }
        }
        $input = [
            'name' => 'ReservaPlus #' . (int) $booking['id'],
            'content' => 'Solicitação de reserva #' . (int) $booking['id'] . '. Consulte a aba ReservaPlus para recursos, período, aprovações, termos e devolução.',
            'entities_id' => (int) $booking['entities_id'], 'type' => \Ticket::DEMAND_TYPE,
            '_users_id_requester' => (int) $booking['users_id_borrower'],
            '_groups_id_assign' => $groups[0], '_disablenotif' => true,
        ];
        if ($assignee > 0) { $input['_users_id_assign'] = $assignee; }
        if (!$ticket->can(-1, CREATE, $input)) { throw new \DomainException('Sem permissão nativa para abrir o chamado desta reserva.'); }
        $id = WorkflowHooks::native(static fn() => (int) $ticket->add($input));
        if ($id < 1 || !$ticket->getFromDB($id)) { throw new \DomainException('O GLPI não confirmou a criação do chamado.'); }
        if ((int) $ticket->fields['entities_id'] !== (int) $booking['entities_id']) {
            throw new \DomainException('Uma regra do GLPI alterou a entidade do chamado. Revise o roteamento.');
        }
        if (in_array((int) $ticket->fields['status'], [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED], true)) {
            throw new \DomainException('Uma regra encerrou o chamado antes do fluxo de reserva. Revise o roteamento.');
        }
        $actualGroups = [];
        foreach ($DB->request(['FROM' => 'glpi_groups_tickets', 'WHERE' => ['tickets_id' => $id, 'type' => \CommonITILActor::ASSIGN]]) as $actor) {
            $actualGroups[] = (int) $actor['groups_id'];
        }
        if (!in_array($groups[0], $actualGroups, true)) {
            throw new \DomainException('Uma regra do GLPI alterou o grupo responsável. Revise o roteamento antes de reservar.');
        }
        // Native rules own the final assignment. Preserve and expose it; never reset in a sync loop.
        Store::event((int) $booking['id'], 'ticket_created', ['tickets_id' => $id, 'proposed_groups_id' => $groups[0], 'proposed_users_id' => $assignee, 'assigned_groups_ids' => $actualGroups]);
        return $id;
    }

    /** Native rules run inside native model updates, after plugin pre-update hooks. */
    private static function assertTicketState(array $booking, bool $requireOpen = true): void
    {
        $ticket = new \Ticket();
        if (!$ticket->getFromDB((int) $booking['tickets_id']) || !empty($ticket->fields['is_deleted'])
            || (int) $ticket->fields['entities_id'] !== (int) $booking['entities_id']
            || ($requireOpen && in_array((int) $ticket->fields['status'], [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED], true))) {
            throw new \DomainException('Uma regra ou integração do GLPI encerrou, removeu ou mudou a entidade do chamado durante a integração da reserva. A operação foi desfeita; revise as regras antes de continuar.');
        }
    }

    /** Stable frozen targets. Each resource uses an any-one decision, not one vote per target. */
    public static function approvalTargets(array $resource): array
    {
        $pool = ApprovalPolicy::resolve($resource);
        $targets = [];
        foreach ($pool['users_ids'] as $id) { $targets[] = ['itemtype' => \User::class, 'id' => (int) $id]; }
        foreach ($pool['groups_ids'] as $id) { $targets[] = ['itemtype' => \Group::class, 'id' => (int) $id]; }
        return $targets;
    }

    /** Attach configured approvers as native observers; assignment is never changed. */
    private static function approvalObservers(array $booking, array $targets): void
    {
        global $DB;
        $ticketId = (int) $booking['tickets_id'];
        $added = [];
        foreach ($targets as $target) {
            $user = $target['itemtype'] === \User::class;
            $table = $user ? 'glpi_tickets_users' : 'glpi_groups_tickets';
            $field = $user ? 'users_id' : 'groups_id';
            // A requester, assignee or existing observer already has its intended native role.
            if ($DB->request(['FROM' => $table, 'WHERE' => ['tickets_id' => $ticketId, $field => $target['id']], 'LIMIT' => 1])->current()) { continue; }
            $id = WorkflowHooks::native(static function () use ($user, $ticketId, $field, $target): int {
                $actor = $user ? new \Ticket_User() : new \Group_Ticket();
                $input = ['tickets_id' => $ticketId, $field => $target['id'], 'type' => \CommonITILActor::OBSERVER, '_disablenotif' => true];
                if ($user) { $input['use_notification'] = 1; }
                return (int) $actor->add($input);
            });
            if ($id < 1 || !$DB->request(['FROM' => $table, 'WHERE' => ['id' => $id, 'tickets_id' => $ticketId, $field => $target['id'], 'type' => \CommonITILActor::OBSERVER], 'LIMIT' => 1])->current()) {
                throw new \DomainException('O GLPI não confirmou o acesso dos aprovadores ao chamado. Nenhuma solicitação foi registrada.');
            }
            $added[] = $target + ['native_actor_id' => $id];
        }
        self::assertTicketState($booking);
        if ($added !== []) { Store::event((int) $booking['id'], 'approval_observers_added', ['targets' => $added]); }
    }

    private static function nativeApproval(array $booking, array $line, string $targetType, int $targetId): int
    {
        return WorkflowHooks::native(static function () use ($booking, $line, $targetType, $targetId): int {
            $approval = new \TicketValidation();
            $id = (int) $approval->add([
                'tickets_id' => (int) $booking['tickets_id'], 'itemtype_target' => $targetType, 'items_id_target' => $targetId,
                'comment_submission' => 'ReservaPlus #' . (int) $booking['id'] . ' / recurso #' . (int) $line['resources_id'] . ' / revisão ' . (int) $booking['scope_revision'] . '. Decisão registrada pela aba Reserva Plus.',
                '_disablenotif' => true,
            ]);
            if ($id < 1 || !$approval->getFromDB($id) || (int) $approval->fields['tickets_id'] !== (int) $booking['tickets_id']
                || $approval->fields['itemtype_target'] !== $targetType || (int) $approval->fields['items_id_target'] !== $targetId) {
                throw new \DomainException('O GLPI não confirmou a solicitação de aprovação.');
            }
            self::assertTicketState($booking);
            return $id;
        });
    }

    public static function requestApprovals(array $booking, array $lines, array $resources): void
    {
        foreach ($lines as $line) {
            $resource = Access::lineResource($line);
            if (empty(ResourceService::policy($resource)['approval_required'])) { continue; }
            $targets = self::approvalTargets($resource);
            if ($targets === []) { throw new \DomainException('Configure os aprovadores antes de publicar ou solicitar o recurso.'); }
            $legacy = ApprovalPolicy::resolve($resource)['mode'] === 'legacy';
            if (!$legacy) { self::approvalObservers($booking, $targets); }
            foreach ($targets as $target) {
                $nativeId = $legacy ? self::nativeApproval($booking, $line, $target['itemtype'], $target['id']) : 0;
                Store::insert('bookingapprovals', [
                    'bookings_id' => (int) $booking['id'], 'lines_id' => (int) $line['id'],
                    'ticketvalidations_id' => $nativeId, 'target_itemtype' => $target['itemtype'], 'target_id' => $target['id'],
                    'status' => 'pending', 'users_id_decider' => 0, 'date_mod' => Store::now(),
                ]);
            }
        }
        self::assertTicketState($booking);
    }

    public static function targetMatchesActor(array $approval): bool
    {
        return match ((string) ($approval['target_itemtype'] ?? '')) {
            \User::class => (int) ($approval['target_id'] ?? 0) === Access::userId(),
            \Group::class => in_array((int) ($approval['target_id'] ?? 0), Access::groups(), true),
            default => false,
        };
    }

    public static function pendingForActor(array $booking): bool
    {
        if (!Access::booking($booking, 'approve') || BookingService::awaitsInitialSignature($booking)) {
            return false;
        }
        foreach (Store::find('bookinglines', ['bookings_id' => (int) $booking['id']]) as $line) {
            if (!Access::canApproveLine($booking, $line)) { continue; }
            foreach (Store::find('bookingapprovals', ['bookings_id' => (int) $booking['id'], 'lines_id' => (int) $line['id'], 'status' => 'pending']) as $approval) {
                if (self::targetMatchesActor($approval)) { return true; }
            }
        }
        return false;
    }

    /** Called under the aggregate and resource locks. Never write a decision for another target. */
    public static function decideLine(array $booking, array $line, bool $accepted, string $reason): void
    {
        if (!Access::canApproveLine($booking, $line)) { throw new \DomainException('Você não pode aprovar este recurso nesta solicitação.'); }
        $rows = Store::find('bookingapprovals', ['bookings_id' => (int) $booking['id'], 'lines_id' => (int) $line['id']], ['ORDER' => 'id ASC']);
        foreach ($rows as $row) {
            if (in_array($row['status'], ['approved', 'rejected'], true)) { throw new \DomainException('A decisão deste recurso já foi registrada. Atualize a página.'); }
        }
        $chosen = null;
        // Prefer an explicit personal designation when the actor is also in an eligible group.
        foreach ([\User::class, \Group::class] as $type) {
            foreach ($rows as $row) {
                if ($row['status'] === 'pending' && $row['target_itemtype'] === $type && self::targetMatchesActor($row)) { $chosen = $row; break 2; }
            }
        }
        if (!$chosen) { throw new \DomainException('Não há aprovação pendente para você neste recurso.'); }
        $id = (int) $chosen['ticketvalidations_id'];
        if ($id < 1) {
            $id = self::nativeApproval($booking, $line, \User::class, Access::userId());
            Store::update('bookingapprovals', (int) $chosen['id'], ['ticketvalidations_id' => $id]);
        }
        self::decide($chosen + ['_native_id' => $id], $accepted, $reason);
        foreach ($rows as $row) {
            if ((int) $row['id'] !== (int) $chosen['id'] && $row['status'] === 'pending') {
                Store::update('bookingapprovals', (int) $row['id'], ['status' => 'covered', 'users_id_decider' => 0, 'date_mod' => Store::now()]);
            }
        }
        Store::event((int) $booking['id'], 'resource_approval_decided', [
            'accepted' => $accepted, 'users_id_decider' => Access::userId(), 'target_itemtype' => $chosen['target_itemtype'],
            'target_id' => (int) $chosen['target_id'], 'ticketvalidations_id' => $id, 'scope_revision' => (int) $booking['scope_revision'],
        ], (int) $line['id']);
    }

    private static function decide(array $approval, bool $accepted, string $reason): void
    {
        $booking = Store::row('bookings', (int) $approval['bookings_id']);
        if (!ApprovalPolicy::canValidateTicket($booking) || !self::targetMatchesActor($approval)) {
            throw new \DomainException('Sem permissão nativa ou vínculo atual para responder esta aprovação.');
        }
        $model = new \TicketValidation();
        $id = (int) ($approval['_native_id'] ?? $approval['ticketvalidations_id']);
        if ($id < 1 || !$model->getFromDB($id) || !$model->canAnswer() || (int) $model->fields['status'] !== \CommonITILValidation::WAITING) {
            throw new \DomainException('Sem permissão nativa para responder esta aprovação pendente.');
        }
        WorkflowHooks::native(static function () use ($model, $id, $accepted, $reason): void {
            if (!$model->update(['id' => $id, 'status' => $accepted ? \CommonITILValidation::ACCEPTED : \CommonITILValidation::REFUSED,
                'users_id_validate' => Access::userId(), 'comment_validation' => $reason, '_disablenotif' => true])) {
                throw new \DomainException('O GLPI recusou a decisão de aprovação.');
            }
            if (!$model->getFromDB($id) || (int) $model->fields['status'] !== ($accepted ? \CommonITILValidation::ACCEPTED : \CommonITILValidation::REFUSED)
                || (int) $model->fields['users_id_validate'] !== Access::userId()) {
                throw new \DomainException('A decisão nativa ou o autor não correspondem à solicitação.');
            }
        });
        self::assertTicketState($booking);
        Store::update('bookingapprovals', (int) $approval['id'], [
            'status' => $accepted ? 'approved' : 'rejected', 'users_id_decider' => Access::userId(), 'date_mod' => Store::now(),
        ]);
    }

    public static function refreshApprovals(array $booking, array $lines): void
    {
        foreach ($lines as $line) {
            $resource = Access::lineResource($line);
            if (empty(ResourceService::policy($resource)['approval_required'])) { continue; }
            $previous = Store::find('bookingapprovals', ['bookings_id' => (int) $booking['id'], 'lines_id' => (int) $line['id']], ['ORDER' => 'id ASC']);
            if ($previous === []) { self::requestApprovals($booking, [$line], []); continue; }
            $legacy = ApprovalPolicy::resolve($resource)['mode'] === 'legacy';
            foreach ($previous as $row) {
                $oldNativeId = (int) $row['ticketvalidations_id'];
                if ($oldNativeId > 0) {
                    Store::insert('bookingapprovalhistory', [
                        'bookings_id' => (int) $booking['id'], 'lines_id' => (int) $line['id'], 'ticketvalidations_id' => $oldNativeId,
                        'status' => (string) $row['status'], 'users_id_decider' => (int) $row['users_id_decider'], 'date_mod' => Store::now(),
                    ]);
                }
                $nativeId = $legacy ? self::nativeApproval($booking, $line, (string) $row['target_itemtype'], (int) $row['target_id']) : 0;
                Store::event((int) $booking['id'], 'approval_superseded', ['previous' => $row, 'new_ticketvalidations_id' => $nativeId, 'scope_revision' => (int) $booking['scope_revision']], (int) $line['id']);
                Store::update('bookingapprovals', (int) $row['id'], ['ticketvalidations_id' => $nativeId, 'status' => 'pending', 'users_id_decider' => 0, 'date_mod' => Store::now()]);
                if ($nativeId > 0) { self::enqueue((int) $booking['id'], 'approval', ['ticketvalidations_id' => $nativeId], 'approval:' . $nativeId); }
            }
        }
        self::assertTicketState($booking);
    }

    public static function followup(array $booking, string $message, bool $private = false): void
    {
        WorkflowHooks::native(static function () use ($booking, $message, $private): void {
            $followup = new \ITILFollowup();
            if (!$followup->add(['itemtype' => \Ticket::class, 'items_id' => (int) $booking['tickets_id'],
                'content' => $message, 'is_private' => $private ? 1 : 0])) {
                throw new \DomainException('Não foi possível registrar o acompanhamento no chamado.');
            }
            // Outbox delivery has its own transaction. Native follow-up rules must
            // not silently close/move an active custody record after its commit.
            self::assertTicketState($booking, !in_array($booking['status'], ['completed', 'cancelled', 'rejected'], true));
        });
    }

    public static function solve(array $booking): void
    {
        $ticket = new \Ticket();
        $id = (int) $booking['tickets_id'];
        if (!$ticket->getFromDB($id) || !$ticket->can($id, UPDATE)) {
            throw new \DomainException('Sem permissão para solucionar o chamado vinculado.');
        }
        WorkflowHooks::native(static function () use ($ticket, $id, $booking): void {
            if (!$ticket->update(['id' => $id, 'status' => \CommonITILObject::SOLVED, '_disablenotif' => true])) {
                throw new \DomainException('O GLPI recusou a solução do chamado; verifique sua matriz de estados e permissões.');
            }
            if (!$ticket->getFromDB($id) || !in_array((int) $ticket->fields['status'], [\CommonITILObject::SOLVED, \CommonITILObject::CLOSED], true)) {
                throw new \DomainException('O GLPI não aplicou a solução do chamado; revise regras e campos obrigatórios.');
            }
            self::assertTicketState($booking, false);
        });
    }

    public static function enqueue(int $bookingId, string $kind, array $payload, string $eventKey): void
    {
        Store::insert('bookingoutbox', [
            'bookings_id' => $bookingId, 'event_key' => hash('sha256', $eventKey), 'kind' => $kind,
            'payload_json' => Store::json($payload), 'status' => 'pending', 'attempts' => 0,
            'date_creation' => Store::now(), 'date_mod' => Store::now(),
        ]);
    }

    public static function deliverOutbox(int $bookingId = 0): int
    {
        $where = ['status' => 'pending'];
        if ($bookingId > 0) { Access::assertBooking(Store::row('bookings', $bookingId)); $where['bookings_id'] = $bookingId; }
        elseif (!\Session::isCron() && !Access::right('admin')) { throw new \DomainException('Fila restrita à ação automática ou administrador.'); }
        $done = 0;
        $touched = [];
        foreach (Store::find('bookingoutbox', $where, ['ORDER' => ['id ASC'], 'LIMIT' => 100]) as $candidate) {
            if (!empty($candidate['next_attempt_at']) && $candidate['next_attempt_at'] > Store::now()) { continue; }
            // Native GLPI approval notification must not precede the signed
            // responsibility term. Leave it pending until the document seals.
            if ($candidate['kind'] === 'approval') {
                $approvalBooking = Store::row('bookings', (int) $candidate['bookings_id']);
                if (in_array('request', BookingService::signaturePurposes(
                    Store::find('bookinglines', ['bookings_id' => (int) $approvalBooking['id']])
                ), true) && !DocumentService::isSatisfied((int) $approvalBooking['id'], 'request')) {
                    continue;
                }
            }
            if (!\Session::isCron() && !Access::booking(Store::row('bookings', (int) $candidate['bookings_id']))) { continue; }
            $touched[(int) $candidate['bookings_id']] = true;
            try {
                Store::transaction(static function () use ($candidate, &$done): void {
                    $event = Store::lock('bookingoutbox', (int) $candidate['id']);
                    if ($event['status'] !== 'pending') { return; }
                    $booking = Store::row('bookings', (int) $event['bookings_id']);
                    $payload = Store::decode($event['payload_json']);
                    if ($event['kind'] === 'followup') {
                        self::followup($booking, (string) $payload['message'], !empty($payload['is_private']));
                    } elseif ($event['kind'] === 'approval') {
                        $approval = new \TicketValidation(); $ticket = new \Ticket();
                        if (!$approval->getFromDB((int) $payload['ticketvalidations_id']) || !$ticket->getFromDB((int) $booking['tickets_id'])) { throw new \DomainException('Vínculo nativo ausente na fila de aprovação.'); }
                        // Do not send a stale request after it was already answered/cancelled.
                        if ($booking['status'] === 'submitted' && (int) $approval->fields['status'] === \CommonITILValidation::WAITING) {
                            \NotificationEvent::raiseEvent('validation', $ticket, ['validation_id' => $approval->getID(), 'validation_status' => \CommonITILValidation::WAITING], $approval);
                        }
                    }
                    Store::update('bookingoutbox', (int) $event['id'], ['status' => 'done', 'attempts' => (int) $event['attempts'] + 1, 'last_error' => null, 'date_mod' => Store::now()]);
                    ++$done;
                });
            } catch (\Throwable $e) {
                Store::transaction(static function () use ($candidate, $e): void {
                    $event = Store::lock('bookingoutbox', (int) $candidate['id']);
                    if ($event['status'] !== 'pending') { return; }
                    $attempts = (int) $event['attempts'] + 1;
                    Store::update('bookingoutbox', (int) $event['id'], [
                        'attempts' => $attempts, 'last_error' => mb_substr($e->getMessage(), 0, 2000),
                        'next_attempt_at' => date('Y-m-d H:i:s', time() + min(3600, 60 * (2 ** min(6, $attempts)))), 'date_mod' => Store::now(),
                    ]);
                    Store::update('bookings', (int) $event['bookings_id'], ['integration_status' => 'attention', 'integration_error' => 'Acompanhamento/notificação pendente; a ação automática repetirá a entrega.']);
                });
            }
        }
        if ($bookingId > 0) { $touched[$bookingId] = true; }
        foreach (array_keys($touched) as $id) {
            if (!Store::find('bookingoutbox', ['bookings_id' => $id, 'status' => 'pending'], ['LIMIT' => 1])) {
                Store::update('bookings', $id, ['integration_status' => 'synced', 'integration_error' => null]);
            }
        }
        return $done;
    }
}
