<?php

declare(strict_types=1);

use Glpi\Plugin\Hooks;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Profile as ReservaPlusProfile;
use GlpiPlugin\Reservaplus\ReservationRequest;

define('PLUGIN_RESERVAPLUS_VERSION', '0.7.0-rc3');
define('PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION', '11.0.7');
define('PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION', '11.1.0');

function plugin_init_reservaplus(): void
{
    global $PLUGIN_HOOKS;

    Plugin::loadLang('reservaplus');

    ReservaPlusProfile::syncCurrentProfileRights();

    $PLUGIN_HOOKS[Hooks::CSRF_COMPLIANT]['reservaplus'] = true;
    $PLUGIN_HOOKS[Hooks::CONFIG_PAGE]['reservaplus'] = 'front/config.form.php';
    $PLUGIN_HOOKS[Hooks::ADD_CSS]['reservaplus'] = ['css/reservaplus.css', 'css/workflow.css'];
    $PLUGIN_HOOKS[Hooks::ADD_JAVASCRIPT]['reservaplus'] = ['js/reservaplus.js'];
    $PLUGIN_HOOKS[Hooks::MENU_TOADD]['reservaplus'] = [
        'tools' => Dashboard::class,
    ];

    // Interface simplificada (self-service): em vez do hook helpdesk_menu_entry
    // — que sempre aninha o item dentro do dropdown "Plug-ins" — usamos
    // redefine_menus para inserir "Reserva Plus" como item de TOPO do menu
    // simplificado (irmão de Home/Chamados/FAQ). Aplica-se apenas à interface
    // helpdesk; ver plugin_reservaplus_redefine_menus().
    $PLUGIN_HOOKS[Hooks::REDEFINE_MENUS]['reservaplus'] = 'plugin_reservaplus_redefine_menus';

    // Sem isto, Profile::cleanProfile() do GLPI remove o direito do plugin da
    // sessão dos perfis de interface simplificada — e o self-service perderia o
    // acesso às reservas mesmo tendo a permissão gravada no banco.
    if (!in_array(ReservationRequest::$rightname, \Profile::$helpdesk_rights, true)) {
        \Profile::$helpdesk_rights[] = ReservationRequest::$rightname;
    }

    if (!in_array(\GlpiPlugin\Reservaplus\Access::RIGHT, \Profile::$helpdesk_rights, true)) {
        \Profile::$helpdesk_rights[] = \GlpiPlugin\Reservaplus\Access::RIGHT;
    }

    Plugin::registerClass(ReservaPlusProfile::class, [
        'addtabon' => [\Profile::class],
    ]);
    Plugin::registerClass(\GlpiPlugin\Reservaplus\Resource::class, [
        'reservation_types' => true,
    ]);
    if (\GlpiPlugin\Reservaplus\Schema::ready()) {
        $guards = \GlpiPlugin\Reservaplus\WorkflowHooks::class;
        foreach ([Hooks::PRE_ITEM_ADD, Hooks::PRE_ITEM_UPDATE, Hooks::PRE_ITEM_DELETE, Hooks::PRE_ITEM_PURGE] as $hook) {
            $PLUGIN_HOOKS[$hook]['reservaplus'][\Reservation::class] = [$guards, 'guardReservation'];
            $PLUGIN_HOOKS[$hook]['reservaplus'][\ReservationItem::class] = [$guards, 'guardReservationItem'];
        }
        foreach ([Hooks::PRE_ITEM_UPDATE, Hooks::PRE_ITEM_DELETE, Hooks::PRE_ITEM_PURGE] as $hook) {
            $PLUGIN_HOOKS[$hook]['reservaplus'][\TicketValidation::class] = [$guards, 'guardValidation'];
        }
        $PLUGIN_HOOKS[Hooks::PRE_ITEM_UPDATE]['reservaplus'][\Ticket::class] = [$guards, 'guardTicket'];
        $PLUGIN_HOOKS[Hooks::RULE_MATCHED]['reservaplus'] = [$guards, 'guardTicketRule'];
        foreach ([Hooks::PRE_ITEM_DELETE, Hooks::PRE_ITEM_PURGE] as $hook) {
            $PLUGIN_HOOKS[$hook]['reservaplus'][\Ticket::class] = [$guards, 'guardTicketDelete'];
            foreach (array_keys(\GlpiPlugin\Reservaplus\ResourceService::nativeTypes()) as $type) {
                $PLUGIN_HOOKS[$hook]['reservaplus'][$type] = [$guards, 'guardAssetDelete'];
            }
        }
        Plugin::registerClass(\GlpiPlugin\Reservaplus\TicketTab::class, ['addtabon' => [\Ticket::class]]);
        if (interface_exists(\Glpi\Form\ServiceCatalog\Provider\LeafProviderInterface::class)) {
            \Glpi\Form\ServiceCatalog\ServiceCatalogManager::getInstance()
                ->registerPluginProvider(new \GlpiPlugin\Reservaplus\ServiceCatalogProvider());
            \Glpi\Form\ServiceCatalog\HomeSearchManager::getInstance()
                ->registerPluginProvider(new \GlpiPlugin\Reservaplus\ServiceCatalogProvider());
        }
    }

}

/**
 * Hook redefine_menus: adiciona "Reserva Plus" como item de topo na interface
 * simplificada (self-service), fora do dropdown "Plug-ins".
 *
 * O hook também é chamado para o menu da interface central, por isso só agimos
 * quando o usuário está na interface helpdesk e tem direito de ver reservas — na
 * interface central o item já vem pelo menu 'tools'.
 *
 * @param array $menu Menu gerado pelo GLPI.
 * @return array
 */
function plugin_reservaplus_redefine_menus(array $menu): array
{
    if (
        Session::getCurrentInterface() === 'helpdesk'
        && \GlpiPlugin\Reservaplus\Access::right('read')
    ) {
        $menu['reservaplus'] = [
            'default' => Dashboard::getUrl('dashboard.php'),
            'title'   => Dashboard::getTypeName(),
            'icon'    => Dashboard::getIcon(),
        ];
    }

    return $menu;
}

function plugin_version_reservaplus(): array
{
    return [
        'name'         => __('Reserva Plus', 'reservaplus'),
        'version'      => PLUGIN_RESERVAPLUS_VERSION,
        'author'       => 'Celso, Codex',
        'license'      => 'GPLv3+',
        'homepage'     => 'https://glpi-project.org',
        'requirements' => [
            'glpi' => [
                'min' => PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION,
                'max' => PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION,
            ],
        ],
    ];
}

function plugin_reservaplus_check_prerequisites(): bool
{
    if (
        version_compare(GLPI_VERSION, PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION, '<')
        || version_compare(GLPI_VERSION, PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION, '>=')
    ) {
        echo sprintf(
            __('O Reserva Plus requer GLPI >= %1$s e < %2$s.', 'reservaplus'),
            PLUGIN_RESERVAPLUS_MIN_GLPI_VERSION,
            PLUGIN_RESERVAPLUS_MAX_GLPI_VERSION
        );
        return false;
    }

    if (version_compare(PHP_VERSION, '8.2.0', '<')) {
        echo __('O Reserva Plus requer PHP 8.2 ou superior.', 'reservaplus');
        return false;
    }

    return true;
}

function plugin_reservaplus_check_config(bool $verbose = false): bool
{
    return true;
}
