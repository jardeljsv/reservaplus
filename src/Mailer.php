<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/**
 * Envio de e-mail do Reserva Plus.
 *
 * Enfileira em glpi_queuednotifications (mesma fila da notificação nativa),
 * para que o envio use o SMTP configurado no GLPI e a tarefa automática
 * "queuednotification" — em vez de abrir conexão SMTP no meio do fluxo da
 * reserva. Se a fila não estiver disponível, cai para o GLPIMailer direto,
 * ainda em HTML.
 *
 * Falhas nunca interrompem a reserva: retornam false e são registradas.
 */
class Mailer
{
    /**
     * @param array{subject:string,html:string,text:string} $template
     */
    public static function queue(
        string $email,
        string $name,
        array $template,
        int $entityId = 0,
        string $event = 'reservaplus',
        int $itemsId = 0
    ): bool {
        global $CFG_GLPI;

        $email = trim($email);
        if ($email === '' || filter_var($email, FILTER_VALIDATE_EMAIL) === false) {
            return false;
        }

        try {
            $queued = new \QueuedNotification();
            $id = $queued->add([
                'itemtype'                 => ReservationRequest::class,
                'items_id'                 => $itemsId,
                'notificationtemplates_id' => 0,
                'entities_id'              => $entityId,
                'mode'                     => \Notification_NotificationTemplate::MODE_MAIL,
                'event'                    => $event,
                'name'                     => $template['subject'],
                'sender'                   => $CFG_GLPI['admin_email'] ?? '',
                'sendername'               => $CFG_GLPI['admin_email_name'] ?? 'Reserva Plus',
                'recipient'                => $email,
                'recipientname'            => $name,
                'body_html'                => $template['html'],
                'body_text'                => $template['text'],
            ]);

            if ($id) {
                return true;
            }
        } catch (\Throwable $e) {
            \Toolbox::logInFile('reservaplus', 'Falha ao enfileirar e-mail: ' . $e->getMessage() . "\n");
        }

        return self::sendNow($email, $template);
    }

    /**
     * Envio imediato (fallback). Manda HTML + alternativa em texto — mandar só
     * texto é o que deixava o e-mail "desconfigurado" no Outlook.
     *
     * @param array{subject:string,html:string,text:string} $template
     */
    private static function sendNow(string $email, array $template): bool
    {
        global $CFG_GLPI;

        if (!class_exists(\GLPIMailer::class)) {
            return false;
        }

        try {
            $mailer  = new \GLPIMailer();
            $message = $mailer->getEmail();

            $from = trim((string) ($CFG_GLPI['admin_email'] ?? ''));
            if ($from !== '') {
                $message->from($from);
            }
            $message->to($email);
            $message->subject($template['subject']);
            $message->text($template['text']);
            $message->html($template['html']);

            return (bool) $mailer->send();
        } catch (\Throwable $e) {
            \Toolbox::logInFile('reservaplus', 'Falha ao enviar e-mail: ' . $e->getMessage() . "\n");
            return false;
        }
    }

    /**
     * URL absoluta de uma página do plugin (para os botões do e-mail).
     * Sem url_base configurado não há link clicável — melhor omitir o botão.
     */
    public static function absoluteUrl(string $frontFile): string
    {
        global $CFG_GLPI;

        $base = rtrim((string) ($CFG_GLPI['url_base'] ?? ''), '/');
        if ($base === '' || preg_match('#^https?://#i', $base) !== 1) {
            return '';
        }

        return $base . Dashboard::getUrl($frontFile);
    }
}
