<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\BookingService;
use GlpiPlugin\Reservaplus\BookingView;
use GlpiPlugin\Reservaplus\DocumentService;
use GlpiPlugin\Reservaplus\RequestDraft;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

// GLPI 11's CheckCsrfListener validates and consumes the native POST token.
// Calling Session::checkCSRF a second time would reject legitimate requests.
if (($_SERVER['REQUEST_METHOD'] ?? '') !== 'POST') {
    http_response_code(405);
    header('Allow: POST');
    exit;
}
$id = max(0, (int) ($_POST['id'] ?? 0));
$action = is_string($_POST['action'] ?? null) ? $_POST['action'] : '';
$target = BookingView::url('booking.php', $id > 0 ? ['id' => $id] : []);
$returnTicket = 0;
$contextDenied = false;
try {
    BookingView::requireReady();
    $key = is_string($_POST['operation_key'] ?? null) ? $_POST['operation_key'] : '';
    $usesTicketContext = array_key_exists('_return_ticket', $_POST)
        || (int) ($_SESSION['reservaplus_commands'][$key]['ticket'] ?? 0) > 0;
    try {
        if (isset($_POST['_return_ticket']) && $_POST['_return_ticket'] !== '' && $_POST['_return_ticket'] !== '0') {
            $contextBooking = BookingService::get($id);
            $returnTicket = BookingView::ticketContext($contextBooking, $_POST['_return_ticket']);
            $target = BookingView::ticketTabUrl($returnTicket);
        }
        BookingView::checkCommand($key, $action, $id, $returnTicket);
    } catch (Throwable $error) {
        $contextDenied = $usesTicketContext;
        throw $error;
    }
    if ($action === 'submit') {
        Access::assertRight('request');
        $draftId = BookingView::text($_POST['_draft_id'] ?? '');
        $submission = $_POST;
        if ($draftId !== '') {
            $draft = RequestDraft::open($draftId);
            $submission = RequestDraft::submission($draft);
            RequestDraft::assertReviewed($draft, BookingView::text($_POST['_review_hash'] ?? ''));
        }
        if ($draftId !== '' && (int) ($draft['submitted_booking_id'] ?? 0) > 0) {
            $id = (int) BookingService::get((int) $draft['submitted_booking_id'])['id'];
        } else {
            $id = BookingService::submit($submission, $key);
            if ($draftId !== '') { $draft['submitted_booking_id'] = $id; RequestDraft::save($draft); }
        }
        $target = BookingView::url('booking.php', ['id' => $id]);
        if ((int) ($submission['tickets_id'] ?? 0) > 0) {
            $origin = BookingView::ticketContext(BookingService::get($id), $submission['tickets_id']);
            $target = BookingView::ticketTabUrl($origin);
        }
        unset($_SESSION['reservaplus_booking_form']);
        Session::addMessageAfterRedirect(BookingView::e(__('Solicitação registrada. Acompanhe as próximas etapas nesta página.', 'reservaplus')));
    } elseif ($action === 'prepare_document') {
        $booking = BookingService::get($id);
        if ((int) ($_POST['expected_revision'] ?? 0) !== (int) $booking['revision']) {
            throw new DomainException(__('A solicitação foi alterada. Atualize a página antes de preparar o documento.', 'reservaplus'));
        }
        $purpose = is_string($_POST['purpose'] ?? null) ? $_POST['purpose'] : '';
        if (!in_array($purpose, ['request', 'handover', 'return_receipt', 'return_inspection'], true)) {
            throw new DomainException(__('Tipo de documento inválido.', 'reservaplus'));
        }
        $documentId = DocumentService::prepare($id, $purpose);
        $target = BookingView::url('agreement.php', ['id' => $documentId] + BookingView::ticketContextQuery($returnTicket));
    } elseif (in_array($action, ['approve', 'reject', 'cancel', 'issue', 'receive', 'inspect', 'complete', 'extend'], true)) {
        if (in_array($action, ['approve', 'reject', 'receive', 'inspect'], true) && (!is_array($_POST['lines_ids'] ?? null) || $_POST['lines_ids'] === [])) {
            throw new DomainException(__('Selecione os itens que deseja registrar nesta operação.', 'reservaplus'));
        }
        BookingService::act($id, $action, $_POST, $key);
        if ($returnTicket === 0 && ($_POST['_return_list'] ?? '') === 'mine') {
            $target = BookingView::url('booking.php', ['scope' => 'mine']);
        } elseif ($returnTicket === 0 && ($_POST['_return_list'] ?? '') === 'requests') {
            $target = BookingView::url('booking.php', ['scope' => 'team']);
        } elseif ($returnTicket === 0 && ($_POST['_return_list'] ?? '') === 'approvals' && in_array($action, ['approve', 'reject'], true)) {
            $target = BookingView::url('booking.php', ['queue' => 'approvals', 'scope' => 'team']);
        }
        Session::addMessageAfterRedirect(BookingView::e(__('Operação registrada. Confira a situação atual da solicitação.', 'reservaplus')));
    } else {
        throw new DomainException(__('Operação inválida.', 'reservaplus'));
    }
} catch (Throwable $error) {
    if ($contextDenied) {
        http_response_code(403);
        BookingView::header(__('Ação indisponível nesta sessão', 'reservaplus'));
        BookingView::notice(BookingView::publicError($error), true);
        if ($returnTicket > 0) {
            echo '<p><a class="btn btn-outline-primary" href="' . BookingView::e(BookingView::ticketTabUrl($returnTicket)) . '">' . BookingView::e(__('Voltar à reserva no chamado', 'reservaplus')) . '</a></p>';
        } else {
            BookingView::link('booking.php', __('Voltar às solicitações', 'reservaplus'));
        }
        BookingView::footer();
        exit;
    }
    if ($action === 'submit') {
        $allowed = ['resources_ids', 'begin', 'end', 'purpose', 'people_count', 'users_id_borrower', 'matricula', 'unit'];
        $_SESSION['reservaplus_booking_form'] = array_intersect_key($_POST, array_flip($allowed));
        $return = [];
        if ((int) ($_POST['tickets_id'] ?? 0) > 0) {
            $return['tickets_id'] = (int) $_POST['tickets_id'];
        }
        if (in_array($_POST['_return_kind'] ?? '', ['room', 'equipment', 'other'], true)) {
            $return['kind'] = $_POST['_return_kind'];
        }
        $return['q'] = mb_substr(BookingView::text($_POST['_return_q'] ?? ''), 0, 200);
        $return['page'] = max(1, min(100000, (int) ($_POST['_return_page'] ?? 1)));
        if ((int) ($_POST['_return_resource'] ?? 0) > 0) {
            $return['resources_id'] = (int) $_POST['_return_resource'];
        }
        $target = BookingView::url('booking.form.php', $return);
        $returnDraft = BookingView::text($_POST['_return_draft'] ?? $_POST['_draft_id'] ?? '');
        if ($returnDraft !== '') {
            try {
                RequestDraft::open($returnDraft);
                $target = BookingView::url('booking.form.php', ['draft' => $returnDraft]);
                unset($_SESSION['reservaplus_booking_form']);
            } catch (Throwable) { /* Expired drafts use the ordinary recovery screen. */ }
        }
    }
    Session::addMessageAfterRedirect(BookingView::e(BookingView::publicError($error)), false, ERROR);
}
header('Location: ' . $target, true, 303);
exit;
