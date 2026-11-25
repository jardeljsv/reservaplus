<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Browse, select, explain and review. No booking mutation is performed here. */
final class RequestIntakeView
{
    public const PAGE_SIZE = 12;
    private static array $availability = [];

    public static function assets(): void
    {
        global $CFG_GLPI;
        $base = rtrim((string) ($CFG_GLPI['root_doc'] ?? ''), '/') . '/plugins/reservaplus/';
        $version = defined('PLUGIN_RESERVAPLUS_VERSION') ? PLUGIN_RESERVAPLUS_VERSION : '0.7';
        echo '<link rel="stylesheet" href="' . BookingView::e($base . 'css/intake.css?v=' . $version) . '">';
        echo '<script defer src="' . BookingView::e($base . 'js/intake.js?v=' . $version) . '"></script>';
    }

    public static function selected(array $draft): array
    {
        $rows = [];
        foreach ($draft['resources_ids'] as $id) {
            try {
                $row = Resource::getRow((int) $id);
                Access::assertResource($row, 'request');
                $rows[(int) $id] = ResourceService::decorate($row);
            } catch (\Throwable) {
                $rows[(int) $id] = ['id' => (int) $id, 'name' => __('Item não disponível para esta solicitação', 'reservaplus'), '_unavailable' => true];
            }
        }
        return $rows;
    }

    public static function hasRoom(array $rows): bool
    {
        foreach ($rows as $row) { if (($row['kind'] ?? '') === 'room') { return true; } }
        return false;
    }

    public static function availability(array $row, array $draft): array
    {
        if (!empty($row['_unavailable'])) { return ['available' => false, 'reasons' => [__('O acesso ou a publicação deste item mudou. Remova-o para continuar.', 'reservaplus')]]; }
        $key = $row['id'] . '|' . $draft['begin'] . '|' . $draft['end'];
        if (!isset(self::$availability[$key])) {
            try {
                [, $end] = Booking::period((string) $draft['begin'], (string) $draft['end']);
                if ($end <= Store::now()) { throw new \DomainException(__('Escolha um período que termine no futuro.', 'reservaplus')); }
                self::$availability[$key] = BookingService::availability((int) $row['id'], (string) $draft['begin'], (string) $draft['end']);
            } catch (\DomainException|\InvalidArgumentException $error) {
                self::$availability[$key] = ['available' => false, 'reasons' => [$error->getMessage()]];
            } catch (\Throwable $error) {
                BookingView::publicError($error);
                self::$availability[$key] = ['available' => false, 'reasons' => [__('Não foi possível confirmar a disponibilidade. Tente atualizar a consulta.', 'reservaplus')]];
            }
        }
        $result = self::$availability[$key];
        if (($row['kind'] ?? '') === 'room' && (int) ($row['capacity'] ?? 0) > 0 && (int) $draft['people_count'] > (int) $row['capacity']) {
            $result['available'] = false;
            $result['reasons'][] = sprintf(__('A sala comporta até %d pessoas.', 'reservaplus'), (int) $row['capacity']);
        }
        return $result;
    }

    public static function validate(array $draft, bool $details = false): array
    {
        global $DB;
        $errors = [];
        if ($draft['resources_ids'] === []) { $errors[] = __('Escolha pelo menos um recurso para continuar. Você pode selecionar vários itens.', 'reservaplus'); }
        try {
            [, $end] = Booking::period((string) $draft['begin'], (string) $draft['end']);
            if ($end <= Store::now()) { $errors[] = __('Escolha um período que termine no futuro.', 'reservaplus'); }
        } catch (\Throwable) { $errors[] = __('Informe início e término válidos; o término deve ser posterior ao início.', 'reservaplus'); }
        $rows = self::selected($draft);
        $entities = $groups = [];
        foreach ($rows as $row) {
            $availability = self::availability($row, $draft);
            if (!$availability['available']) { $errors[] = (string) $row['name'] . ': ' . implode(' ', $availability['reasons']); }
            if (!empty($row['_unavailable'])) { continue; }
            $entities[] = (int) $row['entities_id']; $groups[] = (int) $row['groups_id_ticket'];
            try {
                $policy = ResourceService::policy($row);
                ApprovalPolicy::snapshot($row);
                $duration = (strtotime((string) $draft['end']) - strtotime((string) $draft['begin'])) / 60;
                if ($duration > $policy['max_duration_minutes']) { $errors[] = sprintf(__('%s: o período excede a duração permitida para este recurso.', 'reservaplus'), $row['name']); }
            } catch (\Throwable) { $errors[] = __('A equipe precisa revisar a configuração de um dos recursos selecionados.', 'reservaplus'); }
        }
        if (count(array_unique($entities)) > 1 || count(array_unique($groups)) > 1) {
            $errors[] = __('Os itens selecionados são atendidos por equipes diferentes. Separe-os em solicitações para cada equipe; sua seleção continua disponível para ajustar.', 'reservaplus');
        }
        try { BookingService::assertApprovalDisclosure($rows); }
        catch (\DomainException|\InvalidArgumentException $error) { $errors[] = $error->getMessage(); }
        if ($details && (int) $draft['users_id_borrower'] !== Access::userId()) {
            $borrower = new \User(); $allowed = Access::right('on_behalf');
            if (!$borrower->getFromDB((int) $draft['users_id_borrower']) || empty($borrower->fields['is_active']) || !empty($borrower->fields['is_deleted'])) { $allowed = false; }
            $borrowerEntities = array_map('intval', \Profile_User::getUserEntities((int) $draft['users_id_borrower'], true));
            foreach ($rows as $row) {
                if (!empty($row['_unavailable'])) { $allowed = false; continue; }
                if (!Access::resource($row, 'on_behalf') || !in_array((int) $row['entities_id'], $borrowerEntities, true)) { $allowed = false; }
                $requesterGroups = ResourceService::policy($row)['requester_groups_ids'];
                if ($requesterGroups !== [] && !$DB->request(['FROM' => 'glpi_groups_users', 'WHERE' => ['users_id' => (int) $draft['users_id_borrower'], 'groups_id' => $requesterGroups], 'LIMIT' => 1])->current()) { $allowed = false; }
            }
            if (!$allowed) { $errors[] = __('O usuário escolhido não está habilitado para esta seleção. Confira o responsável pelo uso.', 'reservaplus'); }
        }
        if ($details && trim((string) ($draft['purpose'] ?? '')) === '') {
            $errors[] = __('Informe para que os recursos serão utilizados.', 'reservaplus');
        }
        if ($details && trim((string) ($draft['unit'] ?? '')) === '') {
            $errors[] = __('Informe a Unidade / setor do usuário para continuar a reserva.', 'reservaplus');
        }
        return array_values(array_unique($errors));
    }

    public static function catalog(array $draft): array
    {
        $filters = ['publication' => 'published', 'q' => $draft['q']];
        if ($draft['kind'] !== '') { $filters['kind'] = $draft['kind']; }
        $page = max(1, (int) $draft['page']); $skip = ($page - 1) * self::PAGE_SIZE;
        $rows = []; $matched = 0; $offset = 0;
        do {
            $batch = ResourceService::search($filters, 24, $offset);
            foreach ($batch as $row) {
                if (!Access::resource($row, 'request')) { continue; }
                if ($draft['location'] !== '' && !str_contains(self::normalize(BookingView::locator($row)), self::normalize($draft['location']))) { continue; }
                if ($draft['kind'] === 'room' && $draft['feature'] !== '' && !str_contains(self::normalize((string) ($row['features'] ?? '')), self::normalize($draft['feature']))) { continue; }
                if ($draft['fit'] && ($row['kind'] ?? '') === 'room' && (int) ($row['capacity'] ?? 0) > 0 && (int) $row['capacity'] < (int) $draft['people_count']) { continue; }
                $availability = self::availability($row, $draft);
                if ($draft['available_only'] && !$availability['available']) { continue; }
                if ($matched++ < $skip) { continue; }
                $row['_availability'] = $availability;
                $rows[] = $row;
                if (count($rows) > self::PAGE_SIZE) { return ['rows' => array_slice($rows, 0, self::PAGE_SIZE), 'more' => true]; }
            }
            $offset += count($batch);
        } while (count($batch) === 24);
        return ['rows' => $rows, 'more' => false];
    }

    private static function normalize(string $value): string
    {
        return strtr(mb_strtolower(trim($value)), ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ç'=>'c']);
    }

    private static function button(string $intent, string $label, string $class = 'btn-outline-secondary', string $extra = ''): void
    {
        echo '<button type="submit" name="intent" value="' . BookingView::e($intent) . '" class="btn ' . BookingView::e($class) . '" formnovalidate ' . $extra . '>' . BookingView::e($label) . '</button>';
    }

    private static function form(array $draft): void
    {
        echo '<form id="rp-intake-form" method="post" action="' . BookingView::e(BookingView::url('booking.form.php')) . '" data-rp-intake-form>';
        echo '<button type="submit" name="intent" value="filter" hidden aria-hidden="true" tabindex="-1"></button>';
        BookingView::hidden('draft_id', $draft['id']); BookingView::hidden('view_step', $draft['step']);
    }

    public static function render(array $draft, array $errors = []): void
    {
        self::assets();
        $selected = self::selected($draft);
        echo '<div class="rp-intake" data-testid="request-intake" data-draft-id="' . BookingView::e($draft['id']) . '">';
        echo '<ol class="rp-intake-steps" aria-label="Etapas da solicitação">';
        foreach (['browse' => 'Escolher recursos', 'details' => 'Informar o uso', 'review' => 'Revisar e assinar'] as $key => $label) {
            echo '<li' . ($draft['step'] === $key ? ' aria-current="step" class="is-current"' : '') . '><span>' . BookingView::e($label) . '</span></li>';
        }
        echo '</ol>';
        foreach ($errors as $error) { BookingView::notice($error, true); }
        if ((int) $draft['tickets_id'] > 0) { echo '<p class="rp-intake-ticket">Esta reserva será organizada no chamado <a href="' . BookingView::e(BookingView::ticketUrl((int) $draft['tickets_id'])) . '">#' . (int) $draft['tickets_id'] . '</a>.</p>'; }
        if ($draft['step'] === 'review') { self::review($draft, $selected); }
        else {
            self::form($draft);
            echo '<div class="rp-intake-layout"><div class="rp-intake-main">';
            if ($draft['step'] === 'browse') { self::browse($draft); }
            else { self::details($draft, $selected); }
            echo '</div>';
            self::cart($draft, $selected);
            echo '</div>';
            \Html::closeForm();
        }
        echo '</div>';
    }

    private static function browse(array $draft): void
    {
        $catalog = self::catalog($draft);
        echo '<section class="rp-panel rp-intake-browse"><h2>O que você precisa reservar?</h2><p class="rp-muted">Escolha uma categoria e selecione um ou vários itens. Não é necessário saber o nome do recurso.</p>';
        echo '<div class="rp-kind-choices" role="group" aria-label="Tipo de reserva">';
        foreach (['' => ['Todos os recursos', 'ti-layout-grid'], 'equipment' => ['Equipamentos', 'ti-device-laptop'], 'room' => ['Salas', 'ti-door'], 'other' => ['Outros recursos', 'ti-box']] as $kind => [$label, $icon]) {
            echo '<button type="submit" name="choose_kind" value="' . BookingView::e($kind) . '" class="rp-kind-choice' . ($draft['kind'] === $kind ? ' is-selected' : '') . '" aria-pressed="' . ($draft['kind'] === $kind ? 'true' : 'false') . '" data-testid="intake-kind-' . ($kind ?: 'all') . '"><i class="ti ' . $icon . '" aria-hidden="true"></i><span>' . BookingView::e($label) . '</span></button>';
        }
        echo '</div><div class="rp-intake-period"><div class="rp-intake-period-heading"><h3><i class="ti ti-calendar-event" aria-hidden="true"></i> Quando você vai usar?</h3><span class="rp-muted">O mesmo período vale para os itens selecionados.</span></div><div class="rp-grid' . ($draft['kind'] === 'room' ? ' rp-period-has-room' : '') . '">';
        BookingView::input('begin', 'Início / retirada', BookingView::datetimeInput($draft['begin']), 'datetime-local', false, 'data-testid="intake-begin"');
        BookingView::input('end', 'Término / devolução', BookingView::datetimeInput($draft['end']), 'datetime-local', false, 'data-testid="intake-end"');
        if ($draft['kind'] === 'room') { BookingView::input('people_count', 'Pessoas', (int) $draft['people_count'], 'number', false, 'min="1" max="100000"'); }
        echo '</div><div class="rp-duration-presets"><span>Duração rápida</span>';
        foreach ([60 => '1 hora', 120 => '2 horas', 240 => '4 horas'] as $minutes => $label) { self::button('duration:' . $minutes, $label, 'btn-outline-secondary btn-sm', 'data-duration="' . $minutes . '"'); }
        $filtersOpen = $draft['q'] !== '' || $draft['location'] !== '' || $draft['feature'] !== '' || $draft['fit'];
        echo '</div></div><div class="rp-intake-filters"><details class="rp-intake-filter-details"' . ($filtersOpen ? ' open' : '') . '><summary><i class="ti ti-adjustments-horizontal" aria-hidden="true"></i> Refinar a lista por nome, local ou características</summary><div class="rp-grid">';
        BookingView::input('q', 'Filtrar a lista (opcional)', $draft['q'], 'search', false, 'placeholder="Nome, patrimônio ou referência" maxlength="160"');
        BookingView::input('location', 'Local / prédio (opcional)', $draft['location'], 'search', false, 'placeholder="Filtrar por localização" maxlength="160"');
        if ($draft['kind'] === 'room') {
            BookingView::input('feature', 'Recurso da sala (opcional)', $draft['feature'], 'search', false, 'placeholder="Ex.: projetor, videoconferência" maxlength="100"');
        }
        echo '</div>';
        if ($draft['kind'] === 'room') { echo '<label class="rp-check"><input type="checkbox" name="fit" value="1"' . ($draft['fit'] ? ' checked' : '') . '> Salas que comportam a quantidade de pessoas</label>'; }
        echo '</details><div class="rp-intake-filter-actions">';
        echo '<label class="rp-check"><input type="checkbox" name="available_only" value="1"' . ($draft['available_only'] ? ' checked' : '') . '> Somente disponíveis no período</label>';
        self::button('filter', 'Atualizar disponibilidade', 'btn-outline-primary', 'data-testid="intake-filter"');
        echo '</div></div>';
        BookingView::hidden('page', $draft['page']);
        if ($catalog['rows'] === []) {
            echo '<div class="rp-intake-empty"><i class="ti ti-search" aria-hidden="true"></i><h3>Nenhum recurso neste filtro</h3><p>Experimente outra categoria, limpe os filtros ou altere o período. Os itens já selecionados continuam na sua seleção.</p>';
            if (Access::right('manage')) { BookingView::link('resource.php', 'Gerenciar recursos publicados'); }
            else { echo '<p class="rp-muted">Se a categoria ainda não possui recursos publicados para seu perfil, procure a equipe responsável.</p>'; }
            echo '</div>';
        } else {
            echo '<div class="rp-intake-results-heading"><h3>Escolha os itens</h3><div>';
            self::button('select_visible', 'Selecionar disponíveis desta página', 'btn-link btn-sm', 'data-testid="intake-select-visible"');
            echo '</div></div><div class="rp-catalog-grid" data-rp-catalog>';
            foreach ($catalog['rows'] as $resource) { self::card($resource, $draft); }
            echo '</div>';
        }
        if ((int) $draft['page'] > 1 || $catalog['more']) {
            echo '<nav class="rp-intake-pagination" aria-label="Páginas de recursos">';
            if ((int) $draft['page'] > 1) { self::button('page:' . ((int) $draft['page'] - 1), 'Anterior', 'btn-outline-secondary', 'data-testid="intake-previous-page"'); }
            echo '<span>Página ' . (int) $draft['page'] . '</span>';
            if ($catalog['more']) { self::button('page:' . ((int) $draft['page'] + 1), 'Próxima', 'btn-outline-secondary', 'data-testid="intake-next-page"'); }
            echo '</nav>';
        }
        echo '<p class="rp-intake-assurance"><i class="ti ti-info-circle" aria-hidden="true"></i> Sua seleção é mantida ao trocar de página ou categoria. A reserva só será solicitada após a revisão. Horários em ' . BookingView::e(date_default_timezone_get()) . '.</p></section>';
    }

    private static function card(array $resource, array $draft): void
    {
        $id = (int) $resource['id']; $selected = in_array($id, $draft['resources_ids'], true);
        $available = !empty($resource['_availability']['available']);
        BookingView::hidden('visible_ids[]', $id);
        if ($available) { BookingView::hidden('selectable_ids[]', $id); }
        // A selected item that became busy stays selected until the user removes it.
        echo '<article class="rp-catalog-card' . ($selected ? ' is-selected' : '') . (!$available ? ' is-unavailable' : '') . '" data-resource-card data-resource-id="' . $id . '" data-resource-name="' . BookingView::e($resource['name']) . '" data-resource-kind="' . BookingView::e($resource['kind']) . '">';
        echo '<label class="rp-catalog-pick"><input type="checkbox" name="resources_ids[]" value="' . $id . '"' . ($selected ? ' checked' : '') . (!$available && !$selected ? ' disabled' : '') . ' data-testid="resource-' . $id . '"><span class="rp-catalog-icon"><i class="ti ' . ($resource['kind'] === 'room' ? 'ti-door' : ($resource['kind'] === 'equipment' ? 'ti-device-laptop' : 'ti-box')) . '" aria-hidden="true"></i></span><span class="rp-catalog-title">' . BookingView::e($resource['name']) . '</span></label>';
        $locator = BookingView::locator($resource);
        if ($locator !== '') { echo '<p class="rp-catalog-location"><i class="ti ti-map-pin" aria-hidden="true"></i> ' . BookingView::e($locator) . '</p>'; }
        echo '<div class="rp-catalog-tags">';
        if ($resource['kind'] === 'room' && (int) ($resource['capacity'] ?? 0) > 0) { echo '<span><i class="ti ti-users" aria-hidden="true"></i> Até ' . (int) $resource['capacity'] . ' pessoas</span>'; }
        if (!empty($resource['inventory_number'])) { echo '<span>Patrimônio ' . BookingView::e($resource['inventory_number']) . '</span>'; }
        echo '</div>';
        if (!empty($resource['features'])) { echo '<p class="rp-catalog-features">' . BookingView::e(mb_strimwidth((string) $resource['features'], 0, 150, '…')) . '</p>'; }
        echo '<p class="rp-availability ' . ($available ? 'is-available' : 'is-busy') . '"><i class="ti ' . ($available ? 'ti-circle-check' : 'ti-clock-exclamation') . '" aria-hidden="true"></i> ' . ($available ? 'Disponível no período' : 'Indisponível no período') . '</p>';
        if (!$available) { echo '<p class="rp-catalog-reason">' . BookingView::e(implode(' ', $resource['_availability']['reasons'])) . '</p>'; }
        echo '<details class="rp-catalog-more"><summary>Ver detalhes e condições</summary>';
        if (!empty($resource['_sector_name'])) { echo '<p><strong>Setor:</strong> ' . BookingView::e($resource['_sector_name']) . '</p>'; }
        if (!empty($resource['_ticket_group_name'])) { echo '<p><strong>Atendimento:</strong> ' . BookingView::e($resource['_ticket_group_name']) . '</p>'; }
        if (!empty($resource['directions'])) { echo '<p>' . BookingView::e($resource['directions']) . '</p>'; }
        $policy = ResourceService::policy($resource);
        echo '<p>' . (!empty($policy['approval_required']) ? 'A equipe precisa aprovar esta solicitação.' : 'Confirmação sujeita à disponibilidade.') . '</p>';
        if ($policy['signature_stage'] !== 'none') { echo '<p>O responsável pelo uso receberá o termo para aceite eletrônico na etapa indicada.</p>'; }
        echo '</details></article>';
    }

    private static function periodSummary(array $draft): void
    {
        echo '<div class="rp-intake-period-summary"><i class="ti ti-calendar-event" aria-hidden="true"></i><div><strong>' . BookingView::e(self::date($draft['begin'])) . '</strong><span>até ' . BookingView::e(self::date($draft['end'])) . '</span></div></div>';
    }

    public static function date(string $date): string
    {
        $time = strtotime($date);
        return $time === false ? $date : date('d/m/Y \à\s H:i', $time);
    }

    private static function cart(array $draft, array $selected): void
    {
        echo '<aside class="rp-intake-cart" aria-label="Sua seleção"><div class="rp-panel"><div class="rp-cart-heading"><h2>Sua seleção</h2><span class="rp-cart-count" data-testid="cart-count" data-cart-count>' . count($draft['resources_ids']) . '</span></div>';
        self::periodSummary($draft);
        echo '<ul class="rp-cart-list" data-cart-list>';
        foreach ($selected as $id => $row) {
            $available = self::availability($row, $draft);
            echo '<li data-cart-item="' . $id . '"><div><strong>' . BookingView::e($row['name']) . '</strong>';
            if (!$available['available']) { echo '<span class="rp-cart-problem">' . BookingView::e(implode(' ', $available['reasons'])) . '</span>'; }
            echo '</div><button type="submit" name="intent" value="remove:' . $id . '" class="rp-cart-remove" aria-label="Remover ' . BookingView::e($row['name']) . '"><i class="ti ti-x" aria-hidden="true"></i></button></li>';
        }
        echo '</ul><p class="rp-cart-empty" data-cart-empty' . ($selected !== [] ? ' hidden' : '') . '>Selecione os cartões ao lado. É possível combinar vários equipamentos e salas na mesma solicitação.</p>';
        if ($selected !== []) { self::button('clear', 'Limpar seleção', 'btn-link btn-sm'); }
        echo '<p class="rp-muted">Até 32 itens atendidos pela mesma equipe. Nenhuma reserva foi criada ainda.</p>';
        if ($draft['step'] === 'browse') { self::button('next', 'Continuar com a seleção', 'btn-primary rp-intake-primary', 'data-testid="intake-next"'); }
        else { self::button('review', 'Revisar solicitação', 'btn-primary rp-intake-primary', 'data-testid="intake-review"'); }
        echo '</div>';
        if ($selected !== [] && array_filter($selected, static fn(array $row): bool => !self::availability($row, $draft)['available'])) { self::alternatives($draft, $selected); }
        echo '<p class="rp-intake-help">A consulta mostra a disponibilidade atual. A equipe confirmará a reserva conforme as regras de cada recurso.</p></aside>';
    }

    private static function alternatives(array $draft, array $selected): void
    {
        try { [$begin, $end] = Booking::period($draft['begin'], $draft['end']); } catch (\Throwable) { return; }
        if (array_filter($selected, static fn(array $row): bool => !empty($row['_unavailable']))) { return; }
        $duration = strtotime($end) - strtotime($begin); $found = [];
        foreach ([1800, 3600, 7200, 86400, 90000, 172800] as $shift) {
            $candidate = $draft; $start = strtotime($begin) + $shift;
            if ($start < time()) { continue; }
            $candidate['begin'] = date('Y-m-d\TH:i', $start); $candidate['end'] = date('Y-m-d\TH:i', $start + $duration);
            $ok = true;
            foreach ($selected as $row) { if (!self::availability($row, $candidate)['available']) { $ok = false; break; } }
            if ($ok) { $found[$start] = self::date($candidate['begin']); }
            if (count($found) === 2) { break; }
        }
        echo '<div class="rp-panel rp-intake-alternatives"><h3>Outros horários para a seleção</h3>';
        if ($found === []) { echo '<p>Não encontramos uma alternativa próxima para todos os itens. Altere o período ou remova os itens indisponíveis.</p>'; }
        else { foreach ($found as $start => $label) { self::button('slot:' . $start, $label, 'btn-outline-primary btn-sm'); } echo '<p class="rp-muted">Mantém a duração e consulta novamente antes de enviar.</p>'; }
        echo '</div>';
    }

    private static function details(array $draft, array $selected): void
    {
        echo '<section class="rp-panel"><h2>Como os recursos serão utilizados?</h2><p class="rp-muted">Preencha as informações necessárias para a equipe organizar o atendimento.</p>';
        self::periodSummary($draft);
        self::button('back_browse', 'Alterar recursos ou período', 'btn-link', 'data-testid="intake-back"');
        if (self::hasRoom($selected)) { BookingView::input('people_count', 'Quantidade de participantes', $draft['people_count'], 'number', true, 'min="1" max="100000" data-testid="intake-participants"'); }
        else { BookingView::hidden('people_count', 1); }
        if (Access::right('on_behalf')) {
            echo '<div class="rp-field"><label>Usuário responsável pelo uso</label>';
            $borrowerValue = (int) $draft['users_id_borrower'];
            if ($borrowerValue !== Access::userId() && array_intersect(Access::entityIds(), array_map('intval', \Profile_User::getUserEntities($borrowerValue, true))) === []) { $borrowerValue = Access::userId(); }
            \User::dropdown(['name' => 'users_id_borrower', 'value' => $borrowerValue, 'entity' => Access::entityIds(), 'right' => 'all', 'width' => '100%']);
            echo '</div>';
        } else { echo '<p class="rp-intake-identity"><i class="ti ti-user" aria-hidden="true"></i> Você será o responsável pelo uso e pelos aceites desta solicitação.</p>'; }
        BookingView::input('unit', 'Unidade / setor do usuário', $draft['unit'] ?? '',
            'text', true, 'maxlength="255" autocomplete="organization"');
        echo '<div class="rp-field"><label for="rp-intake-purpose">Finalidade do uso</label><textarea id="rp-intake-purpose" name="purpose" maxlength="5000" required>' . BookingView::e($draft['purpose']) . '</textarea></div>';
        echo '</section>';
    }

    private static function review(array $draft, array $selected): void
    {
        $equipmentSelected = in_array('equipment', array_column($selected, 'kind'), true);
        $beneficiaryIsActor = (int) $draft['users_id_borrower'] === Access::userId();
        $nextButton = !$equipmentSelected ? 'Enviar solicitação'
            : ($beneficiaryIsActor ? 'Continuar para assinatura' : 'Registrar e solicitar assinatura');
        echo '<div class="rp-intake-review"><section class="rp-panel"><div class="rp-review-heading"><div><h2>Confira antes de enviar</h2><p class="rp-muted">' . count($selected) . ' recurso(s) em uma solicitação, com um chamado para acompanhar o atendimento.</p></div><i class="ti ti-clipboard-check" aria-hidden="true"></i></div>';
        self::periodSummary($draft);
        echo '<ul class="rp-review-resources">';
        foreach ($selected as $row) {
            echo '<li><i class="ti ' . (($row['kind'] ?? '') === 'room' ? 'ti-door' : 'ti-device-laptop') . '" aria-hidden="true"></i><div><strong>' . BookingView::e($row['name']) . '</strong><span>' . BookingView::e(BookingView::locator($row)) . '</span></div><span class="rp-availability is-available">Disponível na consulta</span></li>';
        }
        echo '</ul><dl class="rp-review-facts"><dt>Finalidade</dt><dd>' . nl2br(BookingView::e($draft['purpose'])) . '</dd>';
        if (self::hasRoom($selected)) { echo '<dt>Participantes</dt><dd>' . (int) $draft['people_count'] . '</dd>'; }
        if ((int) $draft['users_id_borrower'] !== Access::userId()) { echo '<dt>Responsável pelo uso</dt><dd>' . BookingView::e(self::borrowerName((int) $draft['users_id_borrower'])) . '</dd>'; }
        echo '<dt>Unidade / setor do usuário</dt><dd>' . BookingView::e($draft['unit'] ?? '') . '</dd>';
        echo '</dl><div class="rp-review-next"><h3>Próxima etapa</h3><p>' . BookingView::e(
            !$equipmentSelected
                ? 'Ao enviar, acompanhe a aprovação e as etapas previstas para o recurso.'
                : ($beneficiaryIsActor
                    ? 'A reserva será registrada provisoriamente e você seguirá diretamente para assinar o termo de cessão com mouse ou toque. Somente após o aceite ela entrará na fila de aprovação.'
                    : 'A reserva será registrada provisoriamente. O responsável pelo uso precisará assinar o termo para encaminhá-la à aprovação.')
        ) . '</p></div>';
        self::form($draft);
        echo '<div class="rp-actions">'; self::button('back_browse', 'Editar seleção e período', 'btn-outline-secondary', 'data-testid="intake-back"'); self::button('back_details', 'Editar informações', 'btn-outline-secondary'); echo '</div>';
        \Html::closeForm();
        echo '</section><section class="rp-panel rp-review-submit"><div><strong>Pronto para continuar?</strong><p>' . BookingView::e(
            $equipmentSelected ? 'A disponibilidade será revalidada. O termo deverá ser assinado antes da aprovação.' : 'A disponibilidade será revalidada ao enviar a solicitação.'
        ) . '</p></div>';
        BookingView::formStart('submit'); BookingView::hidden('_return_draft', $draft['id']); BookingView::hidden('_draft_id', $draft['id']);
        BookingView::hidden('_review_hash', $draft['review_hash'] ?? '');
        echo '<button type="submit" class="btn btn-primary" data-testid="intake-submit">' . BookingView::e($nextButton) . '</button>';
        \Html::closeForm();
        echo '</section></div>';
    }

    private static function borrowerName(int $id): string
    {
        $user = new \User();
        return $user->getFromDB($id) ? (trim((string) ($user->fields['firstname'] ?? '') . ' ' . (string) ($user->fields['realname'] ?? '')) ?: (string) $user->fields['name']) : 'Usuário selecionado';
    }
}
