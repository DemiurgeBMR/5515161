<?php
/**
 * Оплата пакетов контактов и тарифов через ЮKassa.
 *
 * Схема: покупатель нажимает «Купить» → rr_create_payment() создаёт платёж в
 * ЮKassa и строку в `payments` → покупателя отправляют на страницу оплаты
 * ЮKassa → после оплаты он возвращается на pages/payment_return.php, а
 * ЮKassa дополнительно шлёт вебхук на api/yookassa_webhook.php. И то и другое
 * вызывает rr_sync_payment(), который НЕ верит присланным данным, а сам
 * спрашивает статус у API ЮKassa — поэтому подделать «оплачено» нельзя.
 * Контакты/тариф начисляет rr_fulfill_payment() ровно один раз.
 */

function rr_payments_enabled() {
    return YOOKASSA_SHOP_ID !== '' && YOOKASSA_SECRET_KEY !== '';
}

/** Название и цена товара по виду ('pack'|'plan') и ключу, либо null. */
function rr_payment_item($kind, $key) {
    if ($kind === 'pack') {
        $items = rr_credit_packs();
    } elseif ($kind === 'plan') {
        $items = rr_recurring_plans();
    } else {
        return null;
    }
    if (!isset($items[$key])) {
        return null;
    }
    $label = $kind === 'pack' ? 'Пакет «' . $items[$key]['label'] . '»' : 'Тариф «' . $items[$key]['label'] . '»';
    return ['label' => $label, 'price' => (int) $items[$key]['price'], 'data' => $items[$key]];
}

/** Запрос к API ЮKassa. Возвращает [HTTP-код, разобранный JSON|null]. */
function rr_yookassa_request($method, $path, $body = null, $idempotenceKey = null) {
    $ch = curl_init((getenv('YOOKASSA_API_URL') ?: 'https://api.yookassa.ru/v3') . $path);
    $headers = ['Content-Type: application/json'];
    if ($idempotenceKey !== null) {
        $headers[] = 'Idempotence-Key: ' . $idempotenceKey;
    }
    curl_setopt_array($ch, [
        CURLOPT_CUSTOMREQUEST  => $method,
        CURLOPT_RETURNTRANSFER => true,
        CURLOPT_TIMEOUT        => 20,
        CURLOPT_USERPWD        => YOOKASSA_SHOP_ID . ':' . YOOKASSA_SECRET_KEY,
        CURLOPT_HTTPHEADER     => $headers,
    ]);
    if ($body !== null) {
        curl_setopt($ch, CURLOPT_POSTFIELDS, json_encode($body, JSON_UNESCAPED_UNICODE));
    }
    $response = curl_exec($ch);
    $code = (int) curl_getinfo($ch, CURLINFO_HTTP_CODE);
    if ($response === false) {
        error_log('rr_yookassa_request ' . $method . ' ' . $path . ': ' . curl_error($ch));
    }
    curl_close($ch);
    return [$code, $response === false ? null : json_decode($response, true)];
}

/**
 * Создаёт платёж и возвращает адрес страницы оплаты ЮKassa
 * (или null, если не получилось — подробности в error_log).
 */
function rr_create_payment(PDO $pdo, $userId, $kind, $itemKey) {
    $item = rr_payment_item($kind, $itemKey);
    if (!$item || !rr_payments_enabled()) {
        return null;
    }

    $idempotenceKey = bin2hex(random_bytes(16));
    $pdo->prepare("
        INSERT INTO payments (user_id, kind, item_key, amount, idempotence_key)
        VALUES (?, ?, ?, ?, ?)
    ")->execute([$userId, $kind, $itemKey, $item['price'], $idempotenceKey]);
    $paymentId = (int) $pdo->lastInsertId();

    [$code, $data] = rr_yookassa_request('POST', '/payments', [
        'amount'       => ['value' => number_format($item['price'], 2, '.', ''), 'currency' => 'RUB'],
        'capture'      => true,
        'confirmation' => ['type' => 'redirect', 'return_url' => SITE_URL . '/pages/payment_return.php?payment=' . $paymentId],
        'description'  => $item['label'] . ' — ' . SITE_NAME,
        'metadata'     => ['payment_id' => (string) $paymentId],
    ], $idempotenceKey);

    $confirmUrl = $data['confirmation']['confirmation_url'] ?? null;
    if ($code < 200 || $code >= 300 || empty($data['id']) || !$confirmUrl) {
        error_log('rr_create_payment: ЮKassa вернула ' . $code . ': ' . json_encode($data, JSON_UNESCAPED_UNICODE));
        $pdo->prepare("UPDATE payments SET status = 'canceled' WHERE id = ?")->execute([$paymentId]);
        return null;
    }

    $pdo->prepare("UPDATE payments SET provider_payment_id = ? WHERE id = ?")->execute([$data['id'], $paymentId]);
    return $confirmUrl;
}

/**
 * Спрашивает у ЮKassa актуальный статус платежа и обновляет нашу строку;
 * при успешной оплате начисляет покупку. Возвращает актуальную строку payments
 * (или null, если платежа нет). Безопасно вызывать сколько угодно раз.
 */
function rr_sync_payment(PDO $pdo, $paymentId) {
    $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ?");
    $stmt->execute([$paymentId]);
    $payment = $stmt->fetch();
    if (!$payment) {
        return null;
    }
    if (!$payment['provider_payment_id'] || !rr_payments_enabled()) {
        return $payment;
    }
    if ($payment['status'] === 'succeeded' && $payment['fulfilled_at']) {
        return $payment;
    }

    [$code, $data] = rr_yookassa_request('GET', '/payments/' . rawurlencode($payment['provider_payment_id']));
    if ($code !== 200 || empty($data['status'])) {
        return $payment;
    }

    if ($data['status'] === 'succeeded') {
        // Сумма из ЮKassa должна совпадать с нашей — иначе не начисляем.
        if (abs((float) ($data['amount']['value'] ?? 0) - (float) $payment['amount']) > 0.001) {
            error_log('rr_sync_payment: сумма не совпала для платежа ' . $paymentId);
            return $payment;
        }
        $pdo->prepare("UPDATE payments SET status = 'succeeded', paid_at = COALESCE(paid_at, NOW()) WHERE id = ?")
            ->execute([$paymentId]);
        rr_fulfill_payment($pdo, $paymentId);
    } elseif ($data['status'] === 'canceled') {
        $pdo->prepare("UPDATE payments SET status = 'canceled' WHERE id = ? AND status = 'pending'")
            ->execute([$paymentId]);
    }

    $stmt->execute([$paymentId]);
    return $stmt->fetch();
}

/** Начисляет купленное ровно один раз (блокировка строки + отметка fulfilled_at). */
function rr_fulfill_payment(PDO $pdo, $paymentId) {
    $pdo->beginTransaction();
    try {
        $stmt = $pdo->prepare("SELECT * FROM payments WHERE id = ? FOR UPDATE");
        $stmt->execute([$paymentId]);
        $payment = $stmt->fetch();

        if (!$payment || $payment['status'] !== 'succeeded' || $payment['fulfilled_at']) {
            $pdo->rollBack();
            return false;
        }

        $item = rr_payment_item($payment['kind'], $payment['item_key']);
        if (!$item) {
            $pdo->rollBack();
            error_log('rr_fulfill_payment: неизвестный товар ' . $payment['kind'] . '/' . $payment['item_key']);
            return false;
        }

        // Цена в истории — та, что реально заплатили, а не текущая из прайса.
        $paid = (float) $payment['amount'];
        if ($payment['kind'] === 'pack') {
            rr_grant_credits($pdo, $payment['user_id'], $payment['item_key'], $item['data']['credits'], $paid);
        } else {
            rr_purchase_subscription($pdo, $payment['user_id'], $payment['item_key'], $paid);
        }

        $pdo->prepare("UPDATE payments SET fulfilled_at = NOW() WHERE id = ?")->execute([$paymentId]);
        $pdo->commit();
        return true;
    } catch (Throwable $e) {
        if ($pdo->inTransaction()) {
            $pdo->rollBack();
        }
        error_log('rr_fulfill_payment: ' . $e->getMessage());
        return false;
    }
}
