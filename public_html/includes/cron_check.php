<?php
/**
 * "Бедный cron" — вместо настоящего планировщика задач (которого нет на
 * локальном Open Server и который не гарантирован на обычном shared-хостинге),
 * эта проверка запускается при первом заходе любого пользователя на сайт
 * после полуночи. Дальше в течение того же дня — не делает вообще ничего,
 * дешёвый SELECT и выход.
 *
 * Подключать в конце /includes/header.php, ПОСЛЕ того как уже подключён
 * config.php (нужны getDbConnection() и SERVICE_DUE_DAYS).
 *
 * Когда переедешь на настоящий хостинг с доступом к cron — можно будет
 * вызывать runDailyReminders() напрямую из cron-задачи вместо этого файла,
 * логика проверки/рассылки не изменится.
 */

define('REMINDER_COOLDOWN_DAYS', 3); // не слать повторное напоминание чаще, чем раз в 3 дня по одной и той же точке

function shouldRunToday($pdo, $jobName) {
    $stmt = $pdo->prepare("SELECT last_run_date FROM system_cron_log WHERE job_name = ?");
    $stmt->execute([$jobName]);
    $lastRun = $stmt->fetchColumn();
    return $lastRun !== date('Y-m-d');
}

function markRunToday($pdo, $jobName) {
    $stmt = $pdo->prepare("
        INSERT INTO system_cron_log (job_name, last_run_date) VALUES (?, ?)
        ON DUPLICATE KEY UPDATE last_run_date = VALUES(last_run_date), last_run_at = NOW()
    ");
    $stmt->execute([$jobName, date('Y-m-d')]);
}

function recentReminderExists($pdo, $user_id, $type, $link) {
    $stmt = $pdo->prepare("
        SELECT COUNT(*) FROM notifications
        WHERE user_id = ? AND type = ? AND link = ?
          AND created_at > NOW() - INTERVAL ? DAY
    ");
    $stmt->execute([$user_id, $type, $link, REMINDER_COOLDOWN_DAYS]);
    return $stmt->fetchColumn() > 0;
}

function runMaintenanceReminders($pdo) {
    $cutoff = serviceDueCutoffDate();

    $stmt = $pdo->prepare("
        SELECT m.id as machine_id,
               COALESCE(m.last_service_at, m.installed_at) as ref_date,
               lo.operator_id, lo.owner_id,
               l.title as location_title, l.city
        FROM location_machines m
        JOIN location_operators lo ON lo.id = m.location_operator_id
        JOIN locations l ON l.id = lo.location_id
        WHERE lo.status = 'active'
          AND m.status != 'removed'
          AND COALESCE(m.last_service_at, m.installed_at) IS NOT NULL
          AND COALESCE(m.last_service_at, m.installed_at) < ?
    ");
    $stmt->execute([$cutoff]);
    $overdue = $stmt->fetchAll();

    foreach ($overdue as $row) {
        $days = (int)floor((time() - strtotime($row['ref_date'])) / 86400);

        // Напоминание оператору — с призывом к действию
        $operatorLink = '/pages/operator_locations.php?highlight_machine=' . $row['machine_id'];
        if (!recentReminderExists($pdo, $row['operator_id'], 'maintenance_due', $operatorLink)) {
            $msg = '⚠️ Точка «' . $row['location_title'] . '» (' . $row['city'] . ') не обслуживалась ' . $days . ' дн.';
            $stmt2 = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'maintenance_due', ?, ?)");
            $stmt2->execute([$row['operator_id'], $msg, $operatorLink]);
        }

        // Информирование владельца — для контроля за оператором
        $ownerLink = '/pages/service_history.php?location_id=' . $row['machine_id'];
        if (!recentReminderExists($pdo, $row['owner_id'], 'maintenance_due_owner', $ownerLink)) {
            $msg = 'ℹ️ Оператор не обслуживал точку «' . $row['location_title'] . '» ' . $days . ' дн.';
            $stmt2 = $pdo->prepare("INSERT INTO notifications (user_id, type, message, link) VALUES (?, 'maintenance_due_owner', ?, ?)");
            $stmt2->execute([$row['owner_id'], $msg, $ownerLink]);
        }
    }
}

// ===== ТОЧКА ВХОДА =====
// Обёрнуто в try/catch: если тут что-то сломается, страница всё равно
// должна отрисоваться — это фоновая проверка, а не критичная часть запроса.
try {
    $pdo = getDbConnection();
    if (shouldRunToday($pdo, 'maintenance_reminders')) {
        markRunToday($pdo, 'maintenance_reminders');
        runMaintenanceReminders($pdo);
    }
} catch (Throwable $e) {
    error_log('cron_check.php: ' . $e->getMessage());
}
