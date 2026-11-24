<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Read-only operational home. Counts are scoped before display pagination. */
final class TechnicianDashboard
{
    private const OPEN = ['submitted', 'approved', 'issued', 'returning'];
    private const STAFF_CAPS = ['manage', 'approve', 'issue', 'receive', 'inspect', 'audit'];
    private const PREVIEW = 5;

    public static function isStaff(): bool
    {
        foreach (self::STAFF_CAPS as $cap) {
            if (Access::right($cap)) { return true; }
        }
        return Access::right('admin');
    }

    public static function queues(): array
    {
        return [
            'approvals' => ['label' => __('Aprovações pendentes', 'reservaplus'), 'hint' => __('Solicitações que sua equipe pode aprovar.', 'reservaplus'), 'icon' => 'ti-checklist', 'tone' => 'blue'],
            'ready' => ['label' => __('Prontas para entrega', 'reservaplus'), 'hint' => __('No período de uso e com os termos necessários.', 'reservaplus'), 'icon' => 'ti-package-export', 'tone' => 'green'],
            'overdue' => ['label' => __('Devoluções em atraso', 'reservaplus'), 'hint' => __('Itens ou acessórios ainda não recebidos.', 'reservaplus'), 'icon' => 'ti-clock-exclamation', 'tone' => 'red'],
            'inspection' => ['label' => __('Aguardando conferência', 'reservaplus'), 'hint' => __('Recebidos, retidos ou em manutenção.', 'reservaplus'), 'icon' => 'ti-clipboard-check', 'tone' => 'amber'],
            'loans' => ['label' => __('Em uso ou a devolver', 'reservaplus'), 'hint' => __('Recursos e acessórios que permanecem com os usuários.', 'reservaplus'), 'icon' => 'ti-device-laptop', 'tone' => 'blue'],
            'signatures' => ['label' => __('Assinaturas pendentes', 'reservaplus'), 'hint' => __('Termos necessários para a próxima etapa.', 'reservaplus'), 'icon' => 'ti-writing-sign', 'tone' => 'violet'],
            'integration' => ['label' => __('Publicação pendente', 'reservaplus'), 'hint' => __('Chamados ou documentos que precisam de atenção.', 'reservaplus'), 'icon' => 'ti-file-alert', 'tone' => 'amber'],
        ];
    }

    private static function owned(array $booking): bool
    {
        return in_array(Access::userId(), [(int) ($booking['users_id_requester'] ?? 0), (int) ($booking['users_id_borrower'] ?? 0)], true);
    }

    public static function belongsToTeam(array $booking): bool
    {
        return self::teamAccess($booking);
    }

    private static function teamAccess(array $booking, array $caps = self::STAFF_CAPS): bool
    {
        foreach ($caps as $cap) {
            if (Access::booking($booking, $cap)) { return true; }
        }
        return false;
    }

    private static function lines(array $booking): array
    {
        return is_array($booking['lines'] ?? null) ? $booking['lines'] : Store::find('bookinglines', ['bookings_id' => (int) $booking['id']], ['ORDER' => 'id ASC']);
    }

    /** Pure physical-state predicates shared by the dashboard and regression tests. */
    public static function physicalFlags(array $booking, array $lines, string $now): array
    {
        $flags = ['allocated' => false, 'loans' => false, 'overdue' => false, 'overdue_items' => false, 'overdue_accessories' => false, 'pending_accessories' => false, 'inspection' => false, 'all_inspected' => false];
        if (!in_array($booking['status'] ?? '', self::OPEN, true)) { return $flags; }
        $flags['all_inspected'] = $lines !== [];
        foreach ($lines as $line) {
            $state = (string) ($line['state'] ?? '');
            $flags['allocated'] = $flags['allocated'] || $state === 'allocated';
            $snapshot = Store::decode($line['snapshot_json'] ?? null);
            $room = (($snapshot['resource'] ?? $snapshot)['kind'] ?? '') === 'room';
            $inCustody = !$room && $state === 'issued' && empty($line['received_at']);
            $accessoriesPending = false;
            if (!$room) {
                foreach (Store::decode($line['accessories_json'] ?? null) as $accessory) {
                    if ((int) ($accessory['issued'] ?? 0) > (int) ($accessory['received'] ?? 0)) { $accessoriesPending = true; break; }
                }
            }
            $flags['pending_accessories'] = $flags['pending_accessories'] || $accessoriesPending;
            $flags['overdue_items'] = $flags['overdue_items'] || ($inCustody && (string) ($booking['end'] ?? '') < $now);
            $flags['overdue_accessories'] = $flags['overdue_accessories'] || ($accessoriesPending && (string) ($booking['end'] ?? '') < $now);
            $roomInUse = $room && in_array($state, ['allocated', 'issued'], true) && (string) $booking['begin'] <= $now && (string) $booking['end'] > $now;
            $roomNeedsInspection = $room && in_array($state, ['allocated', 'issued'], true) && (string) $booking['end'] <= $now && empty($line['inspection_at']);
            $flags['loans'] = $flags['loans'] || $inCustody || $accessoriesPending || $roomInUse;
            $flags['overdue'] = $flags['overdue_items'] || $flags['overdue_accessories'];
            $flags['inspection'] = $flags['inspection'] || $roomNeedsInspection || in_array($state, ['received', 'quarantine', 'maintenance'], true);
            $flags['all_inspected'] = $flags['all_inspected'] && !empty($line['inspection_at']);
        }
        return $flags;
    }

    private static function requiredPurposes(array $booking, array $lines): array
    {
        if (($booking['status'] ?? '') === 'submitted') {
            foreach ($lines as $line) {
                $snapshot = Store::decode($line['snapshot_json'] ?? null);
                $resource = $snapshot['resource'] ?? $snapshot;
                if ((Store::decode($resource['policy_json'] ?? null)['signature_stage'] ?? 'none') === 'submission') { return ['request']; }
            }
            return [];
        }
        $flags = self::physicalFlags($booking, $lines, Store::now());
        if (BookingService::threeTermReturn($booking)) {
            $hasInspection = false;
            foreach ($lines as $line) {
                $hasInspection = $hasInspection || !empty($line['inspection_at']);
            }
            if ($hasInspection) {
                return DocumentService::isSatisfied((int) $booking['id'], 'return_inspection')
                    ? ['return_receipt'] : ['return_inspection'];
            }
            return [];
        }
        if (BookingService::simplifiedReturn($booking)) {
            foreach ($lines as $line) {
                if (!empty($line['received_at'])) { return ['return_receipt']; }
            }
        }
        if ($flags['all_inspected']) { return ['return_inspection']; }
        if ($flags['allocated']) { return BookingService::signaturePurposes($lines); }
        return [];
    }

    private static function pendingSignature(array $booking, array $lines): bool
    {
        foreach (self::requiredPurposes($booking, $lines) as $purpose) {
            if (!DocumentService::isSatisfied((int) $booking['id'], $purpose)) { return true; }
        }
        return false;
    }

    /** Root request list uses this exact predicate for a card's destination. */
    public static function matchesQueue(array $booking, string $queue): bool
    {
        if (!isset(self::queues()[$queue]) || ($queue !== 'integration' && !in_array($booking['status'] ?? '', self::OPEN, true))
            || !Access::right('read') || !Access::entity((int) ($booking['entities_id'] ?? -1))) { return false; }
        $lines = self::lines($booking);
        $flags = self::physicalFlags($booking, $lines, Store::now());
        switch ($queue) {
            case 'approvals':
                return $booking['status'] === 'submitted' && TicketBridge::pendingForActor($booking);
            case 'ready':
                return $flags['allocated'] && Access::booking($booking, 'issue')
                    && (string) $booking['begin'] <= Store::now() && (string) $booking['end'] > Store::now()
                    && (empty($booking['hold_expires_at']) || $booking['hold_expires_at'] > Store::now() || BookingService::signaturesSatisfied((int) $booking['id'], $lines))
                    && !self::pendingSignature($booking, $lines) && self::readyResources($booking, $lines);
            case 'loans':
            case 'overdue':
                return $flags[$queue] && self::teamAccess($booking, ['receive', 'issue', 'manage', 'audit']);
            case 'inspection':
                return $flags['inspection'] && Access::booking($booking, 'inspect');
            case 'signatures':
                return ((self::owned($booking) && Access::booking($booking, 'read')) || self::teamAccess($booking)) && self::pendingSignature($booking, $lines);
            case 'integration':
                if (!self::teamAccess($booking, ['manage', 'audit'])) { return false; }
                if (($booking['integration_status'] ?? 'synced') !== 'synced') { return true; }
                foreach (DocumentService::forBooking((int) $booking['id']) as $document) {
                    if (!$document['is_current']) { continue; }
                    if ($document['status'] === 'artifact_error' || ($document['status'] === 'complete' && $document['attachment_status'] !== 'attached')) { return true; }
                }
                return false;
        }
        return false;
    }

    private static function readyResources(array $booking, array $lines): bool
    {
        foreach ($lines as $line) {
            if (($line['state'] ?? '') !== 'allocated') { continue; }
            $resource = Store::row('resources', (int) $line['resources_id']);
            if (($resource['publication'] ?? '') !== 'published' || ($resource['operational_state'] ?? '') !== 'available'
                || CalendarBridge::conflicts($resource, (string) $booking['begin'], (string) $booking['end'], (int) $booking['id']) !== []) { return false; }
        }
        return true;
    }

    private static function queueVisible(string $queue): bool
    {
        $caps = match ($queue) {
            'approvals' => ['approve'], 'ready' => ['issue'], 'inspection' => ['inspect'],
            'loans', 'overdue' => ['receive', 'issue', 'manage', 'audit'],
            'integration' => ['manage', 'audit'], default => self::STAFF_CAPS,
        };
        foreach ($caps as $cap) { if (Access::right($cap)) { return true; } }
        return false;
    }

    /** Counts use every authorized matching row; only the preview is limited. */
    public static function data(): array
    {
        Access::assertRight('read');
        $staff = self::isStaff();
        $data = ['staff' => $staff, 'queues' => [], 'mine' => ['count' => 0, 'rows' => []],
            'mine_signatures' => 0, 'today' => ['count' => 0, 'rows' => []],
            'categories' => ['room' => 0, 'equipment' => 0, 'other' => 0], 'available' => [], 'available_failed' => false];
        foreach (self::queues() as $key => $definition) {
            if ($staff && self::queueVisible($key)) { $data['queues'][$key] = $definition + ['count' => 0, 'rows' => []]; }
        }
        $today = date('Y-m-d 00:00:00'); $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $where = ['entities_id' => Access::entityIds() ?: [-1], 'status' => self::OPEN];
        if ($staff && isset($data['queues']['integration'])) {
            // A completed booking can still have a failed native PDF attachment.
            $documentBookings = [];
            foreach (Store::find('agreements', ['entities_id' => Access::entityIds() ?: [-1], 'OR' => [
                ['status' => 'artifact_error'], ['status' => 'complete', 'attachment_status' => ['<>', 'attached']],
            ]], ['SELECT' => ['bookings_id']]) as $document) { $documentBookings[] = (int) $document['bookings_id']; }
            unset($where['status']);
            $where['OR'] = [['status' => self::OPEN], ['integration_status' => ['<>', 'synced']]];
            if ($documentBookings !== []) { $where['OR'][] = ['id' => array_values(array_unique($documentBookings))]; }
        }
        if (!$staff) { $where['OR'] = ['users_id_requester' => Access::userId(), 'users_id_borrower' => Access::userId()]; }
        $start = 0;
        do {
            $batch = Store::find('bookings', $where, ['ORDER' => ['end ASC', 'id ASC'], 'LIMIT' => 200, 'START' => $start]);
            $ids = array_map(static fn(array $b): int => (int) $b['id'], $batch);
            $linesByBooking = [];
            if ($ids !== []) {
                foreach (Store::find('bookinglines', ['bookings_id' => $ids], ['ORDER' => 'id ASC']) as $line) { $linesByBooking[(int) $line['bookings_id']][] = $line; }
            }
            foreach ($batch as $booking) {
                if (!Access::booking($booking, 'read')) { continue; }
                $booking['lines'] = $linesByBooking[(int) $booking['id']] ?? [];
                if (self::owned($booking) && in_array($booking['status'], self::OPEN, true)) {
                    self::addPreview($data['mine'], $booking);
                    if (self::pendingSignature($booking, $booking['lines'])) { ++$data['mine_signatures']; }
                }
                if (in_array($booking['status'], self::OPEN, true) && $booking['begin'] < $tomorrow && $booking['end'] > $today) { self::addPreview($data['today'], $booking, 'begin'); }
                foreach (array_keys($data['queues']) as $key) {
                    // Personal records do not grant visibility into a team's queues.
                    if ($key === 'signatures' && !self::teamAccess($booking)) { continue; }
                    if (self::matchesQueue($booking, $key)) { self::addPreview($data['queues'][$key], $booking); }
                }
            }
            $start += 200;
        } while (count($batch) === 200);
        $scan = 0;
        do {
            $resources = Store::find('resources', ['entities_id' => Access::entityIds() ?: [-1], 'publication' => 'published', 'is_deleted' => 0], ['ORDER' => ['name ASC', 'id ASC'], 'LIMIT' => 200, 'START' => $scan]);
            foreach ($resources as $resource) {
                if (!Access::resource($resource, Access::right('request') ? 'request' : 'read')) { continue; }
                $kind = (string) $resource['kind'];
                if (isset($data['categories'][$kind])) { ++$data['categories'][$kind]; }
                if (count($data['available']) < 6 && !$data['available_failed']) {
                    try {
                        if (CalendarBridge::conflicts($resource, Store::now(), date('Y-m-d H:i:s', time() + 3600)) === []) {
                            $data['available'][] = ResourceService::decorate($resource);
                        }
                    } catch (\Throwable $error) {
                        $data['available_failed'] = true; $data['available'] = [];
                        BookingView::publicError($error);
                    }
                }
            }
            $scan += 200;
        } while (count($resources) === 200);
        return $data;
    }

    private static function addPreview(array &$bucket, array $booking, string $orderBy = ''): void
    {
        ++$bucket['count'];
        if ($orderBy !== '') {
            $bucket['rows'][] = $booking;
            usort($bucket['rows'], static fn(array $a, array $b): int => [(string) $a[$orderBy], (int) $a['id']] <=> [(string) $b[$orderBy], (int) $b['id']]);
            $bucket['rows'] = array_slice($bucket['rows'], 0, self::PREVIEW);
        } elseif (count($bucket['rows']) < self::PREVIEW) { $bucket['rows'][] = $booking; }
    }

    public static function render(): void
    {
        $data = self::data();
        global $CFG_GLPI;
        $css = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/reservaplus/css/dashboard.css';
        echo '<link rel="stylesheet" href="' . BookingView::e($css) . '?v=' . BookingView::e(defined('PLUGIN_RESERVAPLUS_VERSION') ? PLUGIN_RESERVAPLUS_VERSION : '0.7') . '">';
        echo '<div class="rp-dashboard" data-rp-dashboard="' . ($data['staff'] ? 'team' : 'mine') . '">';
        if ($data['staff']) { self::renderTeam($data); }
        else { self::renderCategories($data['categories']); self::renderPersonal($data); }
        self::renderAvailable($data);
        if ($data['staff']) { self::renderCategories($data['categories']); }
        self::renderShortcuts();
        echo '</div>';
    }

    private static function renderTeam(array $data): void
    {
        echo '<section aria-labelledby="rp-team-heading"><div class="rp-dash-section-heading"><div><span class="rp-dash-eyebrow">' . BookingView::e(date('d/m/Y')) . '</span><h2 id="rp-team-heading">' . __('O que precisa da equipe', 'reservaplus') . '</h2><p>' . __('Cada fila considera suas permissões e os recursos sob sua responsabilidade.', 'reservaplus') . '</p></div></div><div class="rp-dash-metrics">';
        foreach ($data['queues'] as $key => $queue) {
            $url = BookingView::url('booking.php', ['queue' => $key, 'scope' => 'team']);
            echo '<a class="rp-dash-metric rp-dash-tone-' . BookingView::e($queue['tone']) . '" href="' . BookingView::e($url) . '" data-queue="' . BookingView::e($key) . '"><span class="rp-dash-metric-icon"><i class="ti ' . BookingView::e($queue['icon']) . '" aria-hidden="true"></i></span><strong>' . (int) $queue['count'] . '</strong><span>' . BookingView::e($queue['label']) . '</span><small>' . BookingView::e($queue['hint']) . '</small><span class="rp-dash-card-link">' . __('Abrir fila', 'reservaplus') . ' <i class="ti ti-arrow-right" aria-hidden="true"></i></span></a>';
        }
        echo '</div></section>';
        $priority = null;
        foreach (['overdue', 'inspection', 'approvals', 'ready', 'signatures', 'integration', 'loans'] as $key) {
            if (isset($data['queues'][$key]) && $data['queues'][$key]['count'] > 0) { $priority = $key; break; }
        }
        echo '<div class="rp-dash-columns"><section class="reservaplus-panel"><div class="rp-dash-section-heading"><div><h2>' . ($priority ? BookingView::e($data['queues'][$priority]['label']) : __('Próximas ações', 'reservaplus')) . '</h2><p>' . ($priority ? __('Comece pelos itens desta fila.', 'reservaplus') : __('A equipe está em dia com as filas visíveis neste perfil.', 'reservaplus')) . '</p></div>';
        if ($priority) { BookingView::link('booking.php', __('Ver fila completa', 'reservaplus'), ['queue' => $priority, 'scope' => 'team']); }
        echo '</div>';
        self::renderPreview($priority ? $data['queues'][$priority] : ['count' => 0, 'rows' => []]);
        echo '</section><section class="reservaplus-panel"><div class="rp-dash-section-heading"><div><h2>' . __('Agenda de hoje', 'reservaplus') . '</h2><p>' . __('Reservas em andamento ou previstas para hoje.', 'reservaplus') . '</p></div>';
        BookingView::link('workflow.calendar.php', __('Ver agenda', 'reservaplus'), ['date' => date('Y-m-d'), 'view' => 'day']);
        echo '</div>'; self::renderPreview($data['today']); echo '</section></div>';
    }

    private static function renderPersonal(array $data): void
    {
        echo '<section class="reservaplus-panel"><div class="rp-dash-section-heading"><div><h2>' . __('Minhas reservas', 'reservaplus') . '</h2><p>' . sprintf(__('%d solicitações em andamento.', 'reservaplus'), (int) $data['mine']['count']) . '</p></div>';
        BookingView::link('booking.php', __('Acompanhar todas', 'reservaplus'), ['scope' => 'mine']);
        echo '</div>';
        if ($data['mine_signatures'] > 0) {
            echo '<a class="rp-dash-signature-callout" href="' . BookingView::e(BookingView::url('booking.php', ['scope' => 'mine', 'queue' => 'signatures'])) . '"><i class="ti ti-writing-sign" aria-hidden="true"></i><span><strong>' . sprintf(__('%d reservas precisam de assinatura', 'reservaplus'), (int) $data['mine_signatures']) . '</strong><small>' . __('Abra a solicitação para conferir o termo e os signatários.', 'reservaplus') . '</small></span><i class="ti ti-arrow-right" aria-hidden="true"></i></a>';
        }
        self::renderPreview($data['mine']); echo '</section>';
    }

    private static function renderCategories(array $counts): void
    {
        if (!Access::right('request')) { return; }
        $types = [
            'room' => ['title' => __('Reservar uma sala', 'reservaplus'), 'description' => __('Encontre um espaço pela localização, capacidade e estrutura.', 'reservaplus'), 'icon' => 'ti-door'],
            'equipment' => ['title' => __('Reservar equipamentos', 'reservaplus'), 'description' => __('Escolha um ou vários itens para usar no mesmo período.', 'reservaplus'), 'icon' => 'ti-devices'],
            'other' => ['title' => __('Outros recursos', 'reservaplus'), 'description' => __('Veja os demais recursos que a organização disponibiliza.', 'reservaplus'), 'icon' => 'ti-box'],
        ];
        echo '<section aria-labelledby="rp-browse-heading"><div class="rp-dash-section-heading"><div><h2 id="rp-browse-heading">' . __('O que você precisa reservar?', 'reservaplus') . '</h2><p>' . __('Comece pelo tipo. Você pode escolher os recursos na lista, sem precisar saber seus nomes.', 'reservaplus') . '</p></div></div><div class="rp-dash-categories">';
        foreach ($types as $kind => $type) {
            echo '<a class="rp-dash-category" href="' . BookingView::e(BookingView::url('booking.form.php', ['kind' => $kind])) . '" data-kind="' . $kind . '"><span class="rp-dash-category-icon"><i class="ti ' . $type['icon'] . '" aria-hidden="true"></i></span><div><h3>' . $type['title'] . '</h3><p>' . $type['description'] . '</p><small>' . sprintf(__('%d opções no catálogo', 'reservaplus'), (int) ($counts[$kind] ?? 0)) . '</small></div><i class="ti ti-arrow-up-right" aria-hidden="true"></i></a>';
        }
        echo '</div></section>';
    }

    private static function renderAvailable(array $data): void
    {
        echo '<section class="reservaplus-panel"><div class="rp-dash-section-heading"><div><h2><i class="ti ti-bolt" aria-hidden="true"></i> ' . __('Disponíveis na próxima hora', 'reservaplus') . '</h2><p>' . __('Uma seleção de opções livres agora. A disponibilidade será conferida novamente ao reservar.', 'reservaplus') . '</p></div>';
        BookingView::link('workflow.calendar.php', __('Consultar outro período', 'reservaplus'));
        echo '</div>';
        if ($data['available_failed']) { BookingView::notice(__('Não foi possível consultar os horários agora. Tente novamente na agenda.', 'reservaplus'), true); }
        elseif (array_sum($data['categories']) === 0) {
            self::empty(__('Ainda não há recursos publicados para este perfil.', 'reservaplus'), Access::right('manage')
                ? __('Cadastre um recurso ou traga um item já reservável no GLPI para começar.', 'reservaplus')
                : __('A equipe responsável precisa disponibilizar os recursos para reserva.', 'reservaplus'));
            if (Access::right('manage')) { echo '<div class="rp-actions">'; BookingView::link('resource.form.php', __('Cadastrar recurso', 'reservaplus')); BookingView::link('resource.import.php', __('Trazer do GLPI', 'reservaplus')); echo '</div>'; }
        }
        elseif ($data['available'] === []) { self::empty(__('Nenhuma opção livre na próxima hora.', 'reservaplus'), __('Consulte outra data ou horário na agenda.', 'reservaplus')); }
        else {
            echo '<div class="rp-dash-available">';
            foreach ($data['available'] as $resource) {
                echo '<article class="rp-dash-resource"><span class="rp-dash-free"><i class="ti ti-circle-check" aria-hidden="true"></i> ' . __('Livre agora', 'reservaplus') . '</span><h3>' . BookingView::e($resource['name']) . '</h3><p>' . BookingView::e(BookingView::locator($resource) ?: ($resource['_sector_name'] ?? '')) . '</p>';
                if ($resource['kind'] === 'room' && !empty($resource['capacity'])) { echo '<small><i class="ti ti-users" aria-hidden="true"></i> ' . sprintf(__('Até %d pessoas', 'reservaplus'), (int) $resource['capacity']) . '</small>'; }
                echo '<div class="rp-actions">';
                if (Access::right('request')) { BookingView::link('booking.form.php', __('Selecionar', 'reservaplus'), ['resources_id' => (int) $resource['id'], 'kind' => $resource['kind'], 'begin' => date('Y-m-d\TH:i'), 'end' => date('Y-m-d\TH:i', time() + 3600)], 'btn-outline-primary btn-sm'); }
                else { BookingView::link('resource.form.php', __('Ver recurso', 'reservaplus'), ['id' => (int) $resource['id']], 'btn-outline-primary btn-sm'); }
                echo '</div></article>';
            }
            echo '</div>';
        }
        echo '</section>';
    }

    private static function renderPreview(array $bucket): void
    {
        if ($bucket['rows'] === []) { self::empty(__('Nenhuma reserva nesta lista.', 'reservaplus'), __('As próximas solicitações aparecerão aqui.', 'reservaplus')); return; }
        echo '<div class="rp-dash-bookings">';
        foreach ($bucket['rows'] as $booking) {
            $names = [];
            $bookingLines = self::lines($booking);
            foreach ($bookingLines as $line) {
                $snapshot = Store::decode($line['snapshot_json'] ?? null); $resource = $snapshot['resource'] ?? $snapshot;
                $names[] = (string) ($resource['name'] ?? __('Recurso', 'reservaplus'));
            }
            $summary = implode(', ', array_slice($names, 0, 2));
            if (count($names) > 2) { $summary .= ' +' . (count($names) - 2); }
            echo '<a class="rp-dash-booking" href="' . BookingView::e(BookingView::url('booking.php', ['id' => (int) $booking['id']])) . '"><span class="rp-dash-booking-icon"><i class="ti ti-calendar-event" aria-hidden="true"></i></span><div><strong>' . BookingView::e($summary ?: __('Solicitação de reserva', 'reservaplus')) . '</strong><span>' . BookingView::e(BookingView::date($booking['begin']) . ' — ' . BookingView::date($booking['end'])) . '</span><small>' . __('Reserva', 'reservaplus') . ' #' . (int) $booking['id'] . ' · ' . count($names) . ' ' . __('recurso(s)', 'reservaplus') . '</small></div><span class="rp-dash-status">';
            BookingView::bookingBadge($booking, $bookingLines); echo '</span></a>';
            $returnTerms = ($booking['status'] ?? '') === 'returning'
                ? BookingView::returnTermAlertsForList($booking, $bookingLines,
                    DocumentService::forBooking((int) $booking['id']))
                : [];
            foreach ($returnTerms as $term) {
                echo '<p class="rp-dash-return-pending" style="margin:4px 12px;color:#9a5800;font-weight:600;font-size:12px">'
                    . BookingView::e((string) $term['label']) . '</p>';
            }
            if (!self::isStaff()) {
                echo '<div class="rp-dash-quick-actions">';
                BookingView::renderRequesterActions($booking);
                echo '</div>';
            } elseif (in_array(($booking['status'] ?? ''), ['submitted', 'approved', 'ready', 'issued', 'returning'], true)) {
                echo '<div class="rp-dash-quick-actions">';
                BookingView::renderListActions($booking, self::lines($booking), true);
                echo '</div>';
            }
        }
        echo '</div><p class="rp-dash-preview-count">' . sprintf(__('Exibindo %1$d de %2$d reservas.', 'reservaplus'), count($bucket['rows']), (int) $bucket['count']) . '</p>';
    }

    private static function empty(string $title, string $description): void
    {
        echo '<div class="rp-dash-empty"><i class="ti ti-calendar-check" aria-hidden="true"></i><strong>' . BookingView::e($title) . '</strong><span>' . BookingView::e($description) . '</span></div>';
    }

    private static function renderShortcuts(): void
    {
        $links = [];
        if (Access::right('manage')) { $links[] = ['resource.php', __('Administrar recursos', 'reservaplus'), 'ti-building-warehouse']; $links[] = ['resource.form.php', __('Cadastrar recurso', 'reservaplus'), 'ti-plus']; }
        if (Access::right('admin')) { $links[] = ['sector.php', __('Setores responsáveis', 'reservaplus'), 'ti-sitemap']; }
        if (\GlpiPlugin\Reservaplus\Block::canView()) { $links[] = ['block.php', __('Bloqueios de horário', 'reservaplus'), 'ti-calendar-x']; }
        if (\GlpiPlugin\Reservaplus\Report::canView()) { $links[] = ['report.php', __('Relatórios anteriores', 'reservaplus'), 'ti-chart-bar']; }
        if (ReservationRequest::canView()) { $links[] = ['reservation.php', __('Reservas anteriores', 'reservaplus'), 'ti-history']; }
        if ($links === []) { return; }
        echo '<section class="rp-dash-shortcuts"><h2>' . __('Acesso rápido', 'reservaplus') . '</h2><div>';
        foreach ($links as [$file, $label, $icon]) { echo '<a href="' . BookingView::e(BookingView::url($file)) . '"><i class="ti ' . BookingView::e($icon) . '" aria-hidden="true"></i>' . BookingView::e($label) . '</a>'; }
        echo '</div></section>';
    }
}
