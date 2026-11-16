<?php
/**
 * Почтовый центр админки: журнал, IMAP-синхронизация и безопасные шаблоны.
 * Пароль IMAP берётся из secrets.php; если imap_pass пуст, используется smtp_pass.
 */

function admin_mail_record_sent(string $to, string $subject, string $body, bool $ok, string $error = ''): void
{
    try {
        q_insert('admin_mail_messages', [
            'direction' => 'out',
            'email' => mb_strtolower(trim($to)),
            'subject' => mb_substr($subject, 0, 255),
            'body' => $body,
            'status' => $ok ? 'accepted' : 'failed',
            'error_text' => $ok ? null : mb_substr($error, 0, 500),
            'staff_id' => current_staff()['id'] ?? null,
            'created_at' => now(),
        ]);
    } catch (Throwable $e) {
        error_log('admin mail log: ' . $e->getMessage());
    }
}

function admin_mail_decode_header(string $value): string
{
    if (!function_exists('imap_mime_header_decode')) return $value;
    $parts = @imap_mime_header_decode($value);
    if (!is_array($parts)) return $value;
    $out = '';
    foreach ($parts as $part) {
        $charset = strtoupper((string) ($part->charset ?? 'UTF-8'));
        $chunk = (string) ($part->text ?? '');
        if ($charset !== 'DEFAULT' && $charset !== 'UTF-8') {
            $chunk = @iconv($charset, 'UTF-8//IGNORE', $chunk) ?: $chunk;
        }
        $out .= $chunk;
    }
    return $out;
}

function admin_mail_part_body($imap, int $number, $structure, string $partNo = ''): string
{
    if (isset($structure->parts) && is_array($structure->parts)) {
        foreach ($structure->parts as $i => $part) {
            $n = $partNo === '' ? (string) ($i + 1) : $partNo . '.' . ($i + 1);
            $body = admin_mail_part_body($imap, $number, $part, $n);
            if ($body !== '') return $body;
        }
        return '';
    }
    $type = (int) ($structure->type ?? 0);
    $subtype = strtoupper((string) ($structure->subtype ?? ''));
    if ($type !== 0 || $subtype !== 'PLAIN') return '';
    $raw = @imap_fetchbody($imap, $number, $partNo === '' ? '1' : $partNo);
    if ($raw === false) return '';
    $encoding = (int) ($structure->encoding ?? 0);
    if ($encoding === 3) $raw = base64_decode($raw, true) ?: '';
    elseif ($encoding === 4) $raw = quoted_printable_decode($raw);
    $charset = '';
    foreach (($structure->parameters ?? []) as $p) {
        if (strtolower((string) ($p->attribute ?? '')) === 'charset') $charset = (string) $p->value;
    }
    if ($charset !== '' && strtoupper($charset) !== 'UTF-8') {
        $raw = @iconv($charset, 'UTF-8//IGNORE', $raw) ?: $raw;
    }
    return mb_substr(trim(strip_tags($raw)), 0, 30000);
}

/** Синхронизирует последние 40 писем из INBOX. Требует PHP-расширение IMAP. */
function admin_mail_sync_inbox(): array
{
    if (!function_exists('imap_open')) {
        return ['ok' => false, 'message' => 'На хостинге не включено PHP-расширение IMAP. Входящие письма пока недоступны; отправка и журнал исходящих работают.'];
    }
    $s = hotel_secrets();
    $host = trim((string) ($s['imap_host'] ?? 'imap.timeweb.ru'));
    $port = (int) ($s['imap_port'] ?? 993);
    $user = trim((string) ($s['imap_user'] ?? $s['smtp_user']));
    $pass = (string) (($s['imap_pass'] ?? '') !== '' ? $s['imap_pass'] : $s['smtp_pass']);
    if ($host === '' || $user === '' || $pass === '') {
        return ['ok' => false, 'message' => 'Заполните IMAP-настройки в config/secrets.php. Пароль не присылайте в чат.'];
    }
    $imap = @imap_open('{' . $host . ':' . $port . '/imap/ssl}INBOX', $user, $pass, OP_READONLY, 1);
    if (!$imap) return ['ok' => false, 'message' => 'Не удалось подключиться к IMAP: ' . mb_substr((string) imap_last_error(), 0, 240)];
    $uids = @imap_search($imap, 'ALL', SE_UID) ?: [];
    $uids = array_slice($uids, -40);
    $added = 0;
    foreach ($uids as $uid) {
        $overview = @imap_fetch_overview($imap, (string) $uid, FT_UID);
        $ov = $overview[0] ?? null;
        if (!$ov) continue;
        $msgId = trim((string) ($ov->message_id ?? ''));
        if ($msgId !== '' && q_val('SELECT id FROM admin_mail_messages WHERE message_id = ?', [$msgId])) continue;
        if (q_val('SELECT id FROM admin_mail_messages WHERE mailbox_uid = ?', [(int) $uid])) continue;
        $number = @imap_msgno($imap, (int) $uid);
        if (!$number) continue;
        $structure = @imap_fetchstructure($imap, $number);
        $body = $structure ? admin_mail_part_body($imap, $number, $structure) : '';
        $from = admin_mail_decode_header((string) ($ov->from ?? ''));
        $email = '';
        if (preg_match('/[A-Z0-9._%+\-]+@[A-Z0-9.\-]+\.[A-Z]{2,}/i', $from, $m)) $email = strtolower($m[0]);
        if ($email === '') $email = mb_substr($from, 0, 190);
        try {
            q_insert('admin_mail_messages', [
                'direction' => 'in', 'email' => $email,
                'subject' => mb_substr(admin_mail_decode_header((string) ($ov->subject ?? '')), 0, 255),
                'body' => $body, 'status' => 'received',
                'message_id' => $msgId !== '' ? $msgId : null, 'mailbox_uid' => (int) $uid,
                'created_at' => date('Y-m-d H:i:s', strtotime((string) ($ov->date ?? 'now')) ?: time()),
            ]);
            $added++;
        } catch (Throwable $e) {
            // Дубликаты пропускаем; остальные ошибки фиксируем в error_log.
            error_log('admin IMAP import: ' . $e->getMessage());
        }
    }
    imap_close($imap);
    return ['ok' => true, 'message' => 'Входящие обновлены. Добавлено писем: ' . $added . '.'];
}
