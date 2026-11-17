<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use RuntimeException;

/** Renders only our escaped, frozen snapshot; never executes uploaded HTML. */
final class PdfRenderer
{
    public const TEMPLATE_VERSION = 'reservaplus-terms-v1';

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
