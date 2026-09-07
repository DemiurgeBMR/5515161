<?php
session_start();
require_once __DIR__ . '/../config.php';
require_once __DIR__ . '/../includes/history_data.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['operator', 'owner'], true)) {
    header('Location: /pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$role = $_SESSION['user_role'];
$pdo = getDbConnection();

$filters = [
    'date_from'   => $_GET['date_from'] ?? '',
    'date_to'     => $_GET['date_to'] ?? '',
    'location_id' => $_GET['location_id'] ?? '',
    'event_type'  => $_GET['event_type'] ?? '',
    'source'      => $_GET['source'] ?? '',
];
$format = $_GET['format'] ?? 'csv';

$rows = getServiceHistory($pdo, $user_id, $role, $filters);

// Контрагент — то, что реально интересно смотрящему: владельцу интересен
// оператор, оператору интересен владелец. Своё же имя в каждой строке —
// чистый информационный мусор, поэтому в экспорт идёт только одна колонка.
$counterpartLabel = ($role === 'owner') ? 'Оператор' : 'Владелец';
function counterpartName($row, $role) {
    return $role === 'owner' ? $row['operator_name'] : $row['owner_name'];
}

// Защита от CSV/formula injection: location_title, комментарий и имя
// контрагента — свободный текст пользователей. Если такое поле начинается
// с символа, который Excel/Sheets трактуют как начало формулы (=+-@),
// открытие экспортированного файла может выполнить произвольную формулу
// на машине того, кто его открыл. Ведущий апостроф нейтрализует это.
function csvSafe($value) {
    $value = (string)$value;
    if ($value !== '' && strpbrk($value[0], "=+-@") !== false) {
        return "'" . $value;
    }
    return $value;
}

// ===== CSV =====
if ($format === 'csv') {
    header('Content-Type: text/csv; charset=UTF-8');
    header('Content-Disposition: attachment; filename="service_history_' . date('Y-m-d') . '.csv"');

    $out = fopen('php://output', 'w');
    fwrite($out, "\xEF\xBB\xBF"); // BOM — иначе Excel показывает кириллицу кракозябрами

    $header = ['Дата', 'Точка', 'Тип', 'Источник', $counterpartLabel, 'Срочно', 'Комментарий', 'Фото'];
    fputcsv($out, $header, ';', '"', '\\');

    foreach ($rows as $row) {
        fputcsv($out, [
            date('d.m.Y H:i', strtotime($row['event_date'])),
            csvSafe($row['location_title'] . ' (' . $row['city'] . ')'),
            serviceEventTypeLabel($row['event_type']),
            $row['source_type'] === 'log' ? 'Постфактум' : 'Согласовано',
            csvSafe(counterpartName($row, $role)),
            $row['is_emergency'] ? 'Да' : '',
            csvSafe($row['comment'] ?? ''),
            !empty($row['photos']) ? count($row['photos']) . ' фото' : '',
        ], ';', '"', '\\');
    }

    fclose($out);
    exit;
}

// ===== PDF =====
if ($format === 'pdf') {
    // Требуется библиотека tFPDF (Unicode-версия FPDF — обычный FPDF не умеет кириллицу).
    // Скачать: https://github.com/dejanmarkovic/tFPDF
    // Файлы положить в:
    //   /includes/tfpdf/tfpdf.php
    //   /includes/tfpdf/font/DejaVuSans.ttf
    $tfpdfPath = __DIR__ . '/../includes/tfpdf/tfpdf.php';

    if (!file_exists($tfpdfPath)) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>PDF недоступен</title></head><body style="font-family:sans-serif; max-width:600px; margin:60px auto; padding:0 20px;">';
        echo '<h2>⚠️ Экспорт в PDF пока не настроен</h2>';
        echo '<p>Для генерации PDF на сервере нужна библиотека <b>tFPDF</b> (умеет кириллицу, в отличие от обычного FPDF).</p>';
        echo '<ol>';
        echo '<li>Скачай архив: <a href="https://github.com/dejanmarkovic/tFPDF" target="_blank">github.com/dejanmarkovic/tFPDF</a></li>';
        echo '<li>Положи файл <code>tfpdf.php</code> и папку <code>font/</code> (с DejaVuSans.ttf) в <code>/includes/tfpdf/</code> на сервере</li>';
        echo '<li>Обнови страницу</li>';
        echo '</ol>';
        echo '<p><a href="javascript:history.back()">← Назад</a></p>';
        echo '</body></html>';
        exit;
    }

    require_once $tfpdfPath;

    if (!class_exists('tFPDF')) {
        http_response_code(500);
        echo '<!DOCTYPE html><html lang="ru"><head><meta charset="UTF-8"><title>PDF недоступен</title></head><body style="font-family:sans-serif; max-width:600px; margin:60px auto; padding:0 20px;">';
        echo '<h2>⚠️ Файл tfpdf.php подключился, но класс tFPDF не определился</h2>';
        echo '<p>Обычно это значит, что скачался не сам PHP-код, а HTML-страница GitHub — открой файл в блокноте: он должен начинаться с <code>&lt;?php</code>, а не с <code>&lt;!DOCTYPE html&gt;</code>.</p>';
        echo '<p><a href="javascript:history.back()">← Назад</a></p>';
        echo '</body></html>';
        exit;
    }

    $pdf = new tFPDF();
    $pdf->AddPage('L'); // альбомная — таблица широкая
    $pdf->AddFont('DejaVu', '', 'DejaVuSans.ttf', true);

    $pdf->SetFont('DejaVu', '', 16);
    $pdf->Cell(0, 12, 'История обслуживания', 0, 1);
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetTextColor(107, 114, 128);
    $pdf->Cell(0, 6, 'Сформировано: ' . date('d.m.Y H:i') . '  ·  записей: ' . count($rows), 0, 1);
    $pdf->SetTextColor(0, 0, 0);
    $pdf->Ln(4);

    // Ширины подобраны под альбомный A4 (~277мм рабочей ширины) с запасом.
    // "Тип" расширен — самое частое и самое длинное значение ("Плановое обслуживание").
    $colWidths = [
        'date'        => 30,
        'location'    => 48,
        'type'        => 42,
        'source'      => 24,
        'counterpart' => 32,
        'emergency'   => 16,
        'comment'     => 55,
        'photos'      => 16,
    ];
    $headers = ['Дата', 'Точка', 'Тип', 'Источник', $counterpartLabel, 'Срочно', 'Комментарий', 'Фото'];
    $widthsList = array_values($colWidths);
    $cellPadding = 4; // запас на внутренние отступы ячейки

    // Обрезает текст ТОЧНО по реальной ширине символов в текущем шрифте —
    // применяется вообще ко всем колонкам без исключения, чтобы наложение
    // текста было в принципе невозможно, независимо от длины значения.
    $fitText = function($text, $maxWidthMm) use ($pdf) {
        $text = (string)$text;
        if ($pdf->GetStringWidth($text) <= $maxWidthMm) {
            return $text;
        }
        $ellipsis = '…';
        while (mb_strlen($text) > 0 && $pdf->GetStringWidth($text . $ellipsis) > $maxWidthMm) {
            $text = mb_substr($text, 0, -1);
        }
        return $text . $ellipsis;
    };

    // Шапка — цвет бренда, белый текст, чтобы реально читалась как шапка
    $pdf->SetFont('DejaVu', '', 9);
    $pdf->SetFillColor(233, 69, 96);
    $pdf->SetTextColor(255, 255, 255);
    foreach ($headers as $i => $h) {
        $pdf->Cell($widthsList[$i], 9, $fitText($h, $widthsList[$i] - $cellPadding), 1, 0, 'L', true);
    }
    $pdf->Ln();
    $pdf->SetTextColor(0, 0, 0);

    $pdf->SetFont('DejaVu', '', 8);
    $rowHeight = 7;
    foreach ($rows as $rowIndex => $row) {
        // Зебра — чётные строки чуть темнее, чтобы широкую таблицу было проще читать построчно
        $isEven = ($rowIndex % 2 === 1);
        if ($isEven) {
            $pdf->SetFillColor(249, 250, 251);
        } else {
            $pdf->SetFillColor(255, 255, 255);
        }

        $locationText = $row['location_title'] . ' (' . $row['city'] . ')';
        $cells = [
            'date'        => date('d.m.Y H:i', strtotime($row['event_date'])),
            'location'    => $locationText,
            'type'        => serviceEventTypeLabel($row['event_type']),
            'source'      => $row['source_type'] === 'log' ? 'Постфактум' : 'Согласовано',
            'counterpart' => counterpartName($row, $role),
            'emergency'   => $row['is_emergency'] ? 'Да' : '',
            'comment'     => $row['comment'] ?? '',
            'photos'      => !empty($row['photos']) ? count($row['photos']) . ' шт.' : '',
        ];

        foreach ($cells as $key => $value) {
            $width = $colWidths[$key];
            $displayText = $fitText($value, $width - $cellPadding);
            if ($key === 'emergency' && $value === 'Да') {
                $pdf->SetTextColor(220, 38, 38);
            }
            $pdf->Cell($width, $rowHeight, $displayText, 1, 0, 'L', true);
            if ($key === 'emergency') {
                $pdf->SetTextColor(0, 0, 0);
            }
        }
        $pdf->Ln();
    }

    $pdf->Output('D', 'service_history_' . date('Y-m-d') . '.pdf');
    exit;
}

http_response_code(400);
echo 'Неизвестный формат экспорта';