<?php
/**
 * Уборка номеров: задачи для горничной.
 *
 * Задачи появляются сами:
 *  • после выезда — в день выезда к расчётному часу (12:00), а если
 *    администратор отметил выезд раньше — сразу;
 *  • текущая уборка у проживающих — раз в N дней (настраивается на
 *    странице «Уборка», 0 — только по просьбе);
 *  • разовая — администратор добавляет вручную (гость попросил);
 *  • баня — после каждого сеанса, к его окончанию.
 *
 * Когда подходит время уборки, горничным приходит письмо. Отметку
 * «Убрано» ставит горничная в своём кабинете или администратор.
 *
 * Горничная видит только номера и время — без имён и телефонов гостей.
 */

const CLEANING_KINDS = [
    'checkout' => 'После выезда',
    'stay'     => 'Текущая уборка',
    'extra'    => 'Разовая уборка',
    'bath'     => 'После сеанса',
];

const CLEANING_STATUSES = ['pending' => 'Нужно убрать', 'done' => 'Убрано', 'skipped' => 'Не нужна'];

/** Сколько минут горничная может отменить свою отметку «Убрано» (нажала по ошибке). */
const CLEANING_UNDO_MINUTES = 15;

function cleaning_every_days(): int
{
    return max(0, min(30, (int) setting('cleaning_every_days', '3')));
}

function cleaning_stay_time(): string
{
    $t = (string) setting('cleaning_stay_time', '11:00');
    return preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $t) ? $t : '11:00';
}

function is_housekeeper(?array $staff = null): bool
{
    $staff = $staff ?? current_staff();
    return ($staff['role'] ?? '') === 'housekeeper';
}

/**
 * Привести задачи на дату в соответствие с бронями: добавить недостающие,
 * убрать ненужные (бронь отменили, продлили, перенесли в другую комнату).
 * Безопасно вызывать сколько угодно раз.
 */
function cleaning_sync(string $date): void
{
    if (!parse_date($date)) {
        return;
    }
    $want = [];
    $checkout = $date . ' ' . hotel()['check_out'] . ':00';
    foreach (q_all("SELECT id, room_id FROM bookings WHERE room_id IS NOT NULL AND check_out = ?
                    AND status IN ('confirmed', 'checked_in', 'checked_out')", [$date]) as $b) {
        $want[] = [(int) $b['room_id'], (int) $b['id'], 'checkout', $checkout];
    }
    $every = cleaning_every_days();
    if ($every > 0) {
        $stay = $date . ' ' . cleaning_stay_time() . ':00';
        foreach (q_all("SELECT id, room_id, check_in FROM bookings WHERE room_id IS NOT NULL AND check_in < ? AND check_out > ?
                        AND status IN ('confirmed', 'checked_in')", [$date, $date]) as $b) {
            if (nights_between($b['check_in'], $date) % $every === 0) {
                $want[] = [(int) $b['room_id'], (int) $b['id'], 'stay', $stay];
            }
        }
    }

    $keep = [];
    foreach ($want as [$roomId, $bookingId, $kind, $dueAt]) {
        q('INSERT IGNORE INTO cleanings (room_id, booking_id, kind, due_date, due_at, created_at) VALUES (?, ?, ?, ?, ?, ?)',
            [$roomId, $bookingId, $kind, $date, $dueAt, now()]);
        $keep[$roomId . '|' . $bookingId . '|' . $kind] = true;
    }
    // Баня: уборка после каждого сеанса, к его окончанию.
    $bathKeep = [];
    if (bath_enabled()) {
        foreach (q_all("SELECT id, ends_at FROM bath_bookings WHERE source <> 'block' AND status IN ('new', 'confirmed', 'done')
                        AND ends_at >= ? AND ends_at < ?", [$date . ' 00:00:00', parse_date($date)->modify('+1 day')->format('Y-m-d') . ' 00:00:00']) as $x) {
            q("INSERT IGNORE INTO cleanings (room_id, bath_booking_id, kind, due_date, due_at, created_at) VALUES (NULL, ?, 'bath', ?, ?, ?)",
                [$x['id'], $date, $x['ends_at'], now()]);
            // Сеанс перенесли на другое время того же дня — уборка переезжает вместе с ним.
            q("UPDATE cleanings SET due_at = ? WHERE bath_booking_id = ? AND kind = 'bath' AND status = 'pending' AND due_at <> ?", [$x['ends_at'], $x['id'], $x['ends_at']]);
            $bathKeep[(int) $x['id']] = true;
        }
    }
    $changed = false;
    foreach (q_all("SELECT id, room_id, booking_id, bath_booking_id, kind FROM cleanings WHERE due_date = ? AND status = 'pending' AND kind IN ('checkout', 'stay', 'bath')", [$date]) as $c) {
        $needed = $c['kind'] === 'bath'
            ? isset($bathKeep[(int) $c['bath_booking_id']])
            : isset($keep[$c['room_id'] . '|' . (int) $c['booking_id'] . '|' . $c['kind']]);
        if (!$needed) {
            q('DELETE FROM cleanings WHERE id = ?', [$c['id']]);
            $changed = true;
        }
    }
    // Текущая уборка, которую так и не сделали, назавтра уже не нужна —
    // будет следующая. Уборка после выезда остаётся, пока номер не убран.
    if ($date === today()->format('Y-m-d')) {
        $changed = q("UPDATE cleanings SET status = 'skipped' WHERE kind = 'stay' AND status = 'pending' AND due_date < ?", [$date])->rowCount() > 0 || $changed;
    }
    if ($changed) {
        touch_data_version();
    }
}

/**
 * Задачи на дату (для сегодняшнего дня — и неубранные с прошлых дней)
 * с названием комнаты и ближайшим заездом в неё.
 */
function cleaning_list(string $date): array
{
    $today = $date === today()->format('Y-m-d');
    $rows = q_all('SELECT c.*, r.name AS room_name, t.name AS type_name, s.name AS done_by_name
                   FROM cleanings c
                   LEFT JOIN rooms r ON r.id = c.room_id
                   LEFT JOIN room_types t ON t.id = r.room_type_id
                   LEFT JOIN staff s ON s.id = c.done_by
                   WHERE c.due_date = ?' . ($today ? " OR (c.status = 'pending' AND c.due_date < ?)" : '') . '
                   ORDER BY c.status = \'pending\' DESC, c.due_at, r.sort, r.name', $today ? [$date, $date] : [$date]);
    if (!$rows) {
        return [];
    }
    $next = parse_date($date)->modify('+1 day')->format('Y-m-d');
    $arrivals = [];
    foreach (q_all("SELECT room_id, check_in, guests_count FROM bookings WHERE room_id IS NOT NULL AND check_in IN (?, ?)
                    AND status IN ('new', 'confirmed', 'checked_in') ORDER BY check_in", [$date, $next]) as $a) {
        $arrivals[(int) $a['room_id']] ??= $a;
    }
    $bathNext = [];
    foreach (q_all("SELECT starts_at, guests_count FROM bath_bookings WHERE source <> 'block' AND status IN ('new', 'confirmed')
                    AND starts_at >= ? AND starts_at < ? ORDER BY starts_at", [$date . ' 00:00:00', $next . ' 00:00:00']) as $x) {
        $bathNext[] = $x;
    }
    foreach ($rows as &$c) {
        $c['id'] = (int) $c['id'];
        $c['room_id'] = (int) $c['room_id'];
        $c['is_bath'] = !$c['room_id'];
        $c['place'] = cleaning_place($c);
        if ($c['is_bath']) {
            // Для бани важен следующий сеанс сегодня: убрать нужно до него.
            $a = null;
            foreach ($bathNext as $x) {
                if ($x['starts_at'] >= $c['due_at']) {
                    $a = ['date' => $date, 'today' => true, 'guests' => (int) $x['guests_count'], 'time' => substr($x['starts_at'], 11, 5)];
                    break;
                }
            }
            $c['arrival'] = $a;
        } else {
            $a = $arrivals[$c['room_id']] ?? null;
            $c['arrival'] = $a ? ['date' => $a['check_in'], 'today' => $a['check_in'] === $date, 'guests' => (int) $a['guests_count'], 'time' => null] : null;
        }
        $c['urgent'] = $c['status'] === 'pending' && $c['arrival'] && $c['arrival']['today'];
        $c['is_due'] = $c['status'] === 'pending' && $c['due_at'] <= now();
    }
    return $rows;
}

/** «Номер Стандарт 1» или «Баня». */
function cleaning_place(array $c): string
{
    return $c['room_id'] ? 'Номер ' . $c['room_name'] : 'Баня';
}

/** «После выезда · 12:00» и т. п. */
function cleaning_when(array $c): string
{
    $s = CLEANING_KINDS[$c['kind']] ?? $c['kind'];
    if ($c['due_date'] < today()->format('Y-m-d')) {
        return $s . ' · с ' . ru_date($c['due_date']);
    }
    return $s . ' · с ' . substr($c['due_at'], 11, 5);
}

/** Отметить «Убрано» (горничная или администратор) или «Не нужна» (только администратор). */
function cleaning_mark(int $id, string $status): void
{
    if (!in_array($status, ['done', 'skipped'], true)) {
        throw new BookingError('Неизвестная отметка');
    }
    $me = current_staff();
    if ($status === 'skipped' && is_housekeeper($me)) {
        throw new BookingError('Отменить уборку может только администратор');
    }
    $c = cleaning_get($id);
    if (!$c) {
        throw new BookingError('Уборка не найдена — обновите страницу');
    }
    if ($c['status'] !== 'pending') {
        throw new BookingError(cleaning_place($c) . ' уже отмечен' . ($c['room_id'] ? '' : 'а') . ': ' . CLEANING_STATUSES[$c['status']] . ($c['done_by_name'] ? ' (' . $c['done_by_name'] . ')' : ''));
    }
    q("UPDATE cleanings SET status = ?, done_at = ?, done_by = ? WHERE id = ? AND status = 'pending'", [$status, now(), $me['id'] ?? null, $id]);
    audit('cleaning', $id, $status, cleaning_place($c) . ', ' . mb_strtolower(CLEANING_KINDS[$c['kind']]) . ', ' . ru_date($c['due_date']));
    touch_data_version();
}

/** Снять отметку: администратор — всегда, горничная — свою, в первые 15 минут. */
function cleaning_reopen(int $id): void
{
    $me = current_staff();
    $c = cleaning_get($id);
    if (!$c || $c['status'] === 'pending') {
        throw new BookingError('Отметки уже нет — обновите страницу');
    }
    if (is_housekeeper($me) && ((int) $c['done_by'] !== $me['id'] || strtotime($c['done_at']) < time() - CLEANING_UNDO_MINUTES * 60)) {
        throw new BookingError('Снять отметку может администратор');
    }
    q("UPDATE cleanings SET status = 'pending', done_at = NULL, done_by = NULL WHERE id = ?", [$id]);
    audit('cleaning', $id, 'reopened', cleaning_place($c) . ', ' . ru_date($c['due_date']));
    touch_data_version();
}

/** Разовая уборка (гость попросил, генеральная…) — только администратор. $roomId = 0 — баня. */
function cleaning_add(int $roomId, string $date, string $time, string $note): int
{
    if (is_housekeeper()) {
        throw new BookingError('Добавлять уборки может администратор');
    }
    $room = $roomId ? q_one('SELECT * FROM rooms WHERE id = ? AND is_active = 1', [$roomId]) : (bath_enabled() ? ['name' => null] : null);
    if (!$room) {
        throw new BookingError('Выберите комнату', 'room_id');
    }
    if (!parse_date($date) || $date < today()->format('Y-m-d')) {
        throw new BookingError('Укажите дату — сегодня или позже', 'date');
    }
    if (!preg_match('/^([01]\d|2[0-3]):[0-5]\d$/', $time)) {
        throw new BookingError('Укажите время', 'time');
    }
    $note = clean_line($note, 255);
    $id = q_insert('cleanings', [
        'room_id' => $roomId ?: null, 'booking_id' => null, 'kind' => 'extra', 'due_date' => $date, 'due_at' => $date . ' ' . $time . ':00',
        'note' => $note, 'created_by' => current_staff()['id'] ?? null, 'created_at' => now(),
    ]);
    audit('cleaning', $id, 'added', ($roomId ? 'Номер ' . $room['name'] : 'Баня') . ', ' . ru_date($date) . ' с ' . $time . ($note !== '' ? ', ' . $note : ''));
    touch_data_version();
    cleaning_notify_due();
    return $id;
}

function cleaning_get(int $id): ?array
{
    return q_one('SELECT c.*, r.name AS room_name, s.name AS done_by_name FROM cleanings c LEFT JOIN rooms r ON r.id = c.room_id
                  LEFT JOIN staff s ON s.id = c.done_by WHERE c.id = ?', [$id]);
}

/**
 * Гость выехал (администратор поставил «Выехал»): уборка нужна сейчас,
 * не дожидаясь расчётного часа, — горничной сразу уходит письмо.
 */
function cleaning_after_checkout(int $bookingId): void
{
    $today = today()->format('Y-m-d');
    cleaning_sync($today);
    q("UPDATE cleanings SET due_at = ? WHERE booking_id = ? AND kind = 'checkout' AND due_date = ? AND status = 'pending' AND due_at > ?",
        [now(), $bookingId, $today, now()]);
    cleaning_notify_due();
}

/** Горничные, которым приходят письма об уборке. */
function cleaning_recipients(): array
{
    return array_column(q_all("SELECT email FROM staff WHERE is_active = 1 AND notify = 1 AND role = 'housekeeper'"), 'email');
}

/**
 * Письмо горничным о номерах, которые пора убрать. Каждая задача — в одном
 * письме: потом отметка notified_at не даёт прислать её снова.
 */
function cleaning_notify_due(): int
{
    $today = today()->format('Y-m-d');
    $due = array_values(array_filter(cleaning_list($today), fn ($c) => $c['is_due'] && $c['notified_at'] === null));
    if (!$due) {
        return 0;
    }
    $to = cleaning_recipients();
    if ($to) {
        [$subject, $text] = mail_cleaning_due($due);
        foreach ($to as $email) {
            send_mail($email, $subject, $text);
        }
    }
    tg_notify_cleaning($due);
    $ids = array_column($due, 'id');
    q('UPDATE cleanings SET notified_at = ? WHERE id IN (' . implode(',', array_fill(0, count($ids), '?')) . ')', array_merge([now()], $ids));
    touch_data_version();
    return count($due);
}

/** Сколько номеров ждут уборки прямо сейчас — для меню и «Сегодня». */
function cleaning_due_count(): int
{
    return (int) q_val("SELECT COUNT(*) FROM cleanings WHERE status = 'pending' AND due_at <= ?", [now()]);
}
