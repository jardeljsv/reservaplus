<?php

declare(strict_types=1);

/** Destructive fixture creation in an explicitly named disposable GLPI database only. */
if (getenv('RP_TEST_ALLOW_WRITES') !== '1' || !getenv('RP_TEST_DATABASE') || !getenv('GLPI_ROOT')) {
    fwrite(STDERR, "Refusing integration writes: set GLPI_ROOT, RP_TEST_ALLOW_WRITES=1 and RP_TEST_DATABASE for a disposable GLPI installation.\n");
    exit(2);
}
ob_start();
require __DIR__ . '/bootstrap.php';
global $DB;
if ((string) $DB->dbdefault !== (string) getenv('RP_TEST_DATABASE')) {
    throw new RuntimeException('The connected database does not match RP_TEST_DATABASE; no fixtures were written.');
}

use GlpiPlugin\Reservaplus\Access;
use GlpiPlugin\Reservaplus\Agreement;
use GlpiPlugin\Reservaplus\BookingService;
use GlpiPlugin\Reservaplus\DocumentService;
use GlpiPlugin\Reservaplus\Store;

function rp_doc_login(string $name, int $rights = Access::ADMIN): int
{
    $user = new User();
    rp_assert($user->getFromDBbyName($name), 'Missing native fixture user ' . $name);
    $auth = new Auth();
    $auth->auth_succeded = true;
    $auth->user = $user;
    Session::init($auth);
    Plugin::load('reservaplus');
    $_SESSION['glpiactiveprofile'][Access::RIGHT] = $rights;
    $_SESSION['glpiactiveprofile']['plugin_reservaplus_reservation'] = READ | CREATE;
    $_SESSION['glpiactiveentities'] = [0];
    return (int) $user->getID();
}

function rp_doc_signature(int $id): ?string
{
    $doc = DocumentService::get($id);
    $snapshot = Store::decode($doc['snapshot_json'] ?? null);
    return \GlpiPlugin\Reservaplus\PdfRenderer::requiresHandwrittenSignature($snapshot)
        ? json_encode([[[90, 140], [160, 190], [230, 300], [380, 225], [540, 360]]], JSON_THROW_ON_ERROR)
        : null;
}

function rp_doc_accept(int $id, ?string $role = null): array
{
    DocumentService::artifact($id, 'source');
    $challenge = DocumentService::issueChallenge($id, $role);
    $consent = hash('sha256', DocumentService::consent(DocumentService::get($id), Access::userId(), $role));
    return DocumentService::accept($id, $challenge, $consent, bin2hex(random_bytes(24)), $role, rp_doc_signature($id));
}

$borrower = rp_doc_login('glpi');
$tag = 'RP documents integration ' . bin2hex(random_bytes(6));
$ticket = new Ticket();
$ticketId = $ticket->add(['name' => $tag, 'content' => 'Disposable internal signing fixture', 'entities_id' => 0,
    'type' => Ticket::DEMAND_TYPE, '_users_id_requester' => $borrower, 'status' => CommonITILObject::INCOMING]);
rp_assert((int) $ticketId > 0, 'Native Ticket fixture could not be created');
$resourceId = Store::insert('resources', ['name' => $tag, 'entities_id' => 0, 'kind' => 'equipment',
    'publication' => 'published', 'policy_json' => Store::json(['signature_stage' => 'approval', 'return_signature_required' => true]),
    'accessories_json' => Store::json([['name' => 'Carregador', 'quantity' => 1]]), 'date_creation' => Store::now(), 'date_mod' => Store::now()]);
$resource = Store::row('resources', $resourceId);
$bookingId = Store::insert('bookings', ['entities_id' => 0, 'users_id_requester' => $borrower, 'users_id_borrower' => $borrower,
    'tickets_id' => (int) $ticketId, 'begin' => date('Y-m-d 10:00:00', strtotime('+4 days')), 'end' => date('Y-m-d 11:00:00', strtotime('+4 days')),
    'status' => 'approved', 'purpose' => 'Capacitação técnica — João Conceição', 'operation_key' => bin2hex(random_bytes(32)),
    'hold_expires_at' => date('Y-m-d H:i:s', time() + 3600), 'date_creation' => Store::now(), 'date_mod' => Store::now()]);
$lineId = Store::insert('bookinglines', ['bookings_id' => $bookingId, 'resources_id' => $resourceId,
    'state' => 'allocated', 'snapshot_json' => Store::json(['resource' => $resource]), 'accessories_json' => '[]']);
$documentId = 0;

rp_test('native prepare preserves exact original; source must be served; wrong consent fails', function () use ($bookingId, &$documentId): void {
    rp_same(['handover'], BookingService::signaturePurposes(Store::find('bookinglines', ['bookings_id' => $bookingId])), 'Postapproval policy requires an approved-scope term');
    Store::update('bookings', $bookingId, ['status' => 'submitted']);
    rp_throws(fn () => DocumentService::prepare($bookingId, 'handover'), DomainException::class, 'aprovação');
    Store::update('bookings', $bookingId, ['status' => 'approved']);
    $documentId = DocumentService::prepare($bookingId, 'handover');
    rp_same($documentId, DocumentService::prepare($bookingId, 'handover'), 'Preparing same immutable scope is idempotent');
    $doc = DocumentService::get($documentId);
    $challenge = DocumentService::issueChallenge($documentId);
    $consent = hash('sha256', DocumentService::consent($doc, Access::userId()));
    rp_throws(fn () => DocumentService::accept($documentId, $challenge, $consent, bin2hex(random_bytes(24)), null, rp_doc_signature($documentId)), DomainException::class, 'Abra o PDF');
    $pdf = DocumentService::artifact($documentId, 'source');
    rp_assert(str_starts_with($pdf['bytes'], '%PDF-'));
    rp_same($doc['source_sha256'], hash('sha256', $pdf['bytes']));
    rp_throws(fn () => DocumentService::accept($documentId, $challenge, str_repeat('0', 64), bin2hex(random_bytes(24)), null, rp_doc_signature($documentId)), DomainException::class);
    rp_same(0, count(Store::find('acceptances', ['agreements_id' => $documentId])));
});

rp_test('native authenticated actor and generic model isolation', function () use (&$documentId): void {
    rp_doc_login('post-only');
    rp_throws(fn () => DocumentService::issueChallenge($documentId), DomainException::class, 'signatário');
    rp_doc_login('post-only', 0);
    rp_assert(!Agreement::canView(), 'Generic collection is not available to nonadministrators');
    $model = new Agreement();
    rp_assert(!$model->getFromDB($documentId));
    rp_same([], $model->fields, 'Forbidden model read clears loaded fields');
    rp_doc_login('glpi');
    $model = new Agreement();
    rp_assert($model->getFromDB($documentId));
    rp_assert(!$model->delete(['id' => $documentId], true), 'Generic direct deletion cannot destroy evidence');
    rp_assert(!Agreement::canCreate() && !Agreement::canUpdate() && !Agreement::canDelete() && !Agreement::canPurge());
    rp_assert(!$model->update(['id' => $documentId, 'status' => 'complete']));
});

rp_test('actual PDF acceptance finalizes and attaches through native Document; replay cannot duplicate', function () use ($bookingId, $ticketId, &$documentId): void {
    $source = DocumentService::artifact($documentId, 'source');
    $challenge = DocumentService::issueChallenge($documentId);
    $consent = hash('sha256', DocumentService::consent(DocumentService::get($documentId), Access::userId()));
    $snapshot = Store::decode(DocumentService::get($documentId)['snapshot_json'] ?? null);
    if (\GlpiPlugin\Reservaplus\PdfRenderer::isCessionTerm($snapshot)) {
        rp_throws(static fn() => DocumentService::accept(
            $documentId, $challenge, $consent, bin2hex(random_bytes(24))
        ), DomainException::class, 'Desenhe sua assinatura');
        rp_same(0, count(Store::find('acceptances', ['agreements_id' => $documentId])));
    }
    $key = bin2hex(random_bytes(24));
    $doc = DocumentService::accept($documentId, $challenge, $consent, $key, null, rp_doc_signature($documentId));
    rp_same('complete', $doc['status'], (string) ($doc['last_error'] ?? ''));
    rp_same('attached', $doc['attachment_status'], (string) ($doc['last_error'] ?? ''));
    rp_assert((int) $doc['final_documents_id'] > 0);
    global $DB;
    $relation = $DB->request(['FROM' => 'glpi_documents_items', 'WHERE' => ['documents_id' => (int) $doc['final_documents_id'], 'items_id' => (int) $ticketId, 'itemtype' => 'Ticket']])->current();
    rp_assert((bool) $relation, 'Actual native Document_Item relation must exist');
    DocumentService::accept($documentId, $challenge, $consent, $key, null, rp_doc_signature($documentId));
    rp_same(1, count(Store::find('acceptances', ['agreements_id' => $documentId])));
    rp_same($source['bytes'], DocumentService::artifact($documentId, 'source')['bytes'], 'Original PDF bytes never rewritten');
    rp_assert(DocumentService::verify($doc['verification_token'])['integrity_valid']);
    rp_assert(DocumentService::isSatisfied($bookingId, 'handover'));
    rp_same(null, Store::row('bookings', $bookingId)['hold_expires_at'], 'Completed required artifacts release the approval hold');
});

rp_test('evidence needs booking-scoped audit; operational revision preserves signing; scope change does not', function () use ($bookingId, &$documentId): void {
    rp_doc_login('glpi', Access::AUDIT);
    rp_assert(DocumentService::get($documentId)['is_current'], 'Own borrower can read ordinary document');
    rp_throws(fn () => DocumentService::artifact($documentId, 'evidence'), DomainException::class);
    rp_doc_login('glpi');
    Store::update('bookings', $bookingId, ['revision' => 5, 'status' => 'issued', 'hold_expires_at' => null]);
    rp_assert(DocumentService::isSatisfied($bookingId, 'handover'), 'Operational transitions do not void terms');
    Store::update('bookings', $bookingId, ['scope_revision' => 2]);
    rp_assert(!DocumentService::isSatisfied($bookingId, 'handover'));
    rp_throws(fn () => DocumentService::issueChallenge($documentId), DomainException::class);
    $new = DocumentService::prepare($bookingId, 'handover');
    rp_assert($new !== $documentId, 'New material scope creates another immutable candidate');
});

rp_test('changed source bytes fail closed and preserve prior acceptance', function () use (&$documentId): void {
    $doc = DocumentService::get($documentId);
    $path = GLPI_PLUGIN_DOC_DIR . '/reservaplus/agreements/' . $doc['source_path'];
    $original = file_get_contents($path);
    try {
        file_put_contents($path, $original . "\ncorrupted");
        clearstatcache(true, $path);
        rp_throws(fn () => DocumentService::artifact($documentId, 'source'), RuntimeException::class, 'integridade');
        rp_throws(fn () => DocumentService::verify($doc['verification_token']), RuntimeException::class, 'integridade');
    } finally {
        file_put_contents($path, $original);
        clearstatcache(true, $path);
    }
    rp_same(1, count(Store::find('acceptances', ['agreements_id' => $documentId])));
});

rp_test('return receipt records actual received facts; failed finalization retains consent and physical return', function () use ($bookingId, $lineId): void {
    rp_throws(fn () => DocumentService::prepare($bookingId, 'return_receipt'), DomainException::class, 'fatos');
    $receiver = rp_doc_login('post-only');
    Store::update('bookinglines', $lineId, ['state' => 'received', 'received_at' => Store::now(),
        'accessories_json' => Store::json([['name' => 'Carregador', 'issued' => 1, 'received' => 0]])]);
    Store::update('bookings', $bookingId, ['status' => 'returning']);
    Store::event($bookingId, 'received', ['note' => 'Equipamento recebido; carregador pendente.'], $lineId);
    $id = DocumentService::prepare($bookingId, 'return_receipt');
    $doc = rp_doc_accept($id);
    rp_same('partially_signed', $doc['status']);
    rp_same(['receiver'], $doc['acceptances'][0]['roles']);
    rp_assert(!str_contains($doc['snapshot']['statement'], 'bom estado'));
    rp_doc_login('glpi');
    $keyPath = GLPI_PLUGIN_DOC_DIR . '/reservaplus/agreements/integrity-v1.key';
    $key = file_get_contents($keyPath);
    $publishedFiles = count(glob(dirname($keyPath) . '/*-final.pdf'));
    try {
        unlink($keyPath);
        $doc = rp_doc_accept($id);
        rp_same('artifact_error', $doc['status']);
        rp_assert(!is_file($keyPath), 'Missing historical HMAC key must never be silently regenerated');
        rp_same(2, count($doc['acceptances']));
        rp_same('received', Store::row('bookinglines', $lineId)['state']);
        rp_same($publishedFiles, count(glob(dirname($keyPath) . '/*-final.pdf')), 'Failed finalization removes only unpublished staging files');
    } finally {
        file_put_contents($keyPath, $key);
        chmod($keyPath, 0600);
    }
    DocumentService::finalize($id);
    $doc = DocumentService::get($id);
    rp_same('complete', $doc['status']);
    rp_same(2, count($doc['acceptances']), 'Retry never asks actors to sign again');
    rp_assert(DocumentService::verify($doc['verification_token'])['integrity_valid']);
    rp_same($receiver, (int) $doc['signers'][1]['users_id']);
});

rp_test('inspection needs actual findings and separate inspector attestation; corrections require a new document', function () use ($bookingId, $lineId): void {
    rp_throws(fn () => DocumentService::prepare($bookingId, 'return_inspection'), DomainException::class, 'fatos');
    $inspector = rp_doc_login('post-only');
    Store::update('bookinglines', $lineId, ['state' => 'quarantine', 'inspection_at' => Store::now(), 'inspector_users_id' => $inspector,
        'inspection_json' => Store::json(['result' => 'damage', 'note' => 'Conector avariado; encaminhado para avaliação.', 'accessory_missing' => true])]);
    Store::event($bookingId, 'inspected', ['result' => 'damage'], $lineId);
    $id = DocumentService::prepare($bookingId, 'return_inspection');
    $doc = rp_doc_accept($id);
    rp_same('partially_signed', $doc['status']);
    rp_same(['inspector'], $doc['acceptances'][0]['roles']);
    rp_same('damage', $doc['snapshot']['scope']['lines'][0]['inspection']['result']);
    rp_doc_login('glpi');
    $doc = rp_doc_accept($id);
    rp_same('complete', $doc['status']);
    rp_assert(DocumentService::isSatisfied($bookingId, 'return_inspection'));
    Store::update('bookinglines', $lineId, ['inspection_json' => Store::json(['result' => 'damage', 'note' => 'Laudo corrigido após teste adicional.'])]);
    rp_assert(!DocumentService::isSatisfied($bookingId, 'return_inspection'));
    rp_assert(DocumentService::prepare($bookingId, 'return_inspection') !== $id);
    rp_same(2, count(Store::find('acceptances', ['agreements_id' => $id])), 'Correction preserves earlier acceptance evidence');
});

rp_test('same GLPI user signs the document once, with account and active role recorded', function () use ($bookingId, $lineId, $borrower): void {
    rp_doc_login('glpi', Access::ADMIN);
    Store::update('bookinglines', $lineId, [
        'inspector_users_id' => $borrower,
        'inspection_at' => Store::now(),
        'inspection_json' => Store::json(['result' => 'pass', 'findings' => 'Conferência realizada pelo responsável técnico.']),
    ]);
    Store::update('bookings', $bookingId, ['status' => 'returning']);
    $document = DocumentService::prepare($bookingId, 'return_inspection');
    $doc = DocumentService::get($document);
    rp_same(['borrower', 'inspector'], $doc['roles']);

    // Even with two assigned roles, one authenticated acceptance is sufficient
    // for the same account. Inspection facts remain recorded independently.
    rp_doc_login('glpi', READ);
    $doc = rp_doc_accept($document, 'borrower');
    rp_same('complete', $doc['status']);
    rp_same(1, count($doc['acceptances']));
    rp_same(['borrower'], $doc['acceptances'][0]['roles']);
    rp_assert(DocumentService::isSatisfied($bookingId, 'return_inspection'));

    rp_doc_login('glpi', Access::ADMIN);
    rp_throws(static fn() => DocumentService::issueChallenge($document, 'inspector'),
        DomainException::class, 'já foi registrado');
    rp_same(1, count(Store::find('acceptances', ['agreements_id' => $document])));

    // When the same account chooses the authorized technical profile first,
    // the single acceptance records Inspector, not a second Beneficiary action.
    Store::update('bookinglines', $lineId, [
        'inspection_json' => Store::json(['result' => 'pass', 'findings' => 'Conferência técnica atualizada para nova versão.']),
    ]);
    $technicalDocument = DocumentService::prepare($bookingId, 'return_inspection');
    rp_assert($technicalDocument !== $document);
    $technicalDoc = rp_doc_accept($technicalDocument, 'inspector');
    rp_same('complete', $technicalDoc['status']);
    rp_same(1, count($technicalDoc['acceptances']));
    rp_same(['inspector'], $technicalDoc['acceptances'][0]['roles']);
});

rp_test('expired approval hold blocks fresh signature even before cron', function () use ($bookingId): void {
    Store::update('bookings', $bookingId, ['status' => 'approved', 'hold_expires_at' => date('Y-m-d H:i:s', time() + 3600)]);
    $id = DocumentService::prepare($bookingId, 'request');
    DocumentService::artifact($id, 'source');
    $challenge = DocumentService::issueChallenge($id);
    $consent = hash('sha256', DocumentService::consent(DocumentService::get($id), Access::userId()));
    Store::update('bookings', $bookingId, ['hold_expires_at' => date('Y-m-d H:i:s', time() - 60)]);
    rp_throws(fn () => DocumentService::accept($id, $challenge, $consent, bin2hex(random_bytes(24))), DomainException::class, 'expirou');
    rp_same(0, count(Store::find('acceptances', ['agreements_id' => $id])));
});

echo 'Fixture booking #' . $bookingId . ', ticket #' . $ticketId . '. Records remain in the explicitly authorized disposable database for inspection.' . PHP_EOL;
ob_end_flush();
