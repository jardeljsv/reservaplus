<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonGLPI;

final class TicketTab extends CommonGLPI
{
    public function getTabNameForItem(CommonGLPI $item, $withtemplate = 0): string
    {
        if (!$item instanceof \Ticket || !Schema::ready() || !Access::right('read') || !$item->can((int) $item->getID(), READ)) {
            return '';
        }
        return self::createTabEntry(__('Reserva Plus', 'reservaplus'), icon: Dashboard::getIcon());
    }

    public static function displayTabContentForItem(CommonGLPI $item, $tabnum = 1, $withtemplate = 0): bool
    {
        if (!$item instanceof \Ticket || !Schema::ready() || !Access::right('read') || !$item->can((int) $item->getID(), READ)) {
            return false;
        }
        self::show($item);
        return true;
    }

    private static function show(\Ticket $ticket): void
    {
        BookingView::css();
        echo '<div class="reservaplus-shell rp-workflow rp-ticket-workflow"><section class="rp-panel"><h2>' . BookingView::e(__('Reserva deste chamado', 'reservaplus')) . '</h2><p class="rp-muted">' . BookingView::e(__('Acompanhe os recursos, documentos e próximas etapas. As ações disponíveis seguem suas permissões e a situação da reserva.', 'reservaplus')) . '</p></section>';
        try {
            $rows = BookingService::search(['tickets_id' => (int) $ticket->getID()], 26, 0);
            if ($rows !== []) {
                // One booking per ticket is enforced. Avoid issuing dozens of
                // action tokens if historical data unexpectedly has extra rows.
                ob_start();
                try {
                    BookingView::renderDetail(BookingService::get((int) $rows[0]['id']), (int) $ticket->getID());
                    $detail = ob_get_clean();
                } catch (\Throwable $error) {
                    ob_end_clean();
                    throw $error;
                }
                echo $detail;
            }
            if (count($rows) > 1) {
                echo '<section class="rp-panel"><h2>' . BookingView::e(__('Outras reservas vinculadas', 'reservaplus')) . '</h2>';
                BookingView::renderList(array_slice($rows, 1, 24), [], true);
                echo '</section>';
            }
            if (count($rows) > 25) {
                BookingView::notice(__('Há mais reservas vinculadas. Consulte a lista de solicitações.', 'reservaplus'));
            }
            $hasBooking = $rows !== [] || Store::find('bookings', ['tickets_id' => (int) $ticket->getID()], ['LIMIT' => 1]) !== [];
            if (!$hasBooking && Access::right('request') && $ticket->can((int) $ticket->getID(), UPDATE)) {
                echo '<section class="rp-panel"><p>' . BookingView::e(__('Complete os recursos e o período para organizar uma reserva usando este mesmo chamado.', 'reservaplus')) . '</p>';
                BookingView::link('booking.form.php', __('Organizar reserva', 'reservaplus'), ['tickets_id' => (int) $ticket->getID()], 'btn-primary');
                echo '</section>';
            } elseif ($rows === []) {
                echo '<section class="rp-panel"><p>' . BookingView::e(__('Nenhuma reserva disponível para acompanhamento neste chamado.', 'reservaplus')) . '</p></section>';
            }
        } catch (\Throwable $error) {
            BookingView::notice(BookingView::publicError($error), true);
        }
        echo '</div>';
    }
}
