<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/**
 * Serviço de notificações do Reserva Plus.
 *
 * Estratégia: SEMPRE registra o evento em
 * glpi_plugin_reservaplus_notification_logs (auditoria) e tenta enviar e-mail
 * "best-effort" pela fila do GLPI, respeitando a config use_notifications.
 * Falhas de envio são logadas, nunca interrompem o fluxo da reserva.
 *
 * O conteúdo do e-mail vem de EmailTemplate (HTML + texto). Um envio por
 * reserva vale para reserva avulsa; recorrência e lote usam
 * reservationSeriesCreated() e mandam UM e-mail para o conjunto.
 */
class NotificationService
{
    public const EVENT_RESERVATION_CREATED = 'reservation_created';
    public const EVENT_RESERVATION_SERIES  = 'reservation_series_created';
    public const EVENT_RESERVATION_UPDATED = 'reservation_updated';
    public const EVENT_CANCELLED           = 'cancelled';

    /**
     * Notifica um usuário sobre um evento de reserva.
     *
     * $channelEnabled permite desligar o envio por configuração (ex.:
     * notify_requester) mantendo o registro de auditoria.
     *
     * @param array{subject:string,html:string,text:string} $template
     */
    public static function notify(string $event, int $userId, array $template, bool $channelEnabled = true, int $entityId = 0): void
    {
        $email  = self::getUserEmail($userId);
        $status = 'skipped';

        if ($channelEnabled && $userId > 0 && $email !== '' && self::notificationsEnabled()) {
            $status = Mailer::queue($email, self::userName($userId), $template, $entityId, $event) ? 'sent' : 'failed';
        }

        self::log($event, $userId, $email, $status, $template['subject']);
    }

    // ---- Conveniências por evento --------------------------------------

    public static function reservationCreated(array $request, bool $fireWebhook = true): void
    {
        $userId = (int) ($request['users_id_for'] ?? $request['users_id_requester'] ?? 0);
        $item   = self::itemLabel($request);
        $period = self::periodLabel($request);

        self::notify(
            self::EVENT_RESERVATION_CREATED,
            $userId,
            EmailTemplate::reservationCreated([
                'item'    => $item,
                'period'  => $period,
                'people'  => (int) ($request['people_count'] ?? 0),
                'for'     => self::beneficiaryLabel($request),
                'comment' => trim((string) ($request['comment'] ?? '')),
                'url'     => Mailer::absoluteUrl('reservation.php'),
            ]),
            self::configFlagEnabled('notify_requester'),
            (int) ($request['entities_id'] ?? 0)
        );

        // Em lote/recorrência o webhook é disparado uma vez pelo chamador
        // (evita dezenas de POSTs); aqui só dispara no caminho de 1 reserva.
        if ($fireWebhook) {
            self::webhook('reservation.created', $request + ['item_label' => $item, 'period_label' => $period]);
        }
    }

    /**
     * Recorrência / reserva em massa: UM e-mail para todas as ocorrências.
     *
     * Antes o e-mail saía dentro do laço de criação — reservar uma sala uma vez
     * por mês durante três meses rendia três e-mails idênticos na caixa de
     * entrada. Aqui o chamador acumula o que foi criado e notifica uma vez só,
     * por destinatário.
     *
     * @param array<int,array<string,mixed>> $created Reservas efetivamente criadas.
     */
    public static function reservationSeriesCreated(array $created): void
    {
        if ($created === []) {
            return;
        }

        if (count($created) === 1) {
            self::reservationCreated($created[0], false);
            return;
        }

        // Agrupa por destinatário: um gestor pode reservar para várias pessoas
        // no mesmo envio, e cada uma recebe só o que é dela.
        $byUser = [];
        foreach ($created as $row) {
            $userId = (int) ($row['users_id_for'] ?? $row['users_id_requester'] ?? 0);
            $byUser[$userId][] = $row;
        }

        foreach ($byUser as $userId => $rows) {
            // Ordena as ocorrências pelo 'begin' cru: o rótulo exibido é
            // dd/mm/aaaa, e ordenar por ele colocaria 01/09 antes de 10/08.
            usort($rows, static fn(array $a, array $b): int => strcmp(
                (string) ($a['begin'] ?? ''),
                (string) ($b['begin'] ?? '')
            ));

            $items = [];
            $dates = [];
            foreach ($rows as $row) {
                $items[self::itemLabel($row)] = true;
                if ((string) ($row['begin'] ?? '') !== '') {
                    $dates[] = self::periodLabel($row);
                }
            }
            $dates = array_values(array_unique($dates));

            $first = $rows[0];
            self::notify(
                self::EVENT_RESERVATION_SERIES,
                (int) $userId,
                EmailTemplate::reservationSeriesCreated([
                    'items'        => array_keys($items),
                    'dates'        => $dates,
                    'count'        => count($rows),
                    'period_label' => self::timeRangeLabel($first),
                    'people'       => (int) ($first['people_count'] ?? 0),
                    'for'          => self::beneficiaryLabel($first),
                    'url'          => Mailer::absoluteUrl('reservation.php'),
                ]),
                self::configFlagEnabled('notify_requester'),
                (int) ($first['entities_id'] ?? 0)
            );
        }
    }

    /**
     * Reserva reagendada/editada. $previous é o estado anterior (para citar o
     * horário antigo no e-mail e mandar os dois no webhook).
     *
     * @param array<string,mixed> $request
     * @param array<string,mixed> $previous
     */
    public static function reservationUpdated(array $request, array $previous = []): void
    {
        $userId = (int) ($request['users_id_for'] ?? $request['users_id_requester'] ?? 0);
        $item   = self::itemLabel($request);
        $period = self::periodLabel($request);
        $before = $previous !== [] ? self::periodLabel($previous) : '';

        self::notify(
            self::EVENT_RESERVATION_UPDATED,
            $userId,
            EmailTemplate::reservationUpdated([
                'item'     => $item,
                'period'   => $period,
                'previous' => $before,
                'people'   => (int) ($request['people_count'] ?? 0),
                'url'      => Mailer::absoluteUrl('reservation.php'),
            ]),
            self::configFlagEnabled('notify_requester'),
            (int) ($request['entities_id'] ?? 0)
        );

        self::webhook('reservation.updated', $request + [
            'item_label'   => $item,
            'period_label' => $period,
            'previous'     => $previous,
        ]);
    }

    public static function cancelled(array $request): void
    {
        $userId = (int) ($request['users_id_for'] ?? $request['users_id_requester'] ?? 0);
        $item   = self::itemLabel($request);
        $period = self::periodLabel($request);
        self::notify(
            self::EVENT_CANCELLED,
            $userId,
            EmailTemplate::reservationCancelled([
                'item'   => $item,
                'period' => $period,
                'url'    => Mailer::absoluteUrl('reservation.form.php'),
            ]),
            self::configFlagEnabled('notify_requester'),
            (int) ($request['entities_id'] ?? 0)
        );
        self::webhook('reservation.cancelled', $request + ['item_label' => $item, 'period_label' => $period]);
    }

    /**
     * Dispara o webhook do plugin (POST JSON) para a URL configurada, se houver.
     * Best-effort: timeout curto, nunca interrompe a reserva. Assina o corpo com
     * HMAC-SHA256 (header X-Reservaplus-Signature) quando há segredo configurado,
     * e registra o resultado no log de notificações.
     *
     * @param array<string,mixed> $payload
     */
    public static function webhook(string $event, array $payload): void
    {
        $config = Config::getSingleton();
        $url    = trim((string) ($config->fields['webhook_url'] ?? ''));
        if ($url === '' || preg_match('#^https?://#i', $url) !== 1) {
            return;
        }
        $secret = (string) ($config->fields['webhook_secret'] ?? '');

        $body = json_encode([
            'event'     => $event,
            'data'      => $payload,
            'timestamp' => date('c'),
        ], JSON_UNESCAPED_SLASHES | JSON_UNESCAPED_UNICODE);
        if ($body === false) {
            return;
        }

        $headers = [
            'Content-Type: application/json',
            'User-Agent: ReservaPlus-Webhook/1.0',
            'X-Reservaplus-Event: ' . $event,
        ];
        if ($secret !== '') {
            $headers[] = 'X-Reservaplus-Signature: sha256=' . hash_hmac('sha256', $body, $secret);
        }

        $status = 'failed';
        $detail = '';
        try {
            if (function_exists('curl_init')) {
                $ch = curl_init($url);
                curl_setopt_array($ch, [
                    CURLOPT_POST           => true,
                    CURLOPT_POSTFIELDS     => $body,
                    CURLOPT_HTTPHEADER     => $headers,
                    CURLOPT_RETURNTRANSFER => true,
                    CURLOPT_TIMEOUT        => 5,
                    CURLOPT_CONNECTTIMEOUT => 3,
                ]);
                $resp = curl_exec($ch);
                $code = (int) curl_getinfo($ch, CURLINFO_RESPONSE_CODE);
                $err  = (string) curl_error($ch);
                curl_close($ch);

                if ($resp !== false && $code >= 200 && $code < 300) {
                    $status = 'sent';
                    $detail = 'HTTP ' . $code;
                } else {
                    $detail = $resp === false ? ('curl: ' . $err) : ('HTTP ' . $code);
                }
            } else {
                $ctx = stream_context_create(['http' => [
                    'method'        => 'POST',
                    'header'        => implode("\r\n", $headers),
                    'content'       => $body,
                    'timeout'       => 5,
                    'ignore_errors' => true,
                ]]);
                $resp   = @file_get_contents($url, false, $ctx);
                $status = $resp !== false ? 'sent' : 'failed';
                $detail = $resp !== false ? 'ok' : 'falha no POST';
            }
        } catch (\Throwable $e) {
            $detail = $e->getMessage();
        }

        self::log('webhook.' . $event, 0, $url, $status, $detail !== '' ? $detail : $event);
    }

    // ---- Internos ------------------------------------------------------

    private static function notificationsEnabled(): bool
    {
        global $CFG_GLPI;
        return (int) ($CFG_GLPI['use_notifications'] ?? 0) === 1;
    }

    private static function configFlagEnabled(string $field): bool
    {
        $config = Config::getSingleton();
        return (int) ($config->fields[$field] ?? 1) === 1;
    }

    private static function getUserEmail(int $userId): string
    {
        global $DB;

        if ($userId <= 0 || !$DB->tableExists('glpi_useremails')) {
            return '';
        }

        $row = $DB->request([
            'SELECT'  => ['email'],
            'FROM'    => 'glpi_useremails',
            'WHERE'   => ['users_id' => $userId],
            'ORDER'   => ['is_default DESC', 'id ASC'],
            'LIMIT'   => 1,
        ])->current();

        return $row ? trim((string) ($row['email'] ?? '')) : '';
    }

    /** Nome amigável do destinatário (cabeçalho To: do e-mail). */
    private static function userName(int $userId): string
    {
        if ($userId <= 0) {
            return '';
        }

        $user = new \User();
        if (!$user->getFromDB($userId)) {
            return '';
        }

        return trim((string) $user->getFriendlyName());
    }

    /**
     * Nome de quem vai usar a sala, quando a reserva foi feita por outra pessoa.
     * Reserva "para mim" não precisa dessa linha no e-mail.
     */
    private static function beneficiaryLabel(array $request): string
    {
        $forId       = (int) ($request['users_id_for'] ?? 0);
        $requesterId = (int) ($request['users_id_requester'] ?? 0);

        if ($forId <= 0 || $forId === $requesterId) {
            return '';
        }

        return self::userName($forId);
    }

    private static function log(string $event, int $userId, string $recipient, string $status, string $message): void
    {
        global $DB;

        if (!$DB->tableExists('glpi_plugin_reservaplus_notification_logs')) {
            return;
        }

        $DB->insert('glpi_plugin_reservaplus_notification_logs', [
            'event'         => $event,
            'target_type'   => 'user',
            'target_id'     => $userId,
            'recipient'     => $recipient !== '' ? $recipient : null,
            'status'        => $status,
            'message'       => $message,
            'date_creation' => date('Y-m-d H:i:s'),
        ]);
    }

    private static function itemLabel(array $request): string
    {
        global $DB;

        $itemsId = (int) ($request['reservationitems_id'] ?? 0);
        if ($itemsId <= 0 || !$DB->tableExists('glpi_reservationitems')) {
            return __('item reservável', 'reservaplus');
        }

        $row = $DB->request([
            'SELECT' => ['itemtype', 'items_id'],
            'FROM'   => 'glpi_reservationitems',
            'WHERE'  => ['id' => $itemsId],
            'LIMIT'  => 1,
        ])->current();

        if (!$row) {
            return __('item reservável', 'reservaplus');
        }

        $itemtype = (string) ($row['itemtype'] ?? '');
        $iid      = (int) ($row['items_id'] ?? 0);
        if ($itemtype !== '' && class_exists($itemtype) && $iid > 0) {
            $obj = new $itemtype();
            if ($obj->getFromDB($iid) && method_exists($obj, 'getName')) {
                $name = (string) $obj->getName();
                if ($name !== '') {
                    return $name;
                }
            }
        }

        return __('item reservável', 'reservaplus');
    }

    private static function periodLabel(array $request): string
    {
        $begin = (string) ($request['begin'] ?? '');
        $end   = (string) ($request['end'] ?? '');
        $b = $begin !== '' ? date('d/m/Y H:i', strtotime($begin)) : '';
        $e = $end   !== '' ? date('H:i', strtotime($end))         : '';

        if ($b === '') {
            return __('o período solicitado', 'reservaplus');
        }

        return $e !== '' ? $b . ' – ' . $e : $b;
    }

    /**
     * Só o horário (sem data) — numa série recorrente a data varia, o horário não.
     */
    private static function timeRangeLabel(array $request): string
    {
        $begin = (string) ($request['begin'] ?? '');
        $end   = (string) ($request['end'] ?? '');
        $b = $begin !== '' ? date('H:i', (int) strtotime($begin)) : '';
        $e = $end   !== '' ? date('H:i', (int) strtotime($end))   : '';

        if ($b === '') {
            return '';
        }

        return $e !== '' ? $b . ' – ' . $e : $b;
    }
}
