<?php
session_start();
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
    <title>Мои операторы — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .container { max-width: 1000px; margin: 40px auto; padding: 0 20px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: var(--text-muted, #9a9aa5); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }
        .assignments-table { background: var(--bg-elevated, #16161c); border: 1px solid var(--border, #2a2a33); border-radius: 12px; overflow: hidden; box-shadow: 0 2px 10px rgba(0,0,0,0.3); color: var(--text, #f2f2f5); }
        .assignments-table table { width: 100%; border-collapse: collapse; }
        .assignments-table th { background: var(--bg-elevated-2, #1c1c24); text-align: left; padding: 12px 15px; font-weight: 600; }
        .assignments-table td { padding: 12px 15px; border-top: 1px solid var(--border, #2a2a33); }
        .btn-unassign { background: #e74c3c; color: white; border: none; padding: 4px 12px; border-radius: 4px; cursor: pointer; }
        .btn-unassign:hover { background: #c0392b; }
        .btn-add { background: #e94560; color: white; border: none; padding: 8px 20px; border-radius: 6px; cursor: pointer; font-weight: bold; margin-bottom: 20px; }
        .btn-add:hover { background: #c73652; }
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
            max-width: 500px;
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
        .modal-box label { display: block; font-weight: 600; margin-bottom: 5px; }
        .modal-box select { width: 100%; padding: 10px; background: var(--bg-input, #0f0f14); border: 1px solid var(--border, #2a2a33); border-radius: 6px; color: var(--text, #f2f2f5); }
        .modal-box .btn-submit { width: 100%; padding: 12px; background: #e94560; color: white; border: none; border-radius: 6px; font-weight: bold; cursor: pointer; }
        .modal-box .btn-submit:hover { background: #c73652; }
        .empty { text-align: center; padding: 40px; color: var(--text-muted, #9a9aa5); }
        .flash { padding: 12px 20px; border-radius: 8px; margin-bottom: 20px; }
        .flash-success { background: rgba(46, 204, 113, 0.15); color: #6ee7a0; border: 1px solid rgba(46, 204, 113, 0.3); }
        .flash-error { background: rgba(231, 76, 60, 0.15); color: #ff8a9b; border: 1px solid rgba(231, 76, 60, 0.3); }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="container">
    <a href="/pages/profile.php" class="back-link">← Назад</a>
    <h2>👥 Мои операторы</h2>
    <p style="color: #888; margin-bottom: 20px;">Операторы, закреплённые за вашими локациями.</p>

    <?php if (isset($_SESSION['flash'])): ?>
        <div class="flash flash-<?php echo strpos($_SESSION['flash'], '✅') !== false ? 'success' : 'error'; ?>">
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
            <div id="addError" style="color: #e74c3c; margin-bottom: 10px; display: none;"></div>
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
        document.getElementById('addError').style.display = 'none';
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
        errorEl.style.display = 'none';

        if (!locationId || !operatorId) {
            errorEl.textContent = 'Выберите локацию и оператора';
            errorEl.style.display = 'block';
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
                    errorEl.style.display = 'block';
                }
            })
            .catch(err => {
                errorEl.textContent = 'Ошибка соединения';
                errorEl.style.display = 'block';
            });
    });
});
</script>

<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>