<?php
/**
 * Health-check для оркестратора контейнеров (liveness/readiness-проба) —
 * ничего не знает о сессиях/CSRF/security-заголовках намеренно: должен
 * отвечать максимально быстро и без побочных эффектов, поэтому не подключает
 * includes/session_bootstrap.php, только config.php ради getDbConnection().
 */
require_once __DIR__ . '/config.php';

header('Content-Type: application/json; charset=UTF-8');
header('Cache-Control: no-store');

if (testDbConnection()) {
    echo json_encode(['status' => 'ok']);
} else {
    http_response_code(503);
    echo json_encode(['status' => 'error', 'error' => 'database unreachable']);
}
