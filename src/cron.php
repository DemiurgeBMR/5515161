<?php
/**
 * Фоновые задачи: синхронизация с площадками, напоминания гостям,
 * удаление устаревших данных.
 *
 * Запускаются двумя способами — достаточно любого:
 *  • планировщиком хостинга: php tools/cron.php каждые 10–15 минут;
 *  • сами, пока администратор работает в админке (не чаще раза в 10 минут,
 *    уже после того, как страница отдана — пользователь ничего не ждёт).
 */

function cron_run(): array
{
    $log = [];
    $lock = @fopen(HOTEL_ROOT . '/storage/cron.lock', 'c');
    if ($lock && !flock($lock, LOCK_EX | LOCK_NB)) {
        return ['уже выполняется'];
    }
    set_setting('cron_last_run', (string) time());

    // Площадки — каждые 15 минут.
    if (time() - (int) setting('cron_ical', '0') >= 900) {
        set_setting('cron_ical', (string) time());
        foreach (ical_sync_all() as $roomId => $err) {
            $log[] = 'iCal, комната ' . $roomId . ': ' . ($err ?? 'ок');
        }
    }

    // Напоминания о заезде — накануне, с 9 до 21 часа.
    $hour = (int) date('G');
    if ($hour >= 9 && $hour < 21) {
        $tomorrow = today()->modify('+1 day')->format('Y-m-d');
        $rows = q_all("SELECT b.id FROM bookings b JOIN guests g ON g.id = b.guest_id
                       WHERE b.status = 'confirmed' AND b.check_in = ? AND b.reminder_sent_at IS NULL AND g.email <> ''", [$tomorrow]);
        foreach ($rows as $r) {
            $b = booking_get((int) $r['id']);
            [$subject, $text] = mail_reminder($b);
            if (send_mail($b['guest_email'], $subject, $text)) {
                q('UPDATE bookings SET reminder_sent_at = ? WHERE id = ?', [now(), $b['id']]);
                audit('booking', $b['id'], 'reminder_sent', $b['guest_email'], 'system');
                $log[] = 'напоминание: бронь ' . $b['id'];
            }
        }
    }

    // Уборка — раз в сутки.
    if (time() - (int) setting('cron_cleanup', '0') >= 86400) {
        set_setting('cron_cleanup', (string) time());
        $log[] = 'удалено записей: ' . cron_cleanup();
    }

    if ($lock) {
        flock($lock, LOCK_UN);
        fclose($lock);
    }
    return $log;
}

/**
 * Удаление данных, которые больше не нужны (политика обработки ПД):
 * служебные записи, коды входа и личные данные гостей, чьи заявки не
 * состоялись, — через retention_days дней.
 */
function cron_cleanup(): int
{
    $n = 0;
    $n += q('DELETE FROM rate_limits WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 2 * 86400)])->rowCount();
    $n += q('DELETE FROM login_codes WHERE created_at < ?', [date('Y-m-d H:i:s', time() - 2 * 86400)])->rowCount();

    $days = max(7, (int) setting('retention_days', '30'));
    $cutoff = today()->modify('-' . $days . ' days')->format('Y-m-d');
    $guests = q_all(
        "SELECT g.id FROM guests g
         WHERE g.anonymized_at IS NULL
           AND EXISTS (SELECT 1 FROM bookings b WHERE b.guest_id = g.id)
           AND NOT EXISTS (SELECT 1 FROM bookings b WHERE b.guest_id = g.id AND (b.status IN ('confirmed', 'checked_in', 'checked_out') OR b.check_out >= ? OR b.created_at >= ?))",
        [$cutoff, $cutoff . ' 00:00:00']
    );
    foreach ($guests as $g) {
        guest_anonymize((int) $g['id'], 'срок хранения несостоявшейся заявки истёк');
        $n++;
    }
    return $n;
}

/** Запустить фоновые задачи после ответа, если давно не запускались. */
function cron_maybe_run(): void
{
    if (time() - (int) setting('cron_last_run', '0') < 600) {
        return;
    }
    register_shutdown_function(function () {
        if (function_exists('fastcgi_finish_request')) {
            fastcgi_finish_request();
        }
        try {
            cron_run();
        } catch (Throwable $e) {
            error_log('hotel cron: ' . $e->getMessage());
        }
    });
}
