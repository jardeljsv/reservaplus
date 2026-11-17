<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Dashboard;
use GlpiPlugin\Reservaplus\ReservationRequest;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

Session::checkRight(ReservationRequest::$rightname, READ);

Dashboard::renderHeader(__('Reservas do Reserva Plus', 'reservaplus'));
Dashboard::includeAssets();
ReservationRequest::showList();
Dashboard::renderFooter();
