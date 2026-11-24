<?php

declare(strict_types=1);

require_once __DIR__ . '/bootstrap.php';

use GlpiPlugin\Reservaplus\DocumentService;
use GlpiPlugin\Reservaplus\PdfRenderer;

rp_test('document scope hashes preserve exact material identity and ignore associative key order', static function (): void {
    $scope = ['users_id_borrower' => 12, 'begin' => '2026-11-02 08:00:00', 'end' => '2026-11-03 16:00:00', 'lines' => [['resources_id' => 7, 'serial' => 'ABC']]];
    $reordered = ['lines' => [['serial' => 'ABC', 'resources_id' => 7]], 'end' => $scope['end'], 'begin' => $scope['begin'], 'users_id_borrower' => 12];
    rp_same(DocumentService::scopeHash($scope), DocumentService::scopeHash($reordered));
    foreach (['users_id_borrower' => 13, 'end' => '2026-11-04 16:00:00', 'lines' => [['resources_id' => 8, 'serial' => 'ABC']]] as $key => $replacement) {
        $changed = $scope;
        $changed[$key] = $replacement;
        rp_assert(DocumentService::scopeHash($changed) !== DocumentService::scopeHash($scope), 'Material change failed to change hash: ' . $key);
    }
});

rp_test('new equipment cession template follows approved institutional sections and escapes input', static function (): void {
    $sample = ['template_version' => PdfRenderer::TEMPLATE_VERSION, 'purpose' => 'request',
        'title' => 'Termo de responsabilidade', 'tickets_id' => 10, 'borrower_login' => '12345',
        'scope' => ['bookings_id' => 12, 'scope_revision' => 1,
            'borrower' => ['name' => 'Teste <script>alert(1)</script>', 'matricula' => '', 'unit' => 'SETIS'],
            'begin' => '2026-11-17 18:15:00', 'end' => '2026-11-17 19:15:00', 'purpose_text' => 'Teste',
            'lines' => [['resource' => ['kind' => 'equipment', 'name' => 'PC-01769678', 'manufacturer' => 'Dell',
                'model' => 'Latitude', 'inventory_number' => 'PC-01769678'],
                'accessories' => [['name' => 'Carregador', 'quantity' => 1]]]]]];
    rp_assert(PdfRenderer::isCessionTerm($sample));
    $html = PdfRenderer::html($sample);
    foreach (['TERMO DE CESSÃO DE EQUIPAMENTO', '1. IDENTIFICAÇÃO DO EQUIPAMENTO',
        '2. REQUERENTE', '3. PERÍODO DA CESSÃO', '4. RESPONSABILIDADE',
        '5. ASSINATURA', 'Carregador', 'não comprova a entrega física'] as $needle) {
        rp_assert(str_contains($html, $needle), $needle);
    }
    rp_assert(!str_contains($html, '<script>'));
    rp_assert(str_contains($html, '&lt;script&gt;'));
    $sample['scope']['lines'][0]['resource']['kind'] = 'room';
    rp_assert(!PdfRenderer::isCessionTerm($sample));
    $sample['scope']['lines'][0]['resource']['kind'] = 'equipment';
    $sample['template_version'] = 'reservaplus-terms-v1';
    rp_assert(!PdfRenderer::isCessionTerm($sample));
});

rp_test('new equipment receipt matches cession layout and preserves physical receiving distinctions', static function (): void {
    $sample = ['template_version' => PdfRenderer::TEMPLATE_VERSION,
        'purpose' => 'return_receipt', 'borrower_login' => '03180214120',
        'timezone' => 'America/Sao_Paulo', 'tickets_id' => 236333,
        'scope' => ['bookings_id' => 17, 'scope_revision' => 1,
            'begin' => '2026-11-23 15:00:00', 'end' => '2026-11-23 16:00:00',
            'purpose_text' => 'Atividade <script>alert(1)</script>',
            'borrower' => ['name' => 'Jardel de Jesus da Silva', 'matricula' => '', 'unit' => 'SETIS'],
            'lines' => [['resource' => ['kind' => 'equipment', 'name' => 'PC-01769678',
                'manufacturer' => 'Dell', 'model' => 'Latitude', 'inventory_number' => '1769678',
                'serial' => '01044913010028'],
                'received_at' => '2026-11-23 15:55:00',
                'accessories' => [['name' => 'Carregador', 'issued' => 1, 'received' => 0]]]]]];
    rp_assert(PdfRenderer::isEquipmentReceiptTerm($sample));
    rp_assert(PdfRenderer::requiresHandwrittenSignature($sample));
    $html = PdfRenderer::html($sample);
    foreach (['TERMO DE RECEBIMENTO DE EQUIPAMENTO', '1. IDENTIFICAÇÃO DO EQUIPAMENTO',
        '2. IDENTIFICAÇÃO DO RESPONSÁVEL', '3. PERÍODO DA RESERVA',
        '4. DECLARAÇÃO DE RECEBIMENTO', '5. ASSINATURAS',
        'PC-01769678', 'Carregador', '15:55:00', 'Recebido',
        'não comprova o funcionamento', 'conferência técnica posterior',
        '03180214120', '&lt;script&gt;'] as $needle) {
        rp_assert(str_contains($html, $needle), $needle);
    }
    rp_assert(!str_contains($html, '<script>'));
    rp_assert(!str_contains($html, '<img '));
    $sample['scope']['lines'][0]['resource']['kind'] = 'room';
    rp_assert(!PdfRenderer::isEquipmentReceiptTerm($sample));
    $sample['scope']['lines'][0]['resource']['kind'] = 'equipment';
    $sample['template_version'] = 'reservaplus-terms-v1';
    rp_assert(!PdfRenderer::isEquipmentReceiptTerm($sample));
});

rp_test('three-term PDFs separate borrower return and technical inspector evidence', static function (): void {
    $scope = ['bookings_id' => 23, 'scope_revision' => 1,
        'borrower' => ['name' => 'Beneficiário'],
        'lines' => [[
            'resource' => ['kind' => 'equipment', 'name' => 'Notebook <test>', 'inventory_number' => 'PC-001'],
            'received_at' => '2026-11-23 18:00:00',
            'inspection_at' => '2026-11-23 18:30:00',
            'inspection' => ['result' => 'pass', 'findings' => 'Testado <script>'],
            'accessories' => [['name' => 'Carregador', 'issued' => 1, 'received' => 1]],
        ]]];
    $return = ['template_version' => PdfRenderer::TEMPLATE_VERSION, 'purpose' => 'return_receipt',
        'return_workflow' => 'three_terms_v2', 'scope' => $scope];
    $html = PdfRenderer::html($return);
    rp_assert(str_contains($html, 'TERMO DE DEVOLUÇÃO DE EQUIPAMENTO'));
    rp_assert(str_contains($html, 'ASSINATURA DO BENEFICIÁRIO'));
    rp_assert(str_contains($html, 'após o registro de recebimento'));
    rp_assert(PdfRenderer::requiresHandwrittenSignature($return));
    $return['purpose'] = 'return_inspection';
    $technical = PdfRenderer::html($return);
    rp_assert(str_contains($technical, 'TERMO DE CONFERÊNCIA TÉCNICA'));
    rp_assert(str_contains($technical, 'ASSINATURA DO TÉCNICO RESPONSÁVEL'));
    rp_assert(str_contains($technical, 'Testado &lt;script&gt;'));
    rp_assert(!str_contains($technical, '<script>'));
    rp_assert(PdfRenderer::requiresHandwrittenSignature($return));
});

rp_test('new simplified return PDF is one signed technician term, without legacy output changes', static function (): void {
    $scope = ['bookings_id' => 22, 'lines' => [[
        'resource' => ['kind' => 'equipment', 'name' => 'Notebook R'],
        'received_at' => '2026-11-23 18:55:00', 'accessories' => [
            ['name' => 'Carregador', 'issued' => 1, 'received' => 1],
        ],
    ]], 'borrower' => ['name' => 'Usuário']];
    $sample = ['template_version' => PdfRenderer::TEMPLATE_VERSION, 'purpose' => 'return_receipt',
        'return_workflow' => 'two_terms_v1', 'scope' => $scope];
    $html = PdfRenderer::html($sample);
    rp_assert(str_contains($html, 'TERMO DE DEVOLUÇÃO DE EQUIPAMENTO'));
    rp_assert(str_contains($html, 'ASSINATURA DO TÉCNICO RECEBEDOR'));
    rp_assert(str_contains($html, 'sem emissão de outro termo'));
    rp_assert(PdfRenderer::requiresHandwrittenSignature($sample));
    unset($sample['return_workflow']);
    $legacy = PdfRenderer::html($sample);
    rp_assert(str_contains($legacy, 'TERMO DE RECEBIMENTO DE EQUIPAMENTO'));
    rp_assert(str_contains($legacy, 'ASSINATURAS E REGISTRO ELETRÔNICO'));
});

rp_test('equipment receiving terms require drawing without changing historic or inspection forms', static function (): void {
    $scope = ['lines' => [['resource' => ['kind' => 'equipment', 'name' => 'Notebook']]]];
    $current = ['template_version' => PdfRenderer::TEMPLATE_VERSION,
        'purpose' => 'return_receipt', 'scope' => $scope];
    rp_assert(PdfRenderer::requiresHandwrittenSignature($current));
    rp_assert(!PdfRenderer::isCessionTerm($current));
    $current['purpose'] = 'return_inspection';
    rp_assert(!PdfRenderer::requiresHandwrittenSignature($current));
    $current['purpose'] = 'return_receipt';
    $current['template_version'] = 'reservaplus-terms-v1';
    rp_assert(!PdfRenderer::requiresHandwrittenSignature($current));
    $current['template_version'] = PdfRenderer::TEMPLATE_VERSION;
    $current['scope']['lines'][0]['resource']['kind'] = 'room';
    rp_assert(!PdfRenderer::requiresHandwrittenSignature($current));
    $current['scope'] = ['lines' => []];
    rp_assert(!PdfRenderer::requiresHandwrittenSignature($current));
});

rp_test('handwritten signature validates normalized points, size and shape', static function (): void {
    $good = [[[100, 200], [150, 220], [200, 260], [250, 210]]];
    rp_same($good, DocumentService::validateHandwrittenStrokes(json_encode($good)));
    foreach ([null, '', 'null', '{}', '[]', '[[]]', '[[[1,2],[2,3]]]',
        '[[[10,10],[1001,10],[100,50],[50,100]]]',
        '[[[10,10],[15,15],[20,20],[24,24]]]',
        '[[[10,10],[80,20],[80,"30"],[90,40]]]',
        str_repeat('0', 150001)] as $bad) {
        rp_throws(static fn() => DocumentService::validateHandwrittenStrokes($bad), DomainException::class);
    }
});

rp_test('document signing challenge binds session, signer, exact bytes, consent and revision', static function (): void {
    $doc = ['id' => 10, 'revision' => 3, 'source_sha256' => str_repeat('a', 64)];
    $challenge = ['agreements_id' => 10, 'users_id' => 12, 'revision' => 3, 'source_sha256' => str_repeat('a', 64),
        'session_hash' => str_repeat('b', 64), 'consent_sha256' => str_repeat('c', 64), 'expires_at' => '2026-11-02 08:10:00', 'consumed_at' => null];
    DocumentService::validateChallenge($challenge, $doc, 12, str_repeat('b', 64), str_repeat('c', 64), '2026-11-02 08:00:00');
    foreach (['agreements_id' => 11, 'users_id' => 13, 'revision' => 4, 'source_sha256' => str_repeat('d', 64),
        'session_hash' => str_repeat('d', 64), 'consent_sha256' => str_repeat('d', 64), 'expires_at' => '2026-11-02 08:00:00', 'consumed_at' => '2026-11-02 07:59:00'] as $key => $replacement) {
        $bad = $challenge;
        $bad[$key] = $replacement;
        rp_throws(static fn () => DocumentService::validateChallenge($bad, $doc, 12, str_repeat('b', 64), str_repeat('c', 64), '2026-11-02 08:00:00'), DomainException::class);
    }
});

$snapshot = [
    'title' => 'Termo de recebimento para conferência',
    'statement' => 'Recebimento físico registrado. Não certifica funcionamento ou ausência de danos. Conferência permanece separada.',
    'consent_text' => 'Confirmo ciência conforme meu papel.', 'prepared_at' => '2026-11-02 08:00:00',
    'timezone' => 'America/Sao_Paulo', 'verification_token' => str_repeat('e', 64), 'tickets_id' => 13,
    'scope' => ['bookings_id' => 7, 'users_id_borrower' => 12, 'scope_revision' => 1,
        'begin' => '2026-11-01 08:00:00', 'end' => '2026-11-02 16:00:00', 'purpose_text' => '<script>alert(1)</script>',
        'borrower' => ['name' => 'João da Conceição', 'registration' => '00042', 'unit' => 'Capacitação'],
        'lines' => [['resources_id' => 9, 'resource' => ['name' => 'Projetor <img src="https://invalid.example/image">',
            'inventory_number' => 'SES-001', 'serial' => 'XYZ', 'building' => 'Bloco B', 'floor' => 'Térreo', 'room_number' => '02A'],
            'received_at' => '2026-11-02 08:00:00', 'accessories' => [['name' => 'Cabo HDMI', 'issued' => 1, 'received' => 0]]]]],
];
rp_test('PDF template escapes all user data and preserves incomplete return quantities', static function () use ($snapshot): void {
    $html = PdfRenderer::html($snapshot);
    rp_assert(!str_contains($html, '<script>'));
    rp_assert(!str_contains($html, '<img '));
    rp_assert(str_contains($html, '&lt;script&gt;'));
    rp_assert(str_contains($html, 'recebida: 0'));
    rp_assert(str_contains($html, 'Não certifica funcionamento'));
    rp_assert(str_contains($html, 'João da Conceição'));
});

rp_test('term PDF shows the verified GLPI login as matrícula without trusting declared affiliation', static function () use ($snapshot): void {
    $document = $snapshot;
    $document['borrower_login'] = '10837';
    $html = PdfRenderer::html($document);
    rp_assert(str_contains($html, '<b>Matrícula:</b> 10837'));
    rp_assert(str_contains($html, '<b>Identificação / vínculo informado:</b> 00042'));
    $document['borrower_login'] = '<script>alert(1)</script>';
    $html = PdfRenderer::html($document);
    rp_assert(str_contains($html, '&lt;script&gt;'));
    rp_assert(!str_contains($html, '<script>'));
});

rp_test('one account-level consent is source-bound and independently records the chosen role', static function (): void {
    $hash = str_repeat('a', 64);
    $borrower = ['users_id' => 10837, 'role' => 'borrower', 'roles_json' => json_encode(['borrower']), 'source_sha256' => $hash];
    $inspector = ['users_id' => 10837, 'role' => 'inspector', 'roles_json' => json_encode(['inspector']), 'source_sha256' => $hash];
    rp_assert(DocumentService::userAcceptedFromRows([$borrower], $hash));
    rp_assert(DocumentService::userAcceptedFromRows([$inspector], $hash));
    rp_assert(!DocumentService::userAcceptedFromRows([$borrower], str_repeat('b', 64)));
    rp_assert(DocumentService::roleAcceptedFromRows(['borrower'], [$borrower], $hash));
    rp_assert(!DocumentService::roleAcceptedFromRows(['borrower', 'inspector'], [$borrower], $hash));
    rp_assert(!DocumentService::roleAcceptedFromRows(['inspector'], [$borrower], $hash));
    rp_assert(DocumentService::roleAcceptedFromRows(['borrower', 'inspector'], [$borrower, $inspector], $hash));
    rp_assert(!DocumentService::roleAcceptedFromRows(['borrower', 'inspector'], [$borrower, $inspector], str_repeat('b', 64)));
    $legacy = ['users_id' => 10837, 'role' => 'legacy',
        'roles_json' => json_encode(['borrower', 'inspector']), 'source_sha256' => $hash];
    rp_assert(DocumentService::roleAcceptedFromRows(['borrower', 'inspector'], [$legacy], $hash), 'Historical joint acceptance must remain valid');
});

rp_test('distinct consent texts are bound to each signing role', static function (): void {
    $doc = ['purpose' => 'return_inspection', 'consent_text' => 'Aceito esta versão.',
        'signers_json' => json_encode([['users_id' => 10837, 'roles' => ['borrower', 'inspector']]]),
        'snapshot_json' => json_encode(['scope' => ['lines' => [['resource' => ['kind' => 'equipment']]]]])];
    rp_throws(static fn() => DocumentService::consent($doc, 10837), DomainException::class, 'Selecione');
    $borrower = DocumentService::consent($doc, 10837, 'borrower');
    $inspector = DocumentService::consent($doc, 10837, 'inspector');
    rp_assert(str_contains($borrower, 'Como beneficiário'));
    rp_assert(!str_contains($borrower, 'Como inspetor'));
    rp_assert(str_contains($inspector, 'Como inspetor'));
    rp_assert($borrower !== $inspector);
    rp_throws(static fn() => DocumentService::consent($doc, 10837, 'receiver'), DomainException::class);
});

rp_test('an unrelated account cannot obtain a consent statement for another signer', static function (): void {
    $doc = ['purpose' => 'request', 'signers_json' => json_encode([['users_id' => 12, 'roles' => ['borrower']]])];
    rp_throws(static fn () => DocumentService::consent($doc, 13), DomainException::class);
    $text = DocumentService::consent($doc, 12);
    rp_assert(str_contains($text, 'não declara que a retirada física já ocorreu'));
    $doc['purpose'] = 'return_inspection';
    rp_assert(str_contains(DocumentService::consent($doc, 12), 'Posso contestar divergências'));
});

$tcpdf = getenv('RESERVAPLUS_TCPDF');
if (!class_exists(TCPDF::class) && is_string($tcpdf) && is_file($tcpdf)) {
    require_once $tcpdf;
}
if (class_exists(TCPDF::class)) {
    rp_test('real TCPDF produces UTF-8 source and separate evidence PDF', static function () use ($snapshot): void {
        $source = PdfRenderer::render($snapshot);
        rp_assert(str_starts_with($source, '%PDF-'));
        $s = $snapshot;
        $s['source_sha256'] = hash('sha256', $source);
        $final = PdfRenderer::render($s, [['users_id' => 12, 'identity' => ['name' => 'João da Conceição'], 'roles' => ['borrower'],
            'event_utc' => '2026-11-02T11:00:00Z', 'consent_text' => 'Confirmo ciência deste recebimento.']]);
        rp_assert(str_starts_with($final, '%PDF-'));
        rp_assert(hash('sha256', $source) !== hash('sha256', $final));
        $out = getenv('RESERVAPLUS_PDF_TEST_OUTPUT');
        if (is_string($out) && is_dir($out)) {
            file_put_contents($out . '/source.pdf', $source);
            file_put_contents($out . '/final.pdf', $final);
        }
    });
} else {
    fwrite(STDOUT, "SKIP actual TCPDF render (set RESERVAPLUS_TCPDF or use GLPI_ROOT)\n");
}
