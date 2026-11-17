<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Profile;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkRight('profile', UPDATE);

if ($_SERVER['REQUEST_METHOD'] !== 'POST') {
    http_response_code(405);
    exit;
}

$profileId = (int) ($_POST['profiles_id'] ?? 0);
if ($profileId <= 0) {
    Session::addMessageAfterRedirect(__('Perfil não encontrado.', 'reservaplus'), false, ERROR);
    Html::back();
}

Profile::saveRightsForProfile($profileId, (array) ($_POST['plugin_reservaplus_rights'] ?? []));
Session::addMessageAfterRedirect(__('Permissões do Reserva Plus atualizadas com sucesso.', 'reservaplus'));
Html::redirect($CFG_GLPI['root_doc'] . '/front/profile.form.php?id=' . $profileId);
