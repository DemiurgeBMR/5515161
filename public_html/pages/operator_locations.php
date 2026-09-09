<?php
session_start();
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
    <title>Мои точки — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .locations-container {
            max-width: 1000px;
            margin: 40px auto;
            padding: 0 20px;
        }
        .location-card {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 12px;
            padding: 18px 22px;
            margin-bottom: 15px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            transition: 0.2s;
        }
        .location-card:hover {
            box-shadow: 0 4px 20px rgba(0,0,0,0.5);
            border-color: var(--border-strong, #3a3a45);
        }
        .top-row {
            display: flex;
            justify-content: space-between;
            align-items: flex-start;
            flex-wrap: wrap;
            gap: 12px;
        }
        .location-info .title {
            font-size: 20px;
            font-weight: bold;
        }
        .location-info .address {
            color: var(--text-muted, #9a9aa5);
            font-size: 14px;
            margin-top: 4px;
        }
        .location-info .owner {
            font-size: 14px;
            color: var(--text-muted, #9a9aa5);
            margin-top: 4px;
        }
        .location-actions {
            display: flex;
            gap: 10px;
            flex-wrap: wrap;
        }
        .btn-action {
            padding: 7px 14px;
            border-radius: 6px;
            text-decoration: none;
            font-weight: 600;
            font-size: 13px;
            border: none;
            cursor: pointer;
        }
        .btn-calendar {
            background: #e94560;
            color: white;
        }
        .btn-calendar:hover {
            background: #c73652;
        }
        .empty {
            text-align: center;
            padding: 60px 20px;
            color: var(--text-muted, #9a9aa5);
        }
        .back-link {
            display: inline-block;
            margin-bottom: 20px;
            color: var(--text-muted, #9a9aa5);
            text-decoration: none;
        }
        .back-link:hover {
            text-decoration: underline;
        }
        .badge-assigned {
            display: inline-block;
            background: #2ecc71;
            color: white;
            font-size: 12px;
            padding: 2px 10px;
            border-radius: 20px;
            margin-top: 4px;
        }

        /* ===== Карточка вендинга на точке ===== */
        .machine-box {
            margin-top: 16px;
            padding: 14px 16px;
            border-radius: 10px;
            background: var(--bg-elevated-2, #1c1c24);
            border: 1px solid var(--border, #2a2a33);
        }
        .machine-box.empty-machine {
            display: flex;
            align-items: center;
            justify-content: space-between;
            flex-wrap: wrap;
            gap: 10px;
            text-align: left;
            padding: 14px 16px;
        }
        .machine-info {
            display: flex;
            flex-wrap: wrap;
            gap: 6px 18px;
            font-size: 14px;
            color: var(--text, #f2f2f5);
        }
        .machine-info b { color: var(--text, #f2f2f5); }

        .service-badge {
            display: inline-block;
            padding: 3px 10px;
            border-radius: 20px;
            font-size: 12px;
            font-weight: 700;
            margin-top: 8px;
        }
        .service-ok { background: rgba(46, 204, 113, 0.15); color: #6ee7a0; }
        .service-due { background: rgba(231, 76, 60, 0.15); color: #ff8a9b; }
        .service-unknown { background: var(--bg-elevated-2, #1c1c24); color: var(--text-muted, #9a9aa5); }

        .machine-actions {
            margin-top: 12px;
            display: flex;
            gap: 8px;
            flex-wrap: wrap;
        }
        .btn-service { background: #2ecc71; color: white; }
        .btn-service:hover { background: #27ae60; }
        .btn-edit-machine { background: var(--bg-elevated-2, #1c1c24); color: var(--text, #f2f2f5); }
        .btn-edit-machine:hover { background: var(--border, #2a2a33); }
        .btn-add-machine { background: #e94560; color: white; }
        .btn-add-machine:hover { background: #c73652; }

        /* ===== Модалки ===== */
        .modal-overlay {
            display: none;
            position: fixed;
            inset: 0;
            background: rgba(0,0,0,0.5);
            z-index: 9999;
            justify-content: center;
            align-items: center;
        }
        .modal-overlay.active { display: flex; }
        .modal-box {
            background: var(--bg-elevated, #16161c);
            color: var(--text, #f2f2f5);
            border: 1px solid var(--border, #2a2a33);
            padding: 30px;
            border-radius: 16px;
            max-width: 460px;
            width: 90%;
            box-shadow: 0 20px 60px rgba(0,0,0,0.6);
            position: relative;
        }
        .modal-box .close-btn {
            position: absolute;
            top: 12px;
            right: 18px;
            font-size: 28px;
            cursor: pointer;
            color: var(--text-muted, #9a9aa5);
            background: none;
            border: none;
        }
        .modal-box h3 { margin-top: 0; }
        .modal-box .form-group { margin-bottom: 15px; }
        .modal-box label { display: block; font-weight: 600; margin-bottom: 5px; font-size: 14px; }
        .modal-box select,
        .modal-box input[type="text"],
        .modal-box input[type="date"],
        .modal-box textarea {
            width: 100%;
            padding: 10px;
            background: var(--bg-input, #0f0f14);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 6px;
            font-size: 14px;
            color: var(--text, #f2f2f5);
            box-sizing: border-box;
        }
        .modal-box .btn-submit {
            width: 100%;
            padding: 12px;
            background: #e94560;
            color: white;
            border: none;
            border-radius: 6px;
            font-weight: bold;
            cursor: pointer;
        }
        .modal-box .btn-submit:hover { background: #c73652; }
        .modal-error {
            color: #e74c3c;
            margin-bottom: 10px;
            display: none;
            font-size: 13px;
        }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="locations-container">
    <a href="/pages/operator_dashboard.php" class="back-link">← Назад</a>
    <h2>📍 Мои закреплённые точки</h2>
    <p style="color: #888; margin-bottom: 20px;">Локации, за которыми вы закреплены, и вендинги, которые на них установлены.</p>

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
            <div class="location-card">
                <div class="top-row">
                    <div class="location-info">
                        <div class="title"><?php echo htmlspecialchars($loc['title']); ?></div>
                        <div class="address">📍 <?php echo htmlspecialchars($loc['city'] . ', ' . $loc['address']); ?></div>
                        <div class="owner">👤 Владелец: <?php echo htmlspecialchars($loc['owner_name']); ?></div>
                        <div class="badge-assigned">✅ Закреплён</div>
                    </div>
                    <div class="location-actions">
                        <a href="/pages/location.php?id=<?php echo $loc['location_id']; ?>" class="btn-action btn-edit-machine">👁️ Локация</a>
                        <a href="/pages/operator_vending_events.php?location_id=<?php echo $loc['location_id']; ?>" class="btn-action btn-calendar">📅 Календарь</a>
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
                            <button class="btn-action btn-service" data-lo-id="<?php echo $loc['id']; ?>" onclick="openServiceModal(this)">🔧 Отметить обслуживание</button>
                            <button class="btn-action btn-edit-machine"
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
                        <span style="color:#888; font-size: 14px;">Вендинг на этой точке ещё не указан.</span>
                        <button class="btn-action btn-add-machine" data-lo-id="<?php echo $loc['id']; ?>" onclick="openMachineModal(this)">➕ Указать вендинг</button>
                    </div>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
    <?php else: ?>
        <div class="empty">
            <p>Вы пока не закреплены ни за одной локацией.</p>
            <p><a href="/pages/catalog.php" style="color: #e94560;">Найдите локацию и запросите закрепление</a></p>
        </div>
    <?php endif; ?>
</div>

<!-- ===== Модалка: карточка вендинга (добавить/изменить) ===== -->
<div class="modal-overlay" id="machineModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeMachineModal()">&times;</button>
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
            <div class="modal-error" id="machineError"></div>
            <button type="submit" class="btn-submit">Сохранить</button>
        </form>
    </div>
</div>

<!-- ===== Модалка: отметить обслуживание ===== -->
<div class="modal-overlay" id="serviceModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeServiceModal()">&times;</button>
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
                <div style="color:#888; font-size:12px; margin-top:4px;">Необязательно, можно выбрать несколько фото</div>
            </div>
            <div id="serviceProgressWrap" style="display:none; margin-bottom:14px;">
                <div style="background:#eee; border-radius:20px; overflow:hidden; height:8px;">
                    <div id="serviceProgressBar" style="background:#2ecc71; height:100%; width:0%; transition:width .15s;"></div>
                </div>
                <div id="serviceProgressText" style="color:#888; font-size:12px; margin-top:4px;">0%</div>
            </div>
            <div class="modal-error" id="serviceError"></div>
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
    document.getElementById('serviceProgressWrap').style.display = 'none';
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
    progressWrap.style.display = 'block';
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
        progressWrap.style.display = 'none';
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
                alert('Обслуживание отмечено, но часть фото не сохранилась:\n\n' + data.photo_errors.join('\n'));
            }
            location.reload();
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