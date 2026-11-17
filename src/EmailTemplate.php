<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/**
 * Templates de e-mail do Reserva Plus (HTML + texto puro).
 *
 * Cada método público retorna ['subject', 'html', 'text'].
 *
 * ── Por que o HTML é feito de tabelas ───────────────────────────────────────
 * O Outlook do Windows renderiza e-mail com o motor do Word, que ignora
 * `linear-gradient`, `border-radius`, `box-shadow`, `max-width` e boa parte do
 * posicionamento em `<div>`. Layout montado com divs + gradiente vira aquele
 * e-mail "desconfigurado": cabeçalho sem fundo, cartão espalhado na largura
 * toda e blocos empilhados sem respiro.
 *
 * Portanto, aqui vale:
 *   - estrutura em <table> com width fixo e `bgcolor` (nunca gradiente sozinho);
 *   - estilos SEMPRE inline, em cada <td> (Outlook não herda font da tabela pai);
 *   - gradiente só como `background-image` decorativo por cima do `bgcolor`;
 *   - botão "bulletproof" em tabela, não <a> com padding;
 *   - documento HTML completo, com os hacks mso no <head>.
 */
class EmailTemplate
{
    private const BRAND_900 = '#1e3a8a';
    private const BRAND_500 = '#2563eb';
    private const ACCENT    = '#0891b2';
    private const AMBER     = '#b45309';
    private const RED       = '#b91c1c';
    private const INK       = '#1e293b';
    private const MUTED     = '#5b7488';

    private const FONT = "-apple-system,BlinkMacSystemFont,'Segoe UI',Roboto,Helvetica,Arial,sans-serif";

    private static function h(string $value): string
    {
        return htmlspecialchars($value, ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8');
    }

    /**
     * Moldura comum de todos os e-mails.
     *
     * @param string $emoji    Ícone grande do cabeçalho.
     * @param string $eyebrow  Linha fina acima do título.
     * @param string $title    Título do e-mail.
     * @param string $accent   Cor sólida do cabeçalho (usada como bgcolor).
     * @param string $bodyHtml Conteúdo já montado do corpo.
     * @param string $preview  Texto de pré-visualização (aparece na caixa de entrada).
     */
    private static function wrap(
        string $emoji,
        string $eyebrow,
        string $title,
        string $accent,
        string $bodyHtml,
        string $preview = ''
    ): string {
        $eyebrowSafe = self::h($eyebrow);
        $titleSafe   = self::h($title);
        $previewSafe = self::h($preview !== '' ? $preview : $title);
        $year        = date('Y');
        $font        = self::FONT;
        $gradient    = 'linear-gradient(135deg,' . $accent . ' 0%,' . self::ACCENT . ' 100%)';

        return <<<HTML
<!DOCTYPE html PUBLIC "-//W3C//DTD XHTML 1.0 Transitional//EN" "http://www.w3.org/TR/xhtml1/DTD/xhtml1-transitional.dtd">
<html xmlns="http://www.w3.org/1999/xhtml" lang="pt-BR">
<head>
<meta http-equiv="Content-Type" content="text/html; charset=UTF-8" />
<meta name="viewport" content="width=device-width, initial-scale=1" />
<meta http-equiv="X-UA-Compatible" content="IE=edge" />
<meta name="x-apple-disable-message-reformatting" />
<title>{$titleSafe}</title>
<!--[if mso]>
<xml><o:OfficeDocumentSettings xmlns:o="urn:schemas-microsoft-com:office:office">
<o:AllowPNG/><o:PixelsPerInch>96</o:PixelsPerInch>
</o:OfficeDocumentSettings></xml>
<![endif]-->
<style type="text/css">
body,table,td,a{-webkit-text-size-adjust:100%;-ms-text-size-adjust:100%;}
table,td{mso-table-lspace:0pt;mso-table-rspace:0pt;border-collapse:collapse;}
img{border:0;height:auto;line-height:100%;outline:none;text-decoration:none;-ms-interpolation-mode:bicubic;}
body{margin:0 !important;padding:0 !important;width:100% !important;}
a{color:{$accent};}
@media only screen and (max-width:620px){
  .rp-card{width:100% !important;}
  .rp-pad{padding-left:20px !important;padding-right:20px !important;}
  .rp-label,.rp-value{display:block !important;width:100% !important;padding-right:0 !important;}
  .rp-label{padding-bottom:2px !important;}
  .rp-title{font-size:20px !important;}
}
</style>
</head>
<body style="margin:0;padding:0;background-color:#eef2ff;">
<div style="display:none;font-size:1px;line-height:1px;max-height:0;max-width:0;opacity:0;overflow:hidden;mso-hide:all;">{$previewSafe}</div>
<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" bgcolor="#eef2ff" style="background-color:#eef2ff;">
<tr>
<td align="center" style="padding:24px 12px;">

<table role="presentation" class="rp-card" cellpadding="0" cellspacing="0" border="0" width="600" style="width:600px;max-width:600px;background-color:#ffffff;border:1px solid #d0e0f0;border-radius:14px;overflow:hidden;">

  <tr>
    <td class="rp-pad" bgcolor="{$accent}" style="background-color:{$accent};background-image:{$gradient};padding:26px 30px;color:#ffffff;font-family:{$font};">
      <div style="font-size:34px;line-height:38px;margin:0 0 8px 0;">{$emoji}</div>
      <div style="font-size:11px;line-height:16px;text-transform:uppercase;letter-spacing:1.4px;color:#dbeafe;font-family:{$font};">📅 Reserva Plus &middot; Sebrae</div>
      <div style="font-size:13px;line-height:18px;color:#e0e7ff;font-family:{$font};padding-top:6px;">{$eyebrowSafe}</div>
      <div class="rp-title" style="font-size:23px;line-height:30px;font-weight:bold;color:#ffffff;font-family:{$font};padding-top:3px;">{$titleSafe}</div>
    </td>
  </tr>

  <tr>
    <td class="rp-pad" style="padding:26px 30px;color:#1f2d3d;font-family:{$font};font-size:15px;line-height:23px;">
{$bodyHtml}
    </td>
  </tr>

  <tr>
    <td class="rp-pad" bgcolor="#f4f8fb" style="background-color:#f4f8fb;border-top:1px solid #e3eef6;padding:18px 30px;color:#7089a0;font-family:{$font};font-size:12px;line-height:18px;text-align:center;">
      📅 Reserva Plus &middot; Sebrae MS &copy; {$year}<br />
      <span style="color:#93a7ba;">Mensagem automática — não responda este e-mail.</span>
    </td>
  </tr>

</table>

</td>
</tr>
</table>
</body>
</html>
HTML;
    }

    /** Parágrafo do corpo. $html já deve vir escapado/seguro. */
    private static function p(string $html, string $extraStyle = ''): string
    {
        $font = self::FONT;
        return '<p style="margin:0 0 14px 0;font-family:' . $font . ';font-size:15px;line-height:23px;color:#1f2d3d;' . $extraStyle . '">' . $html . '</p>';
    }

    /**
     * Tabela de detalhes (rótulo + valor).
     *
     * @param array<int,array{0:string,1:string}> $rows
     */
    private static function details(array $rows): string
    {
        $font = self::FONT;
        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;margin:0 0 16px 0;border-collapse:collapse;">';

        foreach ($rows as $row) {
            $label = self::h((string) ($row[0] ?? ''));
            $value = self::h((string) ($row[1] ?? ''));
            if ($value === '') {
                $value = '—';
            }
            $html .= '<tr>'
                . '<td class="rp-label" width="170" style="width:170px;padding:7px 12px 7px 0;font-family:' . $font . ';font-size:14px;line-height:20px;color:' . self::MUTED . ';vertical-align:top;">' . $label . '</td>'
                . '<td class="rp-value" style="padding:7px 0;font-family:' . $font . ';font-size:14px;line-height:20px;color:' . self::INK . ';font-weight:bold;vertical-align:top;">' . $value . '</td>'
                . '</tr>';
        }

        return $html . '</table>';
    }

    /**
     * Botão "bulletproof": tabela com bgcolor, porque padding em <a> não
     * funciona no Outlook (o clique só pegaria no texto).
     */
    private static function button(string $label, string $url, string $color): string
    {
        $label = self::h($label);
        $url   = self::h($url);
        $font  = self::FONT;

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" style="margin:18px 0;">'
            . '<tr><td align="center" bgcolor="' . $color . '" style="background-color:' . $color . ';border-radius:8px;">'
            . '<a href="' . $url . '" target="_blank" style="display:inline-block;padding:12px 26px;font-family:' . $font . ';font-size:14px;line-height:18px;font-weight:bold;color:#ffffff;text-decoration:none;border-radius:8px;">'
            . $label . '</a>'
            . '</td></tr></table>';
    }

    /** Caixa de destaque (aviso/informação) com barra colorida à esquerda. */
    private static function callout(string $html, string $color, string $background, string $textColor): string
    {
        $font = self::FONT;

        return '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;margin:0 0 16px 0;">'
            . '<tr>'
            . '<td width="4" bgcolor="' . $color . '" style="width:4px;background-color:' . $color . ';font-size:0;line-height:0;">&nbsp;</td>'
            . '<td bgcolor="' . $background . '" style="background-color:' . $background . ';padding:12px 16px;font-family:' . $font . ';font-size:14px;line-height:21px;color:' . $textColor . ';">' . $html . '</td>'
            . '</tr></table>';
    }

    /** Lista das datas de uma série recorrente (até 12 itens + "e mais N"). */
    private static function dateList(array $dates): string
    {
        $font  = self::FONT;
        $shown = array_slice($dates, 0, 12);
        $more  = count($dates) - count($shown);

        $html = '<table role="presentation" cellpadding="0" cellspacing="0" border="0" width="100%" style="width:100%;margin:0 0 16px 0;">';
        foreach ($shown as $date) {
            $html .= '<tr><td style="padding:5px 0;border-bottom:1px solid #e8eefb;font-family:' . $font . ';font-size:14px;line-height:20px;color:' . self::INK . ';">'
                . '🗓️ ' . self::h((string) $date) . '</td></tr>';
        }
        if ($more > 0) {
            $html .= '<tr><td style="padding:7px 0;font-family:' . $font . ';font-size:13px;line-height:19px;color:' . self::MUTED . ';">'
                . self::h(sprintf(__('e mais %d ocorrência(s).', 'reservaplus'), $more)) . '</td></tr>';
        }

        return $html . '</table>';
    }

    // ── E-mails ─────────────────────────────────────────────────────────────

    /**
     * Reserva única confirmada.
     *
     * @param array{item:string,period:string,people?:int,for?:string,comment?:string,url?:string} $c
     * @return array{subject:string,html:string,text:string}
     */
    public static function reservationCreated(array $c): array
    {
        $item   = (string) ($c['item'] ?? '');
        $period = (string) ($c['period'] ?? '');
        $rows   = [
            [__('📍 Item', 'reservaplus'), $item],
            [__('🕒 Quando', 'reservaplus'), $period],
        ];
        if ((int) ($c['people'] ?? 0) > 0) {
            $rows[] = [__('👥 Pessoas', 'reservaplus'), (string) (int) $c['people']];
        }
        if (trim((string) ($c['for'] ?? '')) !== '') {
            $rows[] = [__('🙋 Para', 'reservaplus'), (string) $c['for']];
        }
        if (trim((string) ($c['comment'] ?? '')) !== '') {
            $rows[] = [__('📝 Observações', 'reservaplus'), (string) $c['comment']];
        }

        $body = self::p(sprintf(
            /* translators: %s = nome do item reservado */
            self::h(__('Sua reserva de %s está confirmada. 🎉', 'reservaplus')),
            '<strong>' . self::h($item) . '</strong>'
        ))
            . self::details($rows)
            . self::linkBlock($c)
            . self::p(self::h(__('Precisa mudar alguma coisa? Edite ou cancele a reserva em "Minhas reservas".', 'reservaplus')), 'color:' . self::MUTED . ';font-size:13px;');

        return [
            'subject' => sprintf(__('Reserva confirmada: %1$s — %2$s', 'reservaplus'), $item, $period),
            'html'    => self::wrap('📅', __('Reserva confirmada', 'reservaplus'), __('Tudo certo com a sua reserva', 'reservaplus'), self::BRAND_900, $body, sprintf(__('%1$s · %2$s', 'reservaplus'), $item, $period)),
            'text'    => self::plain(__('Reserva confirmada', 'reservaplus'), $rows, (string) ($c['url'] ?? '')),
        ];
    }

    /**
     * Série recorrente / lote confirmado — UM e-mail para todas as ocorrências.
     *
     * @param array{items:array<int,string>,dates:array<int,string>,count:int,period_label?:string,people?:int,for?:string,url?:string} $c
     * @return array{subject:string,html:string,text:string}
     */
    public static function reservationSeriesCreated(array $c): array
    {
        $items = array_values(array_filter(array_map('strval', (array) ($c['items'] ?? []))));
        $dates = array_values(array_filter(array_map('strval', (array) ($c['dates'] ?? []))));
        $count = max(1, (int) ($c['count'] ?? count($dates)));

        $rows = [
            [__('📍 Itens', 'reservaplus'), implode(' · ', $items)],
            [__('🕒 Horário', 'reservaplus'), (string) ($c['period_label'] ?? '')],
            [__('🔁 Reservas criadas', 'reservaplus'), (string) $count],
        ];
        if ((int) ($c['people'] ?? 0) > 0) {
            $rows[] = [__('👥 Pessoas', 'reservaplus'), (string) (int) $c['people']];
        }
        if (trim((string) ($c['for'] ?? '')) !== '') {
            $rows[] = [__('🙋 Para', 'reservaplus'), (string) $c['for']];
        }

        $body = self::p(sprintf(
            self::h(_n('%d reserva foi confirmada. 🎉', '%d reservas foram confirmadas. 🎉', $count, 'reservaplus')),
            $count
        ))
            . self::details($rows)
            . self::p('<strong>' . self::h(__('Datas', 'reservaplus')) . '</strong>')
            . self::dateList($dates)
            . self::callout(
                self::h(__('Este é o único e-mail da série — não enviamos uma mensagem por ocorrência.', 'reservaplus')),
                self::BRAND_500,
                '#eef2ff',
                '#1f2d3d'
            )
            . self::linkBlock($c);

        return [
            'subject' => sprintf(
                _n('Reserva confirmada: %2$s (%1$d ocorrência)', 'Reservas confirmadas: %2$s (%1$d ocorrências)', $count, 'reservaplus'),
                $count,
                implode(', ', array_slice($items, 0, 3))
            ),
            'html'    => self::wrap('🔁', __('Série de reservas', 'reservaplus'), __('Suas reservas estão confirmadas', 'reservaplus'), self::BRAND_900, $body),
            'text'    => self::plain(__('Reservas confirmadas', 'reservaplus'), $rows, (string) ($c['url'] ?? ''), $dates),
        ];
    }

    /**
     * Reserva reagendada/alterada.
     *
     * @param array{item:string,period:string,previous?:string,people?:int,url?:string} $c
     * @return array{subject:string,html:string,text:string}
     */
    public static function reservationUpdated(array $c): array
    {
        $item     = (string) ($c['item'] ?? '');
        $period   = (string) ($c['period'] ?? '');
        $previous = trim((string) ($c['previous'] ?? ''));

        $rows = [[__('📍 Item', 'reservaplus'), $item]];
        if ($previous !== '') {
            $rows[] = [__('🕒 Antes', 'reservaplus'), $previous];
        }
        $rows[] = [__('✅ Agora', 'reservaplus'), $period];
        if ((int) ($c['people'] ?? 0) > 0) {
            $rows[] = [__('👥 Pessoas', 'reservaplus'), (string) (int) $c['people']];
        }

        $body = self::p(self::h(__('Sua reserva foi alterada. ✏️', 'reservaplus')))
            . self::details($rows)
            . self::callout(
                self::h(__('Anote o novo horário na sua agenda.', 'reservaplus')),
                self::AMBER,
                '#fff7ed',
                '#7c3f0a'
            )
            . self::linkBlock($c);

        return [
            'subject' => sprintf(__('Reserva alterada: %1$s — %2$s', 'reservaplus'), $item, $period),
            'html'    => self::wrap('✏️', __('Reserva alterada', 'reservaplus'), __('Novo horário da sua reserva', 'reservaplus'), self::BRAND_900, $body),
            'text'    => self::plain(__('Reserva alterada', 'reservaplus'), $rows, (string) ($c['url'] ?? '')),
        ];
    }

    /**
     * Reserva cancelada.
     *
     * @param array{item:string,period:string,url?:string} $c
     * @return array{subject:string,html:string,text:string}
     */
    public static function reservationCancelled(array $c): array
    {
        $item   = (string) ($c['item'] ?? '');
        $period = (string) ($c['period'] ?? '');
        $rows   = [
            [__('📍 Item', 'reservaplus'), $item],
            [__('🕒 Período', 'reservaplus'), $period],
        ];

        $body = self::p(sprintf(
            self::h(__('Sua reserva de %s foi cancelada. ❌', 'reservaplus')),
            '<strong>' . self::h($item) . '</strong>'
        ))
            . self::details($rows)
            . self::p(self::h(__('O horário voltou a ficar livre para outras pessoas.', 'reservaplus')))
            . self::linkBlock($c, __('📅 Fazer nova reserva', 'reservaplus'));

        return [
            'subject' => sprintf(__('Reserva cancelada: %1$s — %2$s', 'reservaplus'), $item, $period),
            'html'    => self::wrap('❌', __('Reserva cancelada', 'reservaplus'), __('Sua reserva foi cancelada', 'reservaplus'), self::RED, $body),
            'text'    => self::plain(__('Reserva cancelada', 'reservaplus'), $rows, (string) ($c['url'] ?? '')),
        ];
    }

    /** Botão para "Minhas reservas" — só sai se houver URL absoluta configurada. */
    private static function linkBlock(array $c, string $label = ''): string
    {
        $url = trim((string) ($c['url'] ?? ''));
        if ($url === '') {
            return '';
        }

        return self::button($label !== '' ? $label : __('📅 Ver minhas reservas', 'reservaplus'), $url, self::BRAND_500);
    }

    /**
     * Versão texto puro (fallback para clientes sem HTML e para o índice de busca).
     *
     * @param array<int,array{0:string,1:string}> $rows
     * @param array<int,string> $dates
     */
    private static function plain(string $title, array $rows, string $url = '', array $dates = []): string
    {
        $lines = [$title, str_repeat('=', mb_strlen($title)), ''];

        foreach ($rows as $row) {
            $label = trim(preg_replace('/^[^\p{L}]+/u', '', (string) ($row[0] ?? '')) ?? '');
            $lines[] = $label . ': ' . (string) ($row[1] ?? '');
        }

        if ($dates !== []) {
            $lines[] = '';
            $lines[] = __('Datas', 'reservaplus') . ':';
            foreach ($dates as $date) {
                $lines[] = '  - ' . (string) $date;
            }
        }

        if ($url !== '') {
            $lines[] = '';
            $lines[] = __('Minhas reservas', 'reservaplus') . ': ' . $url;
        }

        $lines[] = '';
        $lines[] = '— Reserva Plus';

        return implode("\n", $lines);
    }
}
