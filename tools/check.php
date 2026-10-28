<?php
/**
 * Проверка готовности сайта к запуску: заглушки в [квадратных скобках]
 * в конфиге и текстах, доступ к базе и почте, номера без фото и цен,
 * сотрудники, фоновые задачи.
 *
 *     php tools/check.php
 *
 * Код выхода 1, если что-то не готово, — удобно вставить в деплой.
 */

require __DIR__ . '/../src/bootstrap.php';

$problems = [];
$notes = [];
$add = function (string $where, string $what) use (&$problems) {
    $problems[] = sprintf('  %-34s %s', $where, $what);
};
// Необязательное — показываем, но запуск не блокирует.
$note = function (string $where, string $what) use (&$notes) {
    $notes[] = sprintf('  %-34s %s', $where, $what);
};

$walk = function (array $data, string $path) use (&$walk, $add) {
    foreach ($data as $key => $value) {
        $p = $path === '' ? (string) $key : $path . '.' . $key;
        if (is_array($value)) {
            $walk($value, $p);
        } elseif (is_string($value) && preg_match('/\[[^\]]*\]/u', $value)) {
            $add($p, $value);
        }
    }
};
$walk(hotel(), '');

if (!site_indexing()) {
    $add('indexing', 'сайт закрыт от поисковиков — перед запуском поставьте true');
}
if (metrika_id() === '') {
    $note('analytics.metrika_id', 'Яндекс Метрика не подключена');
}
if ((hotel()['analytics']['yandex_verification'] ?? '') === '') {
    $note('analytics.yandex_verification', 'сайт не подтверждён в Яндекс Вебмастере');
}

if (!has_coords()) {
    $add('address.lat / address.lon', 'нет координат — на сайте не будет карты');
}

// Почта
$s = hotel_secrets();
if (!is_file(HOTEL_ROOT . '/config/secrets.php')) {
    $add('config/secrets.php', 'нет файла — база и почта по умолчанию Open Server (письма не отправляются)');
} elseif ($s['mail_transport'] === 'log') {
    $add('config/secrets.php', "mail_transport = 'log' — письма не отправляются, а пишутся в storage/mail.log");
} elseif ($s['mail_transport'] === 'smtp' && ($s['smtp_user'] === '' || $s['smtp_pass'] === '' || $s['smtp_host'] === '')) {
    $add('config/secrets.php', 'для SMTP не заполнены smtp_host / smtp_user / smtp_pass');
}
if ($s['mail_transport'] !== 'log' && !filter_var($s['mail_from'] ?: $s['smtp_user'], FILTER_VALIDATE_EMAIL)) {
    $add('config/secrets.php', 'не указан mail_from — обратный адрес писем');
}

// База и то, что настраивается в админке
if (!db_ready()) {
    $add('база данных', 'нет подключения или таблиц — проверьте config/secrets.php и выполните php tools/migrate.php');
} else {
    if (!(int) q_val("SELECT COUNT(*) FROM staff WHERE role = 'owner' AND is_active = 1")) {
        $add('админка', 'нет владельца — откройте /admin/ или выполните php tools/admin.php add');
    } elseif (!admin_recipients()) {
        $add('админка → Сотрудники', 'никто не получает письма о новых заявках');
    }
    foreach (room_types(true) as $t) {
        $where = 'админка → Номера: ' . $t['name'];
        if ($t['price_tbd']) {
            $add($where, 'цена не утверждена');
        }
        if (!$t['photos']) {
            $add($where, 'нет фото');
        }
        if (!rooms_list(true, $t['id'])) {
            $add($where, 'нет ни одной комнаты — на сайте не бронируется');
        }
    }
    foreach (stay_discounts() as $d) {
        if (preg_match('/\[[^\]]*\]/u', $d['note'] . $d['label'])) {
            $add('админка → Цены: скидки', $d['label'] . ' — ' . $d['note']);
        }
    }
    foreach (['hero' => 'первый экран', 'bath' => 'баня', 'hammam' => 'хамам'] as $slot => $label) {
        if (!setting('photo_' . $slot)) {
            $add('админка → Номера и фото', 'нет фото «' . $label . '» на главной');
        }
    }
    $last = (int) setting('cron_last_run', '0');
    if ($last && time() - $last > 86400) {
        $add('tools/cron.php', 'фоновые задачи не запускались больше суток — добавьте в планировщик хостинга');
    }
}

foreach (['storage', 'public/uploads'] as $dir) {
    if (!is_dir(HOTEL_ROOT . '/' . $dir) || !is_writable(HOTEL_ROOT . '/' . $dir)) {
        $add($dir, 'папка недоступна для записи');
    }
}

// Заглушки в текстах страниц (правила, политика, вопросы на главной).
foreach (['index.php', 'rules.php', 'privacy.php', 'consent.php'] as $file) {
    $lines = file(HOTEL_ROOT . '/public/' . $file);
    foreach ($lines as $n => $line) {
        // Пропускаем PHP-код и комментарии — нас интересует текст для гостей.
        $text = trim(strip_tags(preg_replace('/<\?(php|=).*?\?>/s', '', $line)));
        // Заглушка — любой текст в скобках: [N], [дата], [8:00]; но не индекс массива вроде $a[0].
        if (preg_match_all('/\[(?!\d+\])[^\]\$\'"]+\]/u', $text, $m)) {
            foreach ($m[0] as $hit) {
                $add('public/' . $file . ':' . ($n + 1), $hit);
            }
        }
    }
}

if ($notes) {
    echo "Можно сделать позже (" . count($notes) . "):\n\n" . implode("\n", $notes) . "\n\n";
}
if ($problems) {
    echo "Перед запуском заполните (" . count($problems) . "):\n\n" . implode("\n", $problems) . "\n";
    exit(1);
}
echo "Всё заполнено — можно запускать.\n";
