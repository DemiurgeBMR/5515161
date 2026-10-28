<?php
/**
 * Брони, гости, оплаты и сообщения гостей.
 *
 * Создание и изменение брони идёт в транзакции с блокировкой комнат типа
 * (lock_rooms): проверка «комната свободна» и запись брони происходят
 * атомарно, поэтому двое одновременно не займут последний номер.
 */

const BOOKING_STATUSES = [
    'new'         => 'Заявка',
    'confirmed'   => 'Подтверждена',
    'checked_in'  => 'Проживает',
    'checked_out' => 'Выехал',
    'cancelled'   => 'Отменена',
    'no_show'     => 'Не приехал',
];

const BOOKING_SOURCES = [
    'site'      => 'Сайт',
    'phone'     => 'Телефон',
    'messenger' => 'Мессенджер',
    'walk_in'   => 'Пришёл сам',
    'ota'       => 'Площадка бронирования',
    'other'     => 'Другое',
];

const PAYMENT_METHODS = [
    'cash'     => 'Наличные',
    'card'     => 'Карта',
    'sbp'      => 'СБП',
    'transfer' => 'Перевод',
    'other'    => 'Другое',
];

const CONTACT_PREFS = [
    'call'     => 'Позвонить',
    'whatsapp' => 'Написать в WhatsApp',
    'telegram' => 'Написать в Telegram',
    'max'      => 'Написать в MAX',
    'email'    => 'Написать на почту',
];

const MESSAGE_KINDS = [
    'extend' => 'Продлить проживание',
    'change' => 'Перенести даты',
    'cancel' => 'Отменить бронь',
    'other'  => 'Другой вопрос',
];

class BookingError extends RuntimeException
{
    public string $field;

    public function __construct(string $message, string $field = '')
    {
        parent::__construct($message);
        $this->field = $field;
    }
}

// -------------------------------------------------------------------
// Гости
// -------------------------------------------------------------------

/** [телефон для показа, только цифры]. Российские номера приводятся к +7 978 123-45-67. */
function normalize_phone(string $phone): array
{
    $phone = trim($phone);
    $d = preg_replace('/\D+/', '', $phone);
    if (strlen($d) === 10 && $d[0] === '9') {
        $d = '7' . $d;
    }
    if (strlen($d) === 11 && $d[0] === '8') {
        $d = '7' . substr($d, 1);
    }
    if (strlen($d) === 11 && $d[0] === '7') {
        return ['+7 ' . substr($d, 1, 3) . ' ' . substr($d, 4, 3) . '-' . substr($d, 7, 2) . '-' . substr($d, 9), $d];
    }
    return [$phone, $d];
}

function valid_email(string $email): bool
{
    return $email === '' || (bool) filter_var($email, FILTER_VALIDATE_EMAIL);
}

/** Найти гостя по телефону (или почте) или завести нового. Имя существующего гостя не перезаписывается. */
function guest_upsert(string $name, string $phone, string $email): int
{
    [$display, $digits] = normalize_phone($phone);
    $email = mb_strtolower(trim($email));
    $guest = null;
    if ($digits !== '') {
        $guest = q_one('SELECT * FROM guests WHERE phone_digits = ? AND anonymized_at IS NULL ORDER BY id DESC LIMIT 1', [$digits]);
    } elseif ($email !== '') {
        $guest = q_one('SELECT * FROM guests WHERE email = ? AND anonymized_at IS NULL ORDER BY id DESC LIMIT 1', [$email]);
    }
    if ($guest) {
        $upd = ['updated_at' => now()];
        if ($guest['email'] === '' && $email !== '') {
            $upd['email'] = $email;
        }
        q_update('guests', (int) $guest['id'], $upd);
        return (int) $guest['id'];
    }
    return q_insert('guests', [
        'name'         => $name !== '' ? $name : 'Гость',
        'phone'        => $display,
        'phone_digits' => $digits,
        'email'        => $email,
        'created_at'   => now(),
        'updated_at'   => now(),
    ]);
}

function guest_get(int $id): ?array
{
    return q_one('SELECT * FROM guests WHERE id = ?', [$id]);
}

function guest_update(int $id, array $d): void
{
    [$display, $digits] = normalize_phone((string) $d['phone']);
    q_update('guests', $id, [
        'name'         => $d['name'],
        'phone'        => $display,
        'phone_digits' => $digits,
        'email'        => mb_strtolower(trim((string) $d['email'])),
        'note'         => $d['note'] ?? null,
        'updated_at'   => now(),
    ]);
}

/** Удалить личные данные гостя (по его просьбе или по сроку хранения). Брони остаются для учёта. */
function guest_anonymize(int $id, string $why): void
{
    q_update('guests', $id, [
        'name' => 'Данные удалены', 'phone' => '', 'phone_digits' => '', 'email' => '', 'note' => null,
        'updated_at' => now(), 'anonymized_at' => now(),
    ]);
    q("UPDATE bookings SET guest_comment = NULL WHERE guest_id = ?", [$id]);
    audit('guest', $id, 'anonymized', $why, current_staff() ? 'staff' : 'system');
}

// -------------------------------------------------------------------
// Брони: чтение
// -------------------------------------------------------------------

function booking_select_sql(): string
{
    return 'SELECT b.*, g.name AS guest_name, g.phone AS guest_phone, g.email AS guest_email,
                   t.name AS type_name, t.slug AS type_slug, r.name AS room_name,
                   (SELECT COALESCE(SUM(p.amount), 0) FROM payments p WHERE p.booking_id = b.id) AS paid,
                   (SELECT COUNT(*) FROM booking_messages m WHERE m.booking_id = b.id AND m.handled_at IS NULL) AS open_messages
            FROM bookings b
            LEFT JOIN guests g ON g.id = b.guest_id
            JOIN room_types t ON t.id = b.room_type_id
            LEFT JOIN rooms r ON r.id = b.room_id';
}

function booking_hydrate(?array $b): ?array
{
    if (!$b) {
        return null;
    }
    foreach (['id', 'guest_id', 'room_type_id', 'room_id', 'guests_count', 'prepayment', 'paid', 'open_messages', 'price_manual'] as $k) {
        if ($b[$k] !== null) {
            $b[$k] = (int) $b[$k];
        }
    }
    $b['total_price'] = $b['total_price'] === null ? null : (int) $b['total_price'];
    $b['nights'] = nights_between($b['check_in'], $b['check_out']);
    $b['status_label'] = BOOKING_STATUSES[$b['status']] ?? $b['status'];
    $b['balance'] = $b['total_price'] !== null ? $b['total_price'] - $b['paid'] : null;
    return $b;
}

function booking_get(int $id): ?array
{
    return booking_hydrate(q_one(booking_select_sql() . ' WHERE b.id = ?', [$id]));
}

function booking_by_token(string $token): ?array
{
    if (!preg_match('/^[a-f0-9]{40}$/', $token)) {
        return null;
    }
    return booking_hydrate(q_one(booking_select_sql() . ' WHERE b.token = ?', [$token]));
}

function booking_number(array $b): string
{
    return '№' . $b['id'];
}

/** Ссылка на страницу брони для гостя. */
function booking_url(array $b): string
{
    return base_url() . '/booking.php?t=' . $b['token'];
}

// -------------------------------------------------------------------
// Брони: создание и изменение
// -------------------------------------------------------------------

function booking_validate_dates(string $checkIn, string $checkOut, bool $allowPast): void
{
    $a = parse_date($checkIn);
    $b = parse_date($checkOut);
    if (!$a) {
        throw new BookingError('Выберите дату заезда', 'check_in');
    }
    if (!$b) {
        throw new BookingError('Выберите дату выезда', 'check_out');
    }
    if ($b <= $a) {
        throw new BookingError('Выезд должен быть позже заезда', 'check_out');
    }
    if (!$allowPast && $a < today()) {
        throw new BookingError('Дата заезда уже прошла', 'check_in');
    }
    if ($a->diff($b)->days > 365) {
        throw new BookingError('Срок больше года — позвоните нам, обсудим', 'check_out');
    }
}

/**
 * Создать бронь.
 *
 * $d: check_in, check_out, room_type_id (0 — подобрать самый недорогой
 * подходящий), guests, name, phone, email, comment, contact_pref;
 * только для админки: room_id, guest_id, status, source, total_price,
 * prepayment, admin_note.
 *
 * $byStaff — бронь заводит администратор: можно задним числом, любой
 * статус и больше гостей, чем мест.
 */
function booking_create(array $d, bool $byStaff = false): array
{
    $name = trim((string) ($d['name'] ?? ''));
    $phone = trim((string) ($d['phone'] ?? ''));
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    $guests = max(1, min(20, (int) ($d['guests'] ?? 1)));
    $checkIn = (string) ($d['check_in'] ?? '');
    $checkOut = (string) ($d['check_out'] ?? '');
    $status = $byStaff ? (string) ($d['status'] ?? 'new') : 'new';
    $guestId = (int) ($d['guest_id'] ?? 0);

    if (!$guestId) {
        if ($name === '') {
            throw new BookingError('Как к вам обращаться?', 'name');
        }
        $digits = normalize_phone($phone)[1];
        if (!$byStaff && (strlen($digits) < 10 || strlen($digits) > 15)) {
            throw new BookingError('Проверьте номер телефона', 'phone');
        }
        if ($byStaff && $digits === '' && $email === '') {
            throw new BookingError('Нужен телефон или почта гостя', 'phone');
        }
    }
    if (!valid_email($email)) {
        throw new BookingError('Проверьте адрес почты', 'email');
    }
    if (!in_array($status, ['new', 'confirmed', 'checked_in', 'checked_out'], true)) {
        throw new BookingError('Неизвестный статус', 'status');
    }
    booking_validate_dates($checkIn, $checkOut, $byStaff);

    $roomId = (int) ($d['room_id'] ?? 0);
    $typeId = (int) ($d['room_type_id'] ?? 0);
    if ($roomId) {
        $room = q_one('SELECT * FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            throw new BookingError('Такой комнаты нет', 'room_id');
        }
        $typeId = (int) $room['room_type_id'];
    }
    if ($typeId) {
        $type = room_type($typeId);
        if (!$type || (!$byStaff && !$type['is_active'])) {
            throw new BookingError('Такого номера нет', 'room_type_id');
        }
        if (!$byStaff && $guests > $type['capacity']) {
            throw new BookingError('В номере «' . $type['name'] . '» — до ' . $type['capacity'] . ' ' . plural($type['capacity'], 'гостя', 'гостей', 'гостей'), 'guests');
        }
        $candidates = [$type];
    } else {
        // «Подберите мне»: самый недорогой номер, где хватит места.
        $candidates = array_values(array_filter(room_types(true), fn ($t) => $t['capacity'] >= $guests));
        usort($candidates, fn ($a, $b) => [$a['base_price'], $a['id']] <=> [$b['base_price'], $b['id']]);
        if (!$candidates) {
            throw new BookingError('Номеров на столько гостей нет — позвоните нам, что-нибудь придумаем', 'guests');
        }
    }

    $occupying = in_array($status, OCCUPYING_STATUSES, true);

    $id = tx(function () use ($candidates, $roomId, $checkIn, $checkOut, $occupying, $byStaff, $d, $name, $phone, $email, $guests, $status, $guestId) {
        $chosenType = null;
        $chosenRoom = null;
        foreach ($candidates as $type) {
            lock_rooms($type['id']);
            if ($roomId) {
                $conflict = $occupying ? room_conflict($roomId, $checkIn, $checkOut) : null;
                if ($conflict) {
                    throw new BookingError('Комната занята: ' . conflict_text($conflict), 'room_id');
                }
                $chosenType = $type;
                $chosenRoom = $roomId;
                break;
            }
            $free = find_free_room($type['id'], $checkIn, $checkOut);
            if ($free) {
                $chosenType = $type;
                $chosenRoom = $free;
                break;
            }
        }
        if (!$chosenRoom) {
            throw new BookingError($byStaff
                ? 'Свободных комнат этого типа на эти даты нет — посмотрите шахматку'
                : 'На эти даты свободных номеров нет. Выберите другие даты или позвоните нам — подскажем, когда освободится', 'check_in');
        }

        $gid = $guestId ?: guest_upsert($name, $phone, $email);
        $quote = quote($chosenType, $checkIn, $checkOut);
        $manualTotal = isset($d['total_price']) && $d['total_price'] !== '' && $d['total_price'] !== null;
        $total = $manualTotal ? max(0, (int) $d['total_price']) : $quote['total'];
        $prepayment = isset($d['prepayment']) && $d['prepayment'] !== '' && $d['prepayment'] !== null
            ? max(0, (int) $d['prepayment'])
            : default_prepayment($quote);

        $source = $byStaff ? (string) ($d['source'] ?? 'phone') : 'site';
        $contact = (string) ($d['contact_pref'] ?? 'call');
        $id = q_insert('bookings', [
            'token'         => bin2hex(random_bytes(20)),
            'guest_id'      => $gid,
            'room_type_id'  => $chosenType['id'],
            'room_id'       => $chosenRoom,
            'check_in'      => $checkIn,
            'check_out'     => $checkOut,
            'guests_count'  => $guests,
            'status'        => $status,
            'source'        => isset(BOOKING_SOURCES[$source]) ? $source : 'other',
            'contact_pref'  => isset(CONTACT_PREFS[$contact]) ? $contact : 'call',
            'total_price'   => $total,
            'price_manual'  => $manualTotal ? 1 : 0,
            'prepayment'    => $prepayment,
            'guest_comment' => trim((string) ($d['comment'] ?? '')) ?: null,
            'admin_note'    => trim((string) ($d['admin_note'] ?? '')) ?: null,
            'created_by'    => $byStaff ? (current_staff()['id'] ?? null) : null,
            'created_at'    => now(),
            'updated_at'    => now(),
        ]);
        audit('booking', $id, 'created', ru_date_range($checkIn, $checkOut) . ', ' . $chosenType['name']
            . ', ' . (BOOKING_STATUSES[$status] ?? $status), $byStaff ? 'staff' : 'guest');
        touch_data_version();
        return $id;
    });
    return booking_get($id);
}

/**
 * Изменить бронь из админки: даты, комнату, гостей, цену, заметки.
 * $d['room_id'] = 0 — подобрать свободную комнату того же типа.
 */
function booking_update(int $id, array $d): array
{
    $old = booking_get($id);
    if (!$old) {
        throw new BookingError('Бронь не найдена');
    }
    $checkIn = (string) $d['check_in'];
    $checkOut = (string) $d['check_out'];
    booking_validate_dates($checkIn, $checkOut, true);

    $roomId = (int) ($d['room_id'] ?? 0);
    $typeId = (int) ($d['room_type_id'] ?? $old['room_type_id']);
    if ($roomId) {
        $room = q_one('SELECT * FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            throw new BookingError('Такой комнаты нет', 'room_id');
        }
        $typeId = (int) $room['room_type_id'];
    }
    $type = room_type($typeId);
    if (!$type) {
        throw new BookingError('Такого типа номера нет', 'room_type_id');
    }
    $email = mb_strtolower(trim((string) ($d['email'] ?? '')));
    if (!valid_email($email)) {
        throw new BookingError('Проверьте адрес почты', 'email');
    }
    if (trim((string) ($d['name'] ?? '')) === '') {
        throw new BookingError('Укажите имя гостя', 'name');
    }

    tx(function () use ($old, $id, $d, $checkIn, $checkOut, $roomId, $type, $email) {
        lock_rooms($type['id']);
        $occupying = in_array($old['status'], OCCUPYING_STATUSES, true);
        $newRoom = $roomId;
        if (!$newRoom) {
            // Та же комната, если она подходит, иначе первая свободная.
            $keep = $old['room_id'] && (int) q_val('SELECT room_type_id FROM rooms WHERE id = ?', [$old['room_id']]) === $type['id']
                && (!$occupying || !room_conflict($old['room_id'], $checkIn, $checkOut, $id));
            $newRoom = $keep ? $old['room_id'] : find_free_room($type['id'], $checkIn, $checkOut, $id);
            if (!$newRoom) {
                throw new BookingError('Свободных комнат этого типа на эти даты нет', 'room_id');
            }
        } elseif ($occupying && ($conflict = room_conflict($newRoom, $checkIn, $checkOut, $id))) {
            throw new BookingError('Комната занята: ' . conflict_text($conflict), 'room_id');
        }

        // Гость: правим карточку, а если её не было — заводим.
        if ($old['guest_id']) {
            guest_update($old['guest_id'], ['name' => trim($d['name']), 'phone' => (string) ($d['phone'] ?? ''), 'email' => $email,
                'note' => q_val('SELECT note FROM guests WHERE id = ?', [$old['guest_id']])]);
            $guestId = $old['guest_id'];
        } else {
            $guestId = guest_upsert(trim($d['name']), (string) ($d['phone'] ?? ''), $email);
        }

        $manual = !empty($d['price_manual']);
        if ($manual) {
            $total = max(0, (int) $d['total_price']);
        } else {
            $total = quote($type, $checkIn, $checkOut)['total'];
        }
        $row = [
            'guest_id'      => $guestId,
            'room_type_id'  => $type['id'],
            'room_id'       => $newRoom,
            'check_in'      => $checkIn,
            'check_out'     => $checkOut,
            'guests_count'  => max(1, min(20, (int) ($d['guests'] ?? $old['guests_count']))),
            'source'        => isset(BOOKING_SOURCES[$d['source'] ?? '']) ? $d['source'] : $old['source'],
            'contact_pref'  => isset(CONTACT_PREFS[$d['contact_pref'] ?? '']) ? $d['contact_pref'] : $old['contact_pref'],
            'total_price'   => $total,
            'price_manual'  => $manual ? 1 : 0,
            'prepayment'    => max(0, (int) ($d['prepayment'] ?? $old['prepayment'])),
            'guest_comment' => trim((string) ($d['comment'] ?? '')) ?: null,
            'admin_note'    => trim((string) ($d['admin_note'] ?? '')) ?: null,
            'updated_at'    => now(),
        ];
        q_update('bookings', $id, $row);

        $changes = [];
        if ($old['check_in'] !== $checkIn || $old['check_out'] !== $checkOut) {
            $changes[] = 'даты: ' . ru_date_range($old['check_in'], $old['check_out']) . ' → ' . ru_date_range($checkIn, $checkOut);
        }
        if ($old['room_id'] !== $newRoom) {
            $changes[] = 'комната: ' . ($old['room_name'] ?? '—') . ' → ' . q_val('SELECT name FROM rooms WHERE id = ?', [$newRoom]);
        }
        if ($old['total_price'] !== $total) {
            $changes[] = 'сумма: ' . ($old['total_price'] === null ? '—' : money($old['total_price'])) . ' → ' . ($total === null ? 'по договорённости' : money($total));
        }
        audit('booking', $id, 'updated', $changes ? implode('; ', $changes) : 'изменены данные');
        touch_data_version();
    });
    return booking_get($id);
}

/**
 * Сменить статус. При «Выехал» раньше даты выезда дата выезда переносится
 * на сегодня — комната освобождается, а отчёт считает реальные ночи.
 */
function booking_set_status(int $id, string $status, string $reason = ''): array
{
    if (!isset(BOOKING_STATUSES[$status])) {
        throw new BookingError('Неизвестный статус');
    }
    tx(function () use ($id, $status, $reason) {
        $b = booking_get($id);
        if (!$b) {
            throw new BookingError('Бронь не найдена');
        }
        lock_rooms($b['room_type_id']);
        $row = ['status' => $status, 'updated_at' => now()];
        $wasOccupying = in_array($b['status'], OCCUPYING_STATUSES, true);
        $willOccupy = in_array($status, OCCUPYING_STATUSES, true);
        if (!$wasOccupying && $willOccupy) {
            if (!$b['room_id']) {
                throw new BookingError('У брони нет комнаты — выберите её в карточке брони');
            }
            if ($conflict = room_conflict($b['room_id'], $b['check_in'], $b['check_out'], $id)) {
                throw new BookingError('Вернуть бронь нельзя — комната уже занята: ' . conflict_text($conflict));
            }
        }
        $details = BOOKING_STATUSES[$b['status']] . ' → ' . BOOKING_STATUSES[$status];
        if ($status === 'cancelled') {
            $row['cancel_reason'] = mb_substr(trim($reason), 0, 255);
            if ($row['cancel_reason'] !== '') {
                $details .= ' (' . $row['cancel_reason'] . ')';
            }
        }
        if ($status === 'checked_out') {
            $today = today()->format('Y-m-d');
            if ($today > $b['check_in'] && $today < $b['check_out']) {
                $row['check_out'] = $today;
                if (!$b['price_manual']) {
                    $row['total_price'] = quote(room_type($b['room_type_id']), $b['check_in'], $today)['total'];
                }
                $details .= ', выезд раньше: ' . ru_date($today);
            }
        }
        q_update('bookings', $id, $row);
        audit('booking', $id, 'status', $details);
        touch_data_version();
    });
    return booking_get($id);
}

// -------------------------------------------------------------------
// Оплаты
// -------------------------------------------------------------------

function booking_payments(int $bookingId): array
{
    return q_all('SELECT p.*, s.name AS staff_name FROM payments p LEFT JOIN staff s ON s.id = p.created_by WHERE p.booking_id = ? ORDER BY p.paid_on, p.id', [$bookingId]);
}

function payment_add(int $bookingId, int $amount, string $method, string $date, string $note): void
{
    if ($amount === 0) {
        throw new BookingError('Укажите сумму', 'amount');
    }
    if (!parse_date($date)) {
        throw new BookingError('Укажите дату оплаты', 'paid_on');
    }
    q_insert('payments', [
        'booking_id' => $bookingId,
        'amount'     => $amount,
        'method'     => isset(PAYMENT_METHODS[$method]) ? $method : 'other',
        'paid_on'    => $date,
        'note'       => mb_substr(trim($note), 0, 255),
        'created_by' => current_staff()['id'] ?? null,
        'created_at' => now(),
    ]);
    audit('booking', $bookingId, $amount > 0 ? 'payment' : 'refund', money(abs($amount)) . ', ' . (PAYMENT_METHODS[$method] ?? $method) . ($note !== '' ? ', ' . $note : ''));
}

function payment_delete(int $paymentId): void
{
    $p = q_one('SELECT * FROM payments WHERE id = ?', [$paymentId]);
    if (!$p) {
        return;
    }
    q('DELETE FROM payments WHERE id = ?', [$paymentId]);
    audit('booking', (int) $p['booking_id'], 'payment_deleted', money((int) $p['amount']) . ' от ' . ru_date($p['paid_on']));
}

// -------------------------------------------------------------------
// Сообщения гостя администратору
// -------------------------------------------------------------------

function booking_messages(int $bookingId): array
{
    return q_all('SELECT m.*, s.name AS staff_name FROM booking_messages m LEFT JOIN staff s ON s.id = m.handled_by WHERE m.booking_id = ? ORDER BY m.id DESC', [$bookingId]);
}

function message_add(array $b, string $kind, string $text, ?string $newCheckOut): int
{
    if (!isset(MESSAGE_KINDS[$kind])) {
        throw new BookingError('Выберите, что нужно сделать', 'kind');
    }
    $text = mb_substr(trim($text), 0, 1000);
    if ($kind === 'extend') {
        if (!$newCheckOut || !parse_date($newCheckOut) || $newCheckOut <= $b['check_out']) {
            throw new BookingError('Новая дата выезда должна быть позже ' . ru_date($b['check_out']), 'new_check_out');
        }
    } else {
        $newCheckOut = null;
    }
    if ($kind !== 'extend' && $text === '') {
        throw new BookingError('Напишите, что нужно', 'message');
    }
    $id = q_insert('booking_messages', [
        'booking_id'    => $b['id'],
        'kind'          => $kind,
        'message'       => $text !== '' ? $text : null,
        'new_check_out' => $newCheckOut,
        'created_at'    => now(),
    ]);
    audit('booking', $b['id'], 'guest_message', MESSAGE_KINDS[$kind] . ($newCheckOut ? ' до ' . ru_date($newCheckOut) : '') . ($text !== '' ? ': ' . $text : ''), 'guest');
    touch_data_version();
    return $id;
}

function message_mark_handled(int $messageId): void
{
    $m = q_one('SELECT * FROM booking_messages WHERE id = ?', [$messageId]);
    if ($m && !$m['handled_at']) {
        q('UPDATE booking_messages SET handled_at = ?, handled_by = ? WHERE id = ?', [now(), current_staff()['id'] ?? null, $messageId]);
        audit('booking', (int) $m['booking_id'], 'message_handled', MESSAGE_KINDS[$m['kind']] ?? $m['kind']);
        touch_data_version();
    }
}

// -------------------------------------------------------------------
// Закрытие комнат (ремонт, свои нужды)
// -------------------------------------------------------------------

function closure_add(int $roomId, string $from, string $to, string $reason): int
{
    booking_validate_dates($from, $to, true);
    return tx(function () use ($roomId, $from, $to, $reason) {
        $room = q_one('SELECT * FROM rooms WHERE id = ?', [$roomId]);
        if (!$room) {
            throw new BookingError('Такой комнаты нет', 'room_id');
        }
        lock_rooms((int) $room['room_type_id']);
        if ($conflict = room_conflict($roomId, $from, $to)) {
            throw new BookingError('В эти даты комната занята: ' . conflict_text($conflict));
        }
        $id = q_insert('room_closures', [
            'room_id' => $roomId, 'date_from' => $from, 'date_to' => $to,
            'reason' => mb_substr(trim($reason), 0, 255), 'source' => 'manual',
            'created_by' => current_staff()['id'] ?? null, 'created_at' => now(),
        ]);
        audit('room', $roomId, 'closed', ru_date_range($from, $to) . ($reason !== '' ? ': ' . $reason : ''));
        touch_data_version();
        return $id;
    });
}

function closure_delete(int $id): void
{
    $c = q_one('SELECT * FROM room_closures WHERE id = ?', [$id]);
    if ($c) {
        q('DELETE FROM room_closures WHERE id = ?', [$id]);
        audit('room', (int) $c['room_id'], 'opened', ru_date_range($c['date_from'], $c['date_to']));
        touch_data_version();
    }
}
