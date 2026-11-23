<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use RuntimeException;

/** Renders only our escaped, frozen snapshot; never executes uploaded HTML. */
final class PdfRenderer
{
    public const TEMPLATE_VERSION = 'reservaplus-terms-v2';

    public static function render(array $snapshot, array $acceptances = []): string
    {
        if (!class_exists(\TCPDF::class)) {
            throw new RuntimeException('O renderizador PDF do GLPI (TCPDF) não está disponível. Verifique as dependências da instalação.');
        }
        $pdf = new \TCPDF('P', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('ReservaPlus');
        $pdf->SetAuthor('ReservaPlus / GLPI');
        $pdf->SetTitle((string) ($snapshot['title'] ?? 'Termo de reserva'));
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(false);
        $pdf->SetMargins(17, 17, 17);
        $pdf->SetAutoPageBreak(true, 17);
        $pdf->SetFont('dejavusans', '', 9);
        $pdf->AddPage();
        $pdf->writeHTML(self::html($snapshot), true, false, true, false, '');
        if ($acceptances !== [] && self::isCessionTerm($snapshot)) {
            self::drawSignature($pdf, $acceptances);
        }
        if ($acceptances !== []) {
            $pdf->AddPage();
            $pdf->writeHTML(self::receiptHtml($snapshot, $acceptances), true, false, true, false, '');
        }
        $bytes = $pdf->Output('', 'S');
        if (!is_string($bytes) || !str_starts_with($bytes, '%PDF-') || strlen($bytes) < 500) {
            throw new RuntimeException('O renderizador não produziu um PDF válido.');
        }
        return $bytes;
    }

    public static function html(array $s): string
    {
        $e = self::escape(...);
        $scope = (array) ($s['scope'] ?? []);
        $borrower = (array) ($scope['borrower'] ?? []);
        if (self::isCessionTerm($s)) {
            return self::cessionHtml($s);
        }
        $html = '<h1>' . $e((string) ($s['title'] ?? 'Termo de reserva')) . '</h1>';
        $html .= '<p><b>Reserva:</b> #' . (int) ($scope['bookings_id'] ?? 0)
            . ' | <b>Chamado:</b> #' . (int) ($s['tickets_id'] ?? 0)
            . ' | <b>Versão do escopo:</b> ' . (int) ($scope['scope_revision'] ?? 1) . '</p>';
        $html .= '<p><b>Usuário beneficiário:</b> ' . $e((string) ($borrower['name'] ?? ''))
            . ' (GLPI #' . (int) ($scope['users_id_borrower'] ?? 0) . ')<br>'
            . '<b>Matrícula:</b> ' . $e((string) ($s['borrower_login'] ?? 'Não informada')) . '<br>'
            . '<b>Identificação / vínculo informado:</b> ' . $e((string) ($borrower['registration'] ?? $borrower['matricula'] ?? 'Não informado'))
            . '<br><b>Unidade do beneficiário:</b> ' . $e((string) ($borrower['unit'] ?? 'Não informada')) . '</p>';
        $html .= '<p><b>Período previsto:</b> ' . $e((string) ($scope['begin'] ?? '')) . ' até '
            . $e((string) ($scope['end'] ?? '')) . '<br><b>Fuso apresentado:</b> '
            . $e((string) ($s['timezone'] ?? '')) . '<br><b>Finalidade:</b> '
            . $e((string) ($scope['purpose_text'] ?? '')) . '</p>';
        $html .= '<p><b>Quantidade prevista de pessoas:</b> ' . (int) ($scope['people_count'] ?? 1) . '</p>';
        $html .= '<p>' . $e((string) ($s['statement'] ?? '')) . '</p>';
        foreach ((array) ($scope['lines'] ?? []) as $i => $line) {
            $r = (array) ($line['resource'] ?? []);
            $html .= '<h3>' . ((int) $i + 1) . '. ' . $e((string) ($r['name'] ?? 'Recurso')) . '</h3>';
            $html .= '<table border="1" cellpadding="5">';
            foreach ([
                'Identificador interno' => '#' . (int) ($line['resources_id'] ?? $r['id'] ?? 0),
                'Patrimônio / código' => $r['inventory_number'] ?? '',
                'Número de série' => $r['serial'] ?? '',
                'Fabricante / modelo' => trim(($r['manufacturer'] ?? '') . ' ' . ($r['model'] ?? '')),
                'Setor responsável' => $r['sector_name'] ?? $r['_sector_name'] ?? ('#' . (int) ($r['sectors_id'] ?? 0)),
                'Responsável pela guarda' => $r['custodian_name'] ?? $r['_custodian_name'] ?? ('GLPI #' . (int) ($r['custodian_users_id'] ?? 0)),
                'Equipe GLPI responsável' => $r['_ticket_group_name'] ?? '',
                'Localização GLPI' => $r['_location_name'] ?? ('#' . (int) ($r['locations_id'] ?? 0)),
                'Localização' => trim(implode(' / ', array_filter(array_map('strval', [$r['building'] ?? '', $r['floor'] ?? '', $r['room_number'] ?? '', $r['wing'] ?? ''])))),
                'Orientações de localização' => $r['directions'] ?? '',
            ] as $label => $value) {
                $html .= '<tr><td width="35%"><b>' . $e($label) . '</b></td><td width="65%">' . $e((string) $value) . '</td></tr>';
            }
            $html .= '</table>';
            if (($r['kind'] ?? '') === 'room') {
                $html .= '<p><b>Capacidade:</b> ' . $e((string) ($r['capacity'] ?? 'Não informada'))
                    . ' | <b>Área (m²):</b> ' . $e((string) ($r['area_m2'] ?? 'Não informada'))
                    . '<br><b>Endereço:</b> ' . $e((string) ($r['address'] ?? ''))
                    . '<br><b>Características:</b> ' . $e((string) ($r['features'] ?? '')) . '</p>';
            }
            $accessories = (array) ($line['accessories'] ?? []);
            if ($accessories !== []) {
                $html .= '<p><b>Componentes / acessórios:</b></p><ul>';
                foreach ($accessories as $a) {
                    $html .= '<li>' . $e((string) ($a['name'] ?? '')) . ' - quantidade prevista/entregue: '
                        . (int) ($a['issued'] ?? $a['quantity'] ?? 1);
                    if (array_key_exists('received', $a)) {
                        $html .= '; recebida: ' . (int) $a['received'];
                    }
                    $html .= '</li>';
                }
                $html .= '</ul>';
            }
            if (!empty($line['received_at'])) {
                $html .= '<p><b>Recebimento físico registrado:</b> ' . $e((string) $line['received_at']) . '</p>';
            }
            if (!empty($line['inspection_at'])) {
                $html .= '<p><b>Conferência:</b> ' . $e((string) $line['inspection_at'])
                    . ' | <b>Inspetor:</b> GLPI #' . (int) ($line['inspector_users_id'] ?? 0)
                    . ' | <b>Resultado:</b> ' . $e((string) ($line['state'] ?? '')) . '</p>';
                $html .= '<p>' . nl2br($e(self::readable((array) ($line['inspection'] ?? [])))) . '</p>';
            }
        }
        $html .= '<h3>Finalidade dos aceites</h3><p>' . $e((string) ($s['consent_text'] ?? '')) . '</p>';
        $html .= '<p><b>Emitido em:</b> ' . $e((string) ($s['prepared_at'] ?? ''))
            . '<br><b>Modelo:</b> ' . self::TEMPLATE_VERSION
            . '<br><b>Referência de verificação interna:</b> ' . $e((string) ($s['verification_token'] ?? '')) . '</p>';
        $html .= '<p>Documento preparado para aceite eletrônico dentro do GLPI. A presença deste PDF, isoladamente, não comprova assinatura. Consulte os eventos de aceite e os hashes na verificação autenticada do ReservaPlus.</p>';
        return $html;
    }

    /** New single-page cession form, based on the institutional equipment model. */
    public static function isCessionTerm(array $snapshot): bool
    {
        if (($snapshot['template_version'] ?? '') !== self::TEMPLATE_VERSION) { return false; }
        if (!in_array((string) ($snapshot['purpose'] ?? ''), ['request', 'handover'], true)) { return false; }
        $lines = (array) ($snapshot['scope']['lines'] ?? []);
        if ($lines === []) { return false; }
        foreach ($lines as $line) {
            if (($line['resource']['kind'] ?? '') !== 'equipment') { return false; }
        }
        return true;
    }

    private static function cessionHtml(array $s): string
    {
        $e = self::escape(...);
        $scope = (array) ($s['scope'] ?? []);
        $borrower = (array) ($scope['borrower'] ?? []);
        $html = '<h1 style="text-align:center;font-size:15pt">TERMO DE CESSÃO DE EQUIPAMENTO</h1>';
        $html .= '<p>Pelo presente termo, o(a) requerente manifesta ciência das condições de cessão temporária do(s) equipamento(s) abaixo relacionado(s). A assinatura antecede a aprovação e <b>não comprova a entrega física</b> nem garante disponibilidade.</p>';
        $html .= '<h3>1. IDENTIFICAÇÃO DO EQUIPAMENTO</h3>';
        foreach ((array) ($scope['lines'] ?? []) as $line) {
            $r = (array) ($line['resource'] ?? []);
            $html .= '<p><b>Equipamento:</b> ' . $e((string) ($r['name'] ?? ''))
                . '<br><b>Marca / modelo:</b> ' . $e(trim((string) ($r['manufacturer'] ?? '') . ' ' . (string) ($r['model'] ?? '')))
                . '<br><b>Patrimônio:</b> ' . $e((string) ($r['inventory_number'] ?? 'Não informado'));
            $acc = [];
            foreach ((array) ($line['accessories'] ?? []) as $item) {
                $acc[] = (string) ($item['name'] ?? '') . ' (' . (int) ($item['quantity'] ?? 1) . ')';
            }
            $html .= '<br><b>Acessório(s):</b> ' . $e($acc !== [] ? implode(', ', $acc) : 'Nenhum informado') . '</p>';
        }
        $html .= '<h3>2. REQUERENTE</h3><p><b>Nome:</b> ' . $e((string) ($borrower['name'] ?? ''))
            . '<br><b>Matrícula / usuário:</b> ' . $e(trim((string) ($borrower['matricula'] ?? '')) !== '' ? (string) $borrower['matricula'] : (string) ($s['borrower_login'] ?? 'Não informada'))
            . '<br><b>Setor / unidade:</b> ' . $e((string) ($borrower['unit'] ?? 'Não informada')) . '</p>';
        $html .= '<h3>3. PERÍODO DA CESSÃO</h3><p><b>Retirada prevista:</b> '
            . $e((string) ($scope['begin'] ?? '')) . '<br><b>Devolução prevista:</b> '
            . $e((string) ($scope['end'] ?? '')) . '</p>';
        $html .= '<h3>4. RESPONSABILIDADE</h3><p>Declaro ciência das responsabilidades relacionadas ao uso do equipamento solicitado. Caso a cessão seja aprovada e a entrega efetivada, comprometo-me a utilizá-lo exclusivamente para as atividades autorizadas, zelando por sua guarda, conservação e uso adequado durante todo o período, e a devolvê-lo com os acessórios nas condições registradas no ato da entrega, ressalvado o desgaste normal de uso.</p>';
        $html .= '<p><b>Finalidade declarada:</b> ' . $e((string) ($scope['purpose_text'] ?? '')) . '</p>';
        $html .= '<h3>5. ASSINATURA DO(A) REQUERENTE</h3><p>A assinatura eletrônica será vinculada à conta autenticada do GLPI e à versão deste documento. O registro manuscrito, quando utilizado, integra o comprovante final.</p>';
        $html .= '<p><b>Reserva:</b> #' . (int) ($scope['bookings_id'] ?? 0)
            . ' | <b>Chamado:</b> #' . (int) ($s['tickets_id'] ?? 0)
            . ' | <b>Versão:</b> ' . (int) ($scope['scope_revision'] ?? 1) . '</p>';
        return $html;
    }

    /** Render only validated normalized strokes; never trust user-controlled SVG/HTML. */
    private static function drawSignature(\TCPDF $pdf, array $acceptances): void
    {
        foreach ($acceptances as $acceptance) {
            $strokes = $acceptance['identity']['handwritten_strokes'] ?? [];
            if (!is_array($strokes) || $strokes === []) { continue; }
            $y = $pdf->GetY() + 5;
            if ($y > 232) { $pdf->AddPage(); $y = 28; }
            $pdf->SetFont('dejavusans', '', 9);
            $pdf->SetXY(18, $y);
            $pdf->Cell(0, 6, 'Assinatura manuscrita eletrônica — ' . (string) ($acceptance['identity']['name'] ?? 'Usuário GLPI'), 0, 1);
            $y += 8;
            $pdf->SetDrawColor(28, 47, 72);
            $pdf->SetLineWidth(0.35);
            foreach ($strokes as $stroke) {
                if (!is_array($stroke) || count($stroke) < 2) { continue; }
                for ($i = 1, $n = count($stroke); $i < $n; $i++) {
                    $p1 = $stroke[$i - 1]; $p2 = $stroke[$i];
                    if (!is_array($p1) || !is_array($p2) || count($p1) !== 2 || count($p2) !== 2) { continue; }
                    $pdf->Line(18 + (float) $p1[0] * 0.16, $y + (float) $p1[1] * 0.04,
                        18 + (float) $p2[0] * 0.16, $y + (float) $p2[1] * 0.04);
                }
            }
            $pdf->SetDrawColor(125, 125, 125);
            $pdf->Line(18, $y + 42, 178, $y + 42);
            $pdf->SetDrawColor(0, 0, 0);
            $pdf->SetFont('dejavusans', '', 7);
            $pdf->SetXY(18, $y + 43);
            $pdf->Cell(0, 5, 'Registrada em UTC: ' . (string) ($acceptance['event_utc'] ?? '')
                . ' | Conta GLPI: #' . (int) ($acceptance['users_id'] ?? 0), 0, 1);
            $pdf->SetY($y + 49);
        }
    }

    private static function receiptHtml(array $s, array $acceptances): string
    {
        $e = self::escape(...);
        $html = '<h1>Comprovante de aceite eletrônico</h1><p>Os aceites abaixo vinculam-se aos bytes do documento original identificado por seu SHA-256. Esta cópia reúne o conteúdo do snapshot e o comprovante; o PDF original permanece preservado separadamente.</p>';
        $html .= '<p><b>SHA-256 do original:</b><br>' . $e((string) ($s['source_sha256'] ?? '')) . '</p>';
        foreach ($acceptances as $a) {
            $identity = is_array($a['identity'] ?? null) ? $a['identity'] : [];
            $html .= '<h3>' . $e((string) ($identity['name'] ?? 'Usuário GLPI')) . ' - GLPI #' . (int) ($a['users_id'] ?? 0) . '</h3>';
            $html .= '<p><b>Papel:</b> ' . $e(implode(', ', array_map(DocumentService::roleLabel(...), (array) ($a['roles'] ?? []))))
                . '<br><b>Registrado em UTC:</b> ' . $e((string) ($a['event_utc'] ?? ''))
                . '<br><b>Método:</b> sessão GLPI autenticada e confirmação explícita'
                . '<br><b>Declaração aceita:</b> ' . $e((string) ($a['consent_text'] ?? '')) . '</p>';
        }
        $html .= '<p><b>Verificação interna:</b> ' . $e((string) ($s['verification_token'] ?? '')) . '</p>';
        $html .= '<p>O mecanismo registra consentimento eletrônico interno. Não é assinatura PDF com certificado pessoal nem carimbo de tempo de autoridade externa. O hash desta cópia final é registrado fora do próprio arquivo para evitar referência circular.</p>';
        return $html;
    }

    private static function readable(array $values): string
    {
        $lines = [];
        foreach ($values as $k => $v) {
            $lines[] = (string) $k . ': ' . (is_scalar($v) || $v === null ? (string) $v : json_encode($v, JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES | JSON_THROW_ON_ERROR));
        }
        return implode("\n", $lines);
    }

    public static function escape(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }
}
