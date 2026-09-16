<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once '../config.php';

// Доступ только для авторизованных собственников
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'owner') {
    header('Location: /pages/login.php');
    exit;
}

$error = '';
$success = '';

// Обработка отправки формы
if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $title = trim($_POST['title'] ?? '');
    $address = trim($_POST['address'] ?? '');
    $city = trim($_POST['city'] ?? '');
    $description = trim($_POST['description'] ?? '');
    $price_month = floatval($_POST['price_month'] ?? 0);
    $width = floatval($_POST['width'] ?? 0);
    $height = floatval($_POST['height'] ?? 0);
    $depth = floatval($_POST['depth'] ?? 0);
    $has_electricity = isset($_POST['has_electricity']) ? 1 : 0;
    $has_wifi = isset($_POST['has_wifi']) ? 1 : 0;
    $has_water = isset($_POST['has_water']) ? 1 : 0;
    $access_hours = $_POST['access_hours'] ?? '24/7';
    $traffic_rating = intval($_POST['traffic_rating'] ?? 0);
    if ($traffic_rating < 0 || $traffic_rating > 5) $traffic_rating = 0;
    $space_type = $_POST['space_type'] ?? null;
    if ($space_type === '') $space_type = null;

    // Валидация
    if (empty($title) || empty($address) || empty($city) || $price_month <= 0) {
        $error = 'Заполните все обязательные поля (название, адрес, город, цена)';
    } else {
        try {
            $pdo = getDbConnection();

            // Геокодируем адрес заранее (а не после вставки) — если Nominatim
            // распознал населённый пункт, используем его нормализованное имя
            // вместо сырого ввода пользователя (лечит опечатки и разнобой вроде
            // "мск"/"Москва") и для координат, и для самой записи локации.
            $geo = geocodeAddress($address, $city);
            if ($geo && !empty($geo['city'])) {
                $city = $geo['city'];
            }

            // 1. Вставляем локацию (неактивную, непромодерированную)
            $stmt = $pdo->prepare("
                INSERT INTO locations
                (owner_id, title, address, city, description, price_month, width, height, depth,
                 has_electricity, has_wifi, has_water, access_hours, traffic_rating, space_type, is_moderated, is_active)
                VALUES (?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, ?, 0, 0)
            ");
            $stmt->execute([
                $_SESSION['user_id'],
                $title,
                $address,
                $city,
                $description,
                $price_month,
                $width,
                $height,
                $depth,
                $has_electricity,
                $has_wifi,
                $has_water,
                $access_hours,
                $traffic_rating,
                $space_type
            ]);
            $location_id = $pdo->lastInsertId();

            // 2. Подготавливаем данные для ревизии
            $revisionData = [
                'title'            => $title,
                'address'          => $address,
                'city'             => $city,
                'description'      => $description,
                'price_month'      => $price_month,
                'width'            => $width,
                'height'           => $height,
                'depth'            => $depth,
                'has_electricity'  => $has_electricity,
                'has_wifi'         => $has_wifi,
                'has_water'        => $has_water,
                'access_hours'     => $access_hours,
                'traffic_rating'   => $traffic_rating,
                'space_type'       => $space_type
            ];

            // Если геокодинг не удался, или найденная улица не похожа на
            // введённую (geocodeAddress тогда отдаёт lat/lng = null, не
            // доверяя случайному совпадению) — локация просто не появится на
            // карте, на модерацию это не влияет.
            if ($geo && $geo['lat'] !== null) {
                $revisionData['latitude'] = $geo['lat'];
                $revisionData['longitude'] = $geo['lng'];
            }

            // 3. Обрабатываем загруженные фото (сохраняем во временную папку)
            $newPhotoPaths = [];
            if (isset($_FILES['photos']) && !empty($_FILES['photos']['name'][0])) {
                $upload_dir = __DIR__ . '/../uploads/revisions/';
                if (!is_dir($upload_dir)) mkdir($upload_dir, 0755, true);

                // Расширение сохранённого файла берётся из проверенного MIME-типа,
                // а не из имени файла клиента: имя вида x.jpg"><script>...</script>
                // иначе целиком становится "расширением" и попадает в путь на диске
                // и в БД, откуда выводится без экранирования на других страницах.
                $allowed_extensions = [
                    'image/jpeg' => 'jpg',
                    'image/png'  => 'png',
                    'image/webp' => 'webp',
                    'image/gif'  => 'gif',
                ];
                $max_size = 5 * 1024 * 1024;
                $uploaded_files = $_FILES['photos'];
                $total_files = min(count($uploaded_files['name']), 5);

                // Накопительная квота на пользователя — раньше размер и число файлов
                // ограничивались только на один запрос, ничто не мешало копить фото
                // годами и постепенно занять весь диск сервера.
                $diskLow = ($free = @disk_free_space(__DIR__)) !== false && $free < 500 * 1024 * 1024;
                $usedBytes = getUserUploadedBytes($pdo, $_SESSION['user_id']);

                for ($i = 0; $i < $total_files; $i++) {
                    if ($uploaded_files['error'][$i] !== UPLOAD_ERR_OK) continue;
                    $tmp_name = $uploaded_files['tmp_name'][$i];
                    $file_type = mime_content_type($tmp_name);
                    $extension = $allowed_extensions[$file_type] ?? null;
                    if (!$extension) continue;
                    if ($uploaded_files['size'][$i] > $max_size) {
                        $error = 'Файл "' . $uploaded_files['name'][$i] . '" превышает 5 МБ';
                        continue;
                    }
                    if ($diskLow || $usedBytes + $uploaded_files['size'][$i] > USER_UPLOAD_QUOTA_BYTES) {
                        $error = 'Достигнут лимит на общий объём загруженных фото (200 МБ на аккаунт). Удалите старые фото у своих локаций, чтобы освободить место.';
                        continue;
                    }

                    $new_name = uniqid() . '.' . $extension;
                    $temp_path = $upload_dir . 'temp_' . $new_name;
                    if (!move_uploaded_file($tmp_name, $temp_path)) continue;

                    $final_path = $upload_dir . $new_name;
                    $compressed = compressImage($temp_path, $final_path, 1200, 1200, 80);
                    if ($compressed) {
                        unlink($temp_path);
                    } else {
                        // Сжатие не удалось (например, повреждённое тело файла) —
                        // сохраняем как есть под тем же безопасным именем, вместо
                        // того чтобы просто потерять фото молча.
                        rename($temp_path, $final_path);
                    }

                    $newPhotoPaths[] = 'uploads/revisions/' . $new_name;
                    $usedBytes += is_file($final_path) ? filesize($final_path) : 0;
                }
            }

            if (!empty($newPhotoPaths)) {
                $revisionData['new_photos'] = $newPhotoPaths;
            }

// Определяем главное фото из единой группы радиокнопок
$mainPhoto = $_POST['main_photo'] ?? '';
if (strpos($mainPhoto, 'new_') === 0) {
    $mainPhotoIndex = (int)substr($mainPhoto, strlen('new_'));
    if (isset($newPhotoPaths[$mainPhotoIndex])) {
        $revisionData['main_photo'] = $newPhotoPaths[$mainPhotoIndex];
    }
} elseif (!empty($newPhotoPaths)) {
    // Если не выбрано – первое фото становится главным
    $revisionData['main_photo'] = $newPhotoPaths[0];
}

            // 4. Сохраняем ревизию в БД
            $stmt = $pdo->prepare("
                INSERT INTO location_revisions (location_id, data, status)
                VALUES (?, ?, 'pending')
            ");
            $stmt->execute([$location_id, json_encode($revisionData)]);

            $success = 'Локация отправлена на модерацию. Она появится после проверки.';
            clearCache('rec_' . $location_id);

            // Очищаем поля формы (опционально)
            // Можно оставить как есть, чтобы пользователь видел успех.

        } catch (PDOException $e) {
            error_log('add_location.php: ' . $e->getMessage());
            $error = 'Ошибка базы данных: ' . $e->getMessage();
        }
    }
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Добавить локацию — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include '../includes/header.php'; ?>
    
    <div class="add-form">
            <a href="/pages/profile.php" onclick="history.back(); return false;" class="back-link">← Назад</a>
        <h2>➕ Добавить новую локацию</h2>
        
        <?php if ($error): ?>
            <div class="error" role="alert"><?php echo htmlspecialchars($error); ?></div>
        <?php endif; ?>
        <?php if ($success): ?>
            <div class="success" role="status"><?php echo htmlspecialchars($success); ?></div>
        <?php endif; ?>
        
        <form method="POST" enctype="multipart/form-data" id="locationForm">
            <!-- Основная информация -->
            <div class="form-group">
                <label>Название места *</label>
                <input type="text" name="title" required placeholder="Например: ТЦ Мега, 1 этаж">
            </div>
            
            <!-- Поле ГОРОД с автодополнением (выбор из подсказки — необязателен). -->
            <div class="form-group city-wrapper">
                <label>Город *</label>
                <input type="text" name="city_display" id="cityInput" required placeholder="Начните вводить город..." autocomplete="off">
                <input type="hidden" name="city" id="cityHidden" value="">
                <div class="city-suggestions" id="citySuggestions"></div>
                <div id="cityStatus"></div>
            </div>
            
            <div class="form-group">
                <label>Адрес *</label>
                <input type="text" name="address" required placeholder="ул. Пушкина, 1">
            </div>
            
            <div class="form-group">
                <label>Описание</label>
                <textarea name="description" placeholder="Опишите место (проходимость, соседи, особенности)"></textarea>
            </div>
            
            <div class="form-row">
                <div class="form-group">
                    <label>Цена в месяц (руб) *</label>
                    <input type="number" name="price_month" required step="1" min="0" placeholder="5000">
                </div>
                <div class="form-group">
                    <label>Часы доступа</label>
                    <select name="access_hours">
                        <option value="24/7">24/7</option>
                        <option value="08:00-22:00">08:00 – 22:00</option>
                        <option value="09:00-21:00">09:00 – 21:00</option>
                        <option value="10:00-20:00">10:00 – 20:00</option>
                        <option value="По договоренности">По договоренности</option>
                    </select>
                </div>
            </div>

            <!-- ★★★ НОВЫЙ БЛОК: ТИП ПОМЕЩЕНИЯ ★★★ -->
            <div class="form-group">
                <label>Тип помещения</label>
                <select name="space_type" class="form-control">
                    <option value="">Не выбран</option>
                    <option value="retail">Торговый центр / Магазин</option>
                    <option value="office">Бизнес-центр / Офис</option>
                    <option value="gym">Спортзал / Фитнес-клуб</option>
                    <option value="hotel">Отель / Гостиница</option>
                    <option value="hospital">Больница / Медицинский центр</option>
                    <option value="transit">Вокзал / Аэропорт</option>
                    <option value="coworking">Коворкинг</option>
                    <option value="laundromat">Прачечная / Химчистка</option>
                    <option value="auto">Автосалон / СТО</option>
                    <option value="warehouse">Склад / Логистика</option>
                    <option value="factory">Завод / Производство</option>
                    <option value="education">Учебное заведение (школа, вуз)</option>
                    <option value="cinema">Кинотеатр / Развлекательный центр</option>
                    <option value="cafe">Кафе / Ресторан</option>
                    <option value="bank">Банк / Финансовое учреждение</option>
                    <option value="post">Почта / Отделение связи</option>
                    <option value="park">Парк / Сквер</option>
                    <option value="stadium">Стадион / Спорткомплекс</option>
                    <option value="museum">Музей / Выставочный центр</option>
                    <option value="other">Другое</option>
                </select>
            </div>

            <!-- ★★★ БЛОК ЗВЁЗД ПРОХОДИМОСТИ (с памяткой) ★★★ -->
            <div class="form-group">
                <label class="traffic-rating-label">
                    Проходимость места
                    <span class="traffic-help-icon" onclick="openTrafficHelp()" title="Что означает каждая звезда?">❓</span>
                </label>
                <div class="star-rating">
                    <?php for ($i = 1; $i <= 5; $i++): ?>
                        <span data-value="<?php echo $i; ?>">★</span>
                    <?php endfor; ?>
                </div>
                <input type="hidden" name="traffic_rating" id="traffic_rating" value="0">
                <div class="traffic-rating-hint">Оцените примерную проходимость (1 — низкая, 5 — очень высокая)</div>
            </div>
            
            <!-- Габариты -->
            <div class="form-row">
                <div class="form-group">
                    <label>Ширина (м)</label>
                    <input type="number" name="width" step="0.1" placeholder="1.0">
                </div>
                <div class="form-group">
                    <label>Высота (м)</label>
                    <input type="number" name="height" step="0.1" placeholder="2.0">
                </div>
                <div class="form-group">
                    <label>Глубина (м)</label>
                    <input type="number" name="depth" step="0.1" placeholder="1.5">
                </div>
            </div>
            
            <!-- Коммуникации -->
            <div class="form-group">
                <label>Что есть на месте</label>
                <div class="checkbox-group">
                    <label>
                        <input type="checkbox" name="has_electricity" checked> ⚡ Электричество
                    </label>
                    <label>
                        <input type="checkbox" name="has_wifi"> 📶 Wi-Fi
                    </label>
                    <label>
                        <input type="checkbox" name="has_water"> 🚰 Вода
                    </label>
                </div>
            </div>

            <!-- Загрузка фото -->
            <div class="form-group">
                <label>Фотографии места (до 5 шт)</label>
                <div class="file-upload" onclick="document.getElementById('photoInput').click();">
                    <span class="icon">📸</span>
                    <div class="text">
                        Кликните или перетащите фото<br>
                        <span>Поддерживаются JPG, PNG, WEBP (до 5 МБ)</span>
                    </div>
                    <input type="file" id="photoInput" name="photos[]" accept="image/*" multiple>
                </div>
                <div id="fileNames" class="file-names-hint"></div>
                <div id="photoPreview" class="photo-preview-grid"></div>
            </div>

            <button type="submit" class="btn-submit">Опубликовать локацию</button>
        </form>
    </div>
    
    <!-- ★★★ МОДАЛЬНОЕ ОКНО С ПАМЯТКОЙ ★★★ -->
    <div class="modal-overlay" id="trafficHelpModal">
        <div class="modal-box">
            <button class="close-btn" onclick="closeTrafficHelp()" aria-label="Закрыть">&times;</button>
            <h3>🚶 Как оценить проходимость места?</h3>
            <p class="traffic-modal-subtitle">Выберите уровень, который лучше всего описывает вашу локацию.</p>
            <table>
                <thead>
                    <tr><th>Рейтинг</th><th>Где встречается</th><th>Трафик (чел/день)</th><th>Нюансы</th></tr>
                </thead>
                <tbody>
                    <tr>
                        <td><span class="stars-demo">★</span> Низкая</td>
                        <td>Малые офисы (&lt;50 чел), жилые дома, тихие коридоры</td>
                        <td>50–200</td>
                        <td>Мало людей, риск низкой окупаемости</td>
                    </tr>
                    <tr>
                        <td><span class="stars-demo">★★</span> Ниже среднего</td>
                        <td>Офисы (50–100 чел), гостиницы, точки "по пути"</td>
                        <td>200–500</td>
                        <td>Трафик есть, но люди часто спешат</td>
                    </tr>
                    <tr>
                        <td><span class="stars-demo">★★★</span> Средняя</td>
                        <td>Крупные офисы (>100 чел), склады, заводы, фитнес-клубы, университеты</td>
                        <td>500–3 000</td>
                        <td><strong>Хороший выбор:</strong> стабильная аудитория</td>
                    </tr>
                    <tr>
                        <td><span class="stars-demo">★★★★</span> Высокая</td>
                        <td>ТРЦ, парки развлечений, больницы, крупные офисные центры</td>
                        <td>3 000–10 000</td>
                        <td>Люди проводят время, высокий потенциал</td>
                    </tr>
                    <tr>
                        <td><span class="stars-demo">★★★★★</span> Максимальная</td>
                        <td>Аэропорты, ж/д вокзалы, туристические центры</td>
                        <td>10 000+</td>
                        <td><strong>Золотая жила,</strong> но аренда очень дорогая</td>
                    </tr>
                </tbody>
            </table>
            <div class="note">
                <strong>💡 Важно!</strong>
                Оценивайте не только количество людей, но и <strong>время пребывания</strong> (стоят/ждут) и наличие <strong>альтернатив</strong> (конкуренты). Самые прибыльные места — где люди задерживаются на 10–30 минут.
            </div>
            <p class="traffic-modal-footnote">Подсказка всегда доступна по ❓</p>
        </div>
    </div>
    
<!-- ★★★ ВАЛИДАЦИЯ ФАЙЛОВ ПРИ ЗАГРУЗКЕ ★★★ -->
<script>
const MAX_FILE_SIZE = 5 * 1024 * 1024; // 5 МБ
const fileInput = document.getElementById('photoInput');
const fileNamesDiv = document.getElementById('fileNames');
const previewContainer = document.getElementById('photoPreview');
const submitBtn = document.querySelector('.btn-submit');

fileInput.addEventListener('change', function(e) {
    const files = Array.from(e.target.files);
    const previewContainer = document.getElementById('photoPreview');
    previewContainer.innerHTML = '';
    let validFiles = [];
    let invalidFiles = [];

    files.forEach((file, index) => {
        if (file.size > MAX_FILE_SIZE) {
            invalidFiles.push(file);
        } else {
            validFiles.push(file);
            const reader = new FileReader();
            reader.onload = function(ev) {
                const div = document.createElement('div');
                div.className = 'photo-preview-item';
                div.innerHTML = `
                    <img src="${ev.target.result}" class="photo-preview-thumb" alt="Предпросмотр фото">
                    <label class="photo-preview-radio-label">
                        <input type="radio" name="main_photo" value="new_${index}" ${index === 0 ? 'checked' : ''}>
                        Главное
                    </label>
                `;
                previewContainer.appendChild(div);
            };
            reader.readAsDataURL(file);
        }
    });

    let message = '';
    if (validFiles.length > 0) {
        message += `<div class="upload-msg-ok">✅ ${validFiles.length} файлов готовы</div>`;
    }
    if (invalidFiles.length > 0) {
        message += `<div class="upload-msg-error">❌ ${invalidFiles.length} файлов превышают 5 МБ</div>`;
        submitBtn.disabled = true;
    } else {
        submitBtn.disabled = false;
    }
    document.getElementById('fileNames').innerHTML = message;
});
</script>
    
    <!--
        Скрипт для автодополнения городов. Выбор из подсказки не обязателен —
        справочник городов (_cities) сейчас пуст (данные внешние, их ещё не
        перенесли на этот сервер), так что жёстко требовать клик по подсказке
        значило бы, что создать локацию нельзя вообще ни для одного города.
        Подсказки — это просто помощь, если справочник заполнят; при отправке
        формы то, что введено в поле, в любом случае уходит как есть.
    -->
    <script>
    document.addEventListener('DOMContentLoaded', function() {
        const input = document.getElementById('cityInput');
        const hidden = document.getElementById('cityHidden');
        const suggestions = document.getElementById('citySuggestions');
        const status = document.getElementById('cityStatus');
        const form = document.getElementById('locationForm');
        let selectedCity = '';
        let timeout = null;

        input.addEventListener('input', function() {
            clearTimeout(timeout);
            const query = this.value.trim();
            
            if (selectedCity && query !== selectedCity) {
                selectedCity = '';
                hidden.value = '';
                status.innerHTML = '';
                status.classList.remove('status-ok', 'status-error');
            }
            
            if (query.length < 2) {
                suggestions.style.display = 'none';
                return;
            }
            
            timeout = setTimeout(function() {
                fetch('/api/cities.php?q=' + encodeURIComponent(query))
                    .then(response => response.json())
                    .then(data => {
                        if (data.length === 0 || data.error) {
                            suggestions.style.display = 'none';
                            status.innerHTML = '⚠️ Город не найден. Уточните запрос.';
                            status.classList.add('status-error'); status.classList.remove('status-ok');
                            return;
                        }
                        suggestions.innerHTML = data.map(item => 
                            `<div class="suggestion-item" data-value="${item.value}">${item.label}</div>`
                        ).join('');
                        suggestions.style.display = 'block';
                        
                        suggestions.querySelectorAll('.suggestion-item').forEach(el => {
                            el.addEventListener('click', function() {
                                const cityName = this.dataset.value;
                                input.value = cityName;
                                hidden.value = cityName;
                                selectedCity = cityName;
                                suggestions.style.display = 'none';
                                status.innerHTML = '✅ Выбран город: ' + cityName;
                                status.classList.add('status-ok'); status.classList.remove('status-error');
                                input.setCustomValidity('');
                            });
                        });
                    })
                    .catch(() => {
                        suggestions.style.display = 'none';
                    });
            }, 300);
        });

        input.addEventListener('blur', function() {
            setTimeout(function() {
                if (!selectedCity) {
                    const val = input.value.trim();
                    if (val.length > 0) {
                        status.innerHTML = 'ℹ️ Город будет сохранён как введено: «' + val + '». Если появится в подсказках — можно выбрать его оттуда для единообразия.';
                        status.classList.add('status-ok'); status.classList.remove('status-error');
                    }
                }
                suggestions.style.display = 'none';
            }, 200);
        });

        input.addEventListener('focus', function() {
            if (!selectedCity) {
                status.innerHTML = '';
                input.setCustomValidity('');
            }
        });

        document.addEventListener('click', function(e) {
            if (!e.target.closest('#cityInput') && !e.target.closest('#citySuggestions')) {
                suggestions.style.display = 'none';
            }
        });

        if (form) {
            form.addEventListener('submit', function(e) {
                // Ничего не выбрано из подсказок — отправляем как есть то, что
                // введено в видимое поле, а не блокируем форму.
                if (!hidden.value || hidden.value.trim() === '') {
                    hidden.value = input.value.trim();
                }
            });
        }
    });
    </script>

    <!-- ★★★ СКРИПТ ДЛЯ ЗВЁЗД И ПАМЯТКИ ★★★ -->
    <script>
        // Управление звёздами (клик)
        document.querySelectorAll('.star-rating span').forEach(function(star) {
            star.addEventListener('click', function() {
                const value = this.dataset.value;
                document.getElementById('traffic_rating').value = value;
                
                document.querySelectorAll('.star-rating span').forEach(function(s, idx) {
                    s.classList.toggle('filled', idx < value);
                });
            });
        });

        // Открыть / закрыть памятку
        function openTrafficHelp() {
            document.getElementById('trafficHelpModal').classList.add('active');
            document.body.style.overflow = 'hidden';
        }
        function closeTrafficHelp() {
            document.getElementById('trafficHelpModal').classList.remove('active');
            document.body.style.overflow = '';
        }
        // Закрыть по клику на оверлей
        document.getElementById('trafficHelpModal').addEventListener('click', function(e) {
            if (e.target === this) closeTrafficHelp();
        });
        // Закрыть по Esc
        document.addEventListener('keydown', function(e) {
            if (e.key === 'Escape') closeTrafficHelp();
        });
    </script>
    
    <?php include '../includes/footer.php'; ?>
</body>
</html>