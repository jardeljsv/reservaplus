<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** All entry points converge here; native hooks reject unmanaged shortcuts. */
final class BookingService
{
    public static function get(int $id): array
    {
        $booking = Store::row('bookings', $id);
        Access::assertBooking($booking);
        $booking['lines'] = Store::find('bookinglines', ['bookings_id' => $id], ['ORDER' => ['id ASC']]);
        $booking['events'] = Store::find('bookingevents', ['bookings_id' => $id], ['ORDER' => ['id ASC']]);
        $booking['approvals'] = Store::find('bookingapprovals', ['bookings_id' => $id]);
        return $booking;
    }

    public static function search(array $filters = [], int $limit = 50, int $offset = 0): array
    {
        Access::assertRight('read');
        $where = ['entities_id' => Access::entityIds() ?: [-1]];
        foreach (['status', 'tickets_id'] as $key) { if (isset($filters[$key]) && $filters[$key] !== '') { $where[$key] = $filters[$key]; } }
        if (!empty($filters['from'])) { $where['end'] = ['>', Booking::date((string) $filters['from'])]; }
        if (!empty($filters['to'])) { $where['begin'] = ['<', Booking::date((string) $filters['to'])]; }
        $rows = []; $skip = 0; $start = 0; $limit = max(1, min(200, $limit)); $offset = max(0, $offset);
        // Apply record authorization before logical pagination; no unscoped row truncation.
        do {
            $batch = Store::find('bookings', $where, ['ORDER' => ['id DESC'], 'LIMIT' => 100, 'START' => $start]);
            foreach ($batch as $booking) {
                if (!Access::booking($booking)) { continue; }
                if ($skip++ < $offset) { continue; }
                $rows[] = $booking;
                if (count($rows) >= $limit) { return $rows; }
            }
            $start += 100;
        } while (count($batch) === 100);
        return $rows;
    }

    public static function availability(int $resourceId, string $begin, string $end, int $excludeBookingId = 0): array
    {
        [$begin, $end] = Booking::period($begin, $end);
        $resource = Resource::getRow($resourceId);
        Access::assertResource($resource, 'read');
        if ($excludeBookingId > 0) { Access::assertBooking(Store::row('bookings', $excludeBookingId)); }
        $reasons = CalendarBridge::conflicts($resource, $begin, $end, $excludeBookingId);
        return ['available' => $reasons === [], 'reasons' => $reasons];
    }

    /**
     * A physically early pickup is not a change to the approved reservation period.
     * Keep the original signed scope and record the actual issue timestamp separately.
     */
    public static function canIssueAt(string $approvedBegin, string $approvedEnd, string $at, int $graceMinutes = 15): bool
    {
        $start = strtotime($approvedBegin);
        $end = strtotime($approvedEnd);
        $now = strtotime($at);
        return $start !== false && $end !== false && $now !== false
            && $graceMinutes >= 0 && $graceMinutes <= 15
            && $now >= $start - $graceMinutes * 60 && $now < $end;
    }

    private static function operationKey(string $key): string
    {
        if (!preg_match('/^[a-zA-Z0-9._:-]{16,160}$/D', $key)) { throw new \InvalidArgumentException('Identificador de operação inválido. Reabra o formulário.'); }
        return hash('sha256', Access::userId() . ':' . $key);
    }

    private static function resourceIds(array $input): array
    {
        $values = $input['resources_ids'] ?? $input['resource_ids'] ?? [];
        if (!is_array($values) || count($values) < 1 || count($values) > 32) { throw new \InvalidArgumentException('Selecione de 1 a 32 recursos.'); }
        $ids = [];
        foreach ($values as $value) {
            if (!ctype_digit((string) $value) || (int) $value < 1) { throw new \InvalidArgumentException('Recurso inválido.'); }
            $ids[] = (int) $value;
        }
        $ids = array_values(array_unique($ids)); sort($ids, SORT_NUMERIC);
        return $ids;
    }

    public static function submit(array $input, string $key): int
    {
        Access::assertRight('request');
        [$begin, $end] = Booking::period((string) ($input['begin'] ?? ''), (string) ($input['end'] ?? ''));
        if ($end <= Store::now()) { throw new \InvalidArgumentException('O período deve terminar no futuro.'); }
        $ids = self::resourceIds($input);
        $purpose = trim((string) ($input['purpose'] ?? ''));
        if ($purpose === '' || mb_strlen($purpose) > 5000) { throw new \InvalidArgumentException('Informe a finalidade em até 5.000 caracteres.'); }
        $people = filter_var($input['people_count'] ?? 1, FILTER_VALIDATE_INT, ['options' => ['min_range' => 1, 'max_range' => 100000]]);
        if ($people === false) { throw new \InvalidArgumentException('Quantidade de pessoas inválida.'); }
        $borrowerId = (int) ($input['users_id_borrower'] ?? Access::userId());
        if ($borrowerId !== Access::userId()) { Access::assertRight('on_behalf'); }
        $user = new \User();
        if (!$user->getFromDB($borrowerId) || empty($user->fields['is_active']) || !empty($user->fields['is_deleted'])) {
            throw new \DomainException('Usuário beneficiário indisponível.');
        }
        $borrower = [
            'users_id' => $borrowerId, 'name' => trim(($user->fields['firstname'] ?? '') . ' ' . ($user->fields['realname'] ?? '')) ?: (string) $user->fields['name'],
            'matricula' => mb_substr(trim((string) ($input['matricula'] ?? '')), 0, 100),
            'unit' => mb_substr(trim((string) ($input['unit'] ?? '')), 0, 255),
            'affiliation_source' => 'declared',
        ];
        $operation = self::operationKey($key);
        $fingerprint = hash('sha256', Store::json([$ids, $begin, $end, $purpose, $people, $borrower, (int) ($input['tickets_id'] ?? 0)]));
        $created = false;
        $id = Store::transaction(static function () use ($ids, $begin, $end, $purpose, $people, $borrower, $borrowerId, $input, $operation, $fingerprint, &$created): int {
            // The resource lock serializes same-capacity commands across all plugin entry points.
            $resources = [];
            foreach ($ids as $id) { $resources[$id] = Store::lock('resources', $id); }
            $prior = Store::find('bookings', ['operation_key' => $operation], ['LIMIT' => 1]);
            if ($prior) {
                Access::assertBooking($prior[0]);
                $event = Store::find('bookingevents', ['bookings_id' => (int) $prior[0]['id'], 'type' => 'submitted'], ['LIMIT' => 1]);
                $payload = $event ? Store::decode($event[0]['payload_json']) : [];
                if (!hash_equals((string) ($payload['fingerprint'] ?? ''), $fingerprint)) { throw new \DomainException('Este identificador já foi usado com outros dados.'); }
                return (int) $prior[0]['id'];
            }
            $resourceEntities = array_values(array_unique(array_map(static fn(array $r): int => (int) $r['entities_id'], $resources)));
            if (count($resourceEntities) !== 1) { throw new \DomainException('Não combine recursos de entidades diferentes.'); }
            // Default edits use the same entity lock. Freeze one coherent version
            // for every resource despite the transaction's READ COMMITTED isolation.
            ApprovalPolicy::lockDefaults($resourceEntities[0]);
            $entity = null; $needsApproval = false; $ticketGroups = [];
            foreach ($resources as $resourceId => $resource) {
                Access::assertResource($resource, 'request');
                $resource = ApprovalPolicy::snapshot($resource);
                // New equipment requests require the responsibility term before
                // approval. Freeze the policy on this booking; historical bookings
                // and room policies remain untouched.
                $frozenPolicy = Store::decode($resource['policy_json'] ?? null);
                if (($resource['kind'] ?? '') === 'equipment') {
                    $frozenPolicy['signature_stage'] = 'submission';
                    $resource['policy_json'] = Store::json($frozenPolicy);
                }
                $resources[$resourceId] = $resource;
                if ($borrowerId !== Access::userId()) { Access::assertResource($resource, 'on_behalf'); }
                if ($entity !== null && $entity !== (int) $resource['entities_id']) { throw new \DomainException('Não combine recursos de entidades diferentes.'); }
                $entity = (int) $resource['entities_id'];
                $policy = Store::decode($resource['policy_json'] ?? null);
                self::assertBorrowerGroups($borrowerId, $policy);
                if (($resource['kind'] ?? '') === 'room' && (int) ($resource['capacity'] ?? 0) > 0 && $people > (int) $resource['capacity']) {
                    throw new \DomainException('A quantidade de pessoas excede a capacidade da sala.');
                }
                $duration = (strtotime($end) - strtotime($begin)) / 60;
                if ((int) ($policy['max_duration_minutes'] ?? 0) > 0 && $duration > (int) $policy['max_duration_minutes']) { throw new \DomainException('Período superior ao limite do recurso.'); }
                $reasons = CalendarBridge::conflicts($resource, $begin, $end);
                if ($reasons) { throw new \DomainException(implode(' ', $reasons)); }
                $ticketGroups[] = (int) $resource['groups_id_ticket'];
                if (!empty($policy['approval_required'])) { $needsApproval = true; }
            }
            if (count(array_unique($ticketGroups)) > 1) {
                throw new \DomainException('Recursos com equipes distintas exigem solicitações separadas nesta versão.');
            }
            self::assertApprovalDisclosure($resources);
            self::assertBorrowerEntity($borrowerId, (int) $entity);
            $now = Store::now();
            $equipmentOnly = $resources !== [] && count(array_filter($resources,
                static fn(array $r): bool => ($r['kind'] ?? '') === 'equipment')) === count($resources);
            $id = Store::insert('bookings', [
                'entities_id' => $entity, 'users_id_requester' => Access::userId(), 'users_id_borrower' => $borrowerId,
                'tickets_id' => null, 'begin' => $begin, 'end' => $end, 'status' => 'submitted', 'revision' => 1, 'scope_revision' => 1,
                'approved_revision' => 0, 'people_count' => $people, 'purpose' => $purpose,
                'borrower_snapshot_json' => Store::json($borrower), 'policy_snapshot_json' => Store::json(['resources' => array_map(static fn($r) => Store::decode($r['policy_json'] ?? null), $resources), 'approval_pools' => array_map(static fn($r) => $r['_approval_policy'], $resources), 'return_workflow' => $equipmentOnly ? 'two_terms_v1' : 'legacy']),
                'operation_key' => $operation, 'integration_status' => 'synced', 'date_creation' => $now, 'date_mod' => $now,
            ]);
            foreach ($resources as $resource) {
                Store::insert('bookinglines', [
                    'bookings_id' => $id, 'resources_id' => (int) $resource['id'], 'state' => 'requested',
                    'snapshot_json' => Store::json(['resource' => ResourceService::decorate($resource)]), 'accessories_json' => '[]',
                    'reservationitems_id' => 0, 'reservations_id' => null, 'calendar_mode' => 'plugin_only',
                ]);
            }
            $booking = Store::row('bookings', $id);
            $ticket = TicketBridge::connect($booking, array_values($resources), (int) ($input['tickets_id'] ?? 0));
            Store::update('bookings', $id, ['tickets_id' => $ticket]); $booking['tickets_id'] = $ticket;
            $lines = Store::find('bookinglines', ['bookings_id' => $id]);
            TicketBridge::requestApprovals($booking, $lines, $resources);
            Store::event($id, 'submitted', ['fingerprint' => $fingerprint, 'borrower' => $borrowerId, 'tickets_id' => $ticket]);
            TicketBridge::enqueue($id, 'followup', ['message' => 'Solicitação de reserva registrada. A lista de aprovadores e a situação de cada recurso estão na aba Reserva Plus. Uma pessoa autorizada decide cada recurso; os demais candidatos não precisam repetir a decisão. Consulte também os termos e a devolução.'], $operation . ':submitted');
            foreach (Store::find('bookingapprovals', ['bookings_id' => $id, 'status' => 'pending']) as $approval) {
                if ((int) $approval['ticketvalidations_id'] > 0) {
                    TicketBridge::enqueue($id, 'approval', ['ticketvalidations_id' => (int) $approval['ticketvalidations_id']], $operation . ':approval:' . $approval['id']);
                }
            }
            if (!$needsApproval) { self::allocate($booking, $lines, $resources); }
            $created = true;
            return $id;
        });
        if ($created) { self::afterCommit($id, 'Solicitação de reserva registrada. Consulte a aba ReservaPlus para acompanhar o fluxo.'); }
        return $id;
    }

    /** Native observers can read ticket attachments: prevent disclosure across private resource pools. */
    public static function assertApprovalDisclosure(array $resources): void
    {
        if (count($resources) < 2) { return; }
        $allTargets = [];
        foreach ($resources as $resource) {
            if (empty(ResourceService::policy($resource)['approval_required'])) { continue; }
            foreach (TicketBridge::approvalTargets($resource) as $target) { $allTargets[$target['itemtype'] . ':' . $target['id']] = $target; }
        }
        foreach ($resources as $resource) {
            $policy = ResourceService::policy($resource);
            if ($policy['requester_groups_ids'] === []) { continue; }
            $allowed = [];
            foreach (TicketBridge::approvalTargets($resource) as $target) { $allowed[$target['itemtype'] . ':' . $target['id']] = true; }
            foreach ($allTargets as $key => $target) {
                if (isset($allowed[$key]) || ($target['itemtype'] === \Group::class && in_array($target['id'], $policy['requester_groups_ids'], true))) { continue; }
                throw new \DomainException('Esta seleção combina um recurso restrito com aprovadores que não podem consultar todos os itens do chamado. Separe os recursos em solicitações ou peça à equipe para revisar os grupos autorizados.');
            }
        }
    }

    private static function assertBorrowerEntity(int $id, int $entity): void
    {
        if (!in_array($entity, array_map('intval', \Profile_User::getUserEntities($id, true)), true)) {
            throw new \DomainException('O beneficiário não possui acesso a esta entidade.');
        }
    }

    private static function assertBorrowerGroups(int $id, array $policy): void
    {
        global $DB;
        $groups = array_values(array_filter(array_map('intval', (array) ($policy['requester_groups_ids'] ?? []))));
        if (!$groups) { return; }
        if (!$DB->request(['FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => $id, 'groups_id' => $groups], 'LIMIT' => 1])->current()) {
            throw new \DomainException('O beneficiário não pertence a um grupo autorizado a reservar este recurso.');
        }
    }

    private static function lines(int $id): array { return Store::find('bookinglines', ['bookings_id' => $id], ['ORDER' => ['resources_id ASC']]); }

    private static function allocate(array $booking, array $lines, array $resources): void
    {
        foreach ($lines as $line) { CalendarBridge::allocate($booking, $line, $resources[(int) $line['resources_id']]); }
        // A submission-stage term may already be sealed before approval.
        // Do not start a new 24-hour signing hold for an already signed scope.
        $needsSignature = self::signaturePurposes($lines) !== []
            && !self::signaturesSatisfied((int) $booking['id'], $lines);
        Store::update('bookings', (int) $booking['id'], [
            'status' => 'approved', 'approved_revision' => (int) $booking['scope_revision'],
            'hold_expires_at' => $needsSignature ? date('Y-m-d H:i:s', time() + 86400) : null,
        ]);
    }

    public static function signaturePurposes(array $lines): array
    {
        $purposes = [];
        foreach ($lines as $line) {
            $snapshot = Store::decode($line['snapshot_json'] ?? null); $r = $snapshot['resource'] ?? $snapshot;
            $policy = Store::decode($r['policy_json'] ?? null);
            $stage = $policy['signature_stage'] ?? 'none';
            if ($stage === 'submission') { $purposes[] = 'request'; }
            if ($stage === 'approval' || $stage === 'handover') { $purposes[] = 'handover'; }
        }
        return array_values(array_unique($purposes));
    }

    /** Read-only: shared by signing, custody release and hold expiry. */
    public static function signaturesSatisfied(int $id, ?array $lines = null): bool
    {
        foreach (self::signaturePurposes($lines ?? self::lines($id)) as $purpose) {
            if (!DocumentService::isSatisfied($id, $purpose)) { return false; }
        }
        return true;
    }

    /** Frozen per new equipment booking: legacy and room workflows are untouched. */
    public static function simplifiedReturn(array $booking): bool
    {
        return (Store::decode($booking['policy_snapshot_json'] ?? null)['return_workflow'] ?? '')
            === 'two_terms_v1';
    }

    /** Complete only after actual inspection and reconciliation. */
    private static function allPhysicalReturnsComplete(int $bookingId): bool
    {
        $lines = self::lines($bookingId);
        if ($lines === []) { return false; }
        foreach ($lines as $line) {
            if (($line['state'] ?? '') !== 'ready' || empty($line['inspection_at'])
                || !Booking::accessoriesComplete(Store::decode($line['accessories_json'] ?? null))) {
                return false;
            }
        }
        return true;
    }

    /** A submitted equipment request remains in its signing step until the immutable
     * source PDF has one valid acceptance per required account and is finalized. */
    public static function awaitsInitialSignature(array $booking, ?array $lines = null): bool
    {
        if (!in_array((string) ($booking['status'] ?? ''), ['submitted', 'approved'], true)) {
            return false;
        }
        $lines ??= self::lines((int) $booking['id']);
        return in_array('request', self::signaturePurposes($lines), true)
            && !DocumentService::isSatisfied((int) $booking['id'], 'request');
    }

    /** HTTP tokens and controls are not part of a business-command identity. */
    public static function commandFingerprint(int $id, string $action, array $input): string
    {
        $domain = [];
        foreach (['expected_revision', 'lines_ids', 'reason', 'note', 'condition', 'received_at', 'deliverer', 'accessories', 'result', 'findings', 'begin', 'end'] as $field) {
            if (array_key_exists($field, $input)) { $domain[$field] = $input[$field]; }
        }
        $canonical = static function (array $value) use (&$canonical): array {
            ksort($value);
            foreach ($value as &$part) { if (is_array($part)) { $part = $canonical($part); } }
            return $value;
        };
        return hash('sha256', Store::json([$id, $action, $canonical($domain)]));
    }

    public static function act(int $id, string $action, array $input, string $key): array
    {
        $caps = ['approve' => 'approve', 'reject' => 'approve', 'cancel' => 'request', 'issue' => 'issue', 'receive' => 'receive', 'inspect' => 'inspect', 'complete' => 'inspect', 'extend' => 'approve'];
        if (!isset($caps[$action])) { throw new \InvalidArgumentException('Ação desconhecida.'); }
        $operation = self::operationKey($key);
        $fingerprint = self::commandFingerprint($id, $action, $input);
        $changed = false;
        Store::transaction(static function () use ($id, $action, $input, $operation, $fingerprint, $caps, &$changed): void {
            $booking = Store::lock('bookings', $id);
            if ($action === 'cancel' && Access::booking($booking, 'manage')) { /* Scoped staff cancellation. */ }
            elseif ($action === 'extend') {
                if (!Access::canReschedule($booking)) { throw new \DomainException('Sem autorização para alterar o período de todos os recursos da solicitação.'); }
            }
            else { Access::assertBooking($booking, $caps[$action]); }
            $previous = Store::find('bookingcommands', ['operation_key' => $operation], ['LIMIT' => 1]);
            if ($previous) {
                $result = Store::decode($previous[0]['result_json']);
                if ((int) $previous[0]['bookings_id'] !== $id || !hash_equals((string) ($result['fingerprint'] ?? ''), $fingerprint)) { throw new \DomainException('Identificador de operação já utilizado com outros dados.'); }
                return;
            }
            if ((int) ($input['expected_revision'] ?? 0) !== (int) $booking['revision']) { throw new \DomainException('A solicitação mudou. Recarregue a página antes de continuar.'); }
            $lines = self::lines($id); $resources = [];
            foreach ($lines as $line) { $rid = (int) $line['resources_id']; $resources[$rid] = Store::lock('resources', $rid); }
            if (in_array($action, ['receive', 'inspect'], true) && empty($input['lines_ids'])) {
                throw new \DomainException('Selecione explicitamente os itens recebidos ou conferidos.');
            }
            $selected = self::selectedLines($lines, $input['lines_ids'] ?? []);
            if (in_array($booking['status'], ['completed', 'cancelled', 'rejected'], true)) { throw new \DomainException('Esta solicitação já foi encerrada.'); }
            $reason = mb_substr(trim((string) ($input['reason'] ?? $input['note'] ?? '')), 0, 5000);
            switch ($action) {
                case 'approve':
                case 'reject':
                    if ($booking['status'] !== 'submitted') { throw new \DomainException('A solicitação não está aguardando aprovação.'); }
                    if ($action === 'reject' && $reason === '') { throw new \DomainException('Informe o motivo da recusa.'); }
                    if ($action === 'approve') {
                        foreach (self::signaturePurposes($lines) as $requiredPurpose) {
                            if ($requiredPurpose === 'request' && !DocumentService::isSatisfied($id, 'request')) {
                                throw new \DomainException('O termo de responsabilidade precisa ser assinado antes da aprovação da reserva.');
                            }
                        }
                    }
                    $madeDecision = false;
                    foreach ($selected as $line) {
                        $required = !empty(ResourceService::policy(Access::lineResource($line))['approval_required']);
                        if (!$required) { continue; }
                        if (!Access::canApproveLine($booking, $line)) { throw new \DomainException('A seleção contém um recurso que você não está autorizado a aprovar.'); }
                        $pending = Store::find('bookingapprovals', ['bookings_id' => $id, 'lines_id' => (int) $line['id'], 'status' => 'pending'], ['LIMIT' => 1]);
                        if ($pending === []) { throw new \DomainException('A decisão de um dos recursos já foi registrada. Recarregue a solicitação.'); }
                        TicketBridge::decideLine($booking, $line, $action === 'approve', $reason); $madeDecision = true;
                    }
                    if (!$madeDecision) { throw new \DomainException('Não há aprovação pendente para os recursos selecionados.'); }
                    if ($action === 'reject') {
                        foreach (Store::find('bookingapprovals', ['bookings_id' => $id, 'status' => 'pending']) as $pending) { Store::update('bookingapprovals', (int) $pending['id'], ['status' => 'superseded', 'date_mod' => Store::now()]); }
                        Store::update('bookings', $id, ['status' => 'rejected']);
                    }
                    elseif (!Store::find('bookingapprovals', ['bookings_id' => $id, 'status' => 'pending'], ['LIMIT' => 1])) { self::allocate($booking, $lines, $resources); }
                    break;
                case 'cancel':
                    foreach ($lines as $line) { if (!in_array($line['state'], ['requested', 'allocated'], true)) { throw new \DomainException('Após a entrega, use devolução ou recolhimento; não cancele a custódia.'); } }
                    foreach ($lines as $line) { if ($line['state'] === 'allocated') { CalendarBridge::release($line); } }
                    foreach (Store::find('bookingapprovals', ['bookings_id' => $id, 'status' => 'pending']) as $pending) { Store::update('bookingapprovals', (int) $pending['id'], ['status' => 'superseded', 'date_mod' => Store::now()]); }
                    Store::update('bookings', $id, ['status' => 'cancelled', 'hold_expires_at' => null]);
                    break;
                case 'issue':
                    if ($booking['status'] !== 'approved') { throw new \DomainException('A solicitação ainda não foi aprovada/alocada.'); }
                    $borrower = new \User();
                    if (!$borrower->getFromDB((int) $booking['users_id_borrower']) || empty($borrower->fields['is_active']) || !empty($borrower->fields['is_deleted'])) {
                        throw new \DomainException('O beneficiário está inativo ou indisponível; não é possível entregar o recurso.');
                    }
                    self::assertBorrowerEntity((int) $booking['users_id_borrower'], (int) $booking['entities_id']);
                    foreach (self::signaturePurposes($lines) as $purpose) { if (!DocumentService::isSatisfied($id, $purpose)) { throw new \DomainException('Há termo obrigatório aguardando assinatura válida.'); } }
                    $condition = mb_substr(trim((string) ($input['condition'] ?? '')), 0, 5000);
                    if ($condition === '') { throw new \DomainException('Registre a conferência e condição de entrega.'); }
                    $issueAt = Store::now();
                    if (!self::canIssueAt((string) $booking['begin'], (string) $booking['end'], $issueAt)) {
                        throw new \DomainException('A entrega é permitida até 15 minutos antes do início aprovado e até o término da reserva.');
                    }
                    foreach ($lines as $line) {
                        $resource = $resources[(int) $line['resources_id']];
                        Access::assertResource($resource, 'issue');
                        self::assertBorrowerGroups((int) $booking['users_id_borrower'], Store::decode($resource['policy_json'] ?? null));
                        foreach (Store::find('bookinglines', ['resources_id' => (int) $line['resources_id'], 'state' => 'issued']) as $other) {
                            if ((int) $other['bookings_id'] !== $id) { throw new \DomainException('Este recurso ainda está fisicamente com outro usuário.'); }
                        }
                        // Validate actual early custody against other bookings and native GLPI holds.
                        $effectiveBegin = $issueAt < $booking['begin'] ? $issueAt : $booking['begin'];
                        $conflicts = CalendarBridge::conflicts($resource, $effectiveBegin, $booking['end'], $id);
                        if ($conflicts) { throw new \DomainException(implode(' ', $conflicts)); }
                        $snapshot = Store::decode($line['snapshot_json']); $r = $snapshot['resource'] ?? $snapshot;
                        $accessories = [];
                        foreach (Store::decode($r['accessories_json'] ?? null) as $item) { $accessories[] = ['name' => (string) $item['name'], 'issued' => (int) $item['quantity'], 'received' => 0]; }
                        Store::update('bookinglines', (int) $line['id'], ['state' => 'issued', 'issued_at' => Store::now(), 'accessories_json' => Store::json($accessories)]);
                        Store::event($id, 'issued', ['condition' => $condition, 'accessories' => $accessories], (int) $line['id']);
                    }
                    Store::update('bookings', $id, ['status' => 'issued', 'hold_expires_at' => null]);
                    break;
                case 'receive':
                    if (!in_array($booking['status'], ['issued', 'returning'], true)) { throw new \DomainException('Não há entrega física pendente nesta solicitação.'); }
                    $received = empty($input['received_at']) ? Store::now() : Booking::date((string) $input['received_at']);
                    if ($received > Store::now()) { throw new \DomainException('O recebimento não pode estar no futuro.'); }
                    if ($received < date('Y-m-d H:i:s', time() - 300) && $reason === '') { throw new \DomainException('Informe o motivo para registrar um recebimento anterior.'); }
                    foreach ($selected as $line) {
                        if (!in_array($line['state'], ['issued', 'received', 'quarantine', 'maintenance'], true)) { throw new \DomainException('Recurso não está pendente de recebimento.'); }
                        if (!empty($line['issued_at']) && $received < $line['issued_at']) { throw new \DomainException('Recebimento anterior à entrega.'); }
                        $deltas = (array) ($input['accessories'][(int) $line['id']] ?? []);
                        if ($line['state'] !== 'issued' && array_sum(array_map('intval', $deltas)) < 1) { throw new \DomainException('Informe os acessórios recebidos nesta nova visita.'); }
                        $accessories = Booking::receiveAccessories(Store::decode($line['accessories_json']), $deltas);
                        $blocked = in_array($line['state'], ['quarantine', 'maintenance'], true);
                        Store::update('bookinglines', (int) $line['id'], ['state' => $blocked ? $line['state'] : 'received', 'received_at' => $line['received_at'] ?: $received,
                            'inspection_at' => null, 'accessories_json' => Store::json($accessories)]);
                        if (!$blocked) { Store::update('resources', (int) $line['resources_id'], ['operational_state' => 'inspection', 'date_mod' => Store::now()]); }
                        Store::event($id, 'received', ['deliverer' => mb_substr((string) ($input['deliverer'] ?? ''), 0, 255), 'note' => $reason, 'accessories_delta' => $deltas], (int) $line['id'], $received);
                    }
                    Store::update('bookings', $id, ['status' => 'returning']);
                    break;
                case 'inspect':
                    if (self::simplifiedReturn($booking) && !DocumentService::isSatisfied($id, 'return_receipt')) {
                        throw new \DomainException('Confirme a assinatura do termo de devolução antes da conferência técnica.');
                    }
                    $result = (string) ($input['result'] ?? '');
                    if (!in_array($result, ['pass', 'quarantine', 'maintenance'], true)) { throw new \DomainException('Selecione o resultado da conferência.'); }
                    $findings = mb_substr(trim((string) ($input['findings'] ?? '')), 0, 10000);
                    if ($findings === '') { throw new \DomainException('Registre os testes e as constatações; uma verificação não realizada não equivale a aprovação.'); }
                    foreach ($selected as $line) {
                        $r = $resources[(int) $line['resources_id']];
                        $room = ($r['kind'] ?? '') === 'room';
                        if (!in_array($line['state'], ['received', 'quarantine', 'maintenance'], true) && !($room && in_array($line['state'], ['allocated', 'issued'], true))) { throw new \DomainException('Receba o equipamento antes da conferência.'); }
                        if (!$room && empty($line['received_at'])) { throw new \DomainException('Não há recebimento físico confirmado para este equipamento.'); }
                        if ($room && Store::now() < $booking['begin']) { throw new \DomainException('O uso da sala ainda não começou.'); }
                        if ($room) {
                            foreach (self::signaturePurposes([$line]) as $purpose) { if (!DocumentService::isSatisfied($id, $purpose)) { throw new \DomainException('O termo de uso da sala ainda está pendente.'); } }
                        }
                        if ($result === 'pass' && !Booking::accessoriesComplete(Store::decode($line['accessories_json']))) { throw new \DomainException('Há acessórios pendentes. Concilie os itens antes de liberar este recurso.'); }
                        $state = $result === 'pass' ? 'ready' : $result;
                        $inspection = ['result' => $result, 'findings' => $findings, 'users_id' => Access::userId(), 'at' => Store::now()];
                        Store::update('bookinglines', (int) $line['id'], ['state' => $state, 'inspection_at' => Store::now(), 'inspector_users_id' => Access::userId(), 'inspection_json' => Store::json($inspection)]);
                        Store::update('resources', (int) $line['resources_id'], ['operational_state' => $result === 'pass' ? 'available' : $result, 'date_mod' => Store::now()]);
                        if ($result === 'pass') { CalendarBridge::releaseTail($line); }
                        Store::event($id, 'inspected', $inspection, (int) $line['id']);
                    }
                    Store::update('bookings', $id, ['status' => 'returning', 'hold_expires_at' => null]);
                    if (self::simplifiedReturn($booking) && self::allPhysicalReturnsComplete($id)) {
                        Store::update('bookings', $id, ['status' => 'completed']);
                        Store::event($id, 'completed', ['method' => 'automatic_after_inspection']);
                        TicketBridge::solve($booking);
                    }
                    break;
                case 'complete':
                    foreach ($lines as $line) {
                        if ($line['state'] !== 'ready' || empty($line['inspection_at']) || !Booking::accessoriesComplete(Store::decode($line['accessories_json']))) {
                            throw new \DomainException('Há recebimentos, acessórios, conferências ou reparos pendentes. Libere cada recurso por nova conferência antes de concluir.');
                        }
                    }
                    if (self::simplifiedReturn($booking)) {
                        if (!DocumentService::isSatisfied($id, 'return_receipt')) {
                            throw new \DomainException('Conclua a assinatura do termo de devolução.');
                        }
                    } elseif (!DocumentService::isSatisfied($id, 'return_inspection')) {
                        throw new \DomainException('Conclua as assinaturas obrigatórias do termo de conferência.');
                    }
                    Store::update('bookings', $id, ['status' => 'completed', 'hold_expires_at' => null]);
                    TicketBridge::solve($booking);
                    break;
                case 'extend':
                    if (!Access::canReschedule($booking)) { throw new \DomainException('A alteração de período exige autorização para todos os recursos da solicitação.'); }
                    if (!in_array($booking['status'], ['submitted', 'approved'], true)) { throw new \DomainException('Nesta versão, altere o período antes da entrega; empréstimos ativos exigem devolução e nova solicitação.'); }
                    [$newBegin, $newEnd] = Booking::period((string) ($input['begin'] ?? $booking['begin']), (string) ($input['end'] ?? ''));
                    if ($newEnd <= Store::now()) { throw new \DomainException('Informe um período futuro.'); }
                    if ($newBegin === (string) $booking['begin'] && $newEnd === (string) $booking['end']) {
                        // No-op: do not supersede native approvals, calendar hold or signed scope.
                        return;
                    }
                    foreach ($resources as $resource) {
                        $p = Store::decode($resource['policy_json'] ?? null);
                        $duration = (strtotime($newEnd) - strtotime($newBegin)) / 60;
                        if ((int) ($p['max_duration_minutes'] ?? 0) > 0 && $duration > (int) $p['max_duration_minutes']) { throw new \DomainException('Período superior ao limite do recurso.'); }
                        if (($resource['kind'] ?? '') === 'room' && (int) ($resource['capacity'] ?? 0) > 0 && (int) $booking['people_count'] > (int) $resource['capacity']) { throw new \DomainException('A capacidade atual da sala é insuficiente.'); }
                        self::assertBorrowerGroups((int) $booking['users_id_borrower'], $p);
                        $conflicts = CalendarBridge::conflicts($resource, $newBegin, $newEnd, $id);
                        if ($conflicts) { throw new \DomainException(implode(' ', $conflicts)); }
                    }
                    // A material change creates a fresh request scope and new native approvals.
                    foreach ($lines as $line) { if ($line['state'] === 'allocated') { CalendarBridge::release($line); } }
                    foreach ($lines as $line) { Store::update('bookinglines', (int) $line['id'], ['state' => 'requested', 'reservations_id' => null]); }
                    // Existing native approvals remain historical. New approvals use the same plugin row identity via refreshed relation below.
                    Store::update('bookings', $id, ['begin' => $newBegin, 'end' => $newEnd, 'status' => 'submitted', 'scope_revision' => (int) $booking['scope_revision'] + 1, 'approved_revision' => 0, 'hold_expires_at' => null]);
                    $new = Store::row('bookings', $id);
                    TicketBridge::refreshApprovals($new, self::lines($id));
                    if (!Store::find('bookingapprovals', ['bookings_id' => $id, 'status' => 'pending'], ['LIMIT' => 1])) { self::allocate($new, self::lines($id), $resources); }
                    break;
            }
            Store::update('bookings', $id, ['revision' => (int) $booking['revision'] + 1, 'date_mod' => Store::now()]);
            Store::event($id, $action, ['reason' => $reason, 'revision' => (int) $booking['revision'] + 1]);
            $followup = 'ReservaPlus #' . $id . ': ' . self::actionLabel($action) . '.';
            if ($action === 'approve') {
                $pendingLines = array_unique(array_map(static fn(array $row): int => (int) $row['lines_id'], Store::find('bookingapprovals', ['bookings_id' => $id, 'status' => 'pending'])));
                $followup .= $pendingLines === [] ? ' Todos os recursos foram aprovados; confira os termos antes da entrega.'
                    : ' Ainda há ' . count($pendingLines) . ' recurso(s) aguardando decisão. O andamento da reserva está na aba Reserva Plus.';
            }
            TicketBridge::enqueue($id, 'followup', ['message' => $followup], $operation . ':followup');
            Store::insert('bookingcommands', ['bookings_id' => $id, 'users_id_actor' => Access::userId(), 'operation_key' => $operation, 'action' => $action,
                'result_json' => Store::json(['fingerprint' => $fingerprint, 'revision' => (int) $booking['revision'] + 1]), 'date_creation' => Store::now()]);
            $changed = true;
        });
        if ($changed) { self::afterCommit($id, 'ReservaPlus #' . $id . ': ' . self::actionLabel($action) . '.'); }
        return self::get($id);
    }

    private static function selectedLines(array $lines, mixed $ids): array
    {
        $all = [];
        foreach ($lines as $line) { $all[(int) $line['id']] = $line; }
        if ($ids === [] || $ids === null) { return $all; }
        if (!is_array($ids)) { throw new \InvalidArgumentException('Seleção de itens inválida.'); }
        $selected = [];
        foreach ($ids as $id) {
            if (!ctype_digit((string) $id) || !isset($all[(int) $id])) { throw new \DomainException('Item não pertence à solicitação.'); }
            $selected[(int) $id] = $all[(int) $id];
        }
        return $selected;
    }

    private static function actionLabel(string $action): string
    {
        return ['approve' => 'aprovação registrada', 'reject' => 'solicitação recusada', 'cancel' => 'solicitação cancelada', 'issue' => 'entrega registrada',
            'receive' => 'recebimento físico registrado; confira itens e acessórios pendentes', 'inspect' => 'conferência registrada', 'complete' => 'devolução concluída', 'extend' => 'período revisado; confira novas aprovações e termos'][$action] ?? $action;
    }

    private static function afterCommit(int $id, string $message): void
    {
        // Prepare terms only after the business transaction. Failure never rolls back custody.
        try {
            DocumentService::prepareForCurrentStage($id);
        } catch (\Throwable $e) {
            \Toolbox::logInFile('php-errors', 'ReservaPlus automatic term #'.$id.': '.$e->getMessage()."\n");
            try {
                Store::update('bookings', $id, [
                    'integration_status' => 'attention',
                    'integration_error' => 'Termo automático pendente: '.mb_substr($e->getMessage(), 0, 900),
                ]);
            } catch (\Throwable) {
                // Main operation is already committed.
            }
        }
        // Physical facts survive downstream failures. Never roll receipt back because a ticket followup failed.
        try { TicketBridge::deliverOutbox($id); }
        catch (\Throwable $e) {
            Store::update('bookings', $id, ['integration_status' => 'attention', 'integration_error' => 'Acompanhamento pendente: ' . mb_substr($e->getMessage(), 0, 1000)]);
        }
    }

    public static function expireHolds(): int
    {
        if (!\Session::isCron() && !Access::right('admin')) { throw new \DomainException('Operação exclusiva da ação automática ou administrador.'); }
        $count = 0;
        foreach (Store::find('bookings', ['status' => 'approved', 'hold_expires_at' => ['<', Store::now()]]) as $candidate) {
            if (!\Session::isCron() && !Access::entity((int) $candidate['entities_id'])) { continue; }
            Store::transaction(static function () use ($candidate, &$count): void {
                $booking = Store::lock('bookings', (int) $candidate['id']);
                if ($booking['status'] !== 'approved' || empty($booking['hold_expires_at']) || $booking['hold_expires_at'] >= Store::now()) { return; }
                $lines = self::lines((int) $booking['id']);
                foreach ($lines as $line) { Store::lock('resources', (int) $line['resources_id']); if ($line['state'] !== 'allocated') { return; } }
                if (self::signaturesSatisfied((int) $booking['id'], $lines)) { Store::update('bookings', (int) $booking['id'], ['hold_expires_at' => null]); return; }
                foreach ($lines as $line) { CalendarBridge::release($line); }
                Store::update('bookings', (int) $booking['id'], ['status' => 'cancelled', 'hold_expires_at' => null, 'revision' => (int) $booking['revision'] + 1, 'date_mod' => Store::now()]);
                Store::event((int) $booking['id'], 'hold_expired', ['reason' => 'Prazo de assinatura/retirada expirado antes da entrega.']); ++$count;
                TicketBridge::enqueue((int) $booking['id'], 'followup', ['message' => 'A alocação expirou antes da entrega, por pendência de assinatura. Abra nova solicitação se ainda precisar do recurso.'], 'expiry:' . $booking['id'] . ':' . $booking['revision']);
            });
        }
        return $count;
    }
}
