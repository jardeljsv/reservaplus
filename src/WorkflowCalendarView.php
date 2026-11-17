<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Scoped occupancy projection shared by HTML and JSON; presentation never grants booking access. */
final class WorkflowCalendarView
{
    private const WEEKDAYS = ['Dom', 'Seg', 'Ter', 'Qua', 'Qui', 'Sex', 'Sáb'];
    private const MONTHS = [1=>'janeiro','fevereiro','março','abril','maio','junho','julho','agosto','setembro','outubro','novembro','dezembro'];

    public static function state(array $input): array
    {
        $view = BookingView::text($input['view'] ?? 'month');
        if (!in_array($view, ['month', 'week', 'day'], true)) { $view = 'month'; }
        $dateText = BookingView::text($input['date'] ?? '');
        if ($dateText === '' && preg_match('/^\d{4}-(0[1-9]|1[0-2])$/D', BookingView::text($input['month'] ?? ''))) {
            $dateText = $input['month'] . '-01';
        }
        $date = self::day($dateText !== '' ? $dateText : date('Y-m-d'));
        $selected = self::day(BookingView::text($input['selected'] ?? '') ?: $date->format('Y-m-d'));
        $kind = BookingView::text($input['kind'] ?? '');
        if (!in_array($kind, ['', 'room', 'equipment', 'other'], true)) { $kind = ''; }
        $resource = BookingView::text($input['resources_id'] ?? '0');
        if (!preg_match('/^(?:0|[1-9]\d{0,9}|native-[1-9]\d{0,9})$/D', $resource)) {
            throw new \DomainException(__('Recurso de calendário inválido.', 'reservaplus'));
        }
        $scope = BookingView::text($input['scope'] ?? 'all') === 'mine' ? 'mine' : 'all';
        $status = BookingView::text($input['status'] ?? '');
        if (!in_array($status, ['', 'pending', 'occupied', 'unavailable'], true)) { $status = ''; }
        if ($view === 'month') {
            $first = $date->modify('first day of this month');
            $start = $first->modify('-' . $first->format('w') . ' days');
            $end = $start->modify('+42 days');
        } elseif ($view === 'week') {
            $start = $date->modify('-' . $date->format('w') . ' days');
            $end = $start->modify('+7 days');
        } else { $start = $date; $end = $start->modify('+1 day'); }
        if ($selected < $start || $selected >= $end) { $selected = $date; }
        return ['view'=>$view,'date'=>$date->format('Y-m-d'),'selected'=>$selected->format('Y-m-d'),
            'kind'=>$kind,'resources_id'=>$resource,'scope'=>$scope,'status'=>$status,
            'start'=>$start->format('Y-m-d H:i:s'),'end'=>$end->format('Y-m-d H:i:s')];
    }

    public static function day(string $value): \DateTimeImmutable
    {
        $day = \DateTimeImmutable::createFromFormat('!Y-m-d', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$day || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $day->format('Y-m-d') !== $value) {
            throw new \DomainException(__('Escolha uma data válida.', 'reservaplus'));
        }
        return $day;
    }

    /** Half-open interval includes all occupied days, without showing a midnight ending on the next day. */
    public static function onDay(array $event, string $day): bool
    {
        $start = self::day($day);
        return (string) $event['start'] < $start->modify('+1 day')->format('Y-m-d H:i:s')
            && (string) $event['end'] > $start->format('Y-m-d H:i:s');
    }

    private static function query(array $state, array $changes = []): array
    {
        return array_replace(array_intersect_key($state, array_flip(['view','date','selected','kind','resources_id','scope','status'])), $changes);
    }

    private static function opaque(string $identity): string
    {
        if (empty($_SESSION['reservaplus_calendar_nonce'])) { $_SESSION['reservaplus_calendar_nonce'] = bin2hex(random_bytes(24)); }
        return substr(hash_hmac('sha256', $identity, (string) $_SESSION['reservaplus_calendar_nonce']), 0, 24);
    }

    /** Never include an inaccessible aggregate's identifier, link, actors, purpose or approval metadata. */
    public static function bookingEvent(array $booking, array $line, array $resource, bool $readable, string $start, string $end): array
    {
        $state = (string) $line['state'];
        $pending = $state === 'requested';
        $own = in_array(Access::userId(), [(int) $booking['users_id_requester'], (int) $booking['users_id_borrower']], true);
        $type = $pending ? 'pending' : (in_array($state, ['received','quarantine','maintenance'], true) ? 'unavailable' : 'occupied');
        $label = $pending ? __('Aguardando aprovação', 'reservaplus') : ($type === 'unavailable' ? __('Indisponível para novas reservas', 'reservaplus') : __('Ocupado', 'reservaplus'));
        if ($readable) {
            $label = match ($state) {
                'allocated' => __('Reserva aprovada', 'reservaplus'), 'issued' => __('Em uso', 'reservaplus'),
                'received' => __('Aguardando conferência', 'reservaplus'), 'ready' => __('Uso concluído', 'reservaplus'),
                'quarantine' => __('Em quarentena', 'reservaplus'), 'maintenance' => __('Em manutenção', 'reservaplus'),
                default => $label,
            };
        }
        $event = ['id'=>self::opaque('line:' . $line['id']), 'title'=>(string) $resource['name'],
            'start'=>$start,'end'=>$end,'type'=>$type,'label'=>$label,'source'=>'workflow',
            'resource_id'=>(int) $resource['id'],'kind'=>(string) $resource['kind'],'own'=>$own,
            'location'=>self::location($resource),'url'=>'','detail'=>__('Horário ocupado por outra reserva.', 'reservaplus'),
            'readonly'=>true];
        if ($readable) {
            $event['booking_id'] = (int) $booking['id'];
            $event['url'] = BookingView::url('booking.php', ['id'=>(int) $booking['id']]);
            $event['detail'] = sprintf(__('Solicitação #%s', 'reservaplus'), (int) $booking['id']);
            $event['readonly'] = false;
        }
        if ($pending) { $event['detail'] = __('Solicitação pendente; este horário ainda não está garantido.', 'reservaplus'); }
        return $event;
    }

    private static function location(array $resource): string
    {
        $parts = array_filter([
            trim((string) ($resource['building'] ?? '')),
            trim((string) ($resource['floor'] ?? '')) !== '' ? __('Andar', 'reservaplus') . ' ' . $resource['floor'] : '',
            trim((string) ($resource['room_number'] ?? '')) !== '' ? __('Sala', 'reservaplus') . ' ' . $resource['room_number'] : '',
        ]);
        return implode(' · ', $parts);
    }

    private static function matches(array $event, array $state): bool
    {
        return ($state['scope'] !== 'mine' || !empty($event['own']))
            && ($state['status'] === '' || $event['type'] === $state['status']);
    }

    /** No event-page pagination: the selected range is one coherent occupancy view. */
    public static function data(array $state): array
    {
        global $DB;
        Access::assertRight('read');
        [$start, $end] = Booking::period((string) $state['start'], (string) $state['end']);
        if (strtotime($end) - strtotime($start) > 93 * 86400) { throw new \DomainException(__('Escolha um intervalo de até 93 dias.', 'reservaplus')); }
        $allResources = []; $resources = []; $nativeMap = []; $managedNative = [];
        for ($offset = 0; ; $offset += 200) {
            $batch = ResourceService::search([], 200, $offset);
            foreach ($batch as $resource) {
                $id = (int) $resource['id']; $allResources[$id] = $resource;
                if ((int) $resource['reservationitems_id'] > 0) { $managedNative[(int) $resource['reservationitems_id']] = $id; }
                if ($state['kind'] === '' || $resource['kind'] === $state['kind']) { $resources[$id] = $resource; }
            }
            if (count($batch) < 200) { break; }
        }
        $nativeContexts = []; $nativeChoices = [];
        if (ReservationRequest::canView()) {
            foreach (ItemGroup::getAllowedItemIds() ?? [] as $nativeId) {
                $context = ItemGroup::context((int) $nativeId);
                if (!ReservationRequest::isGlpiAdmin() && !ReservationRequest::canManageAllRequests()) {
                    $legacyGroups = ItemGroup::getGroupsForItem((int) $nativeId);
                    if ($legacyGroups !== [] && array_intersect($legacyGroups, Access::groups()) === []) { continue; }
                }
                if (!$context) { continue; }
                // A linked or internal managed asset is represented by its plugin resource, once.
                $managedId = $managedNative[(int) $nativeId] ?? 0;
                if (!$managedId) {
                    foreach ($allResources as $resource) {
                        if (($context['itemtype'] === Resource::class && (int) $context['items_id'] === (int) $resource['id'])
                            || ($context['itemtype'] === ($resource['native_itemtype'] ?? '') && (int) $context['items_id'] === (int) ($resource['native_items_id'] ?? 0))) {
                            $managedId = (int) $resource['id']; $managedNative[(int) $nativeId] = $managedId; break;
                        }
                    }
                }
                if ($managedId) {
                    if (isset($resources[$managedId])) { $nativeContexts[(int) $nativeId] = $context; $nativeMap[(int) $nativeId] = $resources[$managedId]; }
                    continue;
                }
                if ($state['kind'] !== '') { continue; } // Legacy assets have no reliable plugin room/equipment classification.
                $name = ReservationRequest::getItemDisplayName(['_itemtype'=>$context['itemtype'],'_items_id'=>$context['items_id'],'reservationitems_id'=>(int) $nativeId]);
                $nativeContexts[(int) $nativeId] = $context;
                $nativeChoices['native-' . (int) $nativeId] = $name;
            }
        }
        // Current workflow read rights also permit the associated native occupancy, independently of legacy UI rights.
        foreach ($resources as $resource) {
            $nativeId = (int) $resource['reservationitems_id'];
            if ($nativeId > 0 && !isset($nativeContexts[$nativeId])) {
                $context = ItemGroup::context($nativeId);
                if ($context) { $nativeContexts[$nativeId] = $context; $nativeMap[$nativeId] = $resource; }
            }
        }
        $choices = [];
        foreach ($resources as $id=>$resource) { $choices[(string) $id] = (string) $resource['name']; }
        $choices += $nativeChoices;
        $selected = (string) $state['resources_id'];
        if ($selected !== '0' && !isset($choices[$selected])) {
            if ($state['kind'] !== '') {
                // A no-JavaScript category change can still submit the previous
                // category's resource. Reset that dependent filter, not the page.
                $selected = '0'; $state['resources_id'] = '0';
            } else {
                throw new \DomainException(__('Este recurso não está disponível no perfil selecionado. Escolha todos os recursos para continuar.', 'reservaplus'));
            }
        }
        if ($selected !== '0') {
            $resources = ctype_digit($selected) ? [(int) $selected=>$resources[(int) $selected]] : [];
            foreach ($nativeContexts as $nativeId=>$context) {
                if ($selected !== 'native-' . $nativeId && (string) ($nativeMap[$nativeId]['id'] ?? '') !== $selected) { unset($nativeContexts[$nativeId], $nativeMap[$nativeId]); }
            }
        }
        $events = []; $projectedNativeIds = []; $conditionProjected = []; $now = Store::now();
        if ($resources !== []) {
            $bookings = Store::find('bookings', ['entities_id'=>Access::entityIds() ?: [-1], 'begin'=>['<',$end],
                'OR'=>[['end'=>['>',$start]],['status'=>['issued','returning']]]], ['ORDER'=>['begin ASC','id ASC']]);
            foreach ($bookings as $booking) {
                if (in_array($booking['status'], ['cancelled','rejected'], true)) { continue; }
                $lines = Store::find('bookinglines', ['bookings_id'=>(int) $booking['id'],'resources_id'=>array_keys($resources)]);
                if ($lines === []) { continue; }
                $readable = Access::booking($booking, 'read');
                foreach ($lines as $line) {
                    $resource = $resources[(int) $line['resources_id']];
                    if (!empty($line['reservations_id'])) { $projectedNativeIds[(int) $line['reservations_id']] = true; }
                    if ($line['state'] === 'released' || ($line['state'] === 'requested' && !$readable)) { continue; }
                    $snapshot = Store::decode($line['snapshot_json'] ?? null);
                    [$eventStart, $eventEnd] = CalendarBridge::interval((array) ($snapshot['resource'] ?? $resource), (string) $booking['begin'], (string) $booking['end']);
                    if ($line['state'] === 'ready' && !empty($line['inspection_at']) && $line['inspection_at'] < $eventEnd) { $eventEnd = (string) $line['inspection_at']; }
                    $overdue = $line['state'] === 'issued' && $booking['end'] < $now;
                    if ($overdue && $end > $now) { $eventEnd = max($eventEnd, $end); }
                    if ($end > $now && in_array($line['state'], ['received','quarantine','maintenance'], true)
                        && ($resource['operational_state'] ?? 'available') !== 'available') {
                        $eventEnd = max($eventEnd, $end);
                        $conditionProjected[(int) $resource['id']] = true;
                    }
                    if (!Booking::overlaps($start, $end, $eventStart, $eventEnd) || $eventEnd <= $eventStart) { continue; }
                    $event = self::bookingEvent($booking, $line, $resource, $readable, $eventStart, $eventEnd);
                    if ($overdue) {
                        $event['type']='unavailable';
                        $event['label']=$readable
                            ? ($resource['kind']==='room' ? __('Encerramento pendente', 'reservaplus') : __('Devolução em atraso', 'reservaplus'))
                            : __('Indisponível para novas reservas', 'reservaplus');
                        $event['detail'].=' · '.($resource['kind']==='room'
                            ? __('Disponibilidade depende da conferência da sala.', 'reservaplus')
                            : __('Disponibilidade depende da devolução e conferência.', 'reservaplus'));
                    }
                    if (self::matches($event, $state)) { $events[] = $event; }
                }
            }
        }
        if ($nativeContexts !== []) {
            // Deduplicate all managed native projections, even if their request is filtered or private.
            foreach (Store::find('bookinglines', ['reservationitems_id'=>array_keys($nativeContexts)]) as $line) {
                if (!empty($line['reservations_id'])) { $projectedNativeIds[(int) $line['reservations_id']] = true; }
            }
            foreach ($DB->request(['FROM'=>'glpi_reservations','WHERE'=>['reservationitems_id'=>array_keys($nativeContexts),'begin'=>['<',$end],'end'=>['>',$start]],'ORDER'=>['begin ASC','id ASC']]) as $row) {
                if (isset($projectedNativeIds[(int) $row['id']])) { continue; }
                $nativeId = (int) $row['reservationitems_id']; $resource = $nativeMap[$nativeId] ?? null;
                $event = ['id'=>self::opaque('native:' . $row['id']), 'title'=>$resource['name'] ?? $nativeChoices['native-' . $nativeId],
                    'start'=>$row['begin'],'end'=>$row['end'],'type'=>'occupied','label'=>__('Reservado no GLPI', 'reservaplus'),
                    'source'=>'glpi','resource_id'=>(int) ($resource['id'] ?? 0),'kind'=>$resource['kind'] ?? 'legacy',
                    'own'=>(int) $row['users_id'] === Access::userId(),'location'=>$resource ? self::location($resource) : '',
                    'detail'=>__('Reserva registrada no calendário GLPI.', 'reservaplus'),'url'=>'','readonly'=>true];
                if (self::matches($event, $state)) { $events[]=$event; }
            }
        }
        if ($state['scope'] !== 'mine') {
            foreach ($resources as $resource) {
                if (isset($conditionProjected[(int) $resource['id']])) { continue; }
                if (($resource['publication'] ?? '') === 'published' && ($resource['operational_state'] ?? '') === 'available') { continue; }
                if ($end <= $now) { continue; }
                $event = ['id'=>self::opaque('condition:' . $resource['id']), 'title'=>$resource['name'], 'start'=>max($start,$now),'end'=>$end,
                    'type'=>'unavailable','label'=>__('Indisponível para novas reservas', 'reservaplus'),'source'=>'restriction',
                    'resource_id'=>(int) $resource['id'],'kind'=>$resource['kind'],'own'=>false,'location'=>self::location($resource),
                    'detail'=>__('A equipe precisa liberar este recurso antes de uma nova reserva.', 'reservaplus'),'url'=>'','readonly'=>true];
                if (self::matches($event, $state)) { $events[]=$event; }
            }
            // Operational blocks apply only to resources whose identity the current user can read.
            if ($DB->tableExists(Store::table('blocks'))) {
                foreach (Store::find('blocks', ['entities_id'=>Access::entityIds() ?: [-1], 'is_active'=>1,'begin'=>['<',$end],'end'=>['>',$start]]) as $block) {
                    $blocked = [];
                    foreach ($resources as $resource) {
                        $nativeId = (int) $resource['reservationitems_id']; $blockId = (int) $block['reservationitems_id']; $type = (string) $block['itemtype'];
                        if ((int) $block['entities_id'] === (int) $resource['entities_id'] && ($blockId === 0 || ($nativeId > 0 && $blockId === $nativeId))
                            && ($type === '' || $type === Resource::class || $type === ($resource['native_itemtype'] ?? ''))) { $blocked[]=['name'=>$resource['name'],'id'=>$resource['id'],'kind'=>$resource['kind']]; }
                    }
                    foreach ($nativeContexts as $nativeId=>$context) {
                        if (isset($nativeMap[$nativeId])) { continue; }
                        if (AvailabilityService::blockApplies($block, $context)) { $blocked[]=['name'=>$nativeChoices['native-' . $nativeId],'id'=>0,'kind'=>'legacy']; }
                    }
                    foreach ($blocked as $index=>$resource) {
                        $event=['id'=>self::opaque('block:' . $block['id'] . ':' . $index),'title'=>$resource['name'],'start'=>$block['begin'],'end'=>$block['end'],
                            'type'=>'unavailable','label'=>__('Horário bloqueado', 'reservaplus'),'source'=>'restriction','resource_id'=>(int) $resource['id'],'kind'=>$resource['kind'],
                            'own'=>false,'location'=>'','detail'=>__('Bloqueio operacional do recurso.', 'reservaplus'),'url'=>'','readonly'=>true];
                        if (self::matches($event,$state)) { $events[]=$event; }
                    }
                }
            }
        }
        usort($events, static fn(array $a,array $b):int => [$a['start'],$a['title'],$a['id']] <=> [$b['start'],$b['title'],$b['id']]);
        return ['state'=>$state,'events'=>$events,'resources'=>$choices,'native_resource_keys'=>array_keys($nativeChoices),'timezone'=>date_default_timezone_get()];
    }

    public static function heading(array $state): string
    {
        $date=self::day($state['date']);
        if ($state['view']==='month') { return ucfirst(self::MONTHS[(int)$date->format('n')]) . ' de ' . $date->format('Y'); }
        if ($state['view']==='day') { return self::longDay($state['date']); }
        return self::day(substr($state['start'],0,10))->format('d/m') . ' – ' . self::day(substr($state['end'],0,10))->modify('-1 day')->format('d/m/Y');
    }

    public static function longDay(string $key): string
    {
        $day=self::day($key);
        return self::WEEKDAYS[(int)$day->format('w')] . ', ' . $day->format('j') . ' de ' . self::MONTHS[(int)$day->format('n')];
    }

    public static function requestUrl(array $state, string $day): string
    {
        $query=['begin'=>$day.'T08:00','end'=>$day.'T09:00'];
        if ($state['kind']!=='') { $query['kind']=$state['kind']; }
        if (ctype_digit((string)$state['resources_id']) && (int)$state['resources_id']>0) { $query['resources_id']=(int)$state['resources_id']; }
        return BookingView::url('booking.form.php',$query);
    }

    private static function timeLabel(array $event, string $day): string
    {
        $startDay=substr($event['start'],0,10);$endDay=substr($event['end'],0,10);
        $begin=$startDay<$day ? __('Continua', 'reservaplus') : substr($event['start'],11,5);
        $end=$endDay>$day ? __('dia seguinte', 'reservaplus') : substr($event['end'],11,5);
        return $begin.' – '.$end;
    }

    private static function chip(array $event, string $day, bool $compact=true): void
    {
        $e=BookingView::e(...);
        $tag=$event['url']!==''?'a':'div';
        echo '<'.$tag.' class="rp-cal-event rp-cal-event--'.$e($event['type']).'"'.($tag==='a'?' href="'.$e($event['url']).'"':'').' title="'.$e($event['title'].' · '.self::timeLabel($event,$day).' · '.$event['label']).'">';
        echo '<span class="rp-cal-event-time">'.$e(self::timeLabel($event,$day)).'</span><strong>'.$e($event['title']).'</strong>';
        if (!$compact) { echo '<span class="rp-cal-event-status">'.$e($event['label']).'</span>'; }
        echo '</'.$tag.'>';
    }

    private static function details(array $events, string $day): void
    {
        $e=BookingView::e(...);$visible=array_values(array_filter($events,static fn(array $event):bool=>self::onDay($event,$day)));
        if (!$visible) { echo '<div class="rp-cal-empty"><i class="ti ti-calendar-search" aria-hidden="true"></i><strong>'.__('Nenhum evento neste filtro', 'reservaplus').'</strong><p>'.__('Escolha recursos e horários para confirmar a disponibilidade.', 'reservaplus').'</p></div>'; return; }
        foreach ($visible as $event) {
            echo '<article class="rp-cal-detail rp-cal-detail--'.$e($event['type']).'"><span class="rp-cal-detail-time">'.$e(self::timeLabel($event,$day)).'</span><h3>'.$e($event['title']).'</h3><span class="rp-cal-pill">'.$e($event['label']).'</span>';
            if ($event['location']!=='') { echo '<p class="rp-cal-location"><i class="ti ti-map-pin" aria-hidden="true"></i> '.$e($event['location']).'</p>'; }
            echo '<p>'.$e($event['detail']).'</p>';
            if ($event['url']!=='') { echo '<a href="'.$e($event['url']).'">'.__('Abrir solicitação', 'reservaplus').' <i class="ti ti-arrow-right" aria-hidden="true"></i></a>'; }
            echo '</article>';
        }
    }

    public static function render(array $data): void
    {
        global $CFG_GLPI;
        $state=$data['state'];$events=$data['events'];$e=BookingView::e(...);
        $asset=rtrim((string)($CFG_GLPI['root_doc']??''),'/').'/plugins/reservaplus/';
        echo '<link rel="stylesheet" href="'.$e($asset.'css/workflow-calendar.css').'?v='.$e(defined('PLUGIN_RESERVAPLUS_VERSION')?PLUGIN_RESERVAPLUS_VERSION:'rc2').'">';
        echo '<section class="rp-cal" data-rp-calendar><div class="rp-cal-toolbar"><div class="rp-cal-period"><span class="rp-cal-kicker">'.__('Planeje o uso dos recursos', 'reservaplus').'</span><h2 data-rp-calendar-title>'.$e(self::heading($state)).'</h2></div><div class="rp-cal-controls">';
        $date=self::day($state['date']);
        $previous=$state['view']==='month'?$date->modify('first day of previous month'):$date->modify($state['view']==='week'?'-7 days':'-1 day');
        $next=$state['view']==='month'?$date->modify('first day of next month'):$date->modify($state['view']==='week'?'+7 days':'+1 day');
        foreach ([[$previous->format('Y-m-d'),'ti-chevron-left',__('Anterior', 'reservaplus')],[date('Y-m-d'),'ti-calendar',__('Hoje', 'reservaplus')],[$next->format('Y-m-d'),'ti-chevron-right',__('Próximo', 'reservaplus')]] as [$key,$icon,$label]) {
            echo '<a class="btn btn-outline-secondary" href="'.$e(BookingView::url('workflow.calendar.php',self::query($state,['date'=>$key,'selected'=>$key]))).'" aria-label="'.$e($label).'"'.($label===__('Hoje','reservaplus')?' data-rp-calendar-today':'').'><i class="ti '.$e($icon).'" aria-hidden="true"></i>'.($label===__('Hoje','reservaplus')?' '.$e($label):'').'</a>';
        }
        echo '<nav class="rp-cal-views" aria-label="'.__('Visualização do calendário','reservaplus').'">';
        foreach (['month'=>__('Mês','reservaplus'),'week'=>__('Semana','reservaplus'),'day'=>__('Dia','reservaplus')] as $view=>$label) {
            echo '<a data-rp-calendar-view="'.$view.'" class="'.($state['view']===$view?'is-active':'').'"'.($state['view']===$view?' aria-current="page"':'').' href="'.$e(BookingView::url('workflow.calendar.php',self::query($state,['view'=>$view,'date'=>$state['selected']]))).'">'.$e($label).'</a>';
        }
        echo '</nav></div></div><form method="get" class="rp-cal-filters" action="'.$e(BookingView::url('workflow.calendar.php')).'" data-rp-calendar-filters><input type="hidden" name="view" value="'.$e($state['view']).'">';
        echo '<label><span>'.__('Ir para a data','reservaplus').'</span><input class="form-control" type="date" name="date" value="'.$e($state['date']).'" required></label>';
        self::select('kind',__('Tipo de recurso','reservaplus'),[''=>__('Todos os tipos','reservaplus'),'room'=>__('Salas','reservaplus'),'equipment'=>__('Equipamentos','reservaplus'),'other'=>__('Outros recursos','reservaplus')],$state['kind']);
        self::select('resources_id',__('Recurso','reservaplus'),['0'=>__('Todos os recursos deste tipo','reservaplus')]+$data['resources'],$state['resources_id']);
        self::select('scope',__('Mostrar','reservaplus'),['all'=>__('Disponibilidade dos recursos','reservaplus'),'mine'=>__('Minhas reservas','reservaplus')],$state['scope']);
        self::select('status',__('Situação','reservaplus'),[''=>__('Todas as situações','reservaplus'),'occupied'=>__('Reservado / em uso','reservaplus'),'pending'=>__('Aguardando aprovação','reservaplus'),'unavailable'=>__('Bloqueado / indisponível','reservaplus')],$state['status']);
        echo '<button class="btn btn-primary" type="submit"><i class="ti ti-filter" aria-hidden="true"></i> '.__('Aplicar','reservaplus').'</button></form>';
        echo '<div class="rp-cal-legend"><span><i class="rp-cal-dot rp-cal-dot--occupied"></i>'.__('Reservado / em uso','reservaplus').'</span><span><i class="rp-cal-dot rp-cal-dot--pending"></i>'.__('Pendente — não garante o horário','reservaplus').'</span><span><i class="rp-cal-dot rp-cal-dot--unavailable"></i>'.__('Bloqueado / indisponível','reservaplus').'</span><small>'.__('Fuso','reservaplus').': '.$e($data['timezone']).'</small></div>';
        if ($data['resources']===[]) { echo '<div class="rp-cal-info">'.__('Nenhum recurso visível neste tipo. Escolha outro tipo ou peça à equipe a publicação dos recursos.','reservaplus').'</div>'; }
        echo '<div class="rp-cal-layout'.($state['view']==='day'?' rp-cal-layout--day':'').'">';
        if ($state['view']==='month') {
            echo '<div class="rp-cal-month" aria-label="'.$e(self::heading($state)).'">';
            foreach (self::WEEKDAYS as $weekday) { echo '<div class="rp-cal-weekday">'.$e($weekday).'</div>'; }
            $start=self::day(substr($state['start'],0,10));
            for ($i=0;$i<42;$i++) {
                $day=$start->modify('+'.$i.' days');$key=$day->format('Y-m-d');$matches=array_values(array_filter($events,static fn(array $event):bool=>self::onDay($event,$key)));
                $url=BookingView::url('workflow.calendar.php',self::query($state,['selected'=>$key]));
                echo '<div class="rp-cal-cell'.($day->format('m')!==$date->format('m')?' is-muted':'').($key===date('Y-m-d')?' is-today':'').($key===$state['selected']?' is-selected':'').'" data-rp-calendar-cell="'.$key.'"><a class="rp-cal-day-number" data-rp-calendar-day="'.$key.'" href="'.$e($url).'" aria-label="'.$e(self::longDay($key).' · '.count($matches).' '.__('eventos','reservaplus')).'"'.($key===$state['selected']?' aria-current="date"':'').'>'.$day->format('j').'</a><div class="rp-cal-cell-events">';
                foreach (array_slice($matches,0,3) as $event) { self::chip($event,$key); }
                if (count($matches)>3) { echo '<a class="rp-cal-more" data-rp-calendar-day="'.$key.'" href="'.$e($url).'">+'.(count($matches)-3).' '.__('mais','reservaplus').'</a>'; }
                echo '</div></div>';
            }
            echo '</div>';
        } elseif ($state['view']==='week') {
            echo '<div class="rp-cal-scroll"><div class="rp-cal-week">';$start=self::day(substr($state['start'],0,10));
            for ($i=0;$i<7;$i++) {
                $day=$start->modify('+'.$i.' days');$key=$day->format('Y-m-d');$matches=array_values(array_filter($events,static fn(array $event):bool=>self::onDay($event,$key)));
                echo '<section class="rp-cal-week-column'.($key===$state['selected']?' is-selected':'').'" data-rp-calendar-cell="'.$key.'"><a class="rp-cal-week-title" data-rp-calendar-day="'.$key.'" href="'.$e(BookingView::url('workflow.calendar.php',self::query($state,['selected'=>$key]))).'"><span>'.$e(self::WEEKDAYS[$i]).'</span><strong>'.$day->format('j').'</strong></a><div class="rp-cal-week-events">';
                foreach (array_slice($matches,0,6) as $event) { self::chip($event,$key,false); }
                if (count($matches)>6) {
                    echo '<a class="rp-cal-more rp-cal-more--week" data-rp-calendar-day="'.$key.'" href="'.$e(BookingView::url('workflow.calendar.php',self::query($state,['selected'=>$key]))).'">+'.(count($matches)-6).' '.__('eventos · ver agenda do dia','reservaplus').'</a>';
                }
                if (!$matches) { echo '<p class="rp-cal-week-empty">'.__('Sem eventos no filtro','reservaplus').'</p>'; }
                echo '</div></section>';
            }
            echo '</div></div>';
        }
        echo '<aside class="rp-cal-agenda" aria-label="'.__('Detalhes do dia','reservaplus').'"><div class="rp-cal-agenda-head"><span class="rp-cal-kicker">'.__('Agenda do dia','reservaplus').'</span><h3 data-rp-calendar-selected-label aria-live="polite">'.$e(self::longDay($state['selected'])).'</h3>';
        if (Access::right('request')) { echo '<a class="btn btn-primary" data-rp-calendar-request href="'.$e(self::requestUrl($state,$state['selected'])).'"><i class="ti ti-plus" aria-hidden="true"></i> '.__('Reservar neste dia','reservaplus').'</a>'; }
        echo '</div><div class="rp-cal-day-events" data-rp-calendar-day-events>';self::details($events,$state['selected']);echo '</div></aside></div>';
        echo '<p class="rp-cal-footnote"><i class="ti ti-info-circle" aria-hidden="true"></i> '.__('Clique em um dia para ver os horários. Reservas de outras pessoas aparecem apenas como ocupação; a disponibilidade final é conferida ao solicitar.','reservaplus').'</p>';
        echo '<script type="application/json" data-rp-calendar-data>'.json_encode(['events'=>$events,'state'=>$state,'request_url'=>self::requestUrl($state,$state['selected'])],JSON_UNESCAPED_UNICODE|JSON_HEX_TAG|JSON_HEX_AMP|JSON_HEX_APOS|JSON_HEX_QUOT|JSON_THROW_ON_ERROR).'</script>';
        echo '</section><script src="'.$e($asset.'js/workflow-calendar.js').'?v='.$e(defined('PLUGIN_RESERVAPLUS_VERSION')?PLUGIN_RESERVAPLUS_VERSION:'rc2').'" defer></script>';
    }

    private static function select(string $name,string $label,array $options,string $value): void
    {
        $e=BookingView::e(...);echo '<label><span>'.$e($label).'</span><select class="form-select" name="'.$e($name).'">';
        foreach ($options as $key=>$text) { echo '<option value="'.$e($key).'"'.((string)$key===$value?' selected':'').'>'.$e($text).'</option>'; }
        echo '</select></label>';
    }
}
