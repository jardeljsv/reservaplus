<?php

declare(strict_types=1);

function plugin_reservaplus_run_install(): bool
{
    global $DB;

    $queries = [
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_configs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `default_duration_minutes` INT UNSIGNED NOT NULL DEFAULT 60,
            `business_hours_start` TIME DEFAULT '08:00:00',
            `business_hours_end` TIME DEFAULT '18:00:00',
            `allow_recurring` TINYINT(1) NOT NULL DEFAULT 1,
            `notify_requester` TINYINT(1) NOT NULL DEFAULT 1,
            `allow_view_others` TINYINT(1) NOT NULL DEFAULT 1,
            `webhook_url` VARCHAR(255) DEFAULT NULL,
            `webhook_secret` VARCHAR(255) DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_requests` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `reservationitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_requester` INT UNSIGNED NOT NULL DEFAULT 0,
            `users_id_for` INT UNSIGNED NOT NULL DEFAULT 0,
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `begin` TIMESTAMP NULL DEFAULT NULL,
            `end` TIMESTAMP NULL DEFAULT NULL,
            `people_count` INT UNSIGNED NOT NULL DEFAULT 1,
            `comment` TEXT DEFAULT NULL,
            `recurrence_json` LONGTEXT DEFAULT NULL,
            `recurrence_group` VARCHAR(40) DEFAULT NULL,
            `native_reservations_json` LONGTEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `recurrence_group` (`recurrence_group`),
            KEY `entities_id` (`entities_id`),
            KEY `reservationitems_id` (`reservationitems_id`),
            KEY `users_id_requester` (`users_id_requester`),
            KEY `users_id_for` (`users_id_for`),
            KEY `status` (`status`),
            KEY `begin_end` (`begin`, `end`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_rules` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `name` VARCHAR(255) NOT NULL,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `profiles_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itemtype` VARCHAR(100) DEFAULT NULL,
            `requires_approval` TINYINT(1) NOT NULL DEFAULT 1,
            `max_duration_minutes` INT UNSIGNED DEFAULT NULL,
            `min_notice_minutes` INT UNSIGNED DEFAULT NULL,
            `max_days_ahead` INT UNSIGNED DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `profiles_id` (`profiles_id`),
            KEY `itemtype` (`itemtype`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_blocks` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `entities_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `reservationitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `itemtype` VARCHAR(100) DEFAULT NULL,
            `begin` TIMESTAMP NULL DEFAULT NULL,
            `end` TIMESTAMP NULL DEFAULT NULL,
            `reason` TEXT DEFAULT NULL,
            `is_active` TINYINT(1) NOT NULL DEFAULT 1,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            `date_mod` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `entities_id` (`entities_id`),
            KEY `reservationitems_id` (`reservationitems_id`),
            KEY `itemtype` (`itemtype`),
            KEY `begin_end` (`begin`, `end`),
            KEY `is_active` (`is_active`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_item_groups` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `reservationitems_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `groups_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            UNIQUE KEY `item_group` (`reservationitems_id`, `groups_id`),
            KEY `reservationitems_id` (`reservationitems_id`),
            KEY `groups_id` (`groups_id`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_item_settings` (
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
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
        "CREATE TABLE IF NOT EXISTS `glpi_plugin_reservaplus_notification_logs` (
            `id` INT UNSIGNED NOT NULL AUTO_INCREMENT,
            `event` VARCHAR(64) NOT NULL,
            `target_type` VARCHAR(64) DEFAULT NULL,
            `target_id` INT UNSIGNED NOT NULL DEFAULT 0,
            `recipient` VARCHAR(255) DEFAULT NULL,
            `status` VARCHAR(32) NOT NULL DEFAULT 'pending',
            `message` TEXT DEFAULT NULL,
            `date_creation` TIMESTAMP NULL DEFAULT NULL,
            PRIMARY KEY (`id`),
            KEY `event` (`event`),
            KEY `target` (`target_type`, `target_id`),
            KEY `status` (`status`)
        ) ENGINE=InnoDB DEFAULT CHARSET=utf8mb4 COLLATE=utf8mb4_unicode_ci",
    ];

    foreach ($queries as $query) {
        if (!$DB->doQuery($query)) {
            return false;
        }
    }

    // Migração para instalações existentes: colunas novas na config.
    $cfgTable = 'glpi_plugin_reservaplus_configs';
    foreach ([
        'webhook_url'       => 'VARCHAR(255) DEFAULT NULL',
        'webhook_secret'    => 'VARCHAR(255) DEFAULT NULL',
        'allow_view_others' => 'TINYINT(1) NOT NULL DEFAULT 1',
    ] as $col => $def) {
        if (!$DB->fieldExists($cfgTable, $col)) {
            if (!$DB->doQuery("ALTER TABLE `{$cfgTable}` ADD COLUMN `{$col}` {$def}")) { return false; }
        }
    }

    // Migração: grupo de recorrência nos requests (para cancelar a série inteira).
    $reqTable = 'glpi_plugin_reservaplus_requests';
    if (!$DB->fieldExists($reqTable, 'recurrence_group')) {
        if (!$DB->doQuery("ALTER TABLE `{$reqTable}` ADD COLUMN `recurrence_group` VARCHAR(40) DEFAULT NULL, ADD KEY `recurrence_group` (`recurrence_group`)")) { return false; }
    }

    // Migração: quantidade de pessoas da reserva (obrigatório no formulário).
    if (!$DB->fieldExists($reqTable, 'people_count')) {
        if (!$DB->doQuery("ALTER TABLE `{$reqTable}` ADD COLUMN `people_count` INT UNSIGNED NOT NULL DEFAULT 1 AFTER `end`")) { return false; }
    }

    // Migração para instalações existentes: colunas novas na configuração da sala.
    $itemSettingsTable = 'glpi_plugin_reservaplus_item_settings';
    if ($DB->tableExists($itemSettingsTable)) {
        foreach ([
            'capacity'      => 'INT UNSIGNED DEFAULT NULL',
            'capacity_mode' => "VARCHAR(16) NOT NULL DEFAULT 'block'",
            'features'      => "VARCHAR(255) NOT NULL DEFAULT ''",
        ] as $col => $def) {
            if (!$DB->fieldExists($itemSettingsTable, $col)) {
                if (!$DB->doQuery("ALTER TABLE `{$itemSettingsTable}` ADD COLUMN `{$col}` {$def}")) { return false; }
            }
        }
    }

    $config = $DB->request([
        'FROM'  => 'glpi_plugin_reservaplus_configs',
        'LIMIT' => 1,
    ])->current();

    if (!$config) {
        if (!$DB->insert('glpi_plugin_reservaplus_configs', [
            'default_duration_minutes' => 60,
            'business_hours_start'     => '08:00:00',
            'business_hours_end'       => '18:00:00',
            'allow_recurring'          => 1,
            // E-mail fica por conta da notificação nativa do GLPI ("Nova
            // reserva"); o envio próprio do plugin é opt-in para evitar
            // e-mail duplicado. Webhook e auditoria não dependem disto.
            'notify_requester'         => 0,
            'date_creation'            => date('Y-m-d H:i:s'),
            'date_mod'                 => date('Y-m-d H:i:s'),
        ])) { return false; }
    }

    return \GlpiPlugin\Reservaplus\Schema::install();
}
