<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use Html;
use Session;
use Throwable;

/** Small escaped server-rendered views. All state changes stay in services. */
final class BookingView
{
    public const PAGE_SIZE = 25;
    private static int $fieldSequence = 0;
    private static int $ticketContext = 0;

    public static function text(mixed $value): string
    {
        return is_scalar($value) ? (string) $value : '';
    }

    public static function e(mixed $value): string
    {
        return htmlspecialchars(self::text($value), ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function url(string $file, array $query = []): string
    {
        global $CFG_GLPI;
        $base = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/reservaplus/front/' . ltrim($file, '/');
        return $base . ($query === [] ? '' : '?' . http_build_query($query));
    }

    public static function ticketUrl(int $id): string
    {
        global $CFG_GLPI;
        return rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/front/ticket.form.php?id=' . $id;
    }

    public static function ticketTabUrl(int $id): string
    {
        if ($id < 1) { throw new \InvalidArgumentException('Chamado inválido.'); }
        return self::ticketUrl($id) . '&' . http_build_query(['forcetab' => TicketTab::class . '$1']);
    }

    /** A return context is a verified native identity, never a user-provided URL. */
    public static function ticketContext(array $booking, mixed $value): int
    {
        if ($value === null || $value === '' || $value === 0 || $value === '0') { return 0; }
        if ((!is_string($value) && !is_int($value)) || !ctype_digit((string) $value) || (int) $value < 1
            || (int) ($booking['tickets_id'] ?? 0) !== (int) $value) {
            throw new \DomainException(__('O chamado de retorno não corresponde a esta reserva.', 'reservaplus'));
        }
        Access::assertBooking($booking);
        $ticket = new \Ticket();
        $id = (int) $value;
        if (!$ticket->getFromDB($id) || !$ticket->can($id, READ)) {
            throw new \DomainException(__('Você não tem acesso ao chamado vinculado nesta sessão.', 'reservaplus'));
        }
        return $id;
    }

    public static function ticketContextQuery(int $ticketId): array
    {
        return $ticketId > 0 ? ['_return_ticket' => $ticketId] : [];
    }

    public static function css(): void
    {
        global $CFG_GLPI;
        Dashboard::includeAssets(false);
        $url = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/reservaplus/css/workflow.css';
        echo '<link rel="stylesheet" href="' . self::e($url) . '?v=' . self::e(defined('PLUGIN_RESERVAPLUS_VERSION') ? PLUGIN_RESERVAPLUS_VERSION : '0.7') . '">';
    }

    public static function header(string $title, string $subtitle = ''): void
    {
        Dashboard::renderHeader($title);
        self::css();
        $staff = TechnicianDashboard::isStaff();
        $current = basename((string) parse_url($_SERVER['REQUEST_URI'] ?? $_SERVER['PHP_SELF'] ?? '', PHP_URL_PATH));
        $subtitle = $subtitle !== '' ? $subtitle : __('Encontre recursos, organize seu uso e acompanhe cada etapa.', 'reservaplus');
        echo '<div class="reservaplus-shell rp-workflow"><header class="rp-app-heading"><div class="rp-app-title"><span class="rp-app-mark" aria-hidden="true"><i class="ti ti-calendar-check"></i></span><div><p class="rp-kicker">Reserva Plus</p><h1>' . self::e($title) . '</h1><p class="rp-muted">' . self::e($subtitle) . '</p></div></div>';
        if (Access::right('request')) {
            echo '<a class="btn btn-primary rp-primary-action" href="' . self::e(self::url('booking.form.php')) . '"><i class="ti ti-plus" aria-hidden="true"></i> ' . self::e(__('Nova reserva', 'reservaplus')) . '</a>';
        }
        echo '</header><nav class="rp-main-nav" aria-label="' . self::e(__('Navegação do Reserva Plus', 'reservaplus')) . '">';
        $links = [
            ['dashboard.php', $staff ? __('Painel', 'reservaplus') : __('Início', 'reservaplus'), 'ti-layout-dashboard', ['dashboard.php']],
            ['booking.form.php', __('Reservar', 'reservaplus'), 'ti-category', ['booking.form.php']],
            ['workflow.calendar.php', __('Agenda', 'reservaplus'), 'ti-calendar', ['workflow.calendar.php']],
            ['booking.php', $staff ? __('Solicitações', 'reservaplus') : __('Minhas solicitações', 'reservaplus'), 'ti-list-details', ['booking.php', 'agreement.php', 'verify.php']],
        ];
        if (Access::right('manage')) {
            $links[] = ['resource.php', __('Recursos', 'reservaplus'), 'ti-building-warehouse', ['resource.php', 'resource.form.php', 'resource.import.php', 'sector.php']];
        }
        foreach ($links as [$file, $label, $icon, $pages]) {
            if ($file === 'booking.form.php' && !Access::right('request')) { continue; }
            $active = in_array($current, $pages, true);
            echo '<a class="rp-nav-link' . ($active ? ' is-active' : '') . '"' . ($active ? ' aria-current="page"' : '') . ' href="' . self::e(self::url($file)) . '"><i class="ti ' . self::e($icon) . '" aria-hidden="true"></i><span>' . self::e($label) . '</span></a>';
        }
        $secondary = [];
        if (Access::right('admin')) { $secondary[] = ['sector.php', __('Setores responsáveis', 'reservaplus')]; }
        if (Block::canView()) { $secondary[] = ['block.php', __('Bloqueios de horário', 'reservaplus')]; }
        if (Access::right('admin') || Config::canView()) { $secondary[] = ['config.form.php', __('Configurações gerais', 'reservaplus')]; }
        if (ReservationRequest::canView()) {
            $secondary[] = ['reservation.php', __('Reservas anteriores', 'reservaplus')];
            $secondary[] = ['calendar.php', __('Agenda anterior', 'reservaplus')];
        }
        if (Report::canView()) { $secondary[] = ['report.php', __('Relatórios das reservas anteriores', 'reservaplus')]; }
        if ($secondary !== []) {
            echo '<details class="rp-nav-more"><summary><i class="ti ti-dots" aria-hidden="true"></i> ' . self::e(__('Mais opções', 'reservaplus')) . '</summary><div class="rp-nav-menu">';
            foreach ($secondary as [$file, $label]) { echo '<a href="' . self::e(self::url($file)) . '">' . self::e($label) . '</a>'; }
            echo '</div></details>';
        }
        echo '</nav>';
    }

    public static function footer(): void
    {
        echo '</div>';
        Dashboard::renderFooter();
    }

    public static function requireReady(): void
    {
        Session::checkLoginUser();
        if (!Schema::ready()) {
            throw new \DomainException(__('A atualização do Reserva Plus precisa ser concluída pelo administrador.', 'reservaplus'));
        }
    }

    public static function link(string $file, string $label, array $query = [], string $class = 'btn-outline-secondary'): void
    {
        echo '<a class="btn ' . self::e($class) . '" href="' . self::e(self::url($file, $query)) . '">' . self::e($label) . '</a>';
    }

    /** A second, plugin-owned request binding; GLPI's kernel owns native CSRF. */
    public static function command(string $action, int $id = 0): string
    {
        $key = bin2hex(random_bytes(24));
        $commands = &$_SESSION['reservaplus_commands'];
        if (!is_array($commands)) {
            $commands = [];
        }
        foreach ($commands as $existing => $command) {
            if (($command['expires'] ?? 0) < time()) {
                unset($commands[$existing]);
            }
        }
        while (count($commands) >= 100) {
            array_shift($commands);
        }
        $commands[$key] = ['action' => $action, 'id' => $id, 'user' => Access::userId(), 'ticket' => self::$ticketContext, 'expires' => time() + 7200];
        return $key;
    }

    public static function checkCommand(string $key, string $action, int $id = 0, int $ticketContext = 0): void
    {
        $command = $_SESSION['reservaplus_commands'][$key] ?? null;
        if (!is_array($command) || ($command['action'] ?? '') !== $action || (int) ($command['id'] ?? -1) !== $id
            || (int) ($command['user'] ?? 0) !== Access::userId() || (int) ($command['ticket'] ?? 0) !== $ticketContext
            || (int) ($command['expires'] ?? 0) < time()) {
            throw new \DomainException(__('O formulário expirou. Atualize a página antes de tentar novamente.', 'reservaplus'));
        }
        // Do not consume here: retries must reach service-level idempotency.
    }

    public static function formStart(string $action, int $id = 0, int $revision = 0): void
    {
        echo '<form method="post" action="' . self::e(self::url('booking.action.php')) . '">';
        self::hidden('action', $action);
        self::hidden('id', $id);
        self::hidden('expected_revision', $revision);
        self::hidden('operation_key', self::command($action, $id));
        if (self::$ticketContext > 0) { self::hidden('_return_ticket', self::$ticketContext); }
    }

    public static function hidden(string $name, mixed $value): void
    {
        echo '<input type="hidden" name="' . self::e($name) . '" value="' . self::e($value) . '">';
    }

    public static function input(string $name, string $label, mixed $value = '', string $type = 'text', bool $required = false, string $attributes = ''): void
    {
        $id = 'rp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . '_' . ++self::$fieldSequence;
        echo '<div class="rp-field"><label for="' . self::e($id) . '">' . self::e($label) . '</label>';
        echo '<input id="' . self::e($id) . '" name="' . self::e($name) . '" type="' . self::e($type) . '" value="' . self::e($value) . '"' . ($required ? ' required' : '') . ' ' . $attributes . '></div>';
    }

    public static function textarea(string $name, string $label, string $value = '', bool $required = false): void
    {
        $id = 'rp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . '_' . ++self::$fieldSequence;
        echo '<div class="rp-field"><label for="' . self::e($id) . '">' . self::e($label) . '</label><textarea id="' . self::e($id) . '" name="' . self::e($name) . '" maxlength="10000"' . ($required ? ' required' : '') . '>' . self::e($value) . '</textarea></div>';
    }

    public static function select(string $name, string $label, array $options, string $value = ''): void
    {
        $id = 'rp_' . preg_replace('/[^a-zA-Z0-9_]/', '_', $name) . '_' . ++self::$fieldSequence;
        echo '<div class="rp-field"><label for="' . self::e($id) . '">' . self::e($label) . '</label><select id="' . self::e($id) . '" name="' . self::e($name) . '">';
        foreach ($options as $key => $text) {
            echo '<option value="' . self::e($key) . '"' . ((string) $key === $value ? ' selected' : '') . '>' . self::e($text) . '</option>';
        }
        echo '</select></div>';
    }

    public static function submit(string $label): void
    {
        echo '<button class="btn btn-primary" type="submit">' . self::e($label) . '</button>';
    }

    public static function date(mixed $value): string
    {
        $value = self::text($value);
        if ($value === '') {
            return '-';
        }
        if (preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', $value)) {
            $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
            if ($date && $date->format('Y-m-d H:i:s') === $value) { return $date->format('d/m/Y H:i'); }
        }
        return $value;
    }

    public static function datetimeInput(mixed $value): string
    {
        return substr(str_replace(' ', 'T', self::text($value)), 0, 16);
    }

    public static function timezoneNote(): void
    {
        echo '<p class="rp-muted">' . self::e(sprintf(__('Horários no fuso da instalação: %s.', 'reservaplus'), date_default_timezone_get())) . '</p>';
    }

    public static function statuses(): array
    {
        return [
            'submitted' => __('Aguardando aprovação', 'reservaplus'), 'approved' => __('Aprovada', 'reservaplus'),
            'ready' => __('Conferido e liberado', 'reservaplus'), 'issued' => __('Em uso', 'reservaplus'),
            'returning' => __('Devolução em andamento', 'reservaplus'), 'completed' => __('Concluída', 'reservaplus'),
            'rejected' => __('Rejeitada', 'reservaplus'), 'cancelled' => __('Cancelada', 'reservaplus'),
            'requested' => __('Solicitado', 'reservaplus'), 'allocated' => __('Reservado', 'reservaplus'),
            'released' => __('Reserva liberada', 'reservaplus'),
            'received' => __('Recebido; aguarda conferência', 'reservaplus'), 'inspection' => __('Em conferência', 'reservaplus'),
            'inspected' => __('Conferido', 'reservaplus'), 'available' => __('Disponível', 'reservaplus'),
            'quarantine' => __('Retido para avaliação', 'reservaplus'), 'maintenance' => __('Em manutenção', 'reservaplus'),
            'lost' => __('Perda pendente de resolução', 'reservaplus'), 'partial' => __('Devolução parcial', 'reservaplus'),
            'awaiting_signatures' => __('Aguardando assinaturas', 'reservaplus'), 'prepared' => __('Pronto para assinatura', 'reservaplus'),
            'signed' => __('Assinado', 'reservaplus'), 'finalized' => __('Finalizado', 'reservaplus'),
            'overdue' => __('Devolução em atraso', 'reservaplus'),
            'complete' => __('Assinado e finalizado', 'reservaplus'),
            'partially_signed' => __('Aguardando os demais signatários', 'reservaplus'),
            'accepted' => __('Aceites registrados; finalizando documento', 'reservaplus'),
            'artifact_error' => __('Aceites registrados; documento precisa de atenção', 'reservaplus'),
            'superseded' => __('Substituído por nova versão', 'reservaplus'), 'artifact_pending' => __('Assinatura registrada; documento em processamento', 'reservaplus'),
        ];
    }

    public static function badge(string $status): void
    {
        $class = preg_replace('/[^a-z_]/', '', $status);
        echo '<span class="rp-badge rp-badge-' . self::e($class) . '">' . self::e(self::statuses()[$status] ?? $status) . '</span>';
    }

    public static function notice(string $message, bool $error = false): void
    {
        echo '<div role="' . ($error ? 'alert' : 'status') . '" class="' . ($error ? 'rp-error' : 'rp-warning') . '">' . self::e($message) . '</div>';
    }

    public static function publicError(Throwable $error): string
    {
        if ($error instanceof \DomainException || $error instanceof \InvalidArgumentException) {
            return $error->getMessage();
        }
        if (class_exists(\Toolbox::class)) {
            \Toolbox::logInFile('reservaplus', get_class($error) . ': ' . $error->getMessage() . "\n");
        }
        return __('Não foi possível concluir esta operação. Atualize a página e, se o problema continuar, informe a equipe responsável.', 'reservaplus');
    }

    public static function renderList(array $rows, array $query = [], bool $compact = false): void
    {
        if ($rows === []) {
            echo '<div class="rp-empty-state"><i class="ti ti-calendar-search" aria-hidden="true"></i><h3>' . self::e(__('Nenhuma solicitação por aqui', 'reservaplus')) . '</h3><p>' . self::e(__('Altere os filtros ou escolha os recursos para sua próxima reserva.', 'reservaplus')) . '</p>';
            if (Access::right('request')) { self::link('booking.form.php', __('Explorar recursos', 'reservaplus'), [], 'btn-primary'); }
            echo '</div>';
            return;
        }
        echo '<div class="rp-table-wrap"><table class="rp-table"><thead><tr>';
        $titles = [__('Recursos e solicitação', 'reservaplus'), __('Situação', 'reservaplus'), __('Período', 'reservaplus')];
        if (!$compact) { $titles[] = __('Responsável pelo uso', 'reservaplus'); }
        $titles[] = __('Chamado', 'reservaplus');
        $titles[] = __('Ações', 'reservaplus');
        foreach ($titles as $title) {
            echo '<th scope="col">' . self::e($title) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($rows as $row) {
            $meta = $row['_display'] ?? BookingQuery::metadata($row);
            $names = array_column($meta['resources'], 'name');
            $title = implode(', ', array_slice($names, 0, 2));
            if (count($names) > 2) { $title .= ' +' . (count($names) - 2); }
            echo '<tr><td><a class="rp-row-title" href="' . self::e(self::url('booking.php', ['id' => (int) $row['id']])) . '">' . self::e($title !== '' ? $title : __('Ver solicitação', 'reservaplus')) . '</a><span class="rp-row-meta">#' . (int) $row['id'] . ' · ' . count($names) . ' ' . self::e(count($names) === 1 ? __('recurso', 'reservaplus') : __('recursos', 'reservaplus')) . '</span>';
            if (!$compact && trim((string) ($row['purpose'] ?? '')) !== '') {
                echo '<span class="rp-row-meta rp-row-purpose">' . self::e(mb_strimwidth((string) $row['purpose'], 0, 120, '…', 'UTF-8')) . '</span>';
            }
            echo '</td><td>';
            self::badge((string) ($row['status'] ?? ''));
            $physical = TechnicianDashboard::physicalFlags($row, $meta['lines'], Store::now());
            if ($physical['overdue']) { echo '<br>'; self::badge('overdue'); }
            echo '</td><td><span class="rp-row-meta">' . self::e(__('Início', 'reservaplus')) . '</span>' . self::e(self::date($row['begin'] ?? '')) . '<span class="rp-row-meta">' . self::e(__('Devolução / término', 'reservaplus')) . '</span>' . self::e(self::date($row['end'] ?? '')) . '</td>';
            if (!$compact) { echo '<td>' . self::e($meta['borrower'] !== '' ? $meta['borrower'] : '—') . '</td>'; }
            echo '<td>';
            if ((int) ($row['tickets_id'] ?? 0) > 0) {
                echo '<a href="' . self::e(self::ticketUrl((int) $row['tickets_id'])) . '">#' . (int) $row['tickets_id'] . '</a>';
            } else {
                echo self::e(__('Pendente', 'reservaplus'));
            }
            echo '</td><td>';
            if (TechnicianDashboard::isStaff()) { self::renderListActions($row, $meta['lines'], true); }
            else { self::renderRequesterActions($row); }
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    /** Shortcuts never bypass BookingService's locks, revision checks or native GLPI validation. */
    public static function renderListActions(array $booking, array $lines, bool $dashboard = false): void
    {
        $eligible = [];
        if (($booking['status'] ?? '') === 'submitted') {
            foreach ($lines as $line) {
                if (!Access::canApproveLine($booking, $line)) { continue; }
                // List/search projections do not include $booking['approvals'].
                // Read the pending records directly, just like TicketBridge::pendingForActor().
                $pending = Store::find('bookingapprovals', [
                    'bookings_id' => (int) $booking['id'],
                    'lines_id' => (int) $line['id'],
                    'status' => 'pending',
                ]);
                foreach ($pending as $approval) {
                    if (TicketBridge::targetMatchesActor($approval)) {
                        $eligible[] = (int) $line['id'];
                        break;
                    }
                }
            }
        }
        $state = (string) ($booking['status'] ?? '');
        $canExtend = in_array($state, ['submitted', 'approved', 'ready'], true) && Access::canReschedule($booking);
        $canCancel = in_array($state, ['submitted', 'approved'], true)
            && (Access::booking($booking, 'request') || Access::booking($booking, 'manage'));
        if ($eligible === [] && !$canExtend && !$canCancel) {
            self::link('booking.php', __('Detalhes', 'reservaplus'), ['id' => (int) $booking['id']], 'btn-outline-secondary btn-sm');
            return;
        }
        echo '<div class="rp-quick-actions rp-quick-actions-inline" aria-label="' . self::e(__('Ações da reserva', 'reservaplus')) . '">';
        if ($eligible !== []) {
            self::formStart('approve', (int) $booking['id'], (int) $booking['revision']);
            foreach ($eligible as $lineId) { self::hidden('lines_ids[]', $lineId); }
            self::hidden('_return_list', 'requests');
            echo '<button type="submit" class="btn rp-mini-action rp-mini-approve" onclick="return confirm(' . self::e(json_encode(__('Confirma a aprovação dos recursos autorizados?', 'reservaplus'), JSON_HEX_APOS | JSON_HEX_QUOT)) . ')">' . self::e(__('Autorizar', 'reservaplus')) . '</button></form>';

            echo '<details class="rp-quick-reject rp-mini-expand"><summary class="btn rp-mini-action rp-mini-reject">' . self::e(__('Rejeitar', 'reservaplus')) . '</summary>';
            self::formStart('reject', (int) $booking['id'], (int) $booking['revision']);
            foreach ($eligible as $lineId) { self::hidden('lines_ids[]', $lineId); }
            self::hidden('_return_list', 'requests');
            self::textarea('reason', __('Motivo da rejeição', 'reservaplus'), '', true);
            self::submit(__('Confirmar rejeição', 'reservaplus'));
            echo '</form></details>';
        }
        if ($canExtend) {
            echo '<details class="rp-mini-expand"><summary class="btn rp-mini-action rp-mini-extend">' . self::e(__('Alterar', 'reservaplus')) . '</summary>';
            self::formStart('extend', (int) $booking['id'], (int) $booking['revision']);
            self::hidden('_return_list', 'requests');
            self::input('begin', __('Novo início', 'reservaplus'), self::datetimeInput($booking['begin']), 'datetime-local', true);
            self::input('end', __('Novo término', 'reservaplus'), self::datetimeInput($booking['end']), 'datetime-local', true);
            self::submit(__('Validar período', 'reservaplus'));
            echo '</form></details>';
        }
        if ($canCancel) {
            echo '<details class="rp-mini-expand"><summary class="btn rp-mini-action rp-mini-cancel">' . self::e(__('Cancelar', 'reservaplus')) . '</summary>';
            self::formStart('cancel', (int) $booking['id'], (int) $booking['revision']);
            self::hidden('_return_list', 'requests');
            self::textarea('reason', __('Motivo do cancelamento', 'reservaplus'), '', true);
            self::submit(__('Confirmar cancelamento', 'reservaplus'));
            echo '</form></details>';
        }
        self::link('booking.php', __('Detalhes', 'reservaplus'), ['id' => (int) $booking['id']], 'btn-outline-secondary btn-sm');
        echo '</div>';
    }

    /** Self-service shortcuts: never expose staff custody or approval commands. */
    public static function renderRequesterActions(array $booking): void
    {
        $id = (int) $booking['id'];
        $owner = in_array(Access::userId(), [(int) $booking['users_id_requester'], (int) $booking['users_id_borrower']], true);
        if (!$owner || !Access::booking($booking, 'read')) { return; }
        $status = (string) ($booking['status'] ?? '');
        echo '<div class="rp-requester-actions" aria-label="' . self::e(__('Ações da minha reserva', 'reservaplus')) . '">';
        self::link('booking.php', __('Acompanhar', 'reservaplus'), ['id' => $id], 'rp-requester-follow btn-sm');
        if (!in_array($status, ['cancelled', 'rejected'], true)) {
            echo '<a class="btn rp-requester-sign btn-sm" href="' . self::e(self::url('booking.php', ['id' => $id]) . '#rp-booking-documents') . '">' . self::e(__('Termos e assinaturas', 'reservaplus')) . '</a>';
        }
        if (Access::right('request')) {
            self::link('booking.form.php', __('Reservar novamente', 'reservaplus'), ['copy_booking_id' => $id], 'rp-requester-repeat btn-sm');
        }
        if (in_array($status, ['submitted', 'approved'], true)
            && (Access::booking($booking, 'request') || Access::booking($booking, 'manage'))) {
            echo '<details class="rp-mini-expand"><summary class="btn rp-mini-action rp-mini-cancel">' . self::e(__('Cancelar', 'reservaplus')) . '</summary>';
            self::formStart('cancel', $id, (int) $booking['revision']);
            self::hidden('_return_list', 'mine');
            self::textarea('reason', __('Motivo do cancelamento', 'reservaplus'), '', true);
            self::submit(__('Confirmar cancelamento', 'reservaplus'));
            echo '</form></details>';
        }
        echo '</div>';
    }

    public static function pagination(string $file, array $query, int $page, bool $more): void
    {
        if ($page === 1 && !$more) { return; }
        echo '<nav class="rp-pagination" aria-label="' . self::e(__('Paginação', 'reservaplus')) . '">';
        if ($page > 1) {
            self::link($file, __('Anterior', 'reservaplus'), array_merge($query, ['page' => $page - 1]));
        }
        echo '<span>' . self::e(sprintf(__('Página %d', 'reservaplus'), $page)) . '</span>';
        if ($more) {
            self::link($file, __('Próxima', 'reservaplus'), array_merge($query, ['page' => $page + 1]));
        }
        echo '</nav>';
    }

    public static function locator(array $resource): string
    {
        return implode(' / ', array_filter([
            (string) ($resource['_location_name'] ?? ''), (string) ($resource['building'] ?? ''),
            (string) ($resource['wing'] ?? ''), (string) ($resource['floor'] ?? ''),
            (string) ($resource['room_number'] ?? ''),
        ], static fn(string $v): bool => trim($v) !== ''));
    }

    public static function renderResource(array $resource, bool $checked = false): void
    {
        $id = (int) $resource['id'];
        echo '<label class="rp-resource"><input type="checkbox" name="resources_ids[]" value="' . $id . '"' . ($checked ? ' checked' : '') . '><h3>' . self::e($resource['name'] ?? '#' . $id) . '</h3>';
        $locator = self::locator($resource);
        if ($locator !== '') {
            echo '<p>' . self::e($locator) . '</p>';
        }
        echo '<dl>';
        foreach ([__('Código / patrimônio', 'reservaplus') => $resource['inventory_number'] ?? '', __('Setor responsável', 'reservaplus') => $resource['_sector_name'] ?? '', __('Responsável pela guarda', 'reservaplus') => $resource['_custodian_name'] ?? '', __('Equipe de atendimento', 'reservaplus') => $resource['_ticket_group_name'] ?? ''] as $label => $value) {
            if ((string) $value !== '') {
                echo '<dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd>';
            }
        }
        if (($resource['kind'] ?? '') === 'room') {
            echo '<dt>' . self::e(__('Capacidade', 'reservaplus')) . '</dt><dd>' . self::e(!empty($resource['capacity']) ? ((int) $resource['capacity'] . ' pessoas') : __('Não informada', 'reservaplus')) . '</dd>';
            if (!empty($resource['area_m2'])) {
                echo '<dt>' . self::e(__('Área', 'reservaplus')) . '</dt><dd>' . self::e($resource['area_m2']) . ' m²</dd>';
            }
        }
        echo '</dl>';
        if (!empty($resource['features'])) {
            echo '<p class="rp-muted">' . self::e($resource['features']) . '</p>';
        }
        if (!empty($resource['_calendar_label'])) {
            echo '<p class="rp-muted">' . self::e($resource['_calendar_label']) . '</p>';
        }
        $policy = ResourceService::policy($resource);
        $signature = ['submission' => __('Assinatura na solicitação', 'reservaplus'), 'approval' => __('Assinatura após aprovação', 'reservaplus'), 'handover' => __('Assinatura antes da retirada / uso', 'reservaplus'), 'none' => __('Sem assinatura de retirada obrigatória', 'reservaplus')];
        echo '<p class="rp-muted">' . self::e(!empty($policy['approval_required']) ? __('Exige aprovação da equipe.', 'reservaplus') : __('Confirmação sujeita à disponibilidade.', 'reservaplus')) . ' ' . self::e($signature[$policy['signature_stage'] ?? 'none'] ?? '') . '.</p>';
        if (!empty($policy['return_signature_required'])) {
            echo '<p class="rp-muted">' . self::e(__('Exige termo assinado na devolução / término de uso.', 'reservaplus')) . '</p>';
        }
        echo '</label>';
    }

    private static function lineSnapshot(array $line): array
    {
        $snapshot = Store::decode($line['snapshot_json'] ?? null);
        return is_array($snapshot['resource'] ?? null) ? $snapshot['resource'] : $snapshot;
    }

    private static function lineName(array $line): string
    {
        $snapshot = self::lineSnapshot($line);
        return (string) ($snapshot['name'] ?? $line['resource_name'] ?? ('Recurso #' . (int) ($line['resources_id'] ?? 0)));
    }

    public static function renderDetail(array $booking, int $ticketContext = 0): void
    {
        $previousContext = self::$ticketContext;
        self::$ticketContext = self::ticketContext($booking, $ticketContext);
        try {
            self::renderDetailContent($booking);
        } finally {
            self::$ticketContext = $previousContext;
        }
    }

    private static function renderDetailContent(array $booking): void
    {
        $id = (int) $booking['id'];
        $state = (string) $booking['status'];
        if (!in_array($state, ['cancelled', 'rejected'], true)) {
            $phase = ['submitted'=>0,'approved'=>1,'issued'=>2,'returning'=>3,'completed'=>4][$state] ?? 0;
            echo '<ol class="rp-progress" aria-label="' . self::e(__('Andamento da reserva', 'reservaplus')) . '">';
            foreach (['Solicitação', 'Aprovação e termos', 'Uso dos recursos', 'Devolução e conferência', 'Concluída'] as $index => $label) {
                echo '<li class="' . ($index < $phase ? 'is-done' : ($index === $phase ? 'is-current' : '')) . '"' . ($index === $phase ? ' aria-current="step"' : '') . '>' . ($index+1) . '. ' . self::e(__($label, 'reservaplus')) . '</li>';
            }
            echo '</ol>';
        }
        echo '<section class="rp-panel"><div class="rp-heading"><h2>' . self::e(sprintf(__('Solicitação #%d', 'reservaplus'), $id)) . '</h2>';
        self::badge((string) $booking['status']);
        echo '</div><dl class="rp-meta">';
        $snapshot = Store::decode($booking['borrower_snapshot_json'] ?? null);
        $values = [
            __('Usuário responsável pelo uso', 'reservaplus') => $snapshot['name'] ?? ('#' . (int) $booking['users_id_borrower']),
            __('Matrícula informada', 'reservaplus') => $snapshot['matricula'] ?? '',
            __('Unidade informada', 'reservaplus') => $snapshot['unit'] ?? '',
            __('Início previsto', 'reservaplus') => self::date($booking['begin'] ?? ''),
            __('Devolução / término previsto', 'reservaplus') => self::date($booking['end'] ?? ''),
        ];
        foreach ($values as $label => $value) {
            if ((string) $value !== '') {
                echo '<div><dt>' . self::e($label) . '</dt><dd>' . self::e($value) . '</dd></div>';
            }
        }
        echo '</dl><p>' . nl2br(self::e($booking['purpose'] ?? '')) . '</p>';
        if (self::$ticketContext > 0) {
            self::link('booking.php', __('Abrir visão completa da reserva', 'reservaplus'), ['id' => $id]);
        } elseif ((int) ($booking['tickets_id'] ?? 0) > 0) {
            echo '<a class="btn btn-outline-primary" href="' . self::e(self::ticketTabUrl((int) $booking['tickets_id'])) . '">' . self::e(sprintf(__('Abrir chamado #%d', 'reservaplus'), $booking['tickets_id'])) . '</a>';
        }
        if (Access::right('request')) {
            echo ' ';
            self::link('booking.form.php', __('Reservar estes recursos novamente', 'reservaplus'), ['copy_booking_id' => $id]);
        }
        self::timezoneNote();
        if (!in_array((string) ($booking['integration_status'] ?? ''), ['', 'ok', 'synced', 'synchronized'], true)) {
            self::notice(__('Há uma atualização pendente com o chamado, calendário ou documentos. A equipe deve verificar a solicitação antes de repetir a operação.', 'reservaplus'));
        }
        if (in_array((string) $booking['status'], ['issued', 'returning'], true)) {
            echo '<p class="rp-muted">' . self::e(__('Nesta versão, o período de empréstimos já entregues não pode ser prorrogado. Registre a devolução e faça uma nova solicitação para outro período.', 'reservaplus')) . '</p>';
        }
        echo '</section>';
        self::renderApprovals($booking);
        echo '<section class="rp-panel"><h2>' . self::e(__('Recursos e devolução', 'reservaplus')) . '</h2><div class="rp-table-wrap"><table class="rp-table"><thead><tr>';
        foreach ([__('Recurso', 'reservaplus'), __('Situação', 'reservaplus'), __('Retirada', 'reservaplus'), __('Recebimento', 'reservaplus'), __('Conferência', 'reservaplus')] as $label) {
            echo '<th scope="col">' . self::e($label) . '</th>';
        }
        echo '</tr></thead><tbody>';
        foreach ($booking['lines'] ?? [] as $line) {
            $resource = self::lineSnapshot($line);
            echo '<tr><td><strong>' . self::e(self::lineName($line)) . '</strong><br><span class="rp-muted">' . self::e(self::locator($resource)) . '</span>';
            self::renderAccessorySummary($line);
            echo '</td><td>';
            self::badge((string) ($line['state'] ?? 'requested'));
            echo '</td><td>' . self::e(self::date($line['issued_at'] ?? '')) . '</td><td>' . self::e(self::date($line['received_at'] ?? '')) . '</td><td>' . self::e(self::date($line['inspection_at'] ?? '')) . '</td></tr>';
        }
        echo '</tbody></table></div><p class="rp-muted">' . self::e(__('O recebimento confirma quais itens voltaram. A conferência e a liberação para novo uso são registradas separadamente.', 'reservaplus')) . '</p></section>';
        self::renderDocuments($booking);
        self::renderActions($booking);
        self::renderHistory($booking);
    }

    /** Only actual decisions count; alternative covered targets are not votes. */
    public static function approvalProgress(array $booking, array $line): array
    {
        $resource = self::lineSnapshot($line);
        $policy = Store::decode($resource['policy_json'] ?? null);
        if (empty($policy['approval_required'])) { return ['status' => 'not_required', 'decision' => null]; }
        $rows = array_values(array_filter($booking['approvals'] ?? [], static fn(array $row): bool => (int) $row['lines_id'] === (int) $line['id']));
        foreach (['rejected', 'approved'] as $status) {
            foreach ($rows as $row) {
                if ($row['status'] === $status) { return ['status' => $status, 'decision' => $row]; }
            }
        }
        if (in_array($booking['status'], ['cancelled', 'rejected', 'completed'], true)) { return ['status' => 'closed', 'decision' => null]; }
        foreach ($rows as $row) { if ($row['status'] === 'pending') { return ['status' => 'pending', 'decision' => null]; } }
        return ['status' => 'unavailable', 'decision' => null];
    }

    private static function approvalActorName(string $type, int $id): string
    {
        static $cache = [];
        $key = $type . ':' . $id;
        if (isset($cache[$key])) { return $cache[$key]; }
        $fallback = ($type === \Group::class ? __('Grupo', 'reservaplus') : __('Usuário', 'reservaplus')) . ' #' . $id . ' (' . __('indisponível', 'reservaplus') . ')';
        if (!in_array($type, [\Group::class, \User::class], true) || $id < 1) { return $fallback; }
        $model = new $type();
        if (!$model->getFromDB($id)) { return $cache[$key] = $fallback; }
        $fields = $model->fields;
        $name = $type === \User::class
            ? (trim((string) ($fields['firstname'] ?? '') . ' ' . (string) ($fields['realname'] ?? '')) ?: (string) ($fields['name'] ?? ''))
            : (string) ($fields['completename'] ?? $fields['name'] ?? '');
        if ($name !== '' && (!empty($fields['is_deleted']) || ($type === \User::class && isset($fields['is_active']) && !(bool) $fields['is_active']))) {
            $name .= ' (' . __('indisponível', 'reservaplus') . ')';
        }
        return $cache[$key] = ($name !== '' ? $name : $fallback);
    }

    private static function renderApprovals(array $booking): void
    {
        $rows = [];
        foreach ($booking['lines'] ?? [] as $line) {
            $progress = self::approvalProgress($booking, $line);
            if ($progress['status'] !== 'not_required') { $rows[] = [$line, $progress]; }
        }
        if ($rows === []) { return; }
        echo '<section class="rp-panel rp-approval-panel"><h2>' . self::e(__('Aprovação dos recursos', 'reservaplus')) . '</h2><p class="rp-muted">' . self::e(__('Cada recurso listado precisa de uma decisão de uma das pessoas ou grupos indicados. A reserva é confirmada quando todos os recursos exigidos forem aprovados e houver disponibilidade.', 'reservaplus')) . '</p><div class="rp-table-wrap"><table class="rp-table"><thead><tr>';
        foreach (['Recurso', 'Situação', 'Quem pode aprovar', 'Decisão registrada'] as $label) { echo '<th>' . self::e(__($label, 'reservaplus')) . '</th>'; }
        echo '</tr></thead><tbody>';
        $labels = ['pending'=>'Aguardando decisão', 'approved'=>'Aprovado', 'rejected'=>'Rejeitado', 'closed'=>'Encerrada sem decisão', 'unavailable'=>'Aprovação indisponível'];
        foreach ($rows as [$line, $progress]) {
            $pool = ApprovalPolicy::resolve(Access::lineResource($line));
            echo '<tr data-rp-approval-line="' . (int) $line['id'] . '"><td><strong>' . self::e(self::lineName($line)) . '</strong></td><td><span class="rp-badge rp-badge-' . self::e($progress['status'] === 'pending' ? 'submitted' : $progress['status']) . '">' . self::e(__($labels[$progress['status']], 'reservaplus')) . '</span></td><td><ul class="rp-approvers">';
            foreach ($pool['groups_ids'] as $id) { echo '<li><i class="ti ti-users" aria-hidden="true"></i> ' . self::e(__('Grupo:', 'reservaplus')) . ' ' . self::e(self::approvalActorName(\Group::class, (int) $id)) . '</li>'; }
            foreach ($pool['users_ids'] as $id) { echo '<li><i class="ti ti-user" aria-hidden="true"></i> ' . self::e(self::approvalActorName(\User::class, (int) $id)) . '</li>'; }
            echo '</ul></td><td>';
            $decision = $progress['decision'];
            if ($decision !== null && (int) $decision['users_id_decider'] > 0) {
                echo self::e(self::approvalActorName(\User::class, (int) $decision['users_id_decider'])) . '<span class="rp-row-meta">' . self::e(self::date($decision['date_mod'] ?? '')) . '</span>';
            } else { echo self::e($progress['status'] === 'pending' ? __('Ainda não registrada', 'reservaplus') : '—'); }
            echo '</td></tr>';
        }
        echo '</tbody></table></div><p class="rp-muted">' . self::e(__('As pendências desta reserva são acompanhadas aqui. O campo de aprovação geral do GLPI considera os registros nativos do chamado e não substitui esta conferência por recurso.', 'reservaplus')) . '</p></section>';
    }

    private static function renderAccessorySummary(array $line): void
    {
        $accessories = Store::decode($line['accessories_json'] ?? null);
        if ($accessories === []) {
            return;
        }
        echo '<ul class="rp-muted">';
        foreach ($accessories as $accessory) {
            if (!is_array($accessory)) {
                continue;
            }
            $issued = (int) ($accessory['issued'] ?? $accessory['quantity'] ?? 0);
            $received = (int) ($accessory['received'] ?? $accessory['returned'] ?? 0);
            echo '<li>' . self::e($accessory['name'] ?? '') . ': ' . self::e(sprintf(__('%1$d de %2$d devolvidos', 'reservaplus'), $received, $issued)) . '</li>';
        }
        echo '</ul>';
    }

    public static function purposeLabel(string $purpose): string
    {
        return [
            'request' => __('Termo da solicitação', 'reservaplus'), 'handover' => __('Termo de responsabilidade pelo uso', 'reservaplus'),
            'return_receipt' => __('Recebimento para conferência', 'reservaplus'),
            'return_inspection' => __('Termo de devolução e conferência', 'reservaplus'),
        ][$purpose] ?? $purpose;
    }

    private static function documentTable(array $documents): void
    {
        echo '<div class="rp-table-wrap"><table class="rp-table"><thead><tr><th>Documento</th><th>Situação</th><th>Acesso</th></tr></thead><tbody>';
        foreach ($documents as $document) {
            echo '<tr><td>' . self::e(self::purposeLabel((string) $document['purpose'])) . '<span class="rp-row-meta">Documento #' . (int) $document['id'] . ' · Versão ' . (int) $document['revision'] . '</span></td><td>';
            self::badge((string) $document['status']);
            if ($document['status'] === 'complete' && $document['attachment_status'] !== 'attached') { echo '<span class="rp-row-meta">Anexo do chamado pendente</span>'; }
            echo '</td><td>';
            $label = !empty($document['can_sign']) && $document['status'] !== 'complete' ? __('Abrir e assinar', 'reservaplus') : __('Ver documento', 'reservaplus');
            if (in_array($document['status'], ['accepted','artifact_error'], true)) { $label = __('Conferir publicação', 'reservaplus'); }
            self::link('agreement.php', $label, ['id' => (int) $document['id']] + self::ticketContextQuery(self::$ticketContext), !empty($document['can_sign']) && $document['status'] !== 'complete' ? 'btn-primary' : 'btn-outline-secondary');
            echo '</td></tr>';
        }
        echo '</tbody></table></div>';
    }

    private static function renderDocuments(array $booking): void
    {
        $id = (int) $booking['id'];
        echo '<section class="rp-panel" id="rp-booking-documents"><h2>' . self::e(__('Documentos e assinaturas', 'reservaplus')) . '</h2>';
        $documents = DocumentService::forBooking($id);
        $current = array_values(array_filter($documents, static fn(array $d): bool => !empty($d['is_current'])));
        $historical = array_values(array_filter($documents, static fn(array $d): bool => empty($d['is_current'])));
        if ($current !== []) { self::documentTable($current); }
        $state = (string) $booking['status'];
        $purposes = [];
        if ($state === 'submitted' && in_array('request', BookingService::signaturePurposes($booking['lines'] ?? []), true)) { $purposes['request'] = true; }
        if (in_array($state, ['approved','ready'], true)) {
            foreach (BookingService::signaturePurposes($booking['lines'] ?? []) as $purpose) { $purposes[$purpose] = true; }
        }
        foreach ($booking['lines'] ?? [] as $line) {
            if (!empty($line['received_at'])) { $purposes['return_receipt'] = false; }
            if (!empty($line['inspection_at'])) { $purposes['return_inspection'] = true; }
        }
        if (in_array($state, ['completed','cancelled','rejected'], true)) { $purposes = []; }
        $owner = in_array(Access::userId(), [(int) $booking['users_id_requester'], (int) $booking['users_id_borrower']], true);
        $canPrepare = $owner || Access::right('admin');
        foreach (['issue','receive','inspect','approve'] as $cap) { $canPrepare = $canPrepare || Access::booking($booking, $cap); }
        $existing = array_column($current, 'purpose');
        $missing = array_diff(array_keys($purposes), $existing);
        foreach ($missing as $purpose) {
            echo '<div class="rp-document-action"><div><h3>' . self::e(self::purposeLabel($purpose)) . '</h3><p class="rp-muted">' . self::e($purposes[$purpose] ? __('Prepare o documento desta etapa para os signatários conferirem e aceitarem.', 'reservaplus') : __('Emita um recibo dos itens recebidos, mesmo antes da conferência final.', 'reservaplus')) . '</p></div>';
            if ($canPrepare) {
                self::formStart('prepare_document', $id, (int) $booking['revision']);
                self::hidden('purpose', $purpose);
                self::submit($purposes[$purpose] ? __('Preparar documento', 'reservaplus') : __('Emitir recibo', 'reservaplus'));
                Html::closeForm();
            }
            echo '</div>';
        }
        if ($current === [] && $missing === []) {
            echo '<p class="rp-muted">' . self::e($state === 'submitted' ? __('Os documentos aplicáveis serão apresentados aqui conforme a aprovação e o andamento da reserva.', 'reservaplus') : __('Nenhum documento pendente de preparação nesta etapa.', 'reservaplus')) . '</p>';
        }
        if ($historical !== []) {
            echo '<details class="rp-document-history"><summary>' . self::e(sprintf(__('Versões anteriores (%d)', 'reservaplus'), count($historical))) . '</summary><p class="rp-muted">' . self::e(__('Preservadas para consulta; as ações da reserva usam a versão atual.', 'reservaplus')) . '</p>';
            self::documentTable($historical);
            echo '</details>';
        }
        echo '</section>';
    }

    private static function actionLines(array $booking, string $capability, array $states = []): array
    {
        $lines = [];
        foreach ($booking['lines'] ?? [] as $line) {
            if ($states !== [] && !in_array((string) ($line['state'] ?? ''), $states, true)) {
                continue;
            }
            try {
                if ($capability === 'approve') {
                    if (self::approvalProgress($booking, $line)['status'] === 'pending' && Access::canApproveLine($booking, $line)) { $lines[] = $line; }
                    continue;
                }
                $resource = Store::row('resources', (int) $line['resources_id']);
                if (Access::resource($resource, $capability)) {
                    $lines[] = $line;
                }
            } catch (\RuntimeException) {
                // A broken link cannot be acted upon from this screen.
            }
        }
        return $lines;
    }

    private static function chooseLines(array $lines, bool $accessories = false, bool $checked = false): void
    {
        echo '<fieldset><legend class="fs-6">' . self::e(__('Selecione os itens desta operação', 'reservaplus')) . '</legend>';
        foreach ($lines as $line) {
            $id = (int) $line['id'];
            echo '<label class="rp-check"><input type="checkbox" name="lines_ids[]" value="' . $id . '"' . ($checked ? ' checked' : '') . '><span>' . self::e(self::lineName($line)) . '</span></label>';
            if ($accessories) {
                foreach (Store::decode($line['accessories_json'] ?? null) as $index => $accessory) {
                    if (!is_array($accessory) || !is_numeric($index)) {
                        continue;
                    }
                    $issued = (int) ($accessory['issued'] ?? $accessory['quantity'] ?? 0);
                    $received = (int) ($accessory['received'] ?? $accessory['returned'] ?? 0);
                    $outstanding = max(0, $issued - $received);
                    if ($outstanding > 0) {
                        self::input('accessories[' . $id . '][' . (int) $index . ']', sprintf(__('Recebidos agora: %s', 'reservaplus'), $accessory['name'] ?? ''), 0, 'number', false, 'min="0" max="' . $outstanding . '" step="1"');
                    }
                }
            }
        }
        echo '</fieldset>';
    }

    private static function actionStart(array $booking, string $action, string $label, string $description = ''): void
    {
        $color = match ($action) {
            'reject' => 'rp-action-reject',
            'cancel' => 'rp-action-cancel',
            'extend' => 'rp-action-reschedule',
            default => 'rp-action-neutral',
        };
        echo '<details class="rp-subform"><summary class="btn rp-detail-action-button ' . $color . '">' . self::e($label) . '</summary>';
        if ($description !== '') {
            echo '<p>' . self::e($description) . '</p>';
        }
        self::formStart($action, (int) $booking['id'], (int) $booking['revision']);
    }

    private static function actionEnd(string $label): void
    {
        self::submit($label);
        Html::closeForm();
        echo '</details>';
    }

    private static function renderActions(array $booking): void
    {
        $state = (string) $booking['status'];
        if (in_array($state, ['completed', 'rejected', 'cancelled'], true)) {
            return;
        }
        echo '<section class="rp-panel"><h2>' . self::e(__('Próximas ações', 'reservaplus')) . '</h2><div class="rp-detail-action-grid">';
        $approveLines = self::actionLines($booking, 'approve', ['requested']);
        if ($approveLines !== [] && $state === 'submitted') {
            self::formStart('approve', (int) $booking['id'], (int) $booking['revision']);
            foreach ($approveLines as $line) { self::hidden('lines_ids[]', (int) $line['id']); }
            echo '<button type="submit" class="btn rp-detail-action-button rp-action-approve" onclick="return confirm(' . self::e(json_encode(__('Confirma a aprovação dos recursos autorizados nesta solicitação?', 'reservaplus'), JSON_HEX_APOS | JSON_HEX_QUOT)) . ')">' . self::e(__('Autorizar recursos', 'reservaplus')) . ' (' . count($approveLines) . ')</button></form>';
            self::actionStart($booking, 'reject', __('Rejeitar solicitação', 'reservaplus'));
            self::chooseLines($approveLines, false, true);
            self::textarea('reason', __('Justificativa da rejeição', 'reservaplus'), '', true);
            self::actionEnd(__('Confirmar rejeição', 'reservaplus'));
        }
        if (in_array($state, ['approved', 'ready'], true) && Access::booking($booking, 'issue')) {
            self::actionStart($booking, 'issue', __('Registrar entrega / início de uso', 'reservaplus'), __('Confira a identidade, os recursos e os acessórios. Os termos obrigatórios devem estar aceitos antes da entrega.', 'reservaplus'));
            self::textarea('condition', __('Condição na entrega e conferência dos acessórios', 'reservaplus'), '', true);
            self::actionEnd(__('Confirmar entrega', 'reservaplus'));
        }
        $receiveLines = array_values(array_filter(self::actionLines($booking, 'receive', ['issued', 'received', 'quarantine', 'maintenance']), static fn(array $line): bool => (self::lineSnapshot($line)['kind'] ?? '') !== 'room'));
        if ($receiveLines !== [] && in_array($state, ['issued', 'returning'], true)) {
            self::actionStart($booking, 'receive', __('Receber devolução', 'reservaplus'), __('Marque apenas os itens que recebeu e informe a quantidade de cada acessório entregue agora. O recebimento não declara que a conferência foi aprovada.', 'reservaplus'));
            self::chooseLines($receiveLines, true);
            self::input('deliverer', __('Nome de quem entregou os itens', 'reservaplus'), '', 'text', true, 'maxlength="255"');
            self::textarea('note', __('Observações do recebimento', 'reservaplus'));
            self::actionEnd(__('Registrar recebimento agora', 'reservaplus'));
        }
        $inspectLines = array_values(array_filter(self::actionLines($booking, 'inspect', ['received', 'quarantine', 'maintenance', 'allocated', 'issued']), static fn(array $line): bool => in_array($line['state'], ['received', 'quarantine', 'maintenance'], true) || (self::lineSnapshot($line)['kind'] ?? '') === 'room'));
        if ($inspectLines !== [] && in_array($state, ['approved', 'issued', 'returning'], true)) {
            self::actionStart($booking, 'inspect', __('Conferir devolução / término de uso', 'reservaplus'), __('Registre a conferência dos equipamentos recebidos ou das salas após o uso. Aponte faltas, avarias e testes realizados; nenhuma verificação é aprovada automaticamente.', 'reservaplus'));
            self::chooseLines($inspectLines);
            self::select('result', __('Resultado da conferência', 'reservaplus'), ['' => __('Selecione o resultado', 'reservaplus'), 'pass' => __('Aprovado para novo uso', 'reservaplus'), 'quarantine' => __('Reter para avaliação', 'reservaplus'), 'maintenance' => __('Encaminhar para manutenção', 'reservaplus')]);
            self::textarea('findings', __('Verificações, condição e pendências', 'reservaplus'), '', true);
            self::actionEnd(__('Registrar conferência', 'reservaplus'));
        }
        $allReady = ($booking['lines'] ?? []) !== [];
        foreach ($booking['lines'] ?? [] as $line) {
            $allReady = $allReady && ($line['state'] ?? '') === 'ready' && !empty($line['inspection_at']);
        }
        if ($allReady && Access::booking($booking, 'inspect') && $state === 'returning') {
            self::actionStart($booking, 'complete', __('Concluir solicitação', 'reservaplus'), __('A conclusão exige os registros de devolução ou término de uso e os documentos obrigatórios. Encerrar não substitui os registros físicos.', 'reservaplus'));
            self::actionEnd(__('Verificar requisitos e concluir', 'reservaplus'));
        }
        if (Access::canReschedule($booking) && in_array($state, ['submitted', 'approved', 'ready'], true)) {
            self::actionStart($booking, 'extend', __('Alterar período antes da entrega', 'reservaplus'), __('A alteração será validada contra a disponibilidade e pode exigir novos termos. Esta ação não altera empréstimos já entregues.', 'reservaplus'));
            self::input('begin', __('Novo início', 'reservaplus'), self::datetimeInput($booking['begin'] ?? ''), 'datetime-local', true);
            self::input('end', __('Novo término', 'reservaplus'), self::datetimeInput($booking['end'] ?? ''), 'datetime-local', true);
            self::actionEnd(__('Validar e alterar período', 'reservaplus'));
        }
        if (in_array($state, ['submitted', 'approved'], true) && (Access::booking($booking, 'request') || Access::booking($booking, 'manage'))) {
            self::actionStart($booking, 'cancel', __('Cancelar antes da entrega', 'reservaplus'));
            self::textarea('reason', __('Motivo do cancelamento', 'reservaplus'), '', true);
            self::actionEnd(__('Cancelar solicitação', 'reservaplus'));
        }
        echo '</div></section>';
    }

    private static function renderHistory(array $booking): void
    {
        $labels = ['submitted'=>'Solicitação enviada', 'approve'=>'Aprovação registrada', 'approved'=>'Aprovação registrada', 'reject'=>'Solicitação rejeitada', 'issue'=>'Entrega confirmada', 'issued'=>'Entrega registrada', 'receive'=>'Recebimento confirmado', 'received'=>'Recebimento registrado', 'inspect'=>'Conferência confirmada', 'inspected'=>'Conferência registrada', 'complete'=>'Solicitação concluída', 'cancel'=>'Solicitação cancelada', 'extend'=>'Período alterado', 'signed'=>'Assinatura registrada'];
        echo '<section class="rp-panel"><h2>' . self::e(__('Histórico', 'reservaplus')) . '</h2><ol class="rp-timeline">';
        foreach ($booking['events'] ?? [] as $event) {
            $type = (string) ($event['type'] ?? $event['event_type'] ?? '');
            echo '<li><time>' . self::e(self::date($event['occurred_at'] ?? $event['recorded_at'] ?? '')) . '</time><strong>' . self::e(__($labels[$type] ?? 'Atualização registrada', 'reservaplus')) . '</strong></li>';
        }
        echo '</ol></section>';
    }
}
