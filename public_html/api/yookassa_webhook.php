<?php
// Вебхук ЮKassa (события payment.succeeded и payment.canceled). Присланным
// данным не доверяем: берём из них только номер платежа, а статус и сумму
// rr_sync_payment() спрашивает у самой ЮKassa по API.
require_once __DIR__ . '/../config.php';

header('Content-Type: application/json; charset=utf-8');

if ($_SERVER['REQUEST_METHOD'] !== 'POST' || !rr_payments_enabled()) {
    http_response_code(404);
    echo json_encode(['error' => 'not found']);
    exit;
}

$event = json_decode(file_get_contents('php://input'), true);
$providerId = is_array($event) ? ($event['object']['id'] ?? '') : '';

if (is_string($providerId) && preg_match('/^[A-Za-z0-9\-]{10,64}$/', $providerId)) {
    $pdo = getDbConnection();
    $stmt = $pdo->prepare("SELECT id FROM payments WHERE provider_payment_id = ?");
    $stmt->execute([$providerId]);
    if ($paymentId = $stmt->fetchColumn()) {
        rr_sync_payment($pdo, (int) $paymentId);
    }
}

echo json_encode(['ok' => true]);
