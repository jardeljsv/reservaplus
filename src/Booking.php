<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Loan aggregate. Time values follow GLPI's configured application timezone. */
class Booking extends \CommonDBTM
{
    public static $rightname = Access::RIGHT;
    public static function getTable($classname = null) { return Store::table('bookings'); }
    public static function getTypeName($nb = 0): string { return __('Solicitação de reserva', 'reservaplus'); }
    public static function getIcon(): string { return 'ti ti-calendar-check'; }
    // Generic Search/API cannot reproduce per-request ownership and group filtering.
    public static function canView(): bool { return false; }
    public static function canCreate(): bool { return false; }
    public static function canUpdate(): bool { return false; }
    public static function canDelete(): bool { return false; }
    public static function canPurge(): bool { return false; }
    public function canViewItem(): bool { return Access::booking($this->fields); }
    public function canCreateItem(): bool { return false; }
    public function canUpdateItem(): bool { return false; }
    public function canDeleteItem(): bool { return false; }
    public function canPurgeItem(): bool { return false; }
    public function prepareInputForAdd($input) { return false; }
    public function prepareInputForUpdate($input) { return false; }
    public function pre_deleteItem() { return false; }
    public function pre_purgeItem() { return false; }

    public static function date(string $value): string
    {
        $value = trim(str_replace('T', ' ', $value));
        if (strlen($value) === 16) { $value .= ':00'; }
        $date = \DateTimeImmutable::createFromFormat('!Y-m-d H:i:s', $value);
        $errors = \DateTimeImmutable::getLastErrors();
        if (!$date || ($errors !== false && ($errors['warning_count'] || $errors['error_count'])) || $date->format('Y-m-d H:i:s') !== $value) {
            throw new \InvalidArgumentException('Data/hora inválida. Use o fuso horário exibido pelo sistema.');
        }
        return $value;
    }

    public static function period(string $begin, string $end): array
    {
        $begin = self::date($begin); $end = self::date($end);
        if ($begin >= $end) { throw new \InvalidArgumentException('O término deve ser posterior ao início.'); }
        return [$begin, $end];
    }

    public static function overlaps(string $begin, string $end, string $otherBegin, string $otherEnd): bool
    {
        return $begin < $otherEnd && $end > $otherBegin;
    }

    /** Receive quantities as deltas. Never infer that an omitted accessory came back. */
    public static function receiveAccessories(array $accessories, array $deltas): array
    {
        foreach ($deltas as $index => $quantity) {
            if (!ctype_digit((string) $index) || !isset($accessories[(int) $index]) || !preg_match('/^\d+$/D', (string) $quantity)) {
                throw new \InvalidArgumentException('Acessório ou quantidade recebida inválida.');
            }
            $index = (int) $index; $quantity = (int) $quantity;
            $received = (int) ($accessories[$index]['received'] ?? 0) + $quantity;
            if ($received > (int) ($accessories[$index]['issued'] ?? 0)) {
                throw new \InvalidArgumentException('A quantidade recebida excede a quantidade entregue.');
            }
            $accessories[$index]['received'] = $received;
        }
        return $accessories;
    }

    public static function accessoriesComplete(array $accessories): bool
    {
        foreach ($accessories as $item) {
            if ((int) ($item['received'] ?? 0) !== (int) ($item['issued'] ?? 0)) { return false; }
        }
        return true;
    }

    public static function cronInfo($name): array
    {
        return ['description' => $name === 'DeliverOutbox'
            ? __('Processar acompanhamentos e notificações pendentes das reservas', 'reservaplus')
            : __('Liberar alocações não retiradas com prazo de assinatura vencido', 'reservaplus')];
    }

    public static function cronExpireHolds($task): int
    {
        $count = BookingService::expireHolds();
        if ($count > 0) { $task->addVolume($count); }
        return $count > 0 ? 1 : 0;
    }

    public static function cronDeliverOutbox($task): int
    {
        $count = TicketBridge::deliverOutbox();
        if ($count > 0) { $task->addVolume($count); }
        return $count > 0 ? 1 : 0;
    }
}
