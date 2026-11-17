<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\BookingView;
use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\Schema;
use GlpiPlugin\Reservaplus\TechnicianDashboard;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();
if (!Dashboard::canView()) { Html::displayRightError(); }
if (Schema::ready() && Access::right('read')) {
    BookingView::header(TechnicianDashboard::isStaff() ? __('Painel da equipe', 'reservaplus') : __('Central de reservas', 'reservaplus'),
        TechnicianDashboard::isStaff() ? __('Aprovações, entregas e devoluções organizadas para o dia da equipe.', 'reservaplus') : __('Encontre o que precisa, escolha os recursos e acompanhe cada etapa da sua reserva.', 'reservaplus'));
    try { TechnicianDashboard::render(); }
    catch (Throwable $error) { BookingView::notice(BookingView::publicError($error), true); }
    BookingView::footer();
} else {
    Dashboard::renderHeader(__('Reserva Plus', 'reservaplus'));
    Dashboard::includeAssets();
    if (\GlpiPlugin\Reservaplus\ReservationRequest::canView()) { Dashboard::showDashboard(); }
    else { echo '<div class="reservaplus-shell"><section class="reservaplus-panel"><h1>Reserva Plus</h1><p>' . __('Use o menu para acessar as ferramentas autorizadas no seu perfil.', 'reservaplus') . '</p></section></div>'; }
    Dashboard::renderFooter();
}
