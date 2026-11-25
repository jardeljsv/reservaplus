<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Short-lived, user-bound browsing state. A draft is not a reservation. */
final class RequestDraft
{
    public const TTL = 7200;
    public const MAX_ITEMS = 32;
    private const SESSION_KEY = 'reservaplus_request_drafts';

    public static function open(string $id = '', array $seed = []): array
    {
        $all = &$_SESSION[self::SESSION_KEY];
        if (!is_array($all)) { $all = []; }
        foreach ($all as $key => $draft) {
            if (!is_array($draft) || (int) ($draft['updated_at'] ?? 0) < time() - self::TTL) { unset($all[$key]); }
        }
        if ($id !== '') {
            $draft = $all[$id] ?? null;
            if (!is_array($draft) || (int) ($draft['user_id'] ?? 0) !== Access::userId()) {
                throw new \DomainException(__('Esta seleção expirou. Abra uma nova reserva para continuar.', 'reservaplus'));
            }
            return $draft;
        }
        if (Access::userId() < 1) { throw new \DomainException('Sessão indisponível.'); }
        while (count($all) >= 5) { array_shift($all); }
        $start = (int) (ceil(time() / 900) * 900);
        $draft = [
            'id' => bin2hex(random_bytes(16)), 'user_id' => Access::userId(), 'updated_at' => time(),
            'step' => 'browse', 'kind' => '', 'q' => '', 'location' => '', 'feature' => '',
            'page' => 1, 'fit' => false, 'available_only' => false, 'resources_ids' => [],
            'begin' => date('Y-m-d\TH:i', $start), 'end' => date('Y-m-d\TH:i', $start + 3600),
            'people_count' => 1, 'purpose' => '', 'unit' => '',
            'users_id_borrower' => Access::userId(), 'tickets_id' => 0,
        ];
        foreach (['kind', 'begin', 'end'] as $field) {
            if (isset($seed[$field]) && is_scalar($seed[$field]) && (string) $seed[$field] !== '') { $draft[$field] = (string) $seed[$field]; }
        }
        $draft['kind'] = in_array($draft['kind'], ['', 'room', 'equipment', 'other'], true) ? $draft['kind'] : '';
        $draft['tickets_id'] = max(0, (int) ($seed['tickets_id'] ?? 0));
        if ((int) ($seed['resources_id'] ?? 0) > 0) { $draft['resources_ids'] = [(int) $seed['resources_id']]; }
        if (is_array($seed['resources_ids'] ?? null)) { $draft['resources_ids'] = self::ids($seed['resources_ids']); }
        foreach (['purpose', 'unit', 'people_count', 'users_id_borrower'] as $field) {
            if (isset($seed[$field]) && is_scalar($seed[$field])) { $draft[$field] = $seed[$field]; }
        }
        if (!Access::right('on_behalf')) { $draft['users_id_borrower'] = Access::userId(); }
        self::save($draft);
        return $draft;
    }

    public static function save(array $draft): void
    {
        if ((int) ($draft['user_id'] ?? 0) !== Access::userId() || !preg_match('/^[a-f0-9]{32}$/D', (string) ($draft['id'] ?? ''))) {
            throw new \DomainException('Seleção indisponível para esta sessão.');
        }
        $draft['updated_at'] = time();
        $_SESSION[self::SESSION_KEY][$draft['id']] = $draft;
    }

    public static function forget(string $id): void
    {
        if ($id !== '' && (int) ($_SESSION[self::SESSION_KEY][$id]['user_id'] ?? 0) === Access::userId()) {
            unset($_SESSION[self::SESSION_KEY][$id]);
        }
    }

    public static function ids(array $values): array
    {
        $out = [];
        foreach ($values as $value) {
            if (!is_scalar($value) || !ctype_digit((string) $value) || (int) $value < 1) { continue; }
            $out[(int) $value] = (int) $value;
        }
        if (count($out) > self::MAX_ITEMS) { throw new \DomainException('Selecione até 32 recursos por solicitação.'); }
        return array_values($out);
    }

    /** A page owns only its visible checkboxes; filtering must preserve the rest of the cart. */
    public static function mergeSelection(array $current, array $visible, array $checked): array
    {
        $current = self::ids($current); $visible = self::ids($visible); $checked = self::ids($checked);
        return self::ids(array_merge(array_values(array_diff($current, $visible)), array_values(array_intersect($checked, $visible))));
    }

    public static function apply(array $draft, array $input): array
    {
        if (($input['view_step'] ?? '') === 'browse') {
            $draft['resources_ids'] = self::mergeSelection($draft['resources_ids'], (array) ($input['visible_ids'] ?? []), (array) ($input['resources_ids'] ?? []));
            foreach (['q' => 160, 'location' => 160, 'feature' => 100, 'begin' => 40, 'end' => 40] as $field => $max) {
                if (is_scalar($input[$field] ?? null)) { $draft[$field] = mb_substr(trim((string) $input[$field]), 0, $max); }
            }
            $draft['available_only'] = isset($input['available_only']);
            $draft['fit'] = isset($input['fit']);
            $draft['page'] = max(1, min(100000, (int) ($input['page'] ?? $draft['page'])));
        }
        if (isset($input['people_count']) && is_scalar($input['people_count'])) { $draft['people_count'] = max(1, min(100000, (int) $input['people_count'])); }
        if (($input['view_step'] ?? '') === 'details') {
            foreach (['purpose' => 5000, 'unit' => 255] as $field => $max) {
                if (is_scalar($input[$field] ?? null)) { $draft[$field] = mb_substr(trim((string) $input[$field]), 0, $max); }
            }
            $draft['users_id_borrower'] = Access::right('on_behalf') ? max(1, (int) ($input['users_id_borrower'] ?? Access::userId())) : Access::userId();
        }
        $intent = BookingView::text($input['intent'] ?? 'filter');
        if (isset($input['choose_kind']) && in_array($input['choose_kind'], ['', 'room', 'equipment', 'other'], true)) {
            if ($draft['kind'] !== $input['choose_kind']) { $draft['q'] = ''; $draft['feature'] = ''; }
            $draft['kind'] = $input['choose_kind']; $draft['page'] = 1; $draft['step'] = 'browse';
        } elseif (str_starts_with($intent, 'page:')) {
            $draft['page'] = max(1, min(100000, (int) substr($intent, 5))); $draft['step'] = 'browse';
        } elseif (str_starts_with($intent, 'remove:')) {
            $draft['resources_ids'] = array_values(array_diff($draft['resources_ids'], [(int) substr($intent, 7)]));
        } elseif ($intent === 'clear') {
            $draft['resources_ids'] = [];
        } elseif ($intent === 'select_visible') {
            $draft['resources_ids'] = self::ids(array_merge($draft['resources_ids'], (array) ($input['selectable_ids'] ?? [])));
        } elseif (str_starts_with($intent, 'duration:')) {
            $minutes = (int) substr($intent, 9);
            if (in_array($minutes, [60, 120, 240], true)) {
                $begin = Booking::date($draft['begin']);
                $draft['end'] = (new \DateTimeImmutable($begin))->modify('+' . $minutes . ' minutes')->format('Y-m-d\TH:i');
            }
        } elseif (str_starts_with($intent, 'slot:')) {
            $ts = (int) substr($intent, 5);
            [$begin, $end] = Booking::period($draft['begin'], $draft['end']);
            $duration = strtotime($end) - strtotime($begin);
            if ($ts > time() - 60 && $ts < time() + 366 * 86400) {
                $draft['begin'] = date('Y-m-d\TH:i', $ts); $draft['end'] = date('Y-m-d\TH:i', $ts + $duration);
            }
        } elseif ($intent === 'filter') {
            $draft['page'] = 1; $draft['step'] = 'browse';
        } elseif ($intent === 'back_browse') { $draft['step'] = 'browse'; }
        elseif ($intent === 'back_details') { $draft['step'] = 'details'; }
        elseif ($intent === 'next') { $draft['step'] = 'details'; }
        elseif ($intent === 'review') { $draft['step'] = 'review'; }
        return $draft;
    }

    public static function submission(array $draft): array
    {
        return array_intersect_key($draft, array_flip(['resources_ids', 'begin', 'end', 'purpose', 'people_count', 'users_id_borrower', 'unit', 'tickets_id']));
    }

    public static function assertReviewed(array $draft, string $displayedHash): void
    {
        $current = hash('sha256', json_encode(self::submission($draft), JSON_THROW_ON_ERROR));
        if ($draft['step'] !== 'review' || $displayedHash === ''
            || !hash_equals((string) ($draft['review_hash'] ?? ''), $current)
            || !hash_equals($current, $displayedHash)) {
            throw new \DomainException(__('A seleção mudou desde esta revisão. Confira a versão atual antes de enviar.', 'reservaplus'));
        }
    }
}
