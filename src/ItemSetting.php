<?php

declare(strict_types=1);

namespace GlpiPlugin\Reservaplus;

use CommonDBTM;

/**
 * Configurações por sala (item reservável): capacidade máxima, como a
 * capacidade é aplicada e recursos disponíveis (projetor, TV, etc.).
 *
 * Uma linha por `reservationitems_id` (UNIQUE). Fica separada de
 * {@see ItemGroup} — aquela tabela é item×grupo (N linhas por sala) e
 * duplicaria a capacidade.
 */
class ItemSetting extends CommonDBTM
{
    public static $table     = 'glpi_plugin_reservaplus_item_settings';
    public static $rightname = 'plugin_reservaplus_reservation';

    /** Bloqueia a reserva quando a quantidade de pessoas excede a capacidade. */
    public const MODE_BLOCK = 'block';
    /** Avisa mas cria a reserva assim mesmo. */
    public const MODE_WARN = 'warn';
    /** Capacidade só informativa (badge nas telas), sem validação. */
    public const MODE_OFF = 'off';

    /**
     * Cache por requisição de {@see getAll()} — as telas percorrem dezenas de
     * salas e não podem consultar o banco linha a linha. Invalidado em save().
     *
     * @var array<int,array{capacity:?int,capacity_mode:string,features:array<int,string>}>|null
     */
    private static ?array $cache = null;

    public static function getTable($classname = null): string
    {
        return 'glpi_plugin_reservaplus_item_settings';
    }

    public static function getTypeName($nb = 0): string
    {
        return __('Configuração da sala — Reserva Plus', 'reservaplus');
    }

    /** Cria a tabela no primeiro uso (idempotente). */
    public static function ensureTable(): void
    {
        global $DB;

        if ($DB->tableExists(static::getTable())) {
            return;
        }

        $DB->doQuery("CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_item_settings` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reservationitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `capacity` INT UNSIGNED DEFAULT NULL,
            `capacity_mode` VARCHAR(16) NOT NULL DEFAULT 'block',
            `features` VARCHAR(255) NOT NULL DEFAULT '',
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `reservationitems_id` (`reservationitems_id`),
            KEY `capacity` (`capacity`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci");
    }

    /**
     * Modos de aplicação da capacidade, para o <select> da tela de salas.
     *
     * @return array<string,string>
     */
    public static function getCapacityModes(): array
    {
        return [
            self::MODE_BLOCK => __('Bloquear a reserva acima da capacidade', 'reservaplus'),
            self::MODE_WARN  => __('Avisar, mas permitir reservar', 'reservaplus'),
            self::MODE_OFF   => __('Só informativo (sem validação)', 'reservaplus'),
        ];
    }

    public static function normalizeMode(string $mode): string
    {
        return array_key_exists($mode, self::getCapacityModes()) ? $mode : self::MODE_BLOCK;
    }

    /**
     * Recursos suportados: slug => [rótulo, ícone tabler].
     *
     * @return array<string,array{label:string,icon:string}>
     */
    public static function getFeatureOptions(): array
    {
        return [
            'projector'  => ['label' => __('Projetor', 'reservaplus'),        'icon' => 'ti ti-projector'],
            'tv'         => ['label' => __('TV / Monitor', 'reservaplus'),    'icon' => 'ti ti-device-tv'],
            'videoconf'  => ['label' => __('Videoconferência', 'reservaplus'), 'icon' => 'ti ti-video'],
            'whiteboard' => ['label' => __('Quadro branco', 'reservaplus'),   'icon' => 'ti ti-writing'],
            'audio'      => ['label' => __('Som / microfone', 'reservaplus'), 'icon' => 'ti ti-microphone'],
            'aircon'     => ['label' => __('Ar-condicionado', 'reservaplus'), 'icon' => 'ti ti-air-conditioning'],
            'accessible' => ['label' => __('Acessível', 'reservaplus'),       'icon' => 'ti ti-wheelchair'],
            'coffee'     => ['label' => __('Copa / café', 'reservaplus'),     'icon' => 'ti ti-coffee'],
        ];
    }

    public static function getFeatureLabel(string $slug): string
    {
        $options = self::getFeatureOptions();
        return (string) ($options[$slug]['label'] ?? $slug);
    }

    public static function getFeatureIcon(string $slug): string
    {
        $options = self::getFeatureOptions();
        return (string) ($options[$slug]['icon'] ?? 'ti ti-point');
    }

    /**
     * Mantém apenas os slugs conhecidos, sem repetição e na ordem canônica.
     *
     * @param array<int,mixed> $features
     * @return array<int,string>
     */
    public static function normalizeFeatures(array $features): array
    {
        $known = array_keys(self::getFeatureOptions());
        $clean = array_map(static fn($f): string => trim((string) $f), $features);

        return array_values(array_filter($known, static fn(string $slug): bool => in_array($slug, $clean, true)));
    }

    /**
     * Configuração de uma sala, sempre normalizada (nunca lança / nunca null).
     *
     * @return array{capacity:?int,capacity_mode:string,features:array<int,string>}
     */
    public static function getForItem(int $reservationItemsId): array
    {
        $all = self::getAll();

        return $all[$reservationItemsId] ?? self::emptySetting();
    }

    /**
     * Todas as configurações, indexadas por reservationitems_id. Uma consulta só
     * — as telas percorrem dezenas de salas e não podem consultar item a item.
     *
     * @return array<int,array{capacity:?int,capacity_mode:string,features:array<int,string>}>
     */
    public static function getAll(): array
    {
        global $DB;

        if (self::$cache !== null) {
            return self::$cache;
        }

        if (!$DB->tableExists(static::getTable())) {
            return [];
        }

        $out = [];
        foreach ($DB->request(['FROM' => static::getTable()]) as $row) {
            $capacity = $row['capacity'] ?? null;
            $out[(int) $row['reservationitems_id']] = [
                'capacity'      => $capacity === null ? null : max(0, (int) $capacity),
                'capacity_mode' => self::normalizeMode((string) ($row['capacity_mode'] ?? self::MODE_BLOCK)),
                'features'      => self::normalizeFeatures(explode(',', (string) ($row['features'] ?? ''))),
            ];
        }

        self::$cache = $out;

        return $out;
    }

    /** @return array{capacity:?int,capacity_mode:string,features:array<int,string>} */
    public static function emptySetting(): array
    {
        return ['capacity' => null, 'capacity_mode' => self::MODE_BLOCK, 'features' => []];
    }

    /**
     * Grava (insere ou atualiza) a configuração da sala. Capacidade <= 0 vira
     * NULL — "sem limite definido".
     *
     * @param array<int,mixed> $features
     */
    public static function save(int $reservationItemsId, ?int $capacity, string $mode, array $features): void
    {
        global $DB;

        if ($reservationItemsId <= 0) {
            return;
        }

        self::ensureTable();
        self::$cache = null;

        $values = [
            'capacity'      => ($capacity !== null && $capacity > 0) ? $capacity : null,
            'capacity_mode' => self::normalizeMode($mode),
            'features'      => implode(',', self::normalizeFeatures($features)),
            'date_mod'      => date('Y-m-d H:i:s'),
        ];

        $existing = $DB->request([
            'SELECT' => ['id'],
            'FROM'   => static::getTable(),
            'WHERE'  => ['reservationitems_id' => $reservationItemsId],
            'LIMIT'  => 1,
        ])->current();

        if ($existing) {
            $DB->update(static::getTable(), $values, ['id' => (int) $existing['id']]);
            return;
        }

        $DB->insert(static::getTable(), $values + [
            'reservationitems_id' => $reservationItemsId,
            'date_creation'       => date('Y-m-d H:i:s'),
        ]);
    }

    /**
     * Valida a quantidade de pessoas contra a capacidade da sala.
     *
     * Retorna null quando cabe (ou quando não há capacidade definida / o modo é
     * "off"). Caso contrário devolve o modo efetivo e a mensagem já formatada —
     * quem chama decide se bloqueia (`block`) ou só avisa (`warn`).
     *
     * @return array{mode:string,message:string}|null
     */
    public static function capacityViolation(int $reservationItemsId, int $peopleCount, string $itemLabel = ''): ?array
    {
        $setting  = self::getForItem($reservationItemsId);
        $capacity = $setting['capacity'];
        $mode     = $setting['capacity_mode'];

        if ($capacity === null || $capacity <= 0 || $mode === self::MODE_OFF || $peopleCount <= $capacity) {
            return null;
        }

        $message = $itemLabel !== ''
            ? sprintf(
                __('%1$s — cabe %2$d pessoa(s), você pediu %3$d', 'reservaplus'),
                $itemLabel,
                $capacity,
                $peopleCount
            )
            : sprintf(
                __('Capacidade máxima: %1$d pessoa(s), você pediu %2$d.', 'reservaplus'),
                $capacity,
                $peopleCount
            );

        return ['mode' => $mode, 'message' => $message];
    }
}
