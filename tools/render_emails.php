<?php

declare(strict_types=1);

/**
 * Renderiza todos os e-mails do Reserva Plus em uma página única, para conferir
 * o visual sem precisar disparar mensagem de verdade.
 *
 * Cada e-mail vai dentro de um <iframe>: o template é um documento HTML
 * completo (com <head> e os ajustes do Outlook), e injetá-lo direto na página
 * faria o navegador descartar o <head> — a prévia mentiria sobre o resultado.
 *
 * Uso (dentro do container):
 *   docker exec glpi-glpi-1 php /var/www/glpi/plugins/reservaplus/tools/render_emails.php > /tmp/emails.html
 */

// Roda sem o bootstrap do GLPI: só precisamos das funções de tradução.
if (!function_exists('__')) {
    function __(string $text, string $domain = ''): string
    {
        return $text;
    }
}
if (!function_exists('_n')) {
    function _n(string $singular, string $plural, int $count, string $domain = ''): string
    {
        return $count === 1 ? $singular : $plural;
    }
}

require dirname(__DIR__) . '/src/EmailTemplate.php';

use GlpiPlugin\Reservaplus\EmailTemplate;

$base = 'https://glpi.exemplo.local';
$myReservations = $base . '/plugins/reservaplus/front/reservation.php';

$emails = [
    [
        'id'      => 'reservation_created',
        'gatilho' => 'front/reservation.form.php — reserva avulsa criada',
        'dest'    => 'Quem vai usar a sala (ou o solicitante, se for para si)',
        'tpl'     => EmailTemplate::reservationCreated([
            'item'    => 'Hall Anexo Auditório(Vidro)',
            'period'  => '07/10/2026 15:00 – 18:00',
            'people'  => 10,
            'for'     => 'Celso De Oliveira Junior',
            'comment' => 'Reunião de alinhamento trimestral',
            'url'     => $myReservations,
        ]),
    ],
    [
        'id'      => 'reservation_series_created',
        'gatilho' => 'front/reservation.form.php — recorrência ou reserva em massa (UM e-mail para o envio inteiro)',
        'dest'    => 'Cada beneficiário recebe só as reservas dele',
        'tpl'     => EmailTemplate::reservationSeriesCreated([
            'items'        => ['6 Cooperação'],
            'dates'        => [
                '10/08/2026 08:00 – 10:00',
                '10/09/2026 08:00 – 10:00',
                '10/10/2026 08:00 – 10:00',
            ],
            'count'        => 3,
            'period_label' => '08:00 – 10:00',
            'people'       => 6,
            'for'          => 'Celso De Oliveira Junior',
            'url'          => $myReservations,
        ]),
    ],
    [
        'id'      => 'reservation_updated',
        'gatilho' => 'front/reservation.form.php — reserva reagendada/editada',
        'dest'    => 'Quem vai usar a sala',
        'tpl'     => EmailTemplate::reservationUpdated([
            'item'     => '1 Empatia',
            'period'   => '12/08/2026 21:30 – 00:00',
            'previous' => '07/08/2026 15:00 – 17:00',
            'people'   => 1,
            'url'      => $myReservations,
        ]),
    ],
    [
        'id'      => 'reservation_cancelled',
        'gatilho' => 'ReservationRequest::cancelRequest() / cancelSeries()',
        'dest'    => 'Quem ia usar a sala',
        'tpl'     => EmailTemplate::reservationCancelled([
            'item'   => 'Hall Anexo Auditório(Vidro)',
            'period' => '07/08/2026 15:00 – 18:00',
            'url'    => $base . '/plugins/reservaplus/front/reservation.form.php',
        ]),
    ],
];

$out = [];
$out[] = '<!doctype html><html lang="pt-BR"><head><meta charset="utf-8">';
$out[] = '<title>Disparos de e-mail — Reserva Plus</title>';
$out[] = '<style>body{margin:0;background:#dde5f5;font-family:-apple-system,BlinkMacSystemFont,"Segoe UI",Roboto,Arial,sans-serif;color:#1f2d3d}';
$out[] = '.hdr{background:#1e3a8a;color:#fff;padding:28px 32px}.hdr h1{margin:0 0 6px;font-size:24px}.hdr p{margin:0;opacity:.85;font-size:14px}';
$out[] = '.meta{max-width:760px;margin:32px auto 0;background:#fff;border-radius:12px;padding:18px 22px;box-shadow:0 4px 14px rgba(30,64,175,.14)}';
$out[] = '.meta h2{margin:0 0 4px;font-size:18px;color:#1e3a8a}.meta dl{margin:10px 0 0;font-size:13px;display:grid;grid-template-columns:130px 1fr;gap:6px 12px}';
$out[] = '.meta dt{color:#5b7488}.meta dd{margin:0;font-weight:600}.meta code{background:#eef2ff;padding:2px 6px;border-radius:5px;font-size:12px}';
$out[] = '.subj{margin-top:12px;padding:10px 14px;background:#f4f8fb;border-left:4px solid #2563eb;border-radius:6px;font-size:14px}';
$out[] = '.txt{margin-top:10px;padding:10px 14px;background:#f7f7f5;border-left:4px solid #b7c4cf;border-radius:6px;font-size:12px;white-space:pre-wrap;color:#44586b;font-family:ui-monospace,Consolas,monospace}';
$out[] = '.mail{display:block;width:100%;border:0;margin:0 auto}';
$out[] = 'hr{border:0;border-top:2px dashed #aebedd;margin:40px auto;max-width:760px}</style></head><body>';
$out[] = '<div class="hdr"><h1>&#128197; Disparos de e-mail — Reserva Plus</h1>'
       . '<p>' . count($emails) . ' variações renderizadas a partir de <code>src/EmailTemplate.php</code> com dados de exemplo.</p></div>';

foreach ($emails as $i => $e) {
    $out[] = '<div class="meta">';
    $out[] = '<h2>' . ($i + 1) . '. <code>' . htmlspecialchars($e['id']) . '</code></h2>';
    $out[] = '<dl>';
    $out[] = '<dt>Destinatário</dt><dd>' . htmlspecialchars($e['dest']) . '</dd>';
    $out[] = '<dt>Gatilho</dt><dd>' . htmlspecialchars($e['gatilho']) . '</dd>';
    $out[] = '</dl>';
    $out[] = '<div class="subj"><strong>Assunto:</strong> ' . htmlspecialchars($e['tpl']['subject']) . '</div>';
    $out[] = '<div class="txt"><strong>Fallback texto puro:</strong>' . "\n" . htmlspecialchars($e['tpl']['text']) . '</div>';
    $out[] = '</div>';
    $out[] = '<iframe class="mail" height="900" title="' . htmlspecialchars($e['id']) . '" srcdoc="'
        . htmlspecialchars($e['tpl']['html'], ENT_QUOTES | ENT_SUBSTITUTE, 'UTF-8') . '"></iframe>';
    $out[] = '<hr>';
}

$out[] = '</body></html>';
echo implode("\n", $out);
