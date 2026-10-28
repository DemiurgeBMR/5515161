<?php
/**
 * Загрузка фотографий из админки.
 *
 * Любое фото пересохраняется заново: уменьшается до 1600 px по длинной
 * стороне, поворачивается по данным камеры телефона и записывается как
 * JPEG со случайным именем. Так с сайта не утекают геометки из EXIF, а под
 * видом картинки нельзя загрузить исполняемый файл.
 */

const UPLOAD_MAX_BYTES = 15 * 1024 * 1024;
const UPLOAD_MAX_SIDE = 1600;

/**
 * $file — элемент $_FILES. Возвращает ['path' => 'uploads/rooms/….jpg', 'width', 'height'].
 * $fromForm = false — для тестов, когда файл не пришёл из формы.
 */
function upload_photo(array $file, string $subdir, bool $fromForm = true): array
{
    if (($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK) {
        throw new BookingError($file['error'] === UPLOAD_ERR_INI_SIZE || $file['error'] === UPLOAD_ERR_FORM_SIZE
            ? 'Файл слишком большой' : 'Файл не загрузился, попробуйте ещё раз', 'photo');
    }
    $tmp = (string) $file['tmp_name'];
    if ($fromForm && !is_uploaded_file($tmp)) {
        throw new BookingError('Файл не загрузился', 'photo');
    }
    if (filesize($tmp) > UPLOAD_MAX_BYTES) {
        throw new BookingError('Файл больше 15 МБ', 'photo');
    }
    if (!function_exists('imagecreatetruecolor')) {
        throw new BookingError('На сервере не включено расширение PHP gd — без него фото не обработать', 'photo');
    }
    $info = @getimagesize($tmp);
    if (!$info || !in_array($info[2], [IMAGETYPE_JPEG, IMAGETYPE_PNG, IMAGETYPE_WEBP], true)) {
        throw new BookingError('Нужна фотография в формате JPEG, PNG или WebP', 'photo');
    }
    [$w, $h] = $info;
    if ($w * $h > 50000000) {
        throw new BookingError('Слишком большое разрешение фото', 'photo');
    }
    @ini_set('memory_limit', '512M');
    switch ($info[2]) {
        case IMAGETYPE_JPEG:
            $src = @imagecreatefromjpeg($tmp);
            break;
        case IMAGETYPE_PNG:
            $src = @imagecreatefrompng($tmp);
            break;
        default:
            $src = function_exists('imagecreatefromwebp') ? @imagecreatefromwebp($tmp) : false;
    }
    if (!$src) {
        throw new BookingError('Не удалось прочитать фото', 'photo');
    }

    // Фото с телефона часто «лежит на боку»: поворот записан в EXIF.
    if ($info[2] === IMAGETYPE_JPEG && function_exists('exif_read_data')) {
        $exif = @exif_read_data($tmp);
        $angle = [3 => 180, 6 => -90, 8 => 90][(int) ($exif['Orientation'] ?? 1)] ?? 0;
        if ($angle) {
            $rotated = imagerotate($src, $angle, 0);
            if ($rotated) {
                imagedestroy($src);
                $src = $rotated;
            }
        }
    }
    $w = imagesx($src);
    $h = imagesy($src);
    $scale = min(1, UPLOAD_MAX_SIDE / max($w, $h));
    $nw = max(1, (int) round($w * $scale));
    $nh = max(1, (int) round($h * $scale));
    $dst = imagecreatetruecolor($nw, $nh);
    imagefill($dst, 0, 0, imagecolorallocate($dst, 255, 255, 255)); // прозрачный PNG — на белом фоне
    imagecopyresampled($dst, $src, 0, 0, 0, 0, $nw, $nh, $w, $h);
    imagedestroy($src);

    $dir = HOTEL_ROOT . '/public/uploads/' . $subdir;
    if (!is_dir($dir) && !@mkdir($dir, 0775, true)) {
        throw new BookingError('Нет доступа к папке public/uploads — разрешите запись', 'photo');
    }
    $name = bin2hex(random_bytes(12)) . '.jpg';
    if (!imagejpeg($dst, $dir . '/' . $name, 82)) {
        throw new BookingError('Не удалось сохранить фото', 'photo');
    }
    imagedestroy($dst);
    return ['path' => 'uploads/' . $subdir . '/' . $name, 'width' => $nw, 'height' => $nh];
}

/** Удалить загруженный файл — только из public/uploads. */
function delete_upload(?string $path): void
{
    if (!$path || !preg_match('#^uploads/[a-z0-9_/-]+\.jpg$#', $path)) {
        return;
    }
    $file = HOTEL_ROOT . '/public/' . $path;
    if (is_file($file)) {
        @unlink($file);
    }
}
