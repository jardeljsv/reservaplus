<?php
declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

/** Checked writes to plugin tables. Native objects must use their native models. */
final class Store
{
    private static int $depth = 0;
    private static bool $rollbackOnly = false;

    public static function table(string $suffix): string
    {
        if (!preg_match('/^[a-z][a-z0-9_]*$/D', $suffix)) {
            throw new \InvalidArgumentException('Invalid plugin table identifier.');
        }
        return 'glpi_plugin_reservaplus_' . $suffix;
    }

    public static function now(): string
    {
        // Match GLPI's database/application timezone, not the browser timezone.
        return date('Y-m-d H:i:s');
    }

    public static function json(mixed $value): string
    {
        return json_encode($value, JSON_THROW_ON_ERROR | JSON_UNESCAPED_UNICODE | JSON_UNESCAPED_SLASHES);
    }

    public static function decode(?string $value): array
    {
        if ($value === null || trim($value) === '') {
            return [];
        }
        $decoded = json_decode($value, true, 64, JSON_THROW_ON_ERROR);
        if (!is_array($decoded)) {
            throw new \UnexpectedValueException('Invalid structured plugin data.');
        }
        return $decoded;
    }

    public static function find(string $suffix, array $where = [], array $options = []): array
    {
        global $DB;
        $query = ['FROM' => self::table($suffix), 'WHERE' => $where];
        foreach (['SELECT', 'ORDER', 'LIMIT', 'START', 'GROUPBY'] as $key) {
            if (array_key_exists($key, $options)) {
                $query[$key] = $options[$key];
            }
        }
        $result = [];
        foreach ($DB->request($query) as $row) {
            $result[] = (array) $row;
        }
        return $result;
    }

    public static function row(string $suffix, int $id): array
    {
        if ($id < 1) {
            throw new \RuntimeException('Registro não encontrado.');
        }
        $rows = self::find($suffix, ['id' => $id], ['LIMIT' => 1]);
        if (!$rows) {
            throw new \RuntimeException('Registro não encontrado.');
        }
        return $rows[0];
    }

    public static function insert(string $suffix, array $values): int
    {
        global $DB;
        if (!$DB->insert(self::table($suffix), $values)) {
            throw new \RuntimeException('Não foi possível gravar o registro.');
        }
        $id = (int) $DB->insertId();
        if ($id < 1) {
            throw new \RuntimeException('O banco não confirmou a gravação.');
        }
        return $id;
    }

    public static function update(string $suffix, int $id, array $values): void
    {
        global $DB;
        if ($id < 1 || !$DB->update(self::table($suffix), $values, ['id' => $id])) {
            throw new \RuntimeException('Não foi possível atualizar o registro.');
        }
    }

    public static function transaction(callable $work): mixed
    {
        global $DB;
        $outer = self::$depth === 0;
        if ($outer) {
            self::$rollbackOnly = false;
            // A worker can wait on a resource after authorization reads have
            // established an InnoDB REPEATABLE READ snapshot. Its conflict
            // reads must then see the preceding lock holder's committed booking.
            // Apply only to this transaction, never the connection-wide default.
            // Entering from an unrelated transaction fails closed instead of
            // silently inheriting an isolation level we cannot guarantee.
            if ($DB->doQuery('SET TRANSACTION ISOLATION LEVEL READ COMMITTED') === false) {
                throw new \RuntimeException('Não foi possível configurar a transação de reserva.');
            }
            if ($DB->beginTransaction() === false) {
                throw new \RuntimeException('Não foi possível iniciar a transação.');
            }
        }
        ++self::$depth;
        try {
            $result = $work();
            if ($outer && self::$rollbackOnly) {
                throw new \RuntimeException('A operação precisa ser repetida; nenhuma alteração foi confirmada.');
            }
            if ($outer && $DB->commit() === false) {
                throw new \RuntimeException('Não foi possível confirmar a transação.');
            }
            return $result;
        } catch (\Throwable $e) {
            self::$rollbackOnly = true;
            if ($outer) {
                $DB->rollBack();
            }
            throw $e;
        } finally {
            --self::$depth;
            if ($outer) {
                self::$rollbackOnly = false;
            }
        }
    }

    public static function inTransaction(): bool
    {
        return self::$depth > 0;
    }

    public static function lock(string $suffix, int $id): array
    {
        global $DB;
        if (!self::inTransaction() || $id < 1) {
            throw new \LogicException('A row lock requires an active plugin transaction and a valid ID.');
        }
        $table = self::table($suffix);
        $result = $DB->doQuery("SELECT * FROM `{$table}` WHERE `id` = {$id} FOR UPDATE");
        if ($result === false) {
            throw new \RuntimeException('Não foi possível bloquear o registro para atualização.');
        }
        $row = $DB->fetchAssoc($result);
        if (!$row) {
            throw new \RuntimeException('Registro não encontrado.');
        }
        return (array) $row;
    }

    public static function event(int $bookingId, string $type, array $payload = [], int $lineId = 0, ?string $occurredAt = null): int
    {
        return self::insert('bookingevents', [
            'bookings_id' => $bookingId,
            'lines_id' => $lineId,
            'users_id_actor' => Access::userId(),
            'type' => $type,
            'occurred_at' => $occurredAt ?? self::now(),
            'recorded_at' => self::now(),
            'payload_json' => self::json($payload),
        ]);
    }
}
