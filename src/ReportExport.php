<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use PhpOffice\PhpSpreadsheet\Spreadsheet;
use PhpOffice\PhpSpreadsheet\Writer\Xlsx;
use PhpOffice\PhpSpreadsheet\Cell\DataType;
use PhpOffice\PhpSpreadsheet\Cell\Coordinate;
use PhpOffice\PhpSpreadsheet\Shared\Date;
use PhpOffice\PhpSpreadsheet\Style\Fill;

/** Receives only the rows already authorized and selected by the report. */
final class ReportExport
{
    private static function e(mixed $value): string
    {
        return htmlspecialchars((string) $value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    public static function pdf(string $period, string $generated, array $summary, array $ranking, array $headers, array $rows): string
    {
        $pdf = new \TCPDF('L', 'mm', 'A4', true, 'UTF-8', false);
        $pdf->SetCreator('Reserva Plus / GLPI');
        $pdf->SetTitle('Relatório gerencial de reservas');
        $pdf->setPrintHeader(false);
        $pdf->setPrintFooter(true);
        $pdf->SetFooterMargin(6);
        $pdf->SetMargins(10, 9, 10);
        $pdf->SetAutoPageBreak(true, 12);
        $pdf->SetFont('dejavusans', '', 8);
        $pdf->AddPage();
        $html = '<table cellpadding="5"><tr><td bgcolor="#183B70" color="#FFFFFF"><span style="font-size:9pt">RESERVA PLUS</span><br/><b style="font-size:17pt">Relatório gerencial de reservas</b><br/>Período: '
            . self::e($period) . '</td></tr></table><p style="color:#475569;font-size:8pt">' . self::e($generated) . '</p>';
        $html .= '<table cellpadding="4" cellspacing="1">';
        foreach (array_chunk($summary, 6, true) as $chunk) {
            $html .= '<tr>';
            foreach ($chunk as $label => $value) {
                $html .= '<td width="16.66%" bgcolor="#EFF4FA"><span style="font-size:8pt;color:#334155">' . self::e($label)
                    . '</span><br/><b style="font-size:13pt;color:#183B70">' . self::e($value) . '</b></td>';
            }
            $html .= '</tr>';
        }
        $html .= '</table>';
        if ($ranking !== []) {
            $html .= '<h3 style="color:#183B70">Equipamentos mais reservados</h3>';
            $html .= self::pdfTable(['Equipamento', 'Reservas', 'Horas previstas', 'Pessoas'], array_slice($ranking, 0, 5), [55,15,15,15]);
        }
        $html .= '<h3 style="color:#183B70">Relação de reservas</h3>';
        $html .= self::pdfTable($headers, $rows, [4,15,16,16,8,12,12,9,8]);
        $pdf->writeHTML($html, true, false, true, false, '');
        return $pdf->Output('', 'S');
    }

    private static function pdfTable(array $headers, array $rows, array $widths): string
    {
        $html = '<table cellpadding="3" cellspacing="0" border="0.25"><thead><tr>';
        foreach ($headers as $i => $label) {
            $html .= '<th width="' . $widths[$i] . '%" bgcolor="#183B70" color="#FFFFFF"><b>' . self::e($label) . '</b></th>';
        }
        $html .= '</tr></thead>';
        foreach ($rows as $i => $row) {
            $html .= '<tr nobr="true" bgcolor="' . ($i % 2 === 0 ? '#F1F5F9' : '#FFFFFF') . '">';
            foreach ($row as $j => $value) {
                $html .= '<td width="' . $widths[$j] . '%">' . self::e($value) . '</td>';
            }
            $html .= '</tr>';
        }
        return $html . '</table>';
    }

    public static function excel(string $period, string $generated, array $summary, array $ranking, array $headers, array $rows): string
    {
        $book = new Spreadsheet();
        $book->getProperties()->setCreator('Reserva Plus / GLPI')->setTitle('Relatório de reservas');
        $sheet = $book->getActiveSheet();
        $sheet->setTitle('Resumo gerencial');
        $summaryRows = [['Período', $period], ['Emissão', $generated]];
        foreach ($summary as $label => $value) { $summaryRows[] = [$label, $value]; }
        self::fill($sheet, ['Indicador', 'Valor'], $summaryRows, [44,72]);
        $sheet = $book->createSheet();
        $sheet->setTitle('Lista de reservas');
        self::fill($sheet, $headers, $rows, [12,35,34,34,14,22,30,22,22,22,22,22,22,18,18,24,40]);
        $sheet = $book->createSheet();
        $sheet->setTitle('Equipamentos');
        self::fill($sheet, ['Equipamento', 'Reservas', 'Horas previstas', 'Pessoas'], $ranking, [48,18,22,18]);
        $book->setActiveSheetIndex(0);
        $tmp = tempnam(sys_get_temp_dir(), 'rp-report-');
        if ($tmp === false) { throw new \RuntimeException('Não foi possível preparar a exportação.'); }
        try {
            (new Xlsx($book))->save($tmp);
            $bytes = file_get_contents($tmp);
            if ($bytes === false) { throw new \RuntimeException('Não foi possível ler a exportação.'); }
            return $bytes;
        } finally {
            @unlink($tmp);
            $book->disconnectWorksheets();
        }
    }

    private static function fill($sheet, array $headers, array $rows, array $widths): void
    {
        $all = array_merge([$headers], $rows);
        foreach ($all as $i => $row) {
            foreach ($row as $j => $value) {
                $cell = Coordinate::stringFromColumnIndex($j + 1) . ($i + 1);
                // Explicit strings prevent formulas in names, comments and other user inputs.
                if (is_int($value) || is_float($value)) {
                    $sheet->setCellValueExplicit($cell, $value, DataType::TYPE_NUMERIC);
                } elseif ($i > 0 && preg_match('/^\d{4}-\d{2}-\d{2} \d{2}:\d{2}:\d{2}$/D', (string) $value)
                    && in_array($headers[$j] ?? '', ['Criação', 'Início previsto', 'Fim previsto', 'Entrega efetiva', 'Devolução efetiva', 'Conferência'], true)) {
                    $sheet->setCellValueExplicit($cell, Date::PHPToExcel(new \DateTimeImmutable($value)), DataType::TYPE_NUMERIC);
                    $sheet->getStyle($cell)->getNumberFormat()->setFormatCode('dd/mm/yyyy hh:mm');
                } else {
                    $sheet->setCellValueExplicit($cell, (string) $value, DataType::TYPE_STRING);
                }
            }
        }
        $last = Coordinate::stringFromColumnIndex(count($headers));
        $end = max(1, count($all));
        $sheet->getStyle('A1:' . $last . '1')->applyFromArray([
            'font' => ['bold' => true, 'color' => ['rgb' => 'FFFFFF']],
            'fill' => ['fillType' => Fill::FILL_SOLID, 'startColor' => ['rgb' => '183B70']],
        ]);
        $sheet->getRowDimension(1)->setRowHeight(24);
        foreach ($headers as $i => $label) {
            $sheet->getColumnDimension(Coordinate::stringFromColumnIndex($i + 1))->setWidth($widths[$i] ?? 24);
        }
        $sheet->freezePane('A2');
        $sheet->setAutoFilter('A1:' . $last . $end);
        $sheet->getStyle('A1:' . $last . $end)->getAlignment()->setWrapText(true)->setVertical('top');
    }
}
