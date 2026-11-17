<?php

declare(strict_types=1);

/**
 * Role-scoped acceptance migration for GLPI 11.
 *
 * Existing signatures are preserved with role='legacy', and the prior
 * uniqueness (agreement + user) becomes (agreement + user + role).
 *
 * CLI only. Back up the database before running:
 *   sudo -u www-data php plugins/reservaplus/tools/migrate_role_acceptances.php --apply
 */
if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit(1);
}
if (!in_array('--apply', $argv, true)) {
    fwrite(STDERR, "Migration is not automatic. Back up the database and rerun with --apply.\n");
    exit(2);
}

$glpiRoot = dirname(__DIR__, 3);
$autoload = $glpiRoot . '/vendor/autoload.php';
if (!is_file($autoload)) {
    fwrite(STDERR, "ERROR: GLPI vendor/autoload.php was not found.\n");
    exit(1);
}
// GLPI 11 CLI: loading inc/includes.php alone does not initialize $DB.
// Boot the application kernel as done by the integration test harness.
require_once $autoload;
$kernel = new \Glpi\Kernel\Kernel();
$kernel->boot();
require_once dirname(__DIR__) . '/src/Schema.php';

use GlpiPlugin\Reservaplus\Schema;

global $DB;
if (!isset($DB) || !$DB instanceof \DBmysql) {
    fwrite(STDERR, "ERROR: GLPI database connection was not initialized. Migration not started.\n");
    exit(1);
}
if (Schema::roleAcceptanceSchemaReady()) {
    fwrite(STDOUT, "OK: per-role acceptance schema is already active.\n");
    exit(0);
}
if (!Schema::migrateRoleAcceptancesOnly() || !Schema::roleAcceptanceSchemaReady()) {
    fwrite(STDERR, "ERROR: role acceptance migration could not be completed. Check MariaDB logs.\n");
    exit(1);
}
fwrite(STDOUT, "OK: role acceptance index updated. Historic documents and signatures preserved.\n");
