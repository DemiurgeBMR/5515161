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

// Получаем все активные закрепления владельца
$stmt = $pdo->prepare("
    SELECT lo.*, l.title as location_title, l.city, u.full_name as operator_name
    FROM location_operators lo
    JOIN locations l ON lo.location_id = l.id
    JOIN users u ON lo.operator_id = u.id
    WHERE lo.owner_id = ? AND lo.status = 'active'
    ORDER BY l.city, l.title
");
$stmt->execute([$user_id]);
$assignments = $stmt->fetchAll();

// Получаем список всех локаций владельца (для выбора при создании)
$stmt = $pdo->prepare("SELECT id, title, city FROM locations WHERE owner_id = ? ORDER BY title");
$stmt->execute([$user_id]);
$locations = $stmt->fetchAll();

// Получаем список всех операторов, которые подавали заявки владельцу (для выбора)
$stmt = $pdo->prepare("
    SELECT DISTINCT u.id, u.full_name
    FROM users u
    JOIN applications a ON a.operator_id = u.id
    WHERE a.owner_id = ? AND u.role = 'operator'
    ORDER BY u.full_name
");
$stmt->execute([$user_id]);
$operators = $stmt->fetchAll();
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Мои операторы — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="oo-container">
    <a href="/pages/profile.php" class="back-link">← Назад</a>
    <h2>👥 Мои операторы</h2>
    <p class="page-intro spaced">Операторы, закреплённые за вашими локациями.</p>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="flash-message<?php echo strpos($_SESSION['flash'], '✅') !== false ? '' : ' flash-error'; ?>">
            <?php echo htmlspecialchars($_SESSION['flash']); unset($_SESSION['flash']); ?>
        </div>
    <?php endif; ?>

    <button class="btn-add" id="addAssignmentBtn">➕ Закрепить оператора</button>

    <?php if (count($assignments) > 0): ?>
        <div class="assignments-table">
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
    <?php else: ?>
        <div class="empty">
            <p>У вас пока нет закреплённых операторов.</p>
            <p>Нажмите «Закрепить оператора», чтобы назначить оператора на локацию.</p>
        </div>
    <?php endif; ?>
</div>

<!-- Модалка добавления -->
<div class="modal-overlay" id="addModal">
    <div class="modal-box">
        <button class="close-btn" onclick="closeModal()">&times;</button>
        <h3>➕ Закрепить оператора</h3>
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
            <div id="addError" class="modal-error"></div>
            <button type="submit" class="btn-submit">Закрепить</button>
        </form>
    </div>
</div>

<script>
document.addEventListener('DOMContentLoaded', function() {
    // Открепление
    document.querySelectorAll('.btn-unassign').forEach(function(btn) {
        btn.addEventListener('click', function() {
            if (!confirm('Открепить этого оператора от локации?')) return;
            var id = this.dataset.id;
            var formData = new FormData();
            formData.append('action', 'unassign');
            formData.append('location_operator_id', id);
            fetch('/api/operator_assign.php', { method: 'POST', body: formData })
                .then(response => response.json())
                .then(data => {
                    if (data.success) {
                        alert('✅ Оператор откреплён');
                        location.reload();
                    } else {
                        alert('❌ Ошибка: ' + data.error);
                    }
                })
                .catch(err => alert('❌ Ошибка соединения'));
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
                    alert('✅ Оператор закреплён');
                    location.reload();
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