<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\BookingService;
use GlpiPlugin\Reservaplus\BookingView;
use GlpiPlugin\Reservaplus\RequestDraft;
use GlpiPlugin\Reservaplus\RequestIntakeView;
use GlpiPlugin\Reservaplus\Resource;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');

$opened = false;
try {
    BookingView::requireReady();
    $method = (string) ($_SERVER['REQUEST_METHOD'] ?? 'GET');
    if (!in_array($method, ['GET', 'POST'], true)) { http_response_code(405); header('Allow: GET, POST'); exit; }
    // Preserve existing document/bookmark links from rc1 without starting a draft.
    if ($method === 'GET' && (int) ($_GET['id'] ?? 0) > 0) {
        $existing = BookingService::get((int) $_GET['id']);
        header('Location: ' . BookingView::url('booking.php', ['id' => (int) $existing['id']]), true, 303);
        exit;
    }
    Access::assertRight('request');
    $draftId = BookingView::text($method === 'POST' ? ($_POST['draft_id'] ?? '') : ($_GET['draft'] ?? ''));
    $seed = [];
    if ($draftId === '') {
        $seed = is_array($_SESSION['reservaplus_booking_form'] ?? null) ? $_SESSION['reservaplus_booking_form'] : [];
        unset($_SESSION['reservaplus_booking_form']);
        foreach (['kind', 'begin', 'end', 'resources_id', 'resources_ids', 'tickets_id'] as $key) { if (isset($_GET[$key])) { $seed[$key] = $_GET[$key]; } }
        $copy = max(0, (int) ($_GET['copy_booking_id'] ?? 0));
        if ($copy > 0) {
            $source = BookingService::get($copy); $ids = [];
            foreach ($source['lines'] as $line) {
                try { $row = Resource::getRow((int) $line['resources_id']); if (Access::resource($row, 'request')) { $ids[] = (int) $row['id']; } } catch (Throwable) { /* Never reveal a resource no longer available to this requester. */ }
            }
            $seed = ['resources_ids' => $ids, 'purpose' => (string) $source['purpose']];
        }
    }
    $draft = RequestDraft::open($draftId, $seed);
    if ((int) ($draft['submitted_booking_id'] ?? 0) > 0) {
        $saved = BookingService::get((int) $draft['submitted_booking_id']);
        header('Location: ' . BookingView::url('booking.php', ['id' => (int) $saved['id']]), true, 303);
        exit;
    }
    if ((int) $draft['tickets_id'] > 0) {
        $ticket = new Ticket(); $ticketId = (int) $draft['tickets_id'];
        if (!$ticket->getFromDB($ticketId) || !$ticket->can($ticketId, UPDATE) || !Access::entity((int) $ticket->fields['entities_id'])) {
            throw new DomainException(__('Você não pode organizar uma reserva neste chamado.', 'reservaplus'));
        }
    }
    if ($method === 'POST') {
        // GLPI's native listener already checked and consumed the POST CSRF token.
        $previousStep = $draft['step'];
        try { $draft = RequestDraft::apply($draft, $_POST); }
        catch (DomainException|InvalidArgumentException $error) {
            $draft['errors'] = [$error->getMessage()];
            RequestDraft::save($draft);
            header('Location: ' . BookingView::url('booking.form.php', ['draft' => $draft['id']]), true, 303);
            exit;
        }
        unset($draft['review_hash']);
        $errors = [];
        if (in_array($draft['step'], ['details', 'review'], true)) {
            $errors = RequestIntakeView::validate($draft, $draft['step'] === 'review');
            if ($errors !== []) { $draft['step'] = $previousStep === 'review' ? 'details' : $previousStep; }
        }
        if ($draft['step'] === 'review' && $errors === []) {
            $draft['review_hash'] = hash('sha256', json_encode(RequestDraft::submission($draft), JSON_THROW_ON_ERROR));
        }
        $draft['errors'] = $errors;
        RequestDraft::save($draft);
        header('Location: ' . BookingView::url('booking.form.php', ['draft' => $draft['id']]), true, 303);
        exit;
    }
    $errors = is_array($draft['errors'] ?? null) ? $draft['errors'] : [];
    if ($draft['step'] === 'review') {
        $fresh = RequestIntakeView::validate($draft, true);
        if ($fresh !== []) { $errors = $fresh; $draft['step'] = 'browse'; unset($draft['review_hash']); RequestDraft::save($draft); }
    }
    BookingView::header(__('Nova reserva', 'reservaplus'), __('Escolha os recursos, informe o uso e revise sua solicitação.', 'reservaplus'));
    $opened = true;
    RequestIntakeView::render($draft, $errors);
} catch (Throwable $error) {
    if (!$opened) { http_response_code(403); BookingView::header(__('Nova reserva', 'reservaplus')); }
    BookingView::notice(BookingView::publicError($error), true);
    BookingView::link('booking.form.php', __('Começar uma nova seleção', 'reservaplus'));
}
BookingView::footer();
