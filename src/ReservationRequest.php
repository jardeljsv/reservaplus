<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;
use Html;
use Session;

class ReservationRequest extends CommonDBTM
{
    public const STATUS_CANCELLED = 'cancelled';
    public const STATUS_CREATED = 'created';

    /**
     * Direito explícito de mexer nas reservas DOS OUTROS (ver detalhes, editar,
     * cancelar e apagar).
     *
     * Antes isso era inferido do direito UPDATE, que qualquer perfil operacional
     * costuma ter para poder reagendar a PRÓPRIA reserva — o efeito era um
     * usuário comum enxergando os botões Cancelar/Apagar nas reservas de
     * terceiros. Agora é um bit próprio, concedido só a quem administra.
     *
     * Valor acima dos bits reservados pelo core (READ..UNLOCK) para não colidir.
     */
    public const RIGHT_MANAGE_ALL = 1024;

    public static $table = 'glpi_plugin_reservaplus_requests';
    public static $rightname = 'plugin_reservaplus_reservation';

    public static function getTable($classname = null)
    {
        return 'glpi_plugin_reservaplus_requests';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Reserva do Reserva Plus', 'reservaplus');
    }

    public static function canView(): bool
    {
        return Session::haveRight(static::$rightname, READ) > 0;
    }

    public static function canCreate(): bool
    {
        return Session::haveRight(static::$rightname, CREATE) > 0;
    }

    public static function canUpdate(): bool
    {
        return Session::haveRight(static::$rightname, UPDATE) > 0;
    }

    public static function canDelete(): bool
    {
        return Session::haveRight(static::$rightname, PURGE) > 0;
    }

    public static function canManageAllRequests(): bool
    {
        return Session::haveRight(static::$rightname, self::RIGHT_MANAGE_ALL) > 0;
    }

    public static function isGlpiAdmin(): bool
    {
        // Super admin tem direito 'config' com UPDATE — proxy seguro para admin
        // GLPI. O antigo fallback por glpi_use_mode === 2 saiu: esse valor é o
        // MODO DEBUG do GLPI, não "é administrador" — com o debug ligado
        // qualquer usuário virava admin de reservas.
        return Session::haveRight('config', UPDATE) > 0;
    }

    public static function entityIds(): array
    {
        $ids = array_values(array_unique(array_map('intval', (array) ($_SESSION['glpiactiveentities'] ?? []))));
        if ($ids === [] && isset($_SESSION['glpiactive_entity'])) {
            $ids[] = (int) $_SESSION['glpiactive_entity'];
        }
        return $ids;
    }

    public static function ownsRow(array $row): bool
    {
        $user = (int) Session::getLoginUserID();
        return $user > 0 && ($user === (int) ($row['users_id_requester'] ?? 0)
            || $user === (int) ($row['users_id_for'] ?? 0));
    }

    public static function canViewRow(array $row): bool
    {
        if (!static::canView() || !Session::haveAccessToEntity((int) ($row['entities_id'] ?? -1))) {
            return false;
        }
        $itemId = (int) ($row['reservationitems_id'] ?? 0);
        if (!ItemGroup::isAllowed($itemId, false)) {
            return false;
        }
        if (self::ownsRow($row) || self::isGlpiAdmin() || self::canManageAllRequests()) {
            return true;
        }
        return !Dashboard::isHelpdeskInterface()
            && (int) (Config::getSingleton()->fields['allow_view_others'] ?? 0) === 1;
    }

    private static function canMutateRow(array $row, int $right): bool
    {
        return self::canViewRow($row)
            && Session::haveRight(static::$rightname, $right) > 0
            && (self::ownsRow($row) || self::isGlpiAdmin() || self::canManageAllRequests())
            && !ItemGroup::isManaged((int) ($row['reservationitems_id'] ?? 0));
    }

    public static function canCancelRow(array $row): bool
    {
        return (string) ($row['status'] ?? '') !== self::STATUS_CANCELLED
            && self::canMutateRow($row, UPDATE);
    }

    public static function canEditRow(array $row): bool
    {
        return (string) ($row['status'] ?? '') === self::STATUS_CREATED
            && self::canMutateRow($row, UPDATE);
    }

    public static function canDeleteRow(array $row): bool
    {
        return self::canMutateRow($row, PURGE);
    }

    public static function cancelRequest(int $requestId): bool
    {
        global $DB;

        if ($requestId <= 0 || !$DB->tableExists(static::getTable())) {
            return false;
        }

        $request = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['id' => $requestId],
            'LIMIT' => 1,
        ])->current();

        if (!$request || !self::canCancelRow($request)) {
            return false;
        }

        $DB->beginTransaction();
        try {
            $locked = $DB->doQuery('SELECT `id` FROM `' . static::getTable() . '` WHERE `id`=' . (int) $requestId . ' FOR UPDATE');
            if (!$locked) {
                throw new \RuntimeException('Could not lock reservation');
            }
            $request = $DB->request(['FROM' => static::getTable(), 'WHERE' => ['id' => $requestId], 'LIMIT' => 1])->current();
            if (!$request || !self::canCancelRow($request)) {
                throw new \RuntimeException('Reservation changed');
            }
            $nativeIds = self::getNativeReservationIds($request);
            if (!self::canDeleteNativeReservations($nativeIds) || !self::deleteNativeReservations($nativeIds)) {
                throw new \RuntimeException('Native cancellation failed');
            }
            if (!$DB->update(static::getTable(), [
                'status' => self::STATUS_CANCELLED,
                'native_reservations_json' => null,
                'date_mod' => date('Y-m-d H:i:s'),
            ], ['id' => $requestId])) {
                throw new \RuntimeException('Reservation cancellation failed');
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            return false;
        }
        NotificationService::cancelled((array) $request);
        return true;
    }

    /**
     * Cancela TODAS as ocorrências futuras de uma série de recorrência (begin no
     * futuro), respeitando a permissão de cada uma (só dono/admin/gestor).
     * Retorna quantas foram efetivamente canceladas.
     */
    public static function cancelSeries(string $group): int
    {
        global $DB;

        $group = trim($group);
        if ($group === '' || !$DB->tableExists(static::getTable())) {
            return 0;
        }

        $now = date('Y-m-d H:i:s');
        $ids = [];
        foreach ($DB->request([
            'SELECT' => ['id'],
            'FROM'   => static::getTable(),
            'WHERE'  => [
                'recurrence_group' => $group,
                'begin'            => ['>=', $now],
                'status'           => [self::STATUS_CREATED],
            ],
            'ORDER'  => ['begin ASC'],
            'LIMIT'  => 500,
        ]) as $row) {
            $ids[] = (int) $row['id'];
        }

        $count = 0;
        foreach ($ids as $id) {
            if (self::cancelRequest($id)) {
                $count++;
            }
        }

        return $count;
    }

    public static function deleteRequest(int $requestId): bool
    {
        global $DB;

        if ($requestId <= 0 || !$DB->tableExists(static::getTable())) {
            return false;
        }

        $request = $DB->request([
            'FROM'  => static::getTable(),
            'WHERE' => ['id' => $requestId],
            'LIMIT' => 1,
        ])->current();

        if (!$request || !self::canDeleteRow($request)) {
            return false;
        }

        $DB->beginTransaction();
        try {
            if (!$DB->doQuery('SELECT `id` FROM `' . static::getTable() . '` WHERE `id`=' . (int) $requestId . ' FOR UPDATE')) {
                throw new \RuntimeException('Could not lock reservation');
            }
            $request = $DB->request(['FROM' => static::getTable(), 'WHERE' => ['id' => $requestId], 'LIMIT' => 1])->current();
            if (!$request || !self::canDeleteRow($request)) {
                throw new \RuntimeException('Reservation changed');
            }
            $nativeIds = self::getNativeReservationIds($request);
            if (!self::canDeleteNativeReservations($nativeIds) || !self::deleteNativeReservations($nativeIds)
                || !$DB->delete(static::getTable(), ['id' => $requestId])) {
                throw new \RuntimeException('Reservation deletion failed');
            }
            $DB->commit();
        } catch (\Throwable $e) {
            $DB->rollBack();
            return false;
        }
        NotificationService::webhook('reservation.cancelled', (array) $request);
        return true;
    }

    public static function getStatusOptions(): array
    {
        return [
            self::STATUS_CREATED   => __('Criada', 'reservaplus'),
            self::STATUS_CANCELLED => __('Cancelada', 'reservaplus'),
        ];
    }

    public static function getStatusLabel(string $status): string
    {
        $options = self::getStatusOptions();
        return (string) ($options[$status] ?? $status);
    }

    public static function getStatusClass(string $status): string
    {
        return match ($status) {
            self::STATUS_CREATED   => 'reservaplus-badge-approved',
            self::STATUS_CANCELLED => 'reservaplus-badge-danger',
            default                => 'reservaplus-badge-approved',
        };
    }

    public static function getRecent(int $limit = 10): array
    {
        return self::getFiltered([], $limit);
    }

    public static function getFiltered(array $extraWhere = [], int $limit = 100, int $offset = 0): array
    {
        global $DB;

        if (!$DB->tableExists(static::getTable())) {
            return [];
        }

        $entities = self::entityIds();
        if ($entities === [] || !static::canView()) {
            return [];
        }
        $allowedItems = ItemGroup::getAllowedItemIds(false) ?? [];
        if ($allowedItems === []) {
            return [];
        }
        $where = ['AND' => [[static::getTable() . '.entities_id' => $entities, static::getTable() . '.reservationitems_id' => $allowedItems]]];
        if ($extraWhere !== []) {
            $where['AND'][] = $extraWhere;
        }
        if (!(self::isGlpiAdmin() || self::canManageAllRequests())
            && (Dashboard::isHelpdeskInterface() || (int) (Config::getSingleton()->fields['allow_view_others'] ?? 0) !== 1)) {
            $where['AND'][] = ['OR' => [
                [static::getTable() . '.users_id_requester' => (int) Session::getLoginUserID()],
                [static::getTable() . '.users_id_for' => (int) Session::getLoginUserID()],
            ]];
        }
        $rows = [];

        foreach ($DB->request([
            'SELECT'    => [
                static::getTable() . '.*',
                'glpi_reservationitems.itemtype AS _itemtype',
                'glpi_reservationitems.items_id AS _items_id',
                'glpi_users.name        AS _user_login',
                'glpi_users.realname    AS _user_realname',
                'glpi_users.firstname   AS _user_firstname',
            ],
            'FROM'      => static::getTable(),
            'LEFT JOIN' => [
                'glpi_reservationitems' => [
                    'FKEY' => [
                        static::getTable()       => 'reservationitems_id',
                        'glpi_reservationitems'  => 'id',
                    ],
                ],
                'glpi_users' => [
                    'FKEY' => [
                        static::getTable() => 'users_id_requester',
                        'glpi_users'       => 'id',
                    ],
                ],
            ],
            'WHERE'     => $where,
            'ORDER'     => [static::getTable() . '.id DESC'],
            'LIMIT'     => max(1, $limit),
            'START'     => max(0, $offset),
        ]) as $row) {
            if (self::canViewRow($row)) {
                $rows[] = $row;
            }
        }

        return $rows;
    }

    public static function getUserDisplayName(array $row): string
    {
        $first = trim((string) ($row['_user_firstname'] ?? ''));
        $last  = trim((string) ($row['_user_realname'] ?? ''));
        $full  = trim($first . ' ' . $last);
        if ($full !== '') {
            return $full;
        }
        $login = trim((string) ($row['_user_login'] ?? ''));
        return $login !== '' ? $login : '#' . (int) ($row['users_id_requester'] ?? 0);
    }

    public static function getItemDisplayName(array $row): string
    {
        $itemtype = (string) ($row['_itemtype'] ?? '');
        $itemsId  = (int) ($row['_items_id'] ?? 0);

        if ($itemtype === '' || $itemsId <= 0 || !class_exists($itemtype)) {
            return __('Item reservável', 'reservaplus') . ' #' . (int) ($row['reservationitems_id'] ?? 0);
        }

        $obj = new $itemtype();
        if (!$obj->getFromDB($itemsId)) {
            return $itemtype . ' #' . $itemsId;
        }

        $name = method_exists($obj, 'getName') ? $obj->getName() : '';
        return $name !== '' ? $name : $itemtype . ' #' . $itemsId;
    }

    public static function getTodayNativeReservations(int $limit = 10): array
    {
        global $DB;

        if (!$DB->tableExists('glpi_reservations')) {
            return [];
        }

        $today = date('Y-m-d 00:00:00');
        $tomorrow = date('Y-m-d 00:00:00', strtotime('+1 day'));
        $rows = [];

        foreach ($DB->request([
            'FROM'  => 'glpi_reservations',
            'WHERE' => [
                'begin' => ['<', $tomorrow],
                'end'   => ['>', $today],
                'reservationitems_id' => ItemGroup::getAllowedItemIds() ?: [-1],
            ],
            'ORDER' => ['begin ASC'],
            'LIMIT' => max(1, $limit),
        ]) as $row) {
            if ((int) ($row['users_id'] ?? 0) !== (int) Session::getLoginUserID()
                && !(self::isGlpiAdmin() || self::canManageAllRequests())) {
                $row['users_id'] = 0;
                $row['comment'] = '';
            }
            $rows[] = $row;
        }

        return $rows;
    }

    private static function fetchUserNames(array $userIds): array
    {
        global $DB;

        $userIds = array_values(array_unique(array_filter(array_map('intval', $userIds))));
        if ($userIds === []) {
            return [];
        }

        $names = [];
        foreach ($DB->request([
            'SELECT' => ['id', 'name', 'realname', 'firstname'],
            'FROM'   => 'glpi_users',
            'WHERE'  => ['id' => $userIds],
        ]) as $u) {
            $first = trim((string) ($u['firstname'] ?? ''));
            $last  = trim((string) ($u['realname'] ?? ''));
            $full  = trim($first . ' ' . $last);
            $names[(int) $u['id']] = $full !== '' ? $full : (string) ($u['name'] ?? '#' . $u['id']);
        }

        return $names;
    }

    /** Ordenações oferecidas no painel de busca da lista. */
    public static function getSortOptions(): array
    {
        return [
            'recent'    => __('Mais recentes', 'reservaplus'),
            'item'      => __('Item (A → Z)', 'reservaplus'),
            'item_desc' => __('Item (Z → A)', 'reservaplus'),
            'begin'     => __('Início (mais próximo)', 'reservaplus'),
            'person'    => __('Pessoa (A → Z)', 'reservaplus'),
        ];
    }

    /**
     * Normaliza texto para busca: minúsculas e sem acento, para que "etica"
     * encontre "2 Ética".
     */
    public static function normalizeForSearch(string $value): string
    {
        $value = mb_strtolower(trim($value), 'UTF-8');
        $ascii = @iconv('UTF-8', 'ASCII//TRANSLIT//IGNORE', $value);

        return $ascii !== false ? strtolower($ascii) : $value;
    }

    /**
     * Busca livre por item, pessoa (solicitante ou beneficiário), comentário ou
     * número da reserva. Feita em PHP porque o nome do item e o da pessoa não
     * saem prontos da consulta — são resolvidos objeto a objeto.
     *
     * @param array<int,array<string,mixed>> $rows
     * @param array<int,string>              $forUserNames
     * @return array<int,array<string,mixed>>
     */
    private static function applySearch(array $rows, string $search, array $forUserNames): array
    {
        $needle = self::normalizeForSearch($search);
        if ($needle === '') {
            return $rows;
        }

        return array_values(array_filter($rows, static function (array $row) use ($needle, $forUserNames): bool {
            $forId  = (int) ($row['users_id_for'] ?? 0);
            $haystack = implode(' ', [
                '#' . (int) ($row['id'] ?? 0),
                self::getItemDisplayName($row),
                self::getUserDisplayName($row),
                (string) ($forUserNames[$forId] ?? ''),
                (string) ($row['comment'] ?? ''),
            ]);

            return str_contains(self::normalizeForSearch($haystack), $needle);
        }));
    }

    /**
     * @param array<int,array<string,mixed>> $rows
     * @return array<int,array<string,mixed>>
     */
    private static function applySort(array $rows, string $sort): array
    {
        if ($sort === 'recent') {
            return $rows; // já vem por id DESC do banco
        }

        $collator = static fn(string $a, string $b): int => strnatcasecmp(
            self::normalizeForSearch($a),
            self::normalizeForSearch($b)
        );

        usort($rows, static function (array $a, array $b) use ($sort, $collator): int {
            return match ($sort) {
                'item'      => $collator(self::getItemDisplayName($a), self::getItemDisplayName($b)),
                'item_desc' => $collator(self::getItemDisplayName($b), self::getItemDisplayName($a)),
                'person'    => $collator(self::getUserDisplayName($a), self::getUserDisplayName($b)),
                'begin'     => strcmp((string) ($a['begin'] ?? ''), (string) ($b['begin'] ?? '')),
                default     => 0,
            };
        });

        return $rows;
    }

    public static function showList(): void
    {
        global $DB;

        $mine    = isset($_GET['mine']) && (string) $_GET['mine'] === '1';
        $isAdmin = self::isGlpiAdmin() || self::canManageAllRequests();

        // Consulta somente leitura de reservas de terceiros: liberada por config
        // (allow_view_others). As ações continuam validadas por linha e no
        // servidor (canCancelRow/canDeleteRow) — ver não significa poder mexer.
        $cfgViewOthers = (int) (Config::getSingleton()->fields['allow_view_others'] ?? 1) === 1;

        // Na interface simplificada (abertura de chamado / self-service) a tela é
        // "as minhas reservas", ponto: quem abre chamado não tem por que
        // navegar na agenda dos colegas. Admin continua vendo tudo mesmo ali.
        $isHelpdesk    = Dashboard::isHelpdeskInterface();
        $canViewOthers = $isAdmin || ($cfgViewOthers && !$isHelpdesk);

        // Admin abre em "Todas" (comportamento atual); usuário comum abre em
        // "Minhas" e só amplia com ?all=1 (e permissão da config).
        $viewAll = $isAdmin ? !$mine : ($canViewOthers && (string) ($_GET['all'] ?? '') === '1');

        $where = [];
        if (!$viewAll) {
            // "Minhas" = as que eu pedi E as que pediram para mim: quando um
            // gestor reserva a sala em nome de alguém, quem vai usar a sala
            // precisa encontrá-la aqui.
            $me = (int) Session::getLoginUserID();
            $where[] = ['OR' => [
                [static::getTable() . '.users_id_requester' => $me],
                [static::getTable() . '.users_id_for'       => $me],
            ]];
        }

        // Filters from GET
        $filterStatus   = trim((string) ($_GET['status'] ?? ''));
        $filterDateFrom = trim((string) ($_GET['date_from'] ?? ''));
        $filterDateTo   = trim((string) ($_GET['date_to'] ?? ''));
        $filterItem     = (int) ($_GET['item'] ?? 0);
        $filterUser     = $viewAll ? (int) ($_GET['user'] ?? 0) : 0;
        $search         = trim((string) ($_GET['q'] ?? ''));
        $sort           = (string) ($_GET['sort'] ?? 'recent');
        if (!array_key_exists($sort, self::getSortOptions())) {
            $sort = 'recent';
        }

        if ($filterUser > 0) {
            // Reservas solicitadas POR X ou feitas PARA X (gestor em nome de alguém)
            $where[] = ['OR' => [
                [static::getTable() . '.users_id_requester' => $filterUser],
                [static::getTable() . '.users_id_for'       => $filterUser],
            ]];
        }

        if ($filterStatus !== '' && array_key_exists($filterStatus, self::getStatusOptions())) {
            $where[static::getTable() . '.status'] = $filterStatus;
        }
        if ($filterDateFrom !== '' && ($ts = strtotime($filterDateFrom)) !== false) {
            $where[static::getTable() . '.begin'] = ['>=', date('Y-m-d 00:00:00', $ts)];
        }
        if ($filterDateTo !== '' && ($ts = strtotime($filterDateTo)) !== false) {
            $where[static::getTable() . '.end'] = ['<=', date('Y-m-d 23:59:59', $ts)];
        }
        if ($filterItem > 0) {
            $where[static::getTable() . '.reservationitems_id'] = $filterItem;
        }

        // Com busca ativa vale a pena varrer mais linhas: o texto é comparado com
        // o NOME do item e da pessoa, que só existem depois de resolvidos em PHP.
        $rows         = self::getFiltered($where, $search !== '' ? 600 : 200);
        $forUserIds   = array_map(fn($r) => (int) ($r['users_id_for'] ?? 0), $rows);
        $forUserNames = self::fetchUserNames($forUserIds);
        $itemSettings = ItemSetting::getAll();

        if ($search !== '') {
            $rows = self::applySearch($rows, $search, $forUserNames);
        }
        $rows = self::applySort($rows, $sort);

        // Opções do filtro "Pessoa": só usuários que aparecem em alguma reserva
        // (solicitante ou beneficiário) — dropdown enxuto e relevante.
        $personOptions = [];
        if ($viewAll) {
            $personIds = [];
            foreach ($rows as $p) {
                $personIds[] = (int) $p['users_id_requester'];
                $personIds[] = (int) $p['users_id_for'];
            }
            $personOptions = self::fetchUserNames($personIds);
            asort($personOptions, SORT_NATURAL | SORT_FLAG_CASE);
        }

        // Single token for all POST forms on this page (avoids CSRF pool exhaustion)
        $csrfToken = Session::getNewCSRFToken();

        // Itens do filtro, em ordem alfabética — o ID de cadastro não diz nada a
        // quem procura uma sala pelo nome.
        $reservableItems = [];
        if ($DB->tableExists('glpi_reservationitems')) {
            foreach ($DB->request([
                'FROM'  => 'glpi_reservationitems',
                'WHERE' => ['is_active' => 1],
                'ORDER' => ['id ASC'],
                'LIMIT' => 200,
            ]) as $itemRow) {
                if (!ItemGroup::isAllowed((int) $itemRow['id'])) {
                    continue;
                }
                $reservableItems[(int) $itemRow['id']] = self::getItemDisplayName([
                    '_itemtype'           => $itemRow['itemtype'] ?? '',
                    '_items_id'           => $itemRow['items_id'] ?? 0,
                    'reservationitems_id' => $itemRow['id'] ?? 0,
                ]);
            }
            uasort($reservableItems, static fn(string $a, string $b): int => strnatcasecmp(
                self::normalizeForSearch($a),
                self::normalizeForSearch($b)
            ));
        }

        $baseUrl = Dashboard::getUrl('reservation.php');
        $mineUrl = $isAdmin ? $baseUrl . '?mine=1' : $baseUrl;
        $allUrl  = $isAdmin ? $baseUrl : $baseUrl . '?all=1';

        echo "<div class='reservaplus-shell'>";
        echo "<div class='reservaplus-toolbar'>";
        echo '<div>';
        echo '<h1>' . __('Reservas', 'reservaplus') . '</h1>';
        echo '<p>' . __('Gerencie e acompanhe as reservas de itens.', 'reservaplus') . '</p>';
        echo '</div>';
        echo "<div class='reservaplus-actions'>";
        if ($canViewOthers) {
            echo "<a class='btn " . ($viewAll ? 'btn-secondary' : 'btn-outline-secondary') . "' href='" . Html::cleanInputText($allUrl) . "'>" . __('Todas', 'reservaplus') . '</a>';
            echo "<a class='btn " . ($viewAll ? 'btn-outline-secondary' : 'btn-secondary') . "' href='" . Html::cleanInputText($mineUrl) . "'><i class='ti ti-user'></i> " . __('Minhas', 'reservaplus') . '</a>';
        }
        if (self::canCreate()) {
            echo "<a class='btn btn-primary' href='" . Dashboard::getUrl('reservation.form.php') . "'><i class='ti ti-plus'></i> " . __('Reservar', 'reservaplus') . '</a>';
        }
        echo "<a class='btn btn-outline-primary' href='" . Dashboard::getUrl('calendar.php') . "'><i class='ti ti-calendar'></i> " . __('Calendário', 'reservaplus') . '</a>';
        echo '</div>';
        echo '</div>';

        // Itens livres na próxima hora: útil para qualquer usuário, não só para
        // quem chega pelo painel administrativo (que o self-service nem abre).
        Dashboard::showAvailableNowPanel();

        // Filter bar
        echo "<form method='get' action='" . Html::cleanInputText($baseUrl) . "' class='reservaplus-filters'>";
        if ($isAdmin && $mine) {
            echo "<input type='hidden' name='mine' value='1'>";
        }
        if (!$isAdmin && $viewAll) {
            echo "<input type='hidden' name='all' value='1'>";
        }

        // Painel de procura: texto livre + ordenação alfabética.
        echo "<label class='reservaplus-filter-search'><span>" . __('Procurar', 'reservaplus') . '</span>';
        echo "<input class='form-control form-control-sm' type='search' name='q' value='" . Html::cleanInputText($search) . "' placeholder='" . __('sala, pessoa, comentário ou #id', 'reservaplus') . "'>";
        echo '</label>';

        echo '<label><span>' . __('Ordenar por', 'reservaplus') . "</span><select class='form-select form-select-sm' name='sort'>";
        foreach (self::getSortOptions() as $sortValue => $sortLabel) {
            $sel = $sort === $sortValue ? ' selected' : '';
            echo "<option value='" . Html::cleanInputText($sortValue) . "'" . $sel . '>' . Html::cleanInputText($sortLabel) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . __('Status', 'reservaplus') . "</span><select class='form-select form-select-sm' name='status'>";
        echo "<option value=''>" . __('Todos', 'reservaplus') . '</option>';
        foreach (self::getStatusOptions() as $val => $label) {
            $sel = $filterStatus === $val ? ' selected' : '';
            echo "<option value='" . Html::cleanInputText($val) . "'" . $sel . '>' . Html::cleanInputText($label) . '</option>';
        }
        echo '</select></label>';

        echo '<label><span>' . __('De', 'reservaplus') . "</span><input class='form-control form-control-sm' type='date' name='date_from' value='" . Html::cleanInputText($filterDateFrom) . "'></label>";
        echo '<label><span>' . __('Até', 'reservaplus') . "</span><input class='form-control form-control-sm' type='date' name='date_to' value='" . Html::cleanInputText($filterDateTo) . "'></label>";

        if ($viewAll && $personOptions !== []) {
            echo '<label><span>' . __('Pessoa', 'reservaplus') . "</span><select class='form-select form-select-sm' name='user'>";
            echo "<option value='0'>" . __('Todas', 'reservaplus') . '</option>';
            foreach ($personOptions as $uid => $uname) {
                $sel = $filterUser === (int) $uid ? ' selected' : '';
                echo "<option value='" . (int) $uid . "'" . $sel . '>' . Html::cleanInputText((string) $uname) . '</option>';
            }
            echo '</select></label>';
        }

        echo '<label><span>' . __('Item', 'reservaplus') . "</span><select class='form-select form-select-sm' name='item'>";
        echo "<option value='0'>" . __('Todos', 'reservaplus') . '</option>';
        foreach ($reservableItems as $itemId => $itemLabel) {
            $sel = $filterItem === $itemId ? ' selected' : '';
            echo "<option value='" . $itemId . "'" . $sel . '>' . Html::cleanInputText($itemLabel) . '</option>';
        }
        echo '</select></label>';

        echo "<div style='display:flex;align-items:flex-end;gap:6px'>";
        echo "<button type='submit' class='btn btn-sm btn-outline-secondary'><i class='ti ti-search'></i> " . __('Filtrar', 'reservaplus') . '</button>';
        echo "<a class='btn btn-sm btn-outline-secondary' href='" . Html::cleanInputText($mine ? $mineUrl : $allUrl) . "' title='" . __('Limpar filtros', 'reservaplus') . "'><i class='ti ti-x'></i></a>";
        echo '</div>';
        echo '</form>'; // GET form — no CSRF token needed

        echo "<section class='reservaplus-panel'>";
        echo "<div class='table-responsive'>";
        echo "<table class='table table-hover reservaplus-table'>";
        echo '<thead><tr>';
        echo '<th>ID</th>';
        echo '<th>' . __('Item', 'reservaplus') . '</th>';
        if ($viewAll) {
            echo '<th>' . __('Solicitante', 'reservaplus') . '</th>';
        }
        echo '<th>' . __('Início', 'reservaplus') . '</th>';
        echo '<th>' . __('Fim', 'reservaplus') . '</th>';
        echo '<th>' . __('Pessoas', 'reservaplus') . '</th>';
        echo '<th>' . __('Status', 'reservaplus') . '</th>';
        echo '<th>' . __('Ações', 'reservaplus') . '</th>';
        echo '</tr></thead><tbody>';

        if ($rows === []) {
            $colspan = $viewAll ? 8 : 7;
            echo "<tr><td colspan='{$colspan}'>";
            echo "<div class='reservaplus-empty reservaplus-empty-table'>";
            echo "<i class='ti ti-" . ($search !== '' ? 'search-off' : 'calendar-plus') . "'></i>";
            echo '<strong>' . __('Nenhuma reserva encontrada.', 'reservaplus') . '</strong>';
            echo '<span>' . (
                $search !== ''
                    ? sprintf(__('Nada corresponde a "%s". Tente outro termo ou limpe os filtros.', 'reservaplus'), Html::cleanInputText($search))
                    : __('As reservas nativas do GLPI também ficam visíveis no calendário.', 'reservaplus')
            ) . '</span>';
            echo '</div>';
            echo '</td></tr>';
        }

        foreach ($rows as $row) {
            $begin       = (string) ($row['begin'] ?? '');
            $end         = (string) ($row['end'] ?? '');
            $beginFmt    = $begin !== '' ? date('d/m/Y H:i', strtotime($begin)) : '-';
            $endFmt      = $end   !== '' ? date('d/m/Y H:i', strtotime($end))   : '-';
            $status      = (string) ($row['status'] ?? self::STATUS_CREATED);
            $reqId       = (int) ($row['users_id_requester'] ?? 0);
            $forId       = (int) ($row['users_id_for'] ?? 0);
            $hasForOther = $forId > 0 && $forId !== $reqId;
            $forName     = $hasForOther ? ($forUserNames[$forId] ?? '#' . $forId) : '';

            echo '<tr>';
            echo '<td>#' . (int) ($row['id'] ?? 0) . '</td>';

            $itemCell = Html::cleanInputText(self::getItemDisplayName($row));
            if ($hasForOther) {
                $itemCell .= " <span class='reservaplus-badge reservaplus-badge-approved' title='" . __('Reservado para', 'reservaplus') . "'><i class='ti ti-user-share' style='font-size:0.75rem'></i> " . Html::cleanInputText($forName) . '</span>';
            }
            echo '<td>' . $itemCell . '</td>';

            if ($viewAll) {
                echo '<td>' . Html::cleanInputText(self::getUserDisplayName($row)) . '</td>';
            }
            echo '<td>' . Html::cleanInputText($beginFmt) . '</td>';
            echo '<td>' . Html::cleanInputText($endFmt) . '</td>';
            // Pessoas / capacidade da sala — deixa visível de imediato quando a
            // reserva foi criada acima do limite (salas em modo "avisar").
            $people   = max(1, (int) ($row['people_count'] ?? 1));
            $capacity = $itemSettings[(int) ($row['reservationitems_id'] ?? 0)]['capacity'] ?? null;
            $overCap  = $capacity !== null && $capacity > 0 && $people > $capacity;
            echo "<td class='" . ($overCap ? 'rp-over-capacity' : '') . "'>";
            echo "<i class='ti ti-users' style='font-size:0.8rem;opacity:.6'></i> " . $people;
            if ($capacity !== null && $capacity > 0) {
                echo "<small class='rp-capacity-hint' title='" . __('Capacidade máxima da sala', 'reservaplus') . "'>/" . (int) $capacity . '</small>';
            }
            echo '</td>';
            echo '<td><span class="reservaplus-badge ' . Html::cleanInputText(self::getStatusClass($status)) . '">' . Html::cleanInputText(self::getStatusLabel($status)) . '</span></td>';
            echo "<td class='reservaplus-row-actions'>";
            $rowId = (int) ($row['id'] ?? 0);
            if (self::canEditRow($row)) {
                echo "<a class='btn btn-sm btn-outline-primary' href='" . Dashboard::getUrl('reservation.form.php') . '?edit=' . $rowId . "'><i class='ti ti-edit'></i> " . __('Editar', 'reservaplus') . '</a>';
            }
            echo "<a class='btn btn-sm btn-outline-secondary' href='" . Dashboard::getUrl('reservation.form.php') . '?duplicate=' . $rowId . "'><i class='ti ti-copy'></i> " . __('Duplicar', 'reservaplus') . '</a>';
            if (self::canCancelRow($row)) {
                echo "<form method='post' action='" . Dashboard::getUrl('reservation.action.php') . "' class='reservaplus-inline-form' onsubmit=\"return confirm('" . __('Cancelar esta reserva?', 'reservaplus') . "');\">";
                echo "<input type='hidden' name='id' value='" . $rowId . "'>";
                echo "<input type='hidden' name='_glpi_csrf_token' value='" . Html::cleanInputText($csrfToken) . "'>";
                echo "<button type='submit' name='cancel' value='1' class='btn btn-sm btn-outline-warning'><i class='ti ti-ban'></i> " . __('Cancelar', 'reservaplus') . '</button>';
                echo '</form>';
            }
            $group = trim((string) ($row['recurrence_group'] ?? ''));
            if ($group !== '' && self::canCancelRow($row)) {
                echo "<form method='post' action='" . Dashboard::getUrl('reservation.action.php') . "' class='reservaplus-inline-form' onsubmit=\"return confirm('" . __('Cancelar TODA a série (ocorrências futuras)?', 'reservaplus') . "');\">";
                echo "<input type='hidden' name='recurrence_group' value='" . Html::cleanInputText($group) . "'>";
                echo "<input type='hidden' name='_glpi_csrf_token' value='" . Html::cleanInputText($csrfToken) . "'>";
                echo "<button type='submit' name='cancel_series' value='1' class='btn btn-sm btn-outline-warning'><i class='ti ti-repeat-off'></i> " . __('Cancelar série', 'reservaplus') . '</button>';
                echo '</form>';
            }
            if (self::canDeleteRow($row)) {
                echo "<form method='post' action='" . Dashboard::getUrl('reservation.action.php') . "' class='reservaplus-inline-form' onsubmit=\"return confirm('" . __('Apagar permanentemente?', 'reservaplus') . "');\">";
                echo "<input type='hidden' name='id' value='" . $rowId . "'>";
                echo "<input type='hidden' name='_glpi_csrf_token' value='" . Html::cleanInputText($csrfToken) . "'>";
                echo "<button type='submit' name='delete' value='1' class='btn btn-sm btn-outline-danger'><i class='ti ti-trash'></i> " . __('Apagar', 'reservaplus') . '</button>';
                echo '</form>';
            }
            echo '</td>';
            echo '</tr>';
        }

        echo '</tbody></table>';
        echo '</div>';
        echo '</section>';
        echo '</div>';
    }

    /**
     * IDs das reservas nativas (glpi_reservations) geradas por este request.
     *
     * @return array<int,int>
     */
    public static function getNativeReservationIds(array $request): array
    {
        $rawValue = (string) ($request['native_reservations_json'] ?? '');
        if ($rawValue === '') {
            return [];
        }

        $decoded = json_decode($rawValue, true);
        if (!is_array($decoded)) {
            return [];
        }

        $ids = [];
        foreach ($decoded as $id) {
            $id = (int) $id;
            if ($id > 0) {
                $ids[] = $id;
            }
        }

        return array_values(array_unique($ids));
    }

    private static function canDeleteNativeReservations(array $reservationIds): bool
    {
        if ($reservationIds === []) {
            return true;
        }

        if (self::isGlpiAdmin() || self::canManageAllRequests()) {
            return true;
        }

        $userId = (int) Session::getLoginUserID();
        foreach ($reservationIds as $reservationId) {
            $reservation = new \Reservation();
            if (!$reservation->getFromDB((int) $reservationId)) {
                continue;
            }

            if ((int) ($reservation->fields['users_id'] ?? 0) !== $userId) {
                return false;
            }
        }

        return true;
    }

    private static function deleteNativeReservations(array $reservationIds): bool
    {
        global $DB;

        foreach ($reservationIds as $reservationId) {
            $id = (int) $reservationId;
            if ($id <= 0) {
                continue;
            }

            // Tenta via objeto GLPI primeiro (respeita hooks)
            $reservation = new \Reservation();
            if ($reservation->getFromDB($id)) {
                if (!$reservation->can($id, PURGE) || !$reservation->delete(['id' => $id], true, false)) {
                    return false;
                }
            }
        }

        return true;
    }
}
