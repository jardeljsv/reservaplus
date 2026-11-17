<?php
/**
 * Harness de verificacao do plugin Reserva Plus — exercita a logica de
 * negocio contra uma instalacao descartavel. Variaveis obrigatorias:
 *   GLPI_ROOT=/caminho/glpi RP_TEST_DATABASE=banco_teste RP_TEST_ALLOW_WRITES=1 php tools/verify.php
 * Todas as fixtures sao revertidas ao final; nunca executar em producao.
 */

use Glpi\Kernel\Kernel;
use GlpiPlugin\Reservaplus\AvailabilityService;
use GlpiPlugin\Reservaplus\Config;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ItemSetting;
use GlpiPlugin\Reservaplus\ReservationRequest;
use GlpiPlugin\Reservaplus\Rule;

// This harness writes fixtures. It must never select an installation implicitly.
if (getenv('RP_TEST_ALLOW_WRITES') !== '1' || !getenv('GLPI_ROOT') || !getenv('RP_TEST_DATABASE')) {
    fwrite(STDERR, "Refusing writes: set GLPI_ROOT, RP_TEST_DATABASE and RP_TEST_ALLOW_WRITES=1 for a disposable installation.\n");
    exit(2);
}
ob_start();
require __DIR__ . '/../tests/bootstrap.php';
global $DB, $CFG_GLPI;
rp_same(getenv('RP_TEST_DATABASE'), $DB->dbdefault, 'Disposable database name guard');
$user = new User();
rp_assert($user->getFromDBbyName(getenv('RP_TEST_ADMIN') ?: 'glpi'), 'Fixture administrator missing');
$auth = new Auth(); $auth->auth_succeded = true; $auth->user = $user; Session::init($auth);
Plugin::load('reservaplus');
$testUserId = (int) $user->getID();
$CFG_GLPI['use_notifications'] = 0;
$DB->beginTransaction();
register_shutdown_function(static function (): void {
    global $DB;
    try { $DB->rollBack(); } catch (Throwable) { /* Already rolled back normally. */ }
});
$DB->update(Config::getTable(), ['webhook_url' => ''], ['id' => 1]);
$computer = new Computer();
$assetId = (int) $computer->add(['name' => 'RP legacy verification ' . bin2hex(random_bytes(6)), 'entities_id' => 0, '_disablenotif' => true]);
rp_assert($assetId > 0, 'Native Computer fixture');
$ri = new ReservationItem();
$fixtureItemId = (int) $ri->add(['itemtype' => Computer::class, 'items_id' => $assetId, 'is_active' => 1, '_disablenotif' => true]);
rp_assert($fixtureItemId > 0, 'Native ReservationItem fixture');

$pass = 0;
$fail = 0;
function check(string $name, bool $ok, string $extra = ''): void
{
    global $pass, $fail;
    echo ($ok ? 'PASS' : 'FAIL') . " — {$name}" . ($extra !== '' ? " ({$extra})" : '') . "\n";
    $ok ? $pass++ : $fail++;
}

global $DB;

// Sessao real, com direitos de plugin reduzidos explicitamente para testar
// usuario comum sem privilegios administrativos.
$_SESSION['glpiID'] = $testUserId;
$_SESSION['glpiactiveprofile']['config'] = 0;
$_SESSION['glpiactiveprofile'][\GlpiPlugin\Reservaplus\Access::RIGHT] = 0;
$_SESSION['glpiactiveprofile']['plugin_reservaplus_reservation'] = READ | CREATE;
$_SESSION['glpiactiveprofile']['plugin_reservaplus_rule'] = READ | CREATE | UPDATE | PURGE;

// 1) Classes principais autoloadam
foreach (['AvailabilityService', 'Block', 'Config', 'ItemGroup', 'ItemSetting', 'NotificationService', 'Profile', 'Report', 'ReservationRequest', 'Rule'] as $class) {
    check("classe {$class} carrega", class_exists('GlpiPlugin\\Reservaplus\\' . $class));
}

// 2) Tabelas
ItemSetting::ensureTable();
foreach ([
    'glpi_plugin_reservaplus_configs',
    'glpi_plugin_reservaplus_requests',
    'glpi_plugin_reservaplus_rules',
    'glpi_plugin_reservaplus_blocks',
    'glpi_plugin_reservaplus_item_groups',
    'glpi_plugin_reservaplus_item_settings',
    'glpi_plugin_reservaplus_notification_logs',
] as $table) {
    check("tabela {$table}", $DB->tableExists($table));
}

// 3) i18n: locales compilados e carregando de verdade (nao so o msgid cru)
foreach (['pt_BR', 'en_US'] as $locale) {
    check("locale {$locale}.mo existe", is_file(__DIR__ . "/../locales/{$locale}.mo"));
}
check(
    'people_count aparece na coluna people_count (schema)',
    $DB->fieldExists('glpi_plugin_reservaplus_requests', 'people_count')
);
check(
    'allow_view_others existe na config (consulta somente leitura)',
    $DB->fieldExists('glpi_plugin_reservaplus_configs', 'allow_view_others')
);
$_SESSION['glpilanguage'] = 'en_US';
$GLOBALS['CFG_GLPI']['languages']['en_US'] ?? null;
\Session::loadLanguage('en_US');
$translated = __('Sim', 'reservaplus');
check("i18n troca de idioma funciona (en_US)", $translated === 'Yes', "obtido: '{$translated}'");
\Session::loadLanguage('pt_BR');
$backToPt = __('Sim', 'reservaplus');
check("i18n volta para pt_BR", $backToPt === 'Sim', "obtido: '{$backToPt}'");

// 4) requires_approval removido da UI/logica (limpeza de codigo morto)
check(
    'front/rule.form.php nao referencia mais requires_approval',
    !str_contains(file_get_contents(__DIR__ . '/../front/rule.form.php'), 'requires_approval')
);
check(
    'front/rule.php nao referencia mais requires_approval',
    !str_contains(file_get_contents(__DIR__ . '/../front/rule.php'), 'requires_approval')
);

// 4b) Assets: css/ e js/ sao copias servidas por public/ — se dessincronizarem,
// o navegador recebe a versao antiga e a tela quebra sem erro visivel.
foreach (['css/reservaplus.css', 'js/reservaplus.js'] as $asset) {
    $root   = __DIR__ . '/../' . $asset;
    $public = __DIR__ . '/../public/' . $asset;
    check(
        "asset {$asset} sincronizado com public/",
        is_file($root) && is_file($public) && md5_file($root) === md5_file($public)
    );
}

// 5) Rule::validateForCreate — regra de duracao maxima bloqueia reserva maior
$DB->insert(Rule::getTable(), [
    'name'                 => 'harness-test-rule',
    'entities_id'          => 0,
    'profiles_id'          => 0,
    'itemtype'             => 'Computer',
    'max_duration_minutes' => 30,
    'min_notice_minutes'   => null,
    'max_days_ahead'       => null,
    'is_active'            => 1,
    'date_creation'        => date('Y-m-d H:i:s'),
    'date_mod'             => date('Y-m-d H:i:s'),
]);
$testRuleId = (int) $DB->insertId();
$begin = date('Y-m-d H:i:s', strtotime('+2 days'));
$end   = date('Y-m-d H:i:s', strtotime('+2 days +1 hour')); // 60 min > limite de 30
$error = Rule::validateForCreate(0, 0, 'Computer', $begin, $end);
check('Rule::validateForCreate rejeita reserva acima da duracao maxima', $error !== null, (string) $error);
$errorOtherType = Rule::validateForCreate(0, 0, 'Monitor', $begin, $end);
check('Rule::validateForCreate ignora regra de itemtype diferente', $errorOtherType === null);
$DB->delete(Rule::getTable(), ['id' => $testRuleId]);
$stillThere = $DB->request(['FROM' => Rule::getTable(), 'WHERE' => ['id' => $testRuleId]])->current();
check('harness limpou a regra de teste', $stillThere === false || $stillThere === null);

// 6) ReservationRequest::canCancelRow / canDeleteRow — dono vs terceiro
$ownRow   = ['entities_id' => 0, 'reservationitems_id' => $fixtureItemId, 'users_id_requester' => $testUserId, 'users_id_for' => $testUserId, 'status' => ReservationRequest::STATUS_CREATED];
$otherRow = ['entities_id' => 0, 'reservationitems_id' => $fixtureItemId, 'users_id_requester' => 999002, 'users_id_for' => 999002, 'status' => ReservationRequest::STATUS_CREATED];
$cancelledRow = ['entities_id' => 0, 'reservationitems_id' => $fixtureItemId, 'users_id_requester' => $testUserId, 'users_id_for' => $testUserId, 'status' => ReservationRequest::STATUS_CANCELLED];
check('READ nao permite cancelar', ReservationRequest::canCancelRow($ownRow) === false);
check('READ nao permite apagar', ReservationRequest::canDeleteRow($ownRow) === false);
$_SESSION['glpiactiveprofile']['plugin_reservaplus_reservation'] = READ | CREATE | UPDATE | PURGE;
check('dono com UPDATE pode cancelar a propria reserva', ReservationRequest::canCancelRow($ownRow) === true);
check('usuario NAO pode cancelar reserva de terceiro (visao somente leitura)', ReservationRequest::canCancelRow($otherRow) === false);
check('reserva ja cancelada nao pode ser cancelada de novo', ReservationRequest::canCancelRow($cancelledRow) === false);
check('dono pode apagar a propria reserva', ReservationRequest::canDeleteRow($ownRow) === true);
check('usuario NAO pode apagar reserva de terceiro', ReservationRequest::canDeleteRow($otherRow) === false);

// 7) getStatusOptions/Label/Class — cobre os 2 status conhecidos
$statusOptions = ReservationRequest::getStatusOptions();
check('getStatusOptions cobre created e cancelled', array_key_exists(ReservationRequest::STATUS_CREATED, $statusOptions) && array_key_exists(ReservationRequest::STATUS_CANCELLED, $statusOptions));
check('getStatusLabel nao devolve vazio', ReservationRequest::getStatusLabel(ReservationRequest::STATUS_CREATED) !== '');
check('getStatusClass nao devolve vazio', ReservationRequest::getStatusClass(ReservationRequest::STATUS_CANCELLED) !== '');

// 8) ItemGroup — a lista sempre materializa os IDs autorizados por entidade/grupo.
$allowedIds = ItemGroup::getAllowedItemIds();
check('getAllowedItemIds devolve IDs explicitamente autorizados', is_array($allowedIds) && in_array($fixtureItemId, $allowedIds, true));

// 9) AvailabilityService::getConflicts — deteccao de sobreposicao
$fakeItemId = $fixtureItemId;
$conflictBegin = date('Y-m-d H:i:s', strtotime('+5 days 10:00'));
$conflictEnd   = date('Y-m-d H:i:s', strtotime('+5 days 11:00'));
$testReservationId = (int) (new Reservation())->add([
    'reservationitems_id' => $fakeItemId,
    'begin'                => $conflictBegin,
    'end'                  => $conflictEnd,
    'users_id'             => $testUserId,
    'comment'              => 'harness-test-conflict', '_disablenotif' => true,
]);
rp_assert($testReservationId > 0, 'Native conflict fixture');
$availability = new AvailabilityService();
$overlapBegin = date('Y-m-d H:i:s', strtotime('+5 days 10:30'));
$overlapEnd   = date('Y-m-d H:i:s', strtotime('+5 days 11:30'));
$conflicts = $availability->getConflicts($fakeItemId, $overlapBegin, $overlapEnd);
check('getConflicts detecta sobreposicao de horario', count($conflicts) === 1, count($conflicts) . ' conflito(s)');
$freeBegin = date('Y-m-d H:i:s', strtotime('+5 days 12:00'));
$freeEnd   = date('Y-m-d H:i:s', strtotime('+5 days 13:00'));
$noConflicts = $availability->getConflicts($fakeItemId, $freeBegin, $freeEnd);
check('getConflicts nao acusa conflito fora do horario', count($noConflicts) === 0);
rp_assert((new Reservation())->delete(['id' => $testReservationId, '_disablenotif' => true], true), 'Native conflict fixture cleanup');
$leftover = $DB->request(['FROM' => 'glpi_reservations', 'WHERE' => ['id' => $testReservationId]])->current();
check('harness limpou a reserva nativa de teste', $leftover === false || $leftover === null);

// 10) ItemSetting — capacidade maxima por sala (block / warn / off)
$capItemId = $fixtureItemId;
ItemSetting::save($capItemId, 8, ItemSetting::MODE_BLOCK, ['projector', 'tv', 'inexistente']);
$capSetting = ItemSetting::getForItem($capItemId);
check('ItemSetting grava e le a capacidade da sala', ($capSetting['capacity'] ?? 0) === 8, 'capacidade: ' . var_export($capSetting['capacity'] ?? null, true));
check(
    'ItemSetting descarta recurso desconhecido',
    $capSetting['features'] === ['projector', 'tv'],
    implode(',', $capSetting['features'])
);
check('capacityViolation nao acusa dentro da capacidade', ItemSetting::capacityViolation($capItemId, 8) === null);
$capBlock = ItemSetting::capacityViolation($capItemId, 12);
check(
    'capacityViolation bloqueia acima da capacidade (modo block)',
    $capBlock !== null && $capBlock['mode'] === ItemSetting::MODE_BLOCK,
    (string) ($capBlock['message'] ?? '')
);

ItemSetting::save($capItemId, 8, ItemSetting::MODE_WARN, []);
$capWarn = ItemSetting::capacityViolation($capItemId, 12);
check(
    'capacityViolation devolve aviso no modo warn',
    $capWarn !== null && $capWarn['mode'] === ItemSetting::MODE_WARN
);

ItemSetting::save($capItemId, 8, ItemSetting::MODE_OFF, []);
check('capacityViolation ignora a capacidade no modo off', ItemSetting::capacityViolation($capItemId, 12) === null);

ItemSetting::save($capItemId, null, ItemSetting::MODE_BLOCK, []);
check('capacidade em branco = sem limite', ItemSetting::capacityViolation($capItemId, 9999) === null);

$DB->delete(ItemSetting::getTable(), ['reservationitems_id' => $capItemId]);
$capLeftover = $DB->request(['FROM' => ItemSetting::getTable(), 'WHERE' => ['reservationitems_id' => $capItemId]])->current();
check('harness limpou a configuracao de sala de teste', $capLeftover === false || $capLeftover === null);

// 11) Edicao de reserva — permissao por linha e conflito ignorando a propria
check('dono pode editar a propria reserva', ReservationRequest::canEditRow($ownRow) === true);
check('usuario NAO pode editar reserva de terceiro', ReservationRequest::canEditRow($otherRow) === false);
check('reserva cancelada nao pode ser editada', ReservationRequest::canEditRow($cancelledRow) === false);

$editReservationId = (int) (new Reservation())->add([
    'reservationitems_id' => $fakeItemId,
    'begin'                => $conflictBegin,
    'end'                  => $conflictEnd,
    'users_id'             => $testUserId,
    'comment'              => 'harness-test-edit', '_disablenotif' => true,
]);
rp_assert($editReservationId > 0, 'Native edit fixture');
check(
    'getConflicts acusa conflito com a propria reserva quando nao a ignora',
    count($availability->getConflicts($fakeItemId, $overlapBegin, $overlapEnd)) === 1
);
check(
    'getConflicts ignora a propria reserva ao reagendar',
    $availability->getConflicts($fakeItemId, $overlapBegin, $overlapEnd, [$editReservationId]) === []
);
rp_assert((new Reservation())->delete(['id' => $editReservationId, '_disablenotif' => true], true), 'Native edit fixture cleanup');
$editLeftover = $DB->request(['FROM' => 'glpi_reservations', 'WHERE' => ['id' => $editReservationId]])->current();
check('harness limpou a reserva nativa de edicao', $editLeftover === false || $editLeftover === null);

// 12) Config::businessHoursViolation — fora do horario comercial configurado
$config = Config::getSingleton();
$bhStart = trim((string) ($config->fields['business_hours_start'] ?? ''));
$bhEnd   = trim((string) ($config->fields['business_hours_end'] ?? ''));
if ($bhStart !== '' && $bhEnd !== '') {
    $veryEarly = date('Y-m-d', strtotime('+3 days')) . ' 03:00:00';
    $veryEarlyEnd = date('Y-m-d', strtotime('+3 days')) . ' 03:30:00';
    $violation = Config::businessHoursViolation($veryEarly, $veryEarlyEnd);
    check('businessHoursViolation acusa reserva de madrugada', $violation !== null, (string) $violation);
} else {
    check('businessHoursViolation (sem horario comercial configurado, pulado)', true);
}

// 13) Direito explicito de gerenciar as reservas dos outros
// (antes bastava ter UPDATE, e usuario comum via Cancelar/Apagar em tudo)
$_SESSION['glpiactiveprofile']['plugin_reservaplus_reservation'] = READ | CREATE | UPDATE;
check(
    'UPDATE sozinho NAO gerencia as reservas dos outros',
    ReservationRequest::canManageAllRequests() === false
);
check(
    'com UPDATE, usuario comum ainda nao apaga reserva de terceiro',
    ReservationRequest::canDeleteRow($otherRow) === false
);
$_SESSION['glpiactiveprofile']['plugin_reservaplus_reservation'] = READ | CREATE | UPDATE | ReservationRequest::RIGHT_MANAGE_ALL;
check(
    'RIGHT_MANAGE_ALL libera o gerenciamento de todas as reservas',
    ReservationRequest::canManageAllRequests() === true
);
check(
    'RIGHT_MANAGE_ALL sem PURGE nao apaga reserva de terceiro',
    ReservationRequest::canDeleteRow($otherRow) === false
);

$_SESSION['glpiactiveprofile']['plugin_reservaplus_reservation'] |= PURGE;
check('gestor com PURGE apaga reserva de terceiro', ReservationRequest::canDeleteRow($otherRow));

// Modo debug do GLPI (glpi_use_mode = 2) nao pode virar "sou administrador"
$_SESSION['glpiactiveprofile']['plugin_reservaplus_reservation'] = READ | CREATE;
$_SESSION['glpi_use_mode'] = 2;
check('modo debug do GLPI nao concede direitos de admin', ReservationRequest::isGlpiAdmin() === false);
unset($_SESSION['glpi_use_mode']);

check(
    'RIGHT_MANAGE_ALL esta na lista de direitos do perfil',
    in_array(
        ReservationRequest::RIGHT_MANAGE_ALL,
        (array) (array_column(\GlpiPlugin\Reservaplus\Profile::getAllRights(), 'rights', 'field')[ReservationRequest::$rightname] ?? []),
        true
    )
);

// 14) Busca e ordenacao alfabetica
check(
    'normalizeForSearch remove acento e caixa',
    ReservationRequest::normalizeForSearch('2 Ética') === '2 etica',
    ReservationRequest::normalizeForSearch('2 Ética')
);
check(
    'getSortOptions oferece ordenacao alfabetica por item',
    array_key_exists('item', ReservationRequest::getSortOptions())
);
$sortSample = ['Multimidia', '1 Empatia', 'Espaço Mindfulness', '2 Ética'];
usort($sortSample, static fn(string $a, string $b): int => strnatcasecmp(
    ReservationRequest::normalizeForSearch($a),
    ReservationRequest::normalizeForSearch($b)
));
check(
    'ordenacao alfabetica coloca acentuada no lugar certo',
    $sortSample === ['1 Empatia', '2 Ética', 'Espaço Mindfulness', 'Multimidia'],
    implode(' | ', $sortSample)
);

// 15) E-mail: HTML compativel com Outlook (tabela + bgcolor, nao div+gradiente)
$mail = \GlpiPlugin\Reservaplus\EmailTemplate::reservationCreated([
    'item'   => 'Sala de teste',
    'period' => '01/01/2030 09:00 – 10:00',
    'people' => 4,
    'url'    => 'https://exemplo.local/plugins/reservaplus/front/reservation.php',
]);
foreach (['subject', 'html', 'text'] as $part) {
    check("EmailTemplate devolve {$part} preenchido", trim((string) ($mail[$part] ?? '')) !== '');
}
check('e-mail e um documento HTML completo', str_contains($mail['html'], '<!DOCTYPE html'));
check('e-mail traz o bloco mso do Outlook', str_contains($mail['html'], '[if mso]'));
check('cabecalho usa bgcolor (fundo solido sobrevive no Outlook)', str_contains($mail['html'], 'bgcolor="#1e3a8a"'));
check('layout do e-mail e feito de tabelas', substr_count($mail['html'], '<table') >= 3);
check('texto puro nao carrega tags HTML', !str_contains($mail['text'], '<'));

// Recorrencia/lote: UM e-mail para a serie inteira, nao um por ocorrencia
$logTable = 'glpi_plugin_reservaplus_notification_logs';
$before = (int) ($DB->request(['COUNT' => 'c', 'FROM' => $logTable])->current()['c'] ?? 0);
$seriesPayload = [];
foreach (['+30 days', '+60 days', '+90 days'] as $offset) {
    $seriesPayload[] = [
        'reservationitems_id' => $fakeItemId,
        'users_id_requester'  => $testUserId,
        'users_id_for'        => $testUserId,
        'begin'               => date('Y-m-d 08:00:00', strtotime($offset)),
        'end'                 => date('Y-m-d 10:00:00', strtotime($offset)),
        'people_count'        => 6,
        'entities_id'         => 0,
    ];
}
\GlpiPlugin\Reservaplus\NotificationService::reservationSeriesCreated($seriesPayload);
$after = (int) ($DB->request(['COUNT' => 'c', 'FROM' => $logTable])->current()['c'] ?? 0);
check(
    'recorrencia de 3 ocorrencias gera 1 notificacao (nao 3)',
    ($after - $before) === 1,
    ($after - $before) . ' notificacao(oes)'
);
// All notification fixtures are rolled back with the outer transaction.

$seriesMail = \GlpiPlugin\Reservaplus\EmailTemplate::reservationSeriesCreated([
    'items'        => ['6 Cooperação'],
    'dates'        => ['10/08/2026 08:00 – 10:00', '10/09/2026 08:00 – 10:00', '10/10/2026 08:00 – 10:00'],
    'count'        => 3,
    'period_label' => '08:00 – 10:00',
    'people'       => 6,
]);
check('e-mail da serie lista todas as datas', substr_count($seriesMail['html'], '2026') >= 3);

// 16) Navegacao do calendario: saida da tela e busca por data
$calendarSource = file_get_contents(__DIR__ . '/../front/calendar.php');
check(
    'calendario tem link de volta para as reservas',
    str_contains($calendarSource, "Dashboard::getUrl('reservation.php')")
);
check(
    'calendario tem campo de busca por data',
    str_contains($calendarSource, 'data-reservaplus-date-jump')
);
$jsSource = file_get_contents(__DIR__ . '/../js/reservaplus.js');
check('JS trata o pulo para a data escolhida', str_contains($jsSource, 'jumpToDate'));
check('JS filtra a lista de salas pela busca', str_contains($jsSource, 'initItemSearch'));
check('JS filtra a lista de pessoas pela busca', str_contains($jsSource, 'initUserSearch'));

// 17) Render da lista: interface simplificada x gestor
// Roda showList() de verdade — pega erro fatal de template e confirma o que
// cada perfil enxerga (self-service so as proprias, gestor com "Todas").
$renderList = static function (array $profile, array $get) use ($testUserId): string {
    $sessionBackup = $_SESSION;
    $getBackup     = $_GET;

    $_SESSION['glpiactiveprofile'] = array_replace($_SESSION['glpiactiveprofile'], $profile);
    $_GET = $get;

    ob_start();
    try {
        ReservationRequest::showList();
        $html = (string) ob_get_clean();
    } catch (\Throwable $e) {
        ob_end_clean();
        $html = 'EXCECAO: ' . $e->getMessage();
    }

    $_SESSION = $sessionBackup;
    $_GET     = $getBackup;

    return $html;
};

$helpdeskProfile = [
    'id'                             => 99,
    'name'                           => 'harness-helpdesk',
    'interface'                      => 'helpdesk',
    'plugin_reservaplus_reservation' => READ | CREATE | PURGE,
];
$managerProfile = [
    'id'                             => 98,
    'name'                           => 'harness-manager',
    'interface'                      => 'central',
    'plugin_reservaplus_reservation' => READ | CREATE | UPDATE | PURGE | ReservationRequest::RIGHT_MANAGE_ALL,
];

$helpdeskHtml = $renderList($helpdeskProfile, []);
check('showList renderiza para o self-service', !str_starts_with($helpdeskHtml, 'EXCECAO'), substr($helpdeskHtml, 0, 120));
check('self-service nao ve o botao "Todas"', !str_contains($helpdeskHtml, '>Todas</a>'));
check('self-service nao ve a coluna Solicitante', !str_contains($helpdeskHtml, '<th>Solicitante</th>'));
check('lista mostra "Disponivel agora" para todos', str_contains($helpdeskHtml, 'reservaplus-available-now'));
check('lista tem campo de procura', str_contains($helpdeskHtml, "name='q'"));
check('lista tem seletor de ordenacao', str_contains($helpdeskHtml, "name='sort'"));

$managerHtml = $renderList($managerProfile, []);
check('showList renderiza para o gestor', !str_starts_with($managerHtml, 'EXCECAO'), substr($managerHtml, 0, 120));
check('gestor ve o botao "Todas"', str_contains($managerHtml, '>Todas</a>'));
check('gestor ve a coluna Solicitante', str_contains($managerHtml, '<th>Solicitante</th>'));

$emptySearchHtml = $renderList($managerProfile, ['q' => 'zzz-nao-existe-zzz']);
check('busca sem resultado explica o motivo', str_contains($emptySearchHtml, 'Nada corresponde'));

$sortOk = true;
foreach (array_keys(ReservationRequest::getSortOptions()) as $sortKey) {
    if (str_starts_with($renderList($managerProfile, ['sort' => $sortKey]), 'EXCECAO')) {
        $sortOk = false;
    }
}
check('todas as ordenacoes renderizam sem erro', $sortOk);

$DB->rollBack();
echo "\nRESULT: {$pass} passed, {$fail} failed; fixtures rolled back\n";
ob_end_flush();
exit($fail > 0 ? 1 : 0);
