<?php
/**
 * Проверка готовности сайта к запуску: находит в конфиге заглушки
 * в [квадратных скобках] и пустые обязательные значения.
 *
 *     php tools/check.php
 *
 * Код выхода 1, если что-то не заполнено, — удобно вставить в деплой.
 */

require __DIR__ . '/../src/bootstrap.php';

$problems = [];

$walk = function (array $data, string $path) use (&$walk, &$problems) {
    foreach ($data as $key => $value) {
        $p = $path === '' ? (string) $key : $path . '.' . $key;
        if (is_array($value)) {
            $walk($value, $p);
        } elseif (is_string($value) && preg_match('/\[[^\]]*\]/u', $value)) {
            $problems[] = sprintf('  %-34s %s', $p, $value);
        } elseif (!empty($value) && $key === 'price_tbd') {
            $problems[] = sprintf('  %-34s %s', $p, 'цена помечена как неутверждённая');
        }
    }
};
$walk(hotel(), '');

if (!has_coords()) {
    $problems[] = sprintf('  %-34s %s', 'address.lat / address.lon', 'нет координат — на сайте не будет карты');
}

$secrets = hotel_secrets();
if (!is_file(HOTEL_ROOT . '/config/secrets.php')) {
    $problems[] = '  config/secrets.php                 нет файла — заявки с сайта некуда отправлять';
} elseif (!filter_var($secrets['mail_to'], FILTER_VALIDATE_EMAIL) && $secrets['telegram_bot_token'] === '') {
    $problems[] = '  config/secrets.php                 не указаны ни почта, ни Telegram для заявок';
}

// Заглушки в текстах страниц (правила, политика, вопросы на главной).
foreach (['index.php', 'rules.php', 'privacy.php', 'consent.php', 'robots.txt'] as $file) {
    $lines = file(HOTEL_ROOT . '/public/' . $file);
    foreach ($lines as $n => $line) {
        // Пропускаем PHP-код и комментарии — нас интересует текст для гостей.
        $text = trim(strip_tags(preg_replace('/<\?(php|=).*?\?>/s', '', $line)));
        // Заглушка — любой текст в скобках: [N], [дата], [8:00]; но не индекс массива вроде $a[0].
        if (preg_match_all('/\[(?!\d+\])[^\]\$\'"]+\]/u', $text, $m)) {
            foreach ($m[0] as $hit) {
                $problems[] = sprintf('  %-34s %s', 'public/' . $file . ':' . ($n + 1), $hit);
            }
        }
    }
}

if ($problems) {
    echo "Перед запуском заполните (" . count($problems) . "):\n\n" . implode("\n", $problems) . "\n";
    exit(1);
}
echo "Всё заполнено — можно запускать.\n";
