<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Read-only request discovery. Apply every row's authorization before pagination. */
final class BookingQuery
{
    private static function fold(string $value): string
    {
        return strtr(mb_strtolower(trim($value), 'UTF-8'), ['á'=>'a','à'=>'a','â'=>'a','ã'=>'a','é'=>'e','ê'=>'e','í'=>'i','ó'=>'o','ô'=>'o','õ'=>'o','ú'=>'u','ü'=>'u','ç'=>'c']);
    }
    public static function metadata(array $booking): array
    {
        $lines = $booking['lines'] ?? Store::find('bookinglines', ['bookings_id' => (int) $booking['id']], ['ORDER' => 'id ASC']);
        $resources = [];
        foreach ($lines as $line) {
            $snapshot = Store::decode($line['snapshot_json'] ?? null);
            $resource = $snapshot['resource'] ?? $snapshot;
            $resources[] = [
                'id' => (int) $line['resources_id'],
                'name' => (string) ($resource['name'] ?? ('Recurso #' . (int) $line['resources_id'])),
                'kind' => (string) ($resource['kind'] ?? 'other'),
                'location' => BookingView::locator($resource),
            ];
        }
        $borrower = Store::decode($booking['borrower_snapshot_json'] ?? null);
        return ['resources' => $resources, 'borrower' => (string) ($borrower['name'] ?? ''), 'lines' => $lines];
    }

    public static function matches(array $booking, array $meta, array $filters): bool
    {
        $kind = (string) ($filters['kind'] ?? '');
        if ($kind !== '' && !in_array($kind, array_column($meta['resources'], 'kind'), true)) { return false; }
        $query = self::fold((string) ($filters['q'] ?? ''));
        if ($query === '') { return true; }
        $haystack = implode(' ', array_merge([
            '#' . (int) $booking['id'], (string) (int) ($booking['tickets_id'] ?? 0),
            (string) ($booking['purpose'] ?? ''), $meta['borrower'],
        ], array_column($meta['resources'], 'name'), array_column($meta['resources'], 'location')));
        return str_contains(self::fold($haystack), $query);
    }

    public static function search(array $filters, int $limit = 26, int $offset = 0): array
    {
        Access::assertRight('read');
        $where = ['entities_id' => Access::entityIds() ?: [-1]];
        $staff = TechnicianDashboard::isStaff();
        if (!$staff || ($filters['scope'] ?? '') === 'mine') {
            $where['OR'] = ['users_id_requester' => Access::userId(), 'users_id_borrower' => Access::userId()];
        }
        $statuses = ['submitted','approved','issued','returning','completed','rejected','cancelled'];
        if (in_array($filters['status'] ?? '', $statuses, true)) { $where['status'] = $filters['status']; }
        if (!empty($filters['from'])) { $where['end'] = ['>', Booking::date((string) $filters['from'])]; }
        if (!empty($filters['to'])) { $where['begin'] = ['<', Booking::date((string) $filters['to'])]; }
        $orders = ['newest' => ['id DESC'], 'start' => ['begin ASC', 'id ASC'], 'latest_end' => ['end DESC', 'id DESC']];
        $order = $orders[$filters['sort'] ?? 'newest'] ?? $orders['newest'];
        $queue = (string) ($filters['queue'] ?? '');
        if ($queue !== '' && !isset(TechnicianDashboard::queues()[$queue])) { return []; }
        $out = []; $seen = 0; $scan = 0;
        $limit = max(1, min(200, $limit)); $offset = max(0, $offset);
        do {
            $batch = Store::find('bookings', $where, ['ORDER' => $order, 'LIMIT' => 100, 'START' => $scan]);
            foreach ($batch as $booking) {
                if (!Access::booking($booking)) { continue; }
                if ($staff && ($filters['scope'] ?? '') === 'team' && !TechnicianDashboard::belongsToTeam($booking)) { continue; }
                if ($queue !== '' && !TechnicianDashboard::matchesQueue($booking, $queue)) { continue; }
                $meta = self::metadata($booking);
                if (!self::matches($booking, $meta, $filters)) { continue; }
                if ($seen++ < $offset) { continue; }
                $booking['_display'] = $meta;
                $out[] = $booking;
                if (count($out) >= $limit) { return $out; }
            }
            $scan += count($batch);
        } while (count($batch) === 100);
        return $out;
    }
}
