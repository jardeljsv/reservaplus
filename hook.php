<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\Profile;

function plugin_reservaplus_install(): bool
{
    require_once __DIR__ . '/sql/install.php';

    if (!plugin_reservaplus_run_install()) {
        return false;
    }

    try {
        Profile::ensureProfileRights();
        foreach (['ExpireHolds' => 300, 'DeliverOutbox' => 60] as $name => $frequency) {
            $task = new \CronTask();
            if (!$task->getFromDBbyName(\GlpiPlugin\Reservaplus\Booking::class, $name)
                && !\CronTask::register(\GlpiPlugin\Reservaplus\Booking::class, $name, $frequency, ['mode' => \CronTask::MODE_EXTERNAL])) {
                throw new \RuntimeException('Não foi possível registrar a ação automática ' . $name);
            }
        }
        return true;
    } catch (\Throwable $e) {
        Toolbox::logInFile('php-errors', 'ReservaPlus install: ' . $e->getMessage() . "\n");
        return false;
    }
}

function plugin_reservaplus_uninstall(): bool
{
    require_once __DIR__ . '/sql/uninstall.php';

    return plugin_reservaplus_run_uninstall();
}
