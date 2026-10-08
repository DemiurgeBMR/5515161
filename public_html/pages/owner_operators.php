<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'owner') {
    header('Location: /pages/login.php');
    exit;
}

$user_id = $_SESSION['user_id'];
$pdo = getDbConnection();

// Получаем все активные закрепления собственника
$stmt = $pdo->prepare("
    SELECT lo.*, l.title as location_title, l.city, u.full_name as operator_name,
           u.avatar_color as operator_color,
           (SELECT a.id FROM applications a
             WHERE a.owner_id = lo.owner_id AND a.operator_id = lo.operator_id AND a.location_id = lo.location_id
             ORDER BY a.created_at DESC LIMIT 1) as application_id,
           (SELECT a.id FROM applications a
             WHERE a.owner_id = lo.owner_id AND a.operator_id = lo.operator_id
             ORDER BY a.updated_at DESC LIMIT 1) as any_application_id
    FROM location_operators lo
    JOIN locations l ON lo.location_id = l.id
    JOIN users u ON lo.operator_id = u.id
    WHERE lo.owner_id = ? AND lo.status = 'active'
    ORDER BY l.city, l.title
");
$stmt->execute([$user_id]);
$assignments = $stmt->fetchAll();

// Получаем список всех локаций собственника (для выбора при создании)
$stmt = $pdo->prepare("SELECT id, title, city FROM locations WHERE owner_id = ? ORDER BY title");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

// Получаем список всех операторов, которые подавали заявки собственнику (для выбора)
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.full_name
    FROM users u
    JOIN applications a ON a.operator_id = u.id
    WHERE a.owner_id = ? AND u.role = 'operator'
    ORDER BY u.full_name
");
$stmt->execute([$user_id]);
$operators = $stmt->fetchAll();

// ===== Телефонная версия: карточки по операторам (на десктопе — прежняя таблица) =====
// Вендинги на закреплённых точках — только чтение, как в «Требует внимания» на profile.php.
$machinesByLo = [];
if ($assignments) {
    $loIds = array_map('intval', array_column($assignments, 'id'));
    $in = implode(',', array_fill(0, count($loIds), '?'));
    $stmt = $pdo->prepare("
        SELECT location_operator_id, machine_type, model, status, installed_at, last_service_at
        FROM location_machines
        WHERE location_operator_id IN ($in) AND status != 'removed'
        ORDER BY id
    ");
    $stmt->execute($loIds);
    foreach ($stmt->fetchAll() as $m) {
        $machinesByLo[(int) $m['location_operator_id']][] = $m;
    }
}
$byOperator = [];
foreach ($assignments as $ass) {
    $oid = (int) $ass['operator_id'];
    if (!isset($byOperator[$oid])) {
        $byOperator[$oid] = [
            'name'  => (string) $ass['operator_name'],
            'color' => preg_match('/^#[0-9a-fA-F]{3,8}$/', (string) $ass['operator_color']) ? $ass['operator_color'] : '',
            'chat'  => $ass['any_application_id'] ? (int) $ass['any_application_id'] : null,
            'items' => [],
        ];
    }
    $byOperator[$oid]['items'][] = $ass;
}
$machineTypeLabels = [
    'snacks' => 'Снеки',
    'drinks' => 'Напитки',
    'coffee' => 'Кофе',
    'combo'  => 'Комбо',
    'other'  => 'Другое',
];
// Статус обслуживания вендинга: [модификатор m-pill, иконка, подпись] — те же правила, что на operator_locations.php
$ooServiceState = function (array $m) {
    if ($m['status'] === 'broken') return ['is-danger', 'warning', 'Сломан'];
    if ($m['status'] === 'needs_service') return ['is-warning', 'wrench', 'Требует ремонта'];
    $ref = $m['last_service_at'] ?: $m['installed_at'];
    if (!$ref) return ['is-muted', 'clock', 'Нет данных об обслуживании'];
    $days = (int) (new DateTime())->diff(new DateTime($ref))->days;
    if (isServiceOverdue($days)) return ['is-warning', 'warning', 'Требует обслуживания · ' . $days . ' дн.'];
    return ['', 'check', $days === 0 ? 'Обслужен сегодня' : 'Обслужен ' . $days . ' дн. назад'];
};
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0, viewport-fit=cover">
    <title>Мои операторы — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body class="m-owner-cab m-owner-ops">
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="oo-container">
    <div class="m-appbar m-only oo-appbar">
        <a href="/pages/profile.php" class="m-appbar-back" onclick="if (history.length > 1) { history.back(); return false; }" aria-label="Назад"><?php echo rr_icon('chevron-left'); ?></a>
        <h1 class="m-appbar-title">Мои операторы</h1>
    </div>
    <a href="/pages/profile.php" class="back-link m-hide">← Назад</a>
    <h2 class="m-hide"><?php echo rr_icon('users'); ?> Мои операторы</h2>
    <p class="page-intro spaced">Операторы, закреплённые за вашими локациями.</p>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="flash-message<?php echo strpos($_SESSION['flash'], 'Успешно') !== false ? '' : ' flash-error'; ?>">
            <?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?>
        </div>
    <?php endif; ?>

    <button class="btn-add" id="addAssignmentBtn"><?php echo rr_icon('plus-circle'); ?> Закрепить оператора</button>

    <?php if (count($assignments) > 0): ?>
        <div class="assignments-table m-hide">
            <table>
                <thead>
                    <tr>
                        <th>Локация</th>
                        <th>Город</th>
                        <th>Оператор</th>
                        <th>Действия</th>
                    </tr>
                </thead>
                <tbody>
                    <?php foreach ($assignments as $ass): ?>
                        <tr>
                            <td><?php echo htmlspecialchars($ass['location_title']); ?></td>
                            <td><?php echo htmlspecialchars($ass['city']); ?></td>
                            <td><?php echo htmlspecialchars($ass['operator_name']); ?></td>
                            <td>
                                <button class="btn-unassign" data-id="<?php echo $ass['id']; ?>">Открепить</button>
                            </td>
                        </tr>
                    <?php endforeach; ?>
                </tbody>
            </table>
        </div>

        <?php // Телефон: карточка на оператора — его точки, вендинги и статус обслуживания; действия по точке — в шторке «⋯». ?>
        <div class="assignments-table oo-m-list m-only">
            <?php foreach ($byOperator as $op):
                $opInitial = mb_strtoupper(mb_substr($op['name'] !== '' ? $op['name'] : 'О', 0, 1, 'UTF-8'), 'UTF-8');
                $locCount = count($op['items']);
            ?>
                <article class="oo-m-card">
                    <div class="oo-m-head">
                        <span class="oo-m-av" aria-hidden="true"<?php echo $op['color'] ? ' style="background:' . htmlspecialchars($op['color'], ENT_QUOTES) . '"' : ''; ?>><?php echo htmlspecialchars($opInitial); ?></span>
                        <div class="oo-m-who">
                            <b class="oo-m-name"><?php echo htmlspecialchars($op['name']); ?></b>
                            <span class="oo-m-sub">Оператор · <?php echo $locCount . ' ' . rr_plural_ru($locCount, 'точка', 'точки', 'точек'); ?></span>
                        </div>
                        <?php if ($op['chat']): ?>
                            <a href="/pages/application_chat.php?application_id=<?php echo $op['chat']; ?>" class="m-btn m-btn--sm m-btn--soft oo-m-write"><?php echo rr_icon('message-circle'); ?> Написать</a>
                        <?php endif; ?>
                    </div>
                    <ul class="oo-m-locs">
                        <?php foreach ($op['items'] as $ass):
                            $sheetId = 'ooActions' . (int) $ass['id'];
                            $machines = $machinesByLo[(int) $ass['id']] ?? [];
                            $chatId = $ass['application_id'] ?: $op['chat'];
                        ?>
                            <li class="oo-m-loc">
                                <span class="oo-m-loc-ic" aria-hidden="true"><?php echo rr_icon('map-pin'); ?></span>
                                <div class="oo-m-loc-main">
                                    <a href="/pages/location.php?id=<?php echo (int) $ass['location_id']; ?>" class="oo-m-loc-title"><?php echo htmlspecialchars($ass['location_title']); ?></a>
                                    <span class="oo-m-loc-sub"><?php echo htmlspecialchars($ass['city']); ?> · закреплён с <?php echo date('d.m.Y', strtotime($ass['created_at'])); ?></span>
                                    <?php if ($machines): ?>
                                        <?php foreach ($machines as $m):
                                            [$pillMod, $pillIcon, $pillText] = $ooServiceState($m);
                                        ?>
                                            <span class="oo-m-machine"><?php echo rr_icon('square'); ?> <?php echo htmlspecialchars($machineTypeLabels[$m['machine_type']] ?? $m['machine_type']); ?><?php if (!empty($m['model'])): ?> · <?php echo htmlspecialchars($m['model']); ?><?php endif; ?></span>
                                            <span class="m-pill <?php echo $pillMod; ?>"><?php echo rr_icon($pillIcon); ?> <?php echo htmlspecialchars($pillText); ?></span>
                                        <?php endforeach; ?>
                                    <?php else: ?>
                                        <span class="m-pill is-muted"><?php echo rr_icon('square'); ?> Вендинг ещё не указан</span>
                                    <?php endif; ?>
                                </div>
                                <button type="button" class="oo-m-more" data-m-sheet-open="<?php echo $sheetId; ?>" aria-haspopup="dialog" aria-controls="<?php echo $sheetId; ?>" aria-label="Действия: <?php echo htmlspecialchars($ass['location_title']); ?>"><?php echo rr_icon('more'); ?></button>
                                <div class="m-sheet" id="<?php echo $sheetId; ?>" role="dialog" aria-modal="true" aria-hidden="true" aria-labelledby="<?php echo $sheetId; ?>T">
                                    <div class="m-sheet-handle" aria-hidden="true"></div>
                                    <div class="m-sheet-head">
                                        <span class="m-sheet-who">
                                            <b id="<?php echo $sheetId; ?>T"><?php echo htmlspecialchars($ass['location_title']); ?></b>
                                            <small><?php echo htmlspecialchars($op['name']); ?></small>
                                        </span>
                                        <button type="button" class="m-sheet-x" data-m-sheet-close aria-label="Закрыть"><?php echo rr_icon('x'); ?></button>
                                    </div>
                                    <div class="m-sheet-body">
                                        <ul class="m-menu">
                                            <?php if ($chatId): ?>
                                                <li><a href="/pages/application_chat.php?application_id=<?php echo (int) $chatId; ?>" class="m-menu-item"><?php echo rr_icon('message-circle'); ?><span>Написать оператору</span></a></li>
                                            <?php endif; ?>
                                            <li><a href="/pages/location.php?id=<?php echo (int) $ass['location_id']; ?>" class="m-menu-item"><?php echo rr_icon('eye'); ?><span>Открыть локацию</span></a></li>
                                            <li><a href="/pages/events_calendar.php" class="m-menu-item"><?php echo rr_icon('calendar'); ?><span>Выезды в календаре</span></a></li>
                                            <li><a href="/pages/service_history.php?location_id=<?php echo (int) $ass['location_id']; ?>" class="m-menu-item"><?php echo rr_icon('wrench'); ?><span>История обслуживания</span></a></li>
                                            <li><button type="button" class="m-menu-item is-danger btn-unassign" data-id="<?php echo (int) $ass['id']; ?>"><?php echo rr_icon('x'); ?><span>Открепить оператора</span></button></li>
                                        </ul>
                                    </div>
                                </div>
                            </li>
                        <?php endforeach; ?>
                    </ul>
                </article>
            <?php endforeach; ?>
        </div>
    <?php else: ?>
        <div class="empty">
            <span class="m-only oo-empty-ic" aria-hidden="true"><?php echo rr_icon('users'); ?></span>
            <p>У вас пока нет закреплённых операторов.</p>
            <p>Нажмите «Закрепить оператора», чтобы назначить оператора на локацию.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Модалка добавления -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeModal()" aria-label="Закрыть">&times;</button>
        <h3><?php echo rr_icon('plus-circle'); ?> Закрепить оператора</h3>
        <form id="addForm">
            <div class="form-group">
                <label for="locationSelect">Локация</label>
                <select id="locationSelect" required>
                    <option value="">-- Выберите локацию --</option>
                    <?php foreach ($locations as $loc): ?>
                        <option value="<?php echo $loc['id']; ?>"><?php echo htmlspecialchars($loc['title'] . ' (' . $loc['city'] . ')'); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div class="form-group">
                <label for="operatorSelect">Оператор</label>
                <select id="operatorSelect" required>
                    <option value="">-- Выберите оператора --</option>
                    <?php foreach ($operators as $op): ?>
                        <option value="<?php echo $op['id']; ?>"><?php echo htmlspecialchars($op['full_name']); ?></option>
                    <?php endforeach; ?>
                </select>
            </div>
            <div id="addError" class="modal-error" role="alert"></div>
            <button type="submit" class="btn-submit">Закрепить</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Открепление
    document.querySelectorAll('.btn-unassign').forEach(function(btn) {
        btn.addEventListener('click', function() {
            var self = this;
            rrConfirm('Открепить этого оператора от локации?', { okText: 'Открепить', danger: true }).then(function(ok) {
                if (!ok) return;
                var id = self.dataset.id;
                var formData = new FormData();
                formData.append('action', 'unassign');
                formData.append('location_operator_id', id);
                fetch('/api/operator_assign.php', { method: 'POST', body: formData })
                    .then(response => response.json())
                    .then(data => {
                        if (data.success) {
                            showToast('Оператор откреплён', 'success');
                            setTimeout(() => location.reload(), 800);
                        } else {
                            showToast('Ошибка: ' + data.error, 'error');
                        }
                    })
                    .catch(err => showToast('Ошибка соединения', 'error'));
            });
        });
    });

    // Открытие модалки
    document.getElementById('addAssignmentBtn').addEventListener('click', function() {
        document.getElementById('addModal').classList.add('active');
    });

    // Закрытие модалки
    window.closeModal = function() {
        document.getElementById('addModal').classList.remove('active');
        document.getElementById('addError').classList.remove('show');
    };
    document.getElementById('addModal').addEventListener('click', function(e) {
        if (e.target === this) closeModal();
    });
    document.addEventListener('keydown', function(e) {
        if (e.key === 'Escape') closeModal();
    });

    // Отправка формы
    document.getElementById('addForm').addEventListener('submit', function(e) {
        e.preventDefault();
        var locationId = document.getElementById('locationSelect').value;
        var operatorId = document.getElementById('operatorSelect').value;
        var errorEl = document.getElementById('addError');
        errorEl.classList.remove('show');

        if (!locationId || !operatorId) {
            errorEl.textContent = 'Выберите локацию и оператора';
            errorEl.classList.add('show');
            return;
        }

        var formData = new FormData();
        formData.append('action', 'assign');
        formData.append('location_id', locationId);
        formData.append('operator_id', operatorId);

        fetch('/api/operator_assign.php', { method: 'POST', body: formData })
            .then(response => response.json())
            .then(data => {
                if (data.success) {
                    showToast('Оператор закреплён', 'success');
                    setTimeout(() => location.reload(), 800);
                } else {
                    errorEl.textContent = data.error || 'Ошибка';
                    errorEl.classList.add('show');
                }
            })
            .catch(err => {
                errorEl.textContent = 'Ошибка соединения';
                errorEl.classList.add('show');
            });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>