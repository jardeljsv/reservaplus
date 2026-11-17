<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ItemGroup;
use GlpiPlugin\Reservaplus\ItemSetting;
use GlpiPlugin\Reservaplus\ReservationRequest;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, UPDATE);

ItemGroup::ensureTable();
ItemSetting::ensureTable();

global $DB;

if (isset($_POST['save']) && (ReservationRequest::isGlpiAdmin() || ReservationRequest::canManageAllRequests())) {
    $itemId   = (int) ($_POST['reservationitems_id'] ?? 0);
    $groupIds = array_map('intval', (array) ($_POST['groups_id'] ?? []));

    if ($itemId > 0) {
        ItemGroup::setGroupsForItem($itemId, $groupIds);

        // Capacidade em branco = sem limite definido (NULL na tabela).
        $rawCapacity = trim((string) ($_POST['capacity'] ?? ''));
        $capacity    = $rawCapacity === '' ? null : max(0, (int) $rawCapacity);

        ItemSetting::save(
            $itemId,
            $capacity,
            (string) ($_POST['capacity_mode'] ?? ItemSetting::MODE_BLOCK),
            (array) ($_POST['features'] ?? [])
        );

        Session::addMessageAfterRedirect(__('Sala atualizada.', 'reservaplus'));
    }

    Html::redirect(Dashboard::getUrl('itemgroup.php'));
}

$settings       = ItemSetting::getAll();
$featureOptions = ItemSetting::getFeatureOptions();
$capacityModes  = ItemSetting::getCapacityModes();

// All active reservable items
$items = [];
if ($DB->tableExists('glpi_reservationitems')) {
    foreach ($DB->request([
        'FROM'  => 'glpi_reservationitems',
        'WHERE' => ['is_active' => 1],
        'ORDER' => ['id ASC'],
    ]) as $row) {
        $itemtype = (string) ($row['itemtype'] ?? '');
        $itemsId  = (int) ($row['items_id'] ?? 0);
        $label    = $itemtype . ' #' . $itemsId;
        if ($itemtype !== '' && class_exists($itemtype) && $itemsId > 0) {
            $obj = new $itemtype();
            if ($obj->getFromDB($itemsId)) {
                $name = method_exists($obj, 'getName') ? $obj->getName() : '';
                if ($name !== '') {
                    $label = $name;
                }
            }
        }
        $row['_label']   = $label;
        $row['_groups']  = ItemGroup::getGroupsForItem((int) $row['id']);
        $row['_setting'] = $settings[(int) $row['id']] ?? ItemSetting::emptySetting();
        $items[] = $row;
    }
}

// All GLPI user groups
$groups = [];
if ($DB->tableExists('glpi_groups')) {
    foreach ($DB->request([
        'SELECT' => ['id', 'name', 'completename'],
        'FROM'   => 'glpi_groups',
        'WHERE'  => ['is_usergroup' => 1],
        'ORDER'  => ['completename ASC'],
    ]) as $row) {
        $groups[] = $row;
    }
}

Html::header(__('Salas — Reserva Plus', 'reservaplus'), $_SERVER['PHP_SELF'], 'tools', Dashboard::class);
Dashboard::includeAssets();

$csrfToken = Session::getNewCSRFToken();

echo "<div class='reservaplus-shell'>";

// Page header
echo "<div class='reservaplus-toolbar'>";
echo '<div>';
echo '<h1>' . __('Salas', 'reservaplus') . '</h1>';
echo '<p>' . __('Capacidade, recursos e quem pode reservar cada sala.', 'reservaplus') . '</p>';
echo '</div>';
echo "<a class='btn btn-outline-secondary' href='" . Dashboard::getUrl('reservation.php') . "'><i class='ti ti-arrow-left'></i> " . __('Reservas', 'reservaplus') . '</a>';
echo '</div>';

echo "<section class='reservaplus-panel'>";

if ($items === []) {
    echo "<div class='reservaplus-empty'>";
    echo "<i class='ti ti-building'></i>";
    echo '<strong>' . __('Nenhuma sala reservável cadastrada.', 'reservaplus') . '</strong>';
    echo '<span>' . __('Cadastre itens reserváveis no GLPI para configurar capacidade, recursos e acesso.', 'reservaplus') . '</span>';
    echo '</div>';
} else {
    echo "<div class='reservaplus-item-cards'>";

    foreach ($items as $item) {
        $itemId       = (int) ($item['id'] ?? 0);
        $itemLabel    = (string) ($item['_label'] ?? '#' . $itemId);
        $itemGroups   = (array) ($item['_groups'] ?? []);
        $setting      = (array) ($item['_setting'] ?? ItemSetting::emptySetting());
        $capacity     = $setting['capacity'] ?? null;
        $capacityMode = (string) ($setting['capacity_mode'] ?? ItemSetting::MODE_BLOCK);
        $features     = (array) ($setting['features'] ?? []);
        $isRestricted = $itemGroups !== [];

        echo "<form method='post' action='" . Dashboard::getUrl('itemgroup.php') . "'>";
        echo "<input type='hidden' name='_glpi_csrf_token' value='" . Html::cleanInputText($csrfToken) . "'>";
        echo "<input type='hidden' name='reservationitems_id' value='" . $itemId . "'>";
        echo "<div class='reservaplus-item-card'>";

        // Card header
        echo "<div class='reservaplus-item-card-header'>";
        echo "<div class='reservaplus-item-card-header-left'>";
        echo "<span class='reservaplus-item-card-icon'><i class='ti ti-door'></i></span>";
        echo '<strong title="' . Html::cleanInputText($itemLabel) . '">' . Html::cleanInputText($itemLabel) . '</strong>';
        echo '</div>';
        echo "<div class='reservaplus-item-card-badges'>";
        if ($capacity !== null && $capacity > 0) {
            echo "<span class='reservaplus-badge reservaplus-badge-neutral' title='" . __('Capacidade máxima', 'reservaplus') . "'><i class='ti ti-users' style='font-size:.72rem'></i>&nbsp;" . (int) $capacity . '</span>';
        }
        if ($isRestricted) {
            echo "<span class='reservaplus-badge reservaplus-badge-pending'><i class='ti ti-lock' style='font-size:.72rem'></i>&nbsp;" . __('Restrita', 'reservaplus') . '</span>';
        } else {
            echo "<span class='reservaplus-badge reservaplus-badge-approved'><i class='ti ti-world' style='font-size:.72rem'></i>&nbsp;" . __('Pública', 'reservaplus') . '</span>';
        }
        echo '</div>';
        echo '</div>';

        // Card body
        echo "<div class='reservaplus-item-card-body'>";

        // Capacidade
        echo "<small>" . __('Capacidade máxima', 'reservaplus') . '</small>';
        echo "<div class='reservaplus-capacity-row'>";
        echo "<input class='form-control form-control-sm' type='number' name='capacity' min='1' max='9999' step='1' "
            . "value='" . ($capacity !== null && $capacity > 0 ? (int) $capacity : '') . "' "
            . "placeholder='" . __('sem limite', 'reservaplus') . "'>";
        echo "<select class='form-select form-select-sm' name='capacity_mode'>";
        foreach ($capacityModes as $modeValue => $modeLabel) {
            $sel = $capacityMode === (string) $modeValue ? ' selected' : '';
            echo "<option value='" . Html::cleanInputText((string) $modeValue) . "'" . $sel . '>' . Html::cleanInputText($modeLabel) . '</option>';
        }
        echo '</select>';
        echo '</div>';
        echo "<p class='reservaplus-field-hint'>" . __('Em branco = sem limite. O limite é conferido contra a quantidade de pessoas da reserva.', 'reservaplus') . '</p>';

        // Recursos
        echo '<small>' . __('Recursos da sala', 'reservaplus') . '</small>';
        echo "<div class='reservaplus-checkgroup'>";
        foreach ($featureOptions as $slug => $opt) {
            $checked      = in_array($slug, $features, true);
            $checkedAttr  = $checked ? ' checked' : '';
            $checkedClass = $checked ? ' is-checked' : '';
            echo "<label class='reservaplus-check-label" . $checkedClass . "'>";
            echo "<input type='checkbox' name='features[]' value='" . Html::cleanInputText((string) $slug) . "'" . $checkedAttr . ' onchange="this.closest(\'.reservaplus-check-label\').classList.toggle(\'is-checked\',this.checked)"> ';
            echo "<span><i class='" . Html::cleanInputText((string) $opt['icon']) . "'></i> " . Html::cleanInputText((string) $opt['label']) . '</span>';
            echo '</label>';
        }
        echo '</div>';

        // Grupos com acesso
        echo '<small>' . __('Grupos com acesso', 'reservaplus') . '</small>';

        if ($groups === []) {
            echo "<div class='reservaplus-no-groups'><i class='ti ti-alert-circle'></i>" . __('Nenhum grupo de usuários cadastrado no GLPI.', 'reservaplus') . '</div>';
        } else {
            echo "<div class='reservaplus-checkgroup'>";
            foreach ($groups as $g) {
                $gid     = (int) $g['id'];
                $gname   = (string) ($g['completename'] ?? $g['name'] ?? '#' . $gid);
                $checked = in_array($gid, $itemGroups, true);
                $checkedAttr  = $checked ? ' checked' : '';
                $checkedClass = $checked ? ' is-checked' : '';
                echo "<label class='reservaplus-check-label" . $checkedClass . "'>";
                echo "<input type='checkbox' name='groups_id[]' value='" . $gid . "'" . $checkedAttr . ' onchange="this.closest(\'.reservaplus-check-label\').classList.toggle(\'is-checked\',this.checked)"> ';
                echo '<span>' . Html::cleanInputText($gname) . '</span>';
                echo '</label>';
            }
            echo '</div>';
            echo "<p class='reservaplus-field-hint'>" . __('Sem seleção = acesso público.', 'reservaplus') . '</p>';
        }

        echo '</div>';

        // Card footer
        echo "<div class='reservaplus-item-card-footer'>";
        echo Html::submit(__('Salvar', 'reservaplus'), ['name' => 'save', 'class' => 'btn btn-sm btn-primary']);
        echo '</div>';

        echo '</div>';
        Html::closeForm();
    }

    echo '</div>';
}

echo '</section>';
echo '</div>';

Html::footer();
