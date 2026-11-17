<?php

declare(strict_types=1);

use GlpiPlugin\Reservaplus\DocumentService;

include_once (defined('GLPI_ROOT') ? GLPI_ROOT . '/inc/includes.php' : __DIR__ . '/../../../inc/includes.php');
Session::checkLoginUser();
if ($_SERVER['REQUEST_METHOD'] !== 'GET') {
    http_response_code(405);
    header('Allow: GET');
    exit;
}
header('Cache-Control: private, no-store');
header('X-Content-Type-Options: nosniff');
header('X-Robots-Tag: noindex, nofollow');
try {
    $artifact = DocumentService::artifact((int) ($_GET['id'] ?? 0), (string) ($_GET['artifact'] ?? 'source'));
    header('Content-Type: ' . $artifact['mime']);
    $inline = $artifact['mime'] === 'application/pdf' && ($_GET['inline'] ?? '') === '1';
    header('Content-Disposition: ' . ($inline ? 'inline' : 'attachment') . '; filename="' . $artifact['filename'] . '"');
    header('Content-Length: ' . strlen($artifact['bytes']));
    header('X-ReservaPlus-SHA256: ' . $artifact['sha256']);
    // No CSP sandbox here: the browser's PDF viewer needs its ordinary internal resources.
    echo $artifact['bytes'];
} catch (Throwable $e) {
    http_response_code(403);
    header('Content-Type: text/plain; charset=utf-8');
    echo 'Documento indisponível para esta sessão.';
}
exit;
