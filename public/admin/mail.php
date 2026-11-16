<?php
/**
 * Почтовый центр: входящие (IMAP), журнал исходящих, шаблоны и рассылки.
 * Рекламная рассылка отправляется индивидуально только гостям с согласием.
 */
require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();
$tab = get('tab', 'inbox');
$allowedTabs = ['inbox', 'sent', 'templates', 'campaign', 'compose'];
if (!in_array($tab, $allowedTabs, true)) $tab = 'inbox';
$notice = '';

if (is_post()) {
    csrf_check();
    $action = post('action');
    try {
        if ($action === 'sync') {
            $result = admin_mail_sync_inbox();
            flash($result['ok'] ? 'ok' : 'error', $result['message']);
            redirect('/admin/mail.php?tab=inbox');
        }
        if ($action === 'template_save') {
            $id = (int) post('template_id');
            $name = mb_substr(trim(post('name')), 0, 160);
            $subject = mb_substr(trim(post('subject')), 0, 255);
            $body = trim(post('body'));
            if ($name === '' || $subject === '' || $body === '') throw new RuntimeException('Заполните название, тему и текст шаблона.');
            if ($id) {
                q('UPDATE admin_mail_templates SET name = ?, subject = ?, body = ?, updated_at = ? WHERE id = ?', [$name, $subject, $body, now(), $id]);
                flash('ok', 'Шаблон обновлён.');
            } else {
                q_insert('admin_mail_templates', ['name' => $name, 'subject' => $subject, 'body' => $body, 'created_by' => (int) $me['id'], 'created_at' => now(), 'updated_at' => now()]);
                flash('ok', 'Шаблон сохранён.');
            }
            redirect('/admin/mail.php?tab=templates');
        }
        if ($action === 'template_delete') {
            q('DELETE FROM admin_mail_templates WHERE id = ?', [(int) post('template_id')]);
            flash('ok', 'Шаблон удалён.');
            redirect('/admin/mail.php?tab=templates');
        }
        if ($action === 'send_one') {
            $to = mb_strtolower(trim(post('to')));
            $subject = trim(post('subject'));
            $body = trim(post('body'));
            if (!filter_var($to, FILTER_VALIDATE_EMAIL) || $subject === '' || $body === '') throw new RuntimeException('Проверьте адрес, тему и текст письма.');
            $ok = send_mail($to, $subject, $body);
            flash($ok ? 'ok' : 'error', $ok ? 'Почтовый сервер принял письмо к отправке.' : 'Не удалось отправить письмо. Проверьте настройки и журнал.');
            redirect('/admin/mail.php?tab=sent');
        }
        if ($action === 'send_campaign') {
            $template = q_one('SELECT * FROM admin_mail_templates WHERE id = ?', [(int) post('template_id')]);
            if (!$template) throw new RuntimeException('Выберите сохранённый шаблон.');
            $ids = $_POST['guest_ids'] ?? [];
            if (!is_array($ids)) $ids = [];
            $ids = array_slice(array_values(array_unique(array_filter(array_map('intval', $ids)))), 0, 50);
            if (!$ids) throw new RuntimeException('Выберите хотя бы одного гостя с согласием на рассылку.');
            $sent = 0; $failed = 0; $skipped = 0;
            foreach ($ids as $guestId) {
                $g = q_one('SELECT id, name, email, marketing_unsubscribe_token FROM guests WHERE id = ? AND marketing_consent = 1 AND anonymized_at IS NULL', [$guestId]);
                if (!$g || !filter_var($g['email'], FILTER_VALIDATE_EMAIL) || !$g['marketing_unsubscribe_token']) { $skipped++; continue; }
                $subject = str_replace(['{{name}}', '{{hotel}}'], [$g['name'], mail_hotel_name()], $template['subject']);
                $body = str_replace(['{{name}}', '{{hotel}}'], [$g['name'], mail_hotel_name()], $template['body']);
                $body .= "\n\nОтказаться от рассылки: " . base_url() . '/unsubscribe.php?token=' . rawurlencode($g['marketing_unsubscribe_token']);
                if (send_mail($g['email'], $subject, $body)) $sent++; else $failed++;
                usleep(150000);
            }
            audit('settings', null, 'mail_campaign', 'шаблон #' . (int) $template['id'] . '; отправлено: ' . $sent . '; ошибки: ' . $failed . '; пропущено: ' . $skipped);
            flash($failed ? 'error' : 'ok', 'Рассылка завершена. Принято сервером: ' . $sent . '; ошибок: ' . $failed . '; пропущено: ' . $skipped . '.');
            redirect('/admin/mail.php?tab=sent');
        }
    } catch (Throwable $e) {
        flash('error', $e->getMessage());
        redirect('/admin/mail.php?tab=' . rawurlencode($tab));
    }
}

$viewId = (int) get('view');
$view = $viewId ? q_one('SELECT * FROM admin_mail_messages WHERE id = ?', [$viewId]) : null;
$editId = (int) get('edit');
$editTemplate = $editId ? q_one('SELECT * FROM admin_mail_templates WHERE id = ?', [$editId]) : null;
$templates = q_all('SELECT * FROM admin_mail_templates ORDER BY updated_at DESC, id DESC');
$messages = [];
if (in_array($tab, ['inbox', 'sent'], true)) {
    $direction = $tab === 'inbox' ? 'in' : 'out';
    $messages = q_all('SELECT * FROM admin_mail_messages WHERE direction = ? ORDER BY created_at DESC, id DESC LIMIT 100', [$direction]);
}
$consentingGuests = $tab === 'campaign' ? q_all("SELECT id, name, email FROM guests WHERE marketing_consent = 1 AND anonymized_at IS NULL AND email <> '' ORDER BY name LIMIT 500") : [];

admin_header('Почта', 'mail');
?>
<div class="adm-head">
    <div><h1>Почта гостиницы</h1><p class="adm-head__sub">info@hotel-neapol.ru · отправка через SMTP Timeweb</p></div>
    <div class="adm-actions"><a class="btn btn--primary btn--sm" href="/admin/mail.php?tab=compose"><?= icon('pencil') ?>Написать письмо</a></div>
</div>
<nav class="tabs" aria-label="Почта">
    <a class="tab<?= $tab === 'inbox' ? ' is-active' : '' ?>" href="/admin/mail.php?tab=inbox">Входящие</a>
    <a class="tab<?= $tab === 'sent' ? ' is-active' : '' ?>" href="/admin/mail.php?tab=sent">Отправленные</a>
    <a class="tab<?= $tab === 'templates' ? ' is-active' : '' ?>" href="/admin/mail.php?tab=templates">Шаблоны</a>
    <a class="tab<?= $tab === 'campaign' ? ' is-active' : '' ?>" href="/admin/mail.php?tab=campaign">Рассылки</a>
</nav>

<?php if ($view): ?>
<section class="panel">
    <div class="adm-head"><div><h2><?= e($view['subject'] ?: '(без темы)') ?></h2><p class="adm-head__sub"><?= e($view['email']) ?> · <?= e(ru_datetime($view['created_at'])) ?></p></div><a class="btn btn--outline btn--sm" href="/admin/mail.php?tab=<?= $view['direction'] === 'in' ? 'inbox' : 'sent' ?>">Назад</a></div>
    <p class="hint">Статус: <?= e($view['status'] === 'accepted' ? 'принято сервером к отправке' : ($view['status'] === 'received' ? 'получено' : 'ошибка отправки')) ?></p>
    <?php if ($view['error_text']): ?><p class="form-error"><?= e($view['error_text']) ?></p><?php endif; ?>
    <div class="msg"><pre style="white-space:pre-wrap;overflow-wrap:anywhere;font:inherit"><?= e($view['body'] ?? '') ?></pre></div>
    <?php if ($view['direction'] === 'in'): ?><a class="btn btn--primary btn--sm" href="/admin/mail.php?tab=compose&amp;to=<?= rawurlencode($view['email']) ?>&amp;subject=<?= rawurlencode('Re: ' . $view['subject']) ?>">Ответить</a><?php endif; ?>
</section>
<?php elseif ($tab === 'inbox'): ?>
<section class="panel">
    <div class="adm-head"><div><h2 class="panel__title">Входящие письма</h2><p class="hint">Письма синхронизируются из IMAP по запросу. На хостинге должно быть включено PHP-расширение IMAP.</p></div>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="sync"><button class="btn btn--outline btn--sm" type="submit"><?= icon('refresh-cw') ?>Обновить входящие</button></form></div>
    <?php if (!$messages): ?><p class="empty">Пока нет импортированных писем. Нажмите «Обновить входящие».</p><?php else: ?>
    <div class="blist"><?php foreach ($messages as $m): ?><a class="brow" href="/admin/mail.php?tab=inbox&amp;view=<?= (int) $m['id'] ?>"><span class="brow__name"><?= e($m['subject'] ?: '(без темы)') ?></span><span class="status status--confirmed">Получено</span><span class="brow__meta"><?= e($m['email']) ?> · <?= e(ru_datetime($m['created_at'])) ?></span></a><?php endforeach; ?></div>
    <?php endif; ?>
</section>
<?php elseif ($tab === 'sent'): ?>
<section class="panel"><h2 class="panel__title">Исходящие письма</h2><p class="hint">Здесь отображаются письма, отправленные сайтом: подтверждения броней, уведомления, ответы и рассылки. «Принято сервером» не означает, что письмо уже появилось во входящих получателя.</p>
<?php if (!$messages): ?><p class="empty">Отправленных писем пока нет.</p><?php else: ?><div class="blist"><?php foreach ($messages as $m): ?><a class="brow" href="/admin/mail.php?tab=sent&amp;view=<?= (int) $m['id'] ?>"><span class="brow__name"><?= e($m['subject'] ?: '(без темы)') ?></span><span class="status status--<?= $m['status'] === 'accepted' ? 'confirmed' : 'cancelled' ?>"><?= e($m['status'] === 'accepted' ? 'Принято сервером' : 'Ошибка') ?></span><span class="brow__meta"><?= e($m['email']) ?> · <?= e(ru_datetime($m['created_at'])) ?></span></a><?php endforeach; ?></div><?php endif; ?>
</section>
<?php elseif ($tab === 'templates'): ?>
<section class="panel">
    <h2 class="panel__title"><?= $editTemplate ? 'Редактировать шаблон' : 'Новый шаблон письма' ?></h2>
    <form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="template_save"><input type="hidden" name="template_id" value="<?= (int) ($editTemplate['id'] ?? 0) ?>">
        <div class="field span-all"><label>Название шаблона</label><input name="name" required maxlength="160" value="<?= e($editTemplate['name'] ?? '') ?>" placeholder="Например, Сезонное предложение"></div>
        <div class="field span-all"><label>Тема письма</label><input name="subject" required maxlength="255" value="<?= e($editTemplate['subject'] ?? '') ?>"></div>
        <div class="field span-all"><label>Текст письма</label><textarea name="body" required rows="9"><?= e($editTemplate['body'] ?? '') ?></textarea><small class="hint">Переменные: {{name}} — имя гостя, {{hotel}} — название гостиницы. Для рассылок ссылка отписки добавляется автоматически.</small></div>
        <div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit">Сохранить шаблон</button></div>
    </form>
</section>
<section class="panel"><h2 class="panel__title">Сохранённые шаблоны</h2>
<?php if (!$templates): ?><p class="empty">Шаблонов пока нет.</p><?php else: ?><div class="blist"><?php foreach ($templates as $t): ?><div class="brow"><span class="brow__name"><?= e($t['name']) ?></span><span><?= e($t['subject']) ?></span><span class="brow__meta"><a href="/admin/mail.php?tab=templates&amp;edit=<?= (int) $t['id'] ?>">Изменить</a> · <form method="post" class="inline"><?= csrf_field() ?><input type="hidden" name="action" value="template_delete"><input type="hidden" name="template_id" value="<?= (int) $t['id'] ?>"><button class="link-button" type="submit" data-confirm="Удалить шаблон?">Удалить</button></form></span></div><?php endforeach; ?></div><?php endif; ?>
</section>
<?php elseif ($tab === 'campaign'): ?>
<section class="panel"><h2 class="panel__title">Новая рассылка</h2>
<p class="hint">Рассылка отправляется каждому гостю отдельным письмом — адреса других получателей не раскрываются. Можно выбрать не более 50 гостей за один запуск. В список попадают только гости с зафиксированным согласием.</p>
<?php if (!$templates): ?><p class="empty">Сначала создайте шаблон в разделе «Шаблоны».</p><?php elseif (!$consentingGuests): ?><p class="empty">Нет гостей с согласием на рассылку. Согласие отмечается в карточке гостя.</p><?php else: ?>
<form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="send_campaign">
<div class="field"><label for="campaign-template">Шаблон</label><select id="campaign-template" name="template_id" required><?php foreach ($templates as $t): ?><option value="<?= (int) $t['id'] ?>"><?= e($t['name']) ?> — <?= e($t['subject']) ?></option><?php endforeach; ?></select></div>
<div class="field"><label>Получатели <span class="muted">(не более 50 за отправку)</span></label>
<div class="blist"><?php foreach ($consentingGuests as $g): ?><label class="brow"><span><input type="checkbox" name="guest_ids[]" value="<?= (int) $g['id'] ?>"> <?= e($g['name']) ?></span><span><?= e($g['email']) ?></span></label><?php endforeach; ?></div></div>
<div class="form-actions"><button class="btn btn--primary btn--sm" type="submit" data-confirm="Отправить выбранным гостям? Письма будут отправлены индивидуально.">Отправить рассылку</button></div>
</form><?php endif; ?>
</section>
<?php elseif ($tab === 'compose'): ?>
<section class="panel"><h2 class="panel__title">Новое письмо</h2>
<form method="post" class="form-grid"><?= csrf_field() ?><input type="hidden" name="action" value="send_one">
<div class="field span-all"><label>Кому</label><input type="email" name="to" required value="<?= e(get('to')) ?>"></div>
<div class="field span-all"><label>Тема</label><input name="subject" maxlength="255" required value="<?= e(get('subject')) ?>"></div>
<div class="field span-all"><label>Текст</label><textarea name="body" rows="10" required></textarea></div>
<div class="form-actions span-all"><button class="btn btn--primary btn--sm" type="submit"><?= icon('send') ?>Отправить</button></div>
</form></section>
<?php endif; ?>
<?php admin_footer(); ?>