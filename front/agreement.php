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
            $signedDocument = DocumentService::accept($id, (string) ($_POST['challenge'] ?? ''), (string) ($_POST['consent_sha256'] ?? ''), (string) ($_POST['operation_key'] ?? ''), (string) ($_POST['role'] ?? ''), is_string($_POST['signature_strokes'] ?? null) ? $_POST['signature_strokes'] : null);
            // Show the signed PDF immediately after acceptance. The requester
            // can continue to the already-unblocked approval step from here.
            Html::redirect(BookingView::url('agreement.php', ['id' => $id, 'signed' => 1]
                + BookingView::ticketContextQuery($returnTicket)));
        } elseif ($action === 'retry') {
            DocumentService::finalize($id);
            $finalizedDoc = DocumentService::get($id);
            if (($finalizedDoc['purpose'] ?? '') === 'return_inspection'
                && ($finalizedDoc['status'] ?? '') === 'complete') {
                try {
                    \GlpiPlugin\Reservaplus\BookingService::completeAfterFinalSignature((int) $finalizedDoc['bookings_id']);
                } catch (Throwable $completionError) {
                    \Toolbox::logInFile('reservaplus', 'Return completion retry #'.$id.': '.$completionError->getMessage()."\n");
                }
            }
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
// Show the immutable final artifact by default once it is complete;
// preserve the original for signing and audit without rewriting either PDF.
$showFinal = (string) ($doc['status'] ?? '') === 'complete'
    && !empty($doc['final_sha256']) && !empty($doc['final_path']);
$viewerArtifact = $showFinal ? 'final' : 'source';
$viewerTitle = $showFinal ? 'PDF final assinado com comprovante de aceite' : 'PDF original da versão a ser aceita';
$justSigned = ($_GET['signed'] ?? '') === '1'
    && DocumentService::userAcceptedFromRows(
        array_values(array_filter($doc['acceptances'], static fn (array $row): bool =>
            (int) ($row['users_id'] ?? 0) === Access::userId()
        )),
        (string) $doc['source_sha256']
    );
if ($justSigned && $showFinal) {
    echo '<div class="alert alert-success" role="status"><strong>Assinatura registrada com sucesso.</strong> Abaixo está a versão final do termo com o comprovante de aceite.</div>';
} elseif ($justSigned) {
    echo '<div class="alert alert-info" role="status">Aceite registrado. O PDF final ainda está sendo preparado; o original permanece disponível para consulta.</div>';
}
if ($showFinal && $doc['is_current']) {
    if (($doc['purpose'] ?? '') === 'request') {
        echo '<p><a class="btn btn-primary" href="' . $e($backUrl) . '">Continuar para aprovação da reserva</a></p>';
    } elseif (($doc['purpose'] ?? '') === 'return_receipt') {
        $relatedBooking = Store::row('bookings', (int) $doc['bookings_id']);
        if (\GlpiPlugin\Reservaplus\BookingService::threeTermReturn($relatedBooking)) {
            echo '<p><a class="btn btn-primary" href="' . $e($backUrl) . '">'
                . 'Comprovante de devolução assinado — acompanhar reserva</a></p>';
        } elseif (\GlpiPlugin\Reservaplus\BookingService::simplifiedReturn($relatedBooking)) {
            echo '<p><a class="btn btn-primary" href="' . $e($backUrl . '#rp-booking-actions') . '">'
                . 'Continuar para conferência técnica</a></p>';
        }
    } elseif (($doc['purpose'] ?? '') === 'return_inspection'
        && \GlpiPlugin\Reservaplus\BookingService::unifiedReturn(
            Store::row('bookings', (int) $doc['bookings_id'])
        )) {
        echo '<p><a class="btn btn-primary" href="' . $e($backUrl) . '">'
            . 'Termo assinado — acompanhar devolução</a></p>';
    } elseif (($doc['purpose'] ?? '') === 'return_inspection'
        && \GlpiPlugin\Reservaplus\BookingService::threeTermReturn(
            Store::row('bookings', (int) $doc['bookings_id'])
        )) {
        echo '<p><a class="btn btn-primary" href="' . $e($backUrl) . '">'
            . 'Conferência assinada — liberar comprovante ao beneficiário</a></p>';
    }
}
echo '<p>';
if ($showFinal) {
    echo '<a class="btn btn-primary" target="_blank" rel="noopener" href="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=final&inline=1') . '">Abrir versão assinada</a> ';
    echo '<a class="btn btn-outline-primary" href="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=final') . '">Baixar PDF assinado</a> ';
}
echo '<a class="btn btn-outline-secondary" target="_blank" rel="noopener" href="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=source&inline=1') . '">Abrir PDF original</a> ';
echo '<a class="btn btn-outline-secondary" href="' . $e(BookingView::url('verify.php', ['code' => $doc['verification_token']] + BookingView::ticketContextQuery($returnTicket))) . '">Verificar integridade</a></p>';
echo '<iframe title="' . $e($viewerTitle) . '" src="' . $e($base . 'agreement.download.php?id=' . $id . '&artifact=' . $viewerArtifact . '&inline=1') . '" style="width:100%;height:560px;border:1px solid #ccd5df"></iframe>';
if (!$isRequesterInterface) {
    echo '<details class="rp-subform"><summary>Identificação técnica do arquivo</summary><p class="rp-muted">SHA-256 do original: <code>' . $e((string) $doc['source_sha256']) . '</code></p></details>';
}
echo '<h2>' . ($isRequesterInterface ? 'Meu aceite' : 'Signatários e finalidade') . '</h2><ul>';
$myId = Access::userId();
$mySigned = false;
foreach ($doc['signers'] as $signer) {
    if ($isRequesterInterface && (int) ($signer['users_id'] ?? 0) !== $myId) {
        continue;
    }
    $acceptedRecord = null;
    foreach ($doc['acceptances'] as $acceptance) {
        if ((int) ($acceptance['users_id'] ?? 0) !== (int) $signer['users_id']) { continue; }
        if (DocumentService::userAcceptedFromRows([$acceptance], (string) $doc['source_sha256'])) {
            $acceptedRecord = $acceptance;
            break;
        }
    }
    if ($acceptedRecord !== null && (int) $signer['users_id'] === $myId) { $mySigned = true; }
    $recordedRoles = $acceptedRecord['roles'] ?? [];
    $signedAs = $recordedRoles !== []
        ? implode(', ', array_map(DocumentService::roleLabel(...), (array) $recordedRoles))
        : 'Signatário';
    $status = $acceptedRecord !== null
        ? 'Aceite registrado como ' . $signedAs . ' em ' . (string) $acceptedRecord['event_utc']
        : 'Aguardando aceite';
    echo '<li>' . $e((string) $signer['name']) . ': ' . $e($status) . '</li>';
}
echo '</ul>';
if ($isRequesterInterface && $doc['is_current'] && $mySigned) {
    echo '<div class="alert alert-success">Seu aceite deste documento já foi registrado. Não é necessário assinar novamente em outro perfil.</div>';
}
$chosenRole = (string) ($doc['signing_role'] ?? '');
if ($doc['is_current'] && !$mySigned && $chosenRole !== '') {
    echo '<div class="rp-signature-role card my-3 p-3">';
    try {
        $challenge = DocumentService::issueChallenge($id, $chosenRole);
        $consent = DocumentService::consent($doc, $myId, $chosenRole);
        echo '<form method="post" action="' . $e($base . 'agreement.php') . '">';
        foreach (['id' => $id, 'action' => 'sign', 'role' => $chosenRole, 'challenge' => $challenge,
            'consent_sha256' => hash('sha256', $consent), 'operation_key' => bin2hex(random_bytes(24))] as $name => $value) {
            echo Html::hidden($name, ['value' => $value]);
        }
        if ($returnTicket > 0) { BookingView::hidden('_return_ticket', $returnTicket); }
        echo '<p><strong>Leia o documento e confirme seu aceite.</strong> O sistema registra a conta GLPI e o perfil autorizado utilizado nesta assinatura.</p>';
        if (PdfRenderer::requiresHandwrittenSignature((array) ($doc['snapshot'] ?? []))) {
            echo '<div class="rp-handwritten-panel my-3" data-rp-handwritten>';
            $relatedWorkflow = (string) (($doc['snapshot'] ?? [])['return_workflow'] ?? '');
            $drawingLabel = match ((string) ($doc['purpose'] ?? '')) {
                'return_receipt' => $relatedWorkflow === 'three_terms_v2'
                    ? 'Assinatura do beneficiário que devolve (mouse ou toque)'
                    : 'Assinatura do técnico recebedor (mouse ou toque)',
                'return_inspection' => 'Assinatura do técnico responsável pela conferência (mouse ou toque)',
                default => 'Assinatura do(a) requerente (mouse ou toque)',
            };
            echo '<label class="form-label"><strong>' . $e($drawingLabel) . '</strong></label>';
            echo '<div style="max-width:680px;border:1px solid #9aaabc;border-radius:8px;background:#fff;overflow:hidden">';
            echo '<canvas width="680" height="170" style="display:block;width:100%;height:170px;touch-action:none;cursor:crosshair" aria-label="Área de desenho da assinatura"></canvas></div>';
            echo '<p class="rp-muted">Desenhe sua assinatura. Ela será vinculada à sua conta GLPI e incorporada ao PDF final.</p>';
            echo Html::hidden('signature_strokes', ['value' => '']);
            echo '<button type="button" class="btn btn-outline-secondary btn-sm" data-rp-clear>Limpar assinatura</button>';
            echo '</div>';
        }
        echo '<label class="form-check"><input class="form-check-input" type="checkbox" name="accept_statement" value="1" required><span class="form-check-label">' . $e($consent) . '</span></label>';
        echo '<button type="submit" class="btn btn-primary mt-3">Assinar termo</button>';
        Html::closeForm();
    } catch (Throwable $failure) {
        $message = ($failure instanceof DomainException || $failure instanceof InvalidArgumentException)
            ? $failure->getMessage() : 'A confirmação está indisponível. Tente novamente ou contate a equipe responsável.';
        echo '<div class="alert alert-warning">' . $e($message) . '</div>';
    }
    echo '</div>';
}
// Each submitted point is an integer on a fixed 1000x1000 grid.
// No image upload or arbitrary SVG/HTML reaches the server.
echo <<<'RPSIGNATURE'
<script>
(function () {
  for (const box of document.querySelectorAll('[data-rp-handwritten]')) {
    const canvas = box.querySelector('canvas');
    const input = box.querySelector('input[name="signature_strokes"]');
    const clear = box.querySelector('[data-rp-clear]');
    const ctx = canvas.getContext('2d');
    if (!ctx || !input) { continue; }
    const strokes = [];
    let stroke = null;
    ctx.strokeStyle = '#182f48';
    ctx.lineWidth = 2.4;
    ctx.lineCap = 'round';
    ctx.lineJoin = 'round';
    function point(e) {
      const r = canvas.getBoundingClientRect();
      return [Math.max(0, Math.min(1000, Math.round((e.clientX-r.left) * 1000/r.width))),
              Math.max(0, Math.min(1000, Math.round((e.clientY-r.top) * 1000/r.height)))];
    }
    function draw(p, move) {
      const x=p[0]*canvas.width/1000, y=p[1]*canvas.height/1000;
      if(move) {ctx.beginPath();ctx.moveTo(x,y);}
      else {ctx.lineTo(x,y);ctx.stroke();}
    }
    canvas.addEventListener('pointerdown', function(e) {
      if(strokes.length>=120) return;
      stroke=[point(e)]; strokes.push(stroke); draw(stroke[0],true);
      canvas.setPointerCapture(e.pointerId); e.preventDefault();
    });
    canvas.addEventListener('pointermove', function(e) {
      if(!stroke || stroke.length>=400) return;
      const p=point(e),last=stroke[stroke.length-1];
      if(p[0]===last[0]&&p[1]===last[1])return;
      stroke.push(p);draw(p,false);e.preventDefault();
    });
    function finish() {
      if (stroke && stroke.length===1) {strokes.pop();}
      stroke=null;
      input.value=JSON.stringify(strokes);
    }
    canvas.addEventListener('pointerup',finish);
    canvas.addEventListener('pointercancel',finish);
    clear.addEventListener('click',function(){
      strokes.length=0;stroke=null;input.value='';
      ctx.clearRect(0,0,canvas.width,canvas.height);
    });
    const form=box.closest('form');
    form.addEventListener('submit',function(e){
      finish();
      const points=strokes.reduce((n,a)=>n+a.length,0);
      if(points<4){
        e.preventDefault();
        window.alert('Desenhe sua assinatura no quadro antes de confirmar.');
      }
    });
  }
})();
</script>
RPSIGNATURE;
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
