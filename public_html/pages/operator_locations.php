<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'operator') {
    header('Location: /pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

// Активные закрепления оператора + карточка вендинга на точке (если уже заведена)
$stmt = $pdo->prepare("
    SELECT lo.*, l.title, l.address, l.city, l.price_month, l.owner_id,
           u.full_name as owner_name,
           m.id as machine_id, m.machine_type, m.model, m.serial_number,
           m.installed_at, m.last_service_at, m.status as machine_status
    FROM location_operators lo
    JOIN locations l ON lo.location_id = l.id
    JOIN users u ON l.owner_id = u.id
    LEFT JOIN location_machines m ON m.location_operator_id = lo.id
    WHERE lo.operator_id = ? AND lo.status = 'active'
    ORDER BY l.city, l.title
");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

$machineTypeLabels = [
    'snacks' => 'Снеки',
    'drinks' => 'Напитки',
    'coffee' => 'Кофе',
    'combo'  => 'Комбо',
    'other'  => 'Другое',
];

function daysSince($dateString) {
    if (!$dateString) return null;
    $diff = (new DateTime())->diff(new DateTime($dateString));
    return (int)$diff->days;
}
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои точки — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="locations-container">
    <a href="/pages/operator_dashboard.php" class="back-link">← Назад</a>
    <h2>📍 Мои закреплённые точки</h2>
    <p class="page-intro spaced">Локации, за которыми вы закреплены, и вендинги, которые на них установлены.</p>

    <?php if (count($locations) > 0): ?>
        <?php foreach ($locations as $loc):
            $hasMachine = !empty($loc['machine_id']);
            $refDate = $loc['last_service_at'] ?: $loc['installed_at'];
            $days = daysSince($refDate);
            if ($days === null) {
                $badgeClass = 'service-unknown';
                $badgeText = 'Нет данных об обслуживании';
            } elseif (isServiceOverdue($days)) {
                $badgeClass = 'service-due';
                $badgeText = '⚠️ Требует обслуживания (' . $days . ' дн. назад)';
            } else {
                $badgeClass = 'service-ok';
                $badgeText = '✅ Обслужено ' . $days . ' дн. назад';
            }
        ?>
            <div class="ol-location-card">
                <div class="top-row">
                    <div class="location-info">
                        <div class="title"><?php echo htmlspecialchars($loc['title']); ?></div>
                        <div class="address">📍 <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                        <div class="owner">👤 Владелец: <?php echo htmlspecialchars($loc['owner_name']); ?></div>
                        <div class="badge-assigned">✅ Закреплён</div>
                    </div>
                    <div class="location-actions">
                        <a href="/pages/location.php?id=<?php echo $loc['location_id']; ?>" class="ol-btn-action btn-edit-machine">👁️ Локация</a>
                        <a href="/pages/operator_vending_events.php?location_id=<?php echo $loc['location_id']; ?>" class="ol-btn-action btn-calendar">📅 Календарь</a>
                    </div>
                </div>

                <?php if ($hasMachine): ?>
                    <div class="machine-box">
                        <div class="machine-info">
                            <span><b>Тип:</b> <?php echo htmlspecialchars($machineTypeLabels[$loc['machine_type']] ?? $loc['machine_type']); ?></span>
                            <?php if ($loc['model']): ?><span><b>Модель:</b> <?php echo htmlspecialchars($loc['model']); ?></span><?php endif; ?>
                            <?php if ($loc['serial_number']): ?><span><b>Серийный №:</b> <?php echo htmlspecialchars($loc['serial_number']); ?></span><?php endif; ?>
                            <?php if ($loc['installed_at']): ?><span><b>Установлен:</b> <?php echo date('d.m.Y', strtotime($loc['installed_at'])); ?></span><?php endif; ?>
                        </div>
                        <div>
                            <span class="service-badge <?php echo $badgeClass; ?>"><?php echo $badgeText; ?></span>
                        </div>
                        <div class="machine-actions">
                            <button class="ol-btn-action btn-service" data-lo-id="<?php echo $loc['id']; ?>" onclick="openServiceModal(this)">🔧 Отметить обслуживание</button>
                            <button class="ol-btn-action btn-edit-machine"
                                    data-lo-id="<?php echo $loc['id']; ?>"
                                    data-machine-type="<?php echo htmlspecialchars($loc['machine_type']); ?>"
                                    data-model="<?php echo htmlspecialchars($loc['model'] ?? ''); ?>"
                                    data-serial="<?php echo htmlspecialchars($loc['serial_number'] ?? ''); ?>"
                                    data-installed="<?php echo htmlspecialchars($loc['installed_at'] ?? ''); ?>"
                                    onclick="openMachineModal(this)">✏️ Изменить данные</button>
                        </div>
                    </div>
                <?php else: ?>
                    <div class="machine-box empty-machine">
                        <span class="empty-machine-note">Вендинг на этой точке ещё не указан.</span>
                        <button class="ol-btn-action btn-add-machine" data-lo-id="<?php echo $loc['id']; ?>" onclick="openMachineModal(this)">➕ Указать вендинг</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty">
            <p>Вы пока не закреплены ни за одной локацией.</p>
            <p><a href="/pages/catalog.php" class="accent-link">Найдите локацию и запросите закрепление</a></p>
        </div>
    <?php endif; ?>
</div>

<!-- ===== Модалка: карточка вендинга (добавить/изменить) ===== -->
<div class="modal-overlay" id="machineModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeMachineModal()" aria-label="Закрыть">&times;</button>
        <h3 id="machineModalTitle">➕ Указать вендинг</h3>
        <form id="machineForm">
            <input type="hidden" id="machineLoId">
            <div class="form-group">
                <label for="machineType">Тип вендинга *</label>
                <select id="machineType" required>
                    <option value="">-- Выберите тип --</option>
                    <option value="snacks">Снеки</option>
                    <option value="drinks">Напитки</option>
                    <option value="coffee">Кофе</option>
                    <option value="combo">Комбо</option>
                    <option value="other">Другое</option>
                </select>
            </div>
            <div class="form-group">
                <label for="machineModel">Модель</label>
                <input type="text" id="machineModel" placeholder="Например, Unicum Rosso">
            </div>
            <div class="form-group">
                <label for="machineSerial">Серийный номер</label>
                <input type="text" id="machineSerial" placeholder="Необязательно">
            </div>
            <div class="form-group">
                <label for="machineInstalledAt">Дата установки</label>
                <input type="date" id="machineInstalledAt">
            </div>
            <div class="ol-modal-error" id="machineError" role="alert"></div>
            <button type="submit" class="btn-submit">Сохранить</button>
        </form>
    </div>
</div>

<!-- ===== Модалка: отметить обслуживание ===== -->
<div class="modal-overlay" id="serviceModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeServiceModal()" aria-label="Закрыть">&times;</button>
        <h3>🔧 Отметить обслуживание</h3>
        <form id="serviceForm">
            <input type="hidden" id="serviceLoId">
            <div class="form-group">
                <label for="serviceType">Что было сделано *</label>
                <select id="serviceType" required>
                    <option value="maintenance">Плановое обслуживание</option>
                    <option value="restock">Пополнение товара</option>
                    <option value="repair">Ремонт</option>
                </select>
            </div>
            <div class="form-group">
                <label for="serviceComment">Комментарий</label>
                <textarea id="serviceComment" rows="3" placeholder="Необязательно"></textarea>
            </div>
            <div class="form-group">
                <label for="servicePhotos">Фото подтверждения</label>
                <input type="file" id="servicePhotos" accept="image/*" multiple>
                <div class="photo-upload-hint">Необязательно, можно выбрать несколько фото</div>
            </div>
            <div id="serviceProgressWrap" class="ol-progress-wrap ol-hidden">
                <div class="ol-progress-track">
                    <div id="serviceProgressBar" class="ol-progress-bar-fill"></div>
                </div>
                <div id="serviceProgressText" class="ol-progress-text">0%</div>
            </div>
            <div class="ol-modal-error" id="serviceError" role="alert"></div>
            <button type="submit" class="btn-submit" id="serviceSubmitBtn">Отметить</button>
        </form>
    </div>
</div>

<script>
function openMachineModal(btn) {
    const d = btn.dataset;
    document.getElementById('machineLoId').value = d.loId;
    document.getElementById('machineType').value = d.machineType || '';
    document.getElementById('machineModel').value = d.model || '';
    document.getElementById('machineSerial').value = d.serial || '';
    document.getElementById('machineInstalledAt').value = d.installed || '';
    document.getElementById('machineModalTitle').textContent = d.machineType ? '✏️ Изменить данные вендинга' : '➕ Указать вендинг';
    document.getElementById('machineError').style.display = 'none';
    document.getElementById('machineModal').classList.add('active');
}
function closeMachineModal() {
    document.getElementById('machineModal').classList.remove('active');
}

function openServiceModal(btn) {
    document.getElementById('serviceLoId').value = btn.dataset.loId;
    document.getElementById('serviceComment').value = '';
    document.getElementById('serviceType').value = 'maintenance';
    document.getElementById('servicePhotos').value = '';
    document.getElementById('serviceError').style.display = 'none';
    document.getElementById('serviceProgressWrap').classList.add('ol-hidden');
    document.getElementById('serviceProgressBar').style.width = '0%';
    document.getElementById('serviceModal').classList.add('active');
}
function closeServiceModal() {
    document.getElementById('serviceModal').classList.remove('active');
}

document.querySelectorAll('.modal-overlay').forEach(function(overlay) {
    overlay.addEventListener('click', function(e) {
        if (e.target === this) this.classList.remove('active');
    });
});
document.addEventListener('keydown', function(e) {
    if (e.key === 'Escape') {
        closeMachineModal();
        closeServiceModal();
    }
});

document.getElementById('machineForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const errorEl = document.getElementById('machineError');
    errorEl.style.display = 'none';

    const formData = new FormData();
    formData.append('action', 'save_machine');
    formData.append('location_operator_id', document.getElementById('machineLoId').value);
    formData.append('machine_type', document.getElementById('machineType').value);
    formData.append('model', document.getElementById('machineModel').value);
    formData.append('serial_number', document.getElementById('machineSerial').value);
    formData.append('installed_at', document.getElementById('machineInstalledAt').value);

    fetch('/api/installation.php', { method: 'POST', body: formData })
        .then(r => r.json())
        .then(data => {
            if (data.success) {
                location.reload();
            } else {
                errorEl.textContent = data.error || 'Ошибка сохранения';
                errorEl.style.display = 'block';
            }
        })
        .catch(() => {
            errorEl.textContent = 'Ошибка соединения';
            errorEl.style.display = 'block';
        });
});

document.getElementById('serviceForm').addEventListener('submit', function(e) {
    e.preventDefault();
    const errorEl = document.getElementById('serviceError');
    errorEl.style.display = 'none';

    const submitBtn = document.getElementById('serviceSubmitBtn');
    if (submitBtn.disabled) return; // защита от повторной отправки, пока идёт первая
    submitBtn.disabled = true;
    submitBtn.textContent = 'Отправка...';

    const progressWrap = document.getElementById('serviceProgressWrap');
    const progressBar = document.getElementById('serviceProgressBar');
    const progressText = document.getElementById('serviceProgressText');
    progressWrap.classList.remove('ol-hidden');
    progressBar.style.width = '0%';
    progressText.textContent = '0%';

    const formData = new FormData();
    formData.append('action', 'quick_service');
    formData.append('location_operator_id', document.getElementById('serviceLoId').value);
    formData.append('event_type', document.getElementById('serviceType').value);
    formData.append('comment', document.getElementById('serviceComment').value);

    const photoFiles = document.getElementById('servicePhotos').files;
    for (let i = 0; i < photoFiles.length; i++) {
        formData.append('photos[]', photoFiles[i]);
    }

    function resetButton() {
        submitBtn.disabled = false;
        submitBtn.textContent = 'Отметить';
        progressWrap.classList.add('ol-hidden');
    }

    const xhr = new XMLHttpRequest();
    xhr.open('POST', '/api/installation.php');

    xhr.upload.addEventListener('progress', function(ev) {
        if (!ev.lengthComputable) return;
        const percent = Math.round((ev.loaded / ev.total) * 100);
        progressBar.style.width = percent + '%';
        progressText.textContent = percent + '%' + (percent === 100 ? ' · обработка на сервере...' : '');
    });

    xhr.onload = function() {
        resetButton();
        let data;
        try {
            data = JSON.parse(xhr.responseText);
        } catch (err) {
            errorEl.textContent = 'Сервер вернул некорректный ответ';
            errorEl.style.display = 'block';
            return;
        }
        if (data.success) {
            if (data.photo_errors && data.photo_errors.length > 0) {
                rrAlert('Обслуживание отмечено, но часть фото не сохранилась:\n\n' + data.photo_errors.join('\n')).then(() => location.reload());
            } else {
                location.reload();
            }
        } else {
            errorEl.textContent = data.error || 'Ошибка сохранения';
            errorEl.style.display = 'block';
        }
    };

    xhr.onerror = function() {
        resetButton();
        errorEl.textContent = 'Ошибка соединения';
        errorEl.style.display = 'block';
    };

    xhr.send(formData);
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>