<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\BookingView;
use GlpiPlugin\Reservaplus\DocumentService;
use GlpiPlugin\Reservaplus\PdfRenderer;
use GlpiPlugin\Reservaplus\Store;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();
header('Cache-Control: private, no-store');
header('X-Robots-Tag: noindex, nofollow');
$id = (int) ($_POST['id'] ?? $_GET['id'] ?? 0);
$error = '';
// The helpdesk UI presents only the beneficiary's own consent.
// Staff responsibilities remain enforced server-side and visible in central.
$isRequesterInterface = Session::getCurrentInterface() === 'helpdesk';
$returnTicket = 0;
$contextValidated = false;
$hasTicketContext = array_key_exists('_return_ticket', $_POST) || array_key_exists('_return_ticket', $_GET);
try {
    $loaded = DocumentService::get($id);
    $returnTicket = BookingView::ticketContext(
        Store::row('bookings', (int) $loaded['bookings_id']),
        $_POST['_return_ticket'] ?? $_GET['_return_ticket'] ?? 0
    );
    $contextValidated = true;
    $doc = $loaded;
    if ($_SERVER['REQUEST_METHOD'] === 'POST') {
        // GLPI's kernel already validated and consumed the native CSRF token.
        $action = (string) ($_POST['action'] ?? '');
        if ($action === 'sign') {
            if (($_POST['accept_statement'] ?? '') !== '1') {
                throw new DomainException('Marque a declaração para registrar um aceite explícito.');
            }
            DocumentService::accept($id, (string) ($_POST['challenge'] ?? ''), (string) ($_POST['consent_sha256'] ?? ''), (string) ($_POST['operation_key'] ?? ''), (string) ($_POST['role'] ?? ''));
        } elseif ($action === 'retry') {
            DocumentService::finalize($id);
        } else {
            throw new DomainException('Ação inválida.');
        }
        Html::redirect(BookingView::url('agreement.php', ['id' => $id] + BookingView::ticketContextQuery($returnTicket)));
    } elseif ($_SERVER['REQUEST_METHOD'] !== 'GET') {
        http_response_code(405);
        header('Allow: GET, POST');
        exit;
    }
    $doc = DocumentService::get($id);
} catch (\Glpi\Exception\RedirectException $redirect) {
    throw $redirect;
} catch (Throwable $e) {
    if ($hasTicketContext && !$contextValidated) { http_response_code(403); }
    $error = ($e instanceof DomainException || $e instanceof InvalidArgumentException)
        ? $e->getMessage() : 'Não foi possível processar este documento. Tente novamente ou contate a equipe responsável.';
    if ($isRequesterInterface && str_contains($error, 'Atualize o banco do Reserva Plus')) {
        $error = 'O aceite está temporariamente indisponível. Solicite à equipe responsável a regularização do sistema.';
    }
    if (!$e instanceof DomainException && !$e instanceof InvalidArgumentException) {
        error_log('ReservaPlus document operation failed: ' . get_class($e));
    }
}
BookingView::header(__('Revisar e assinar documento', 'reservaplus'), __('Confira o documento e confirme o aceite com sua conta.', 'reservaplus'));
$e = PdfRenderer::escape(...);
echo '<section class="rp-panel">';
if ($error !== '') {
    echo '<div class="alert alert-danger" role="alert">' . $e($error) . '</div>';
    if (!isset($doc)) {
        echo '</section>';
        BookingView::footer();
        exit;
    }
}
$base = BookingView::url('');
$canAudit = Access::booking(Store::row('bookings', (int) $doc['bookings_id']), 'audit');
echo '<h2>' . $e((string) ($doc['snapshot']['title'] ?? DocumentService::title((string) $doc['purpose']))) . '</h2>';
$backUrl = $returnTicket > 0 ? BookingView::ticketTabUrl($returnTicket) : BookingView::url('booking.php', ['id' => (int) $doc['bookings_id']]);
$backLabel = $returnTicket > 0 ? 'Voltar à aba Reserva Plus do chamado #' . $returnTicket : 'Voltar à reserva #' . (int) $doc['bookings_id'];
echo '<p><a href="' . $e($backUrl) . '">' . $e($backLabel) . '</a></p>';
echo '<p>Documento #' . $id . ' | Versão ' . (int) $doc['revision'] . ' | Estado: <strong>' . $e(DocumentService::statusLabel((string) $doc['status'])) . '</strong></p>';
if (!$doc['is_current']) {
    echo '<div class="alert alert-warning">Esta versão é histórica. O escopo atual da reserva exige um documento atualizado; os aceites anteriores permanecem preservados.</div>';
}
echo '<p><a class="btn btn-outline-primary" target="_blank" rel="noopener" href="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=source&inline=1') . '">Abrir PDF original</a> ';
if (!empty($doc['final_sha256'])) {
    echo '<a class="btn btn-primary" href="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=final') . '">Baixar PDF com comprovante</a> ';
}
echo '<a class="btn btn-outline-secondary" href="' . $e(BookingView::url('verify.php', ['code' => $doc['verification_token']] + BookingView::ticketContextQuery($returnTicket))) . '">Verificar integridade</a></p>';
echo '<iframe title="PDF original da versão a ser aceita" src="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=source&inline=1') . '" style="width:100%;height:560px;border:1px solid #ccd5df"></iframe>';
if (!$isRequesterInterface) {
    echo '<details class="rp-subform"><summary>Identificação técnica do arquivo</summary><p class="rp-muted">SHA-256 do original: <code>' . $e((string) $doc['source_sha256']) . '</code></p></details>';
}
echo '<h2>' . ($isRequesterInterface ? 'Meu aceite' : 'Signatários e finalidade') . '</h2><ul>';
$rolesPending = (array) ($doc['pending_roles'] ?? []);
$rolesAvailable = (array) ($doc['signable_roles'] ?? []);
if ($isRequesterInterface) {
    // Presentation only: backend still requires every assigned staff signature.
    $rolesPending = array_values(array_intersect($rolesPending, ['borrower']));
    $rolesAvailable = array_values(array_intersect($rolesAvailable, ['borrower']));
}
foreach ($doc['signers'] as $signer) {
    if ($isRequesterInterface && (int) ($signer['users_id'] ?? 0) !== Access::userId()) {
        continue;
    }
    foreach ((array) ($signer['roles'] ?? []) as $role) {
        if ($isRequesterInterface && $role !== 'borrower') { continue; }
        $acceptedRecord = null;
        foreach ($doc['acceptances'] as $acceptance) {
            if ((int) ($acceptance['users_id'] ?? 0) !== (int) $signer['users_id']) { continue; }
            if (DocumentService::roleAcceptedFromRows([$role], [$acceptance], (string) $doc['source_sha256'])) {
                $acceptedRecord = $acceptance;
                break;
            }
        }
        echo '<li>' . $e((string) $signer['name']) . ' — ' . $e(DocumentService::roleLabel((string) $role)) . ': '
            . ($acceptedRecord ? 'aceite em ' . $e((string) $acceptedRecord['event_utc']) : 'aguardando aceite') . '</li>';
    }
}
echo '</ul>';
if ($doc['is_current'] && $rolesPending !== []) {
    foreach ($rolesPending as $role) {
        echo '<div class="rp-signature-role card my-3 p-3">';
        if (!$isRequesterInterface) {
            echo '<h3>' . $e(DocumentService::roleLabel((string) $role)) . '</h3>';
        }
        if (!in_array($role, $rolesAvailable, true)) {
            $reason = match ($role) {
                'receiver' => 'A assinatura como recebedor exige o perfil com permissão de recebimento.',
                'inspector' => 'A assinatura como inspetor exige o perfil com permissão de inspeção.',
                default => 'Este papel não pode ser assinado com o perfil atual.',
            };
            echo '<div class="alert alert-warning">' . $e($reason) . '</div>';
            echo '</div>';
            continue;
        }
        try {
            $challenge = DocumentService::issueChallenge($id, (string) $role);
            $consent = DocumentService::consent($doc, Access::userId(), (string) $role);
            echo '<form method="post" action="' . $e($base . 'agreement.php') . '">';
            foreach (['id' => $id, 'action' => 'sign', 'role' => $role, 'challenge' => $challenge,
                'consent_sha256' => hash('sha256', $consent), 'operation_key' => bin2hex(random_bytes(24))] as $name => $value) {
                echo Html::hidden($name, ['value' => $value]);
            }
            if ($returnTicket > 0) { BookingView::hidden('_return_ticket', $returnTicket); }
            echo $isRequesterInterface
                ? '<p><strong>Confira o termo de devolução e registre sua ciência.</strong> Abra o PDF original antes de confirmar.</p>'
                : '<p><strong>Este aceite corresponde somente ao papel: ' . $e(DocumentService::roleLabel((string) $role)) . '.</strong> Abra o PDF original e confira o registro antes de confirmar.</p>';
            echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="accept_statement" value="1" required><span class="form-check-label">' . $e($consent) . '</span></label>';
            echo '<button type="submit" class="btn btn-primary mt-3">'
                . ($isRequesterInterface ? 'Confirmar meu aceite' : 'Assinar como ' . $e(DocumentService::roleLabel((string) $role)))
                . '</button>';
            Html::closeForm();
        } catch (Throwable $failure) {
            $message = ($failure instanceof DomainException || $failure instanceof InvalidArgumentException)
                ? $failure->getMessage() : 'A confirmação está indisponível. Tente novamente ou contate a equipe responsável.';
            echo '<div class="alert alert-warning">' . $e($message) . '</div>';
        }
        echo '</div>';
    }
}
if (in_array($doc['status'], ['accepted', 'artifact_error'], true) || ($doc['status'] === 'complete' && $doc['attachment_status'] !== 'attached')) {
    echo '<div class="alert alert-info">Os aceites já registrados estão preservados. A publicação do arquivo ou anexo precisa ser concluída.</div>';
    if (!empty($doc['last_error']) && $canAudit) {
        echo '<p>' . $e((string) $doc['last_error']) . '</p>';
    }
    echo '<form method="post" action="' . $e($base . 'agreement.php') . '">';
    echo Html::hidden('id', ['value' => $id]) . Html::hidden('action', ['value' => 'retry']);
    if ($returnTicket > 0) { BookingView::hidden('_return_ticket', $returnTicket); }
    echo '<button class="btn btn-outline-primary" type="submit">Tentar concluir publicação</button>';
    Html::closeForm();
}
if ($canAudit && !empty($doc['evidence_sha256'])) {
    echo '<p><a href="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=evidence') . '">Baixar evidências restritas</a></p>';
}
echo '<p class="small">O aceite interno não é uma assinatura PDF com certificado pessoal. Documentos de devolução distinguem recebimento físico, conferência e ciência dos achados. Contestações devem permanecer registradas no chamado.</p>';
echo '</section>';
BookingView::footer();
