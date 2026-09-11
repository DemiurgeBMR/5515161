<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

// Только для админа
if (!isset($_SESSION['user_id']) || $_SESSION['user_role'] !== 'admin') {
    header('Location: /pages/login.php');
    exit;
}

$pdo = getDbConnection();

$roleFilter = $_GET['role'] ?? '';
if (!in_array($roleFilter, ['owner', 'operator', 'admin'], true)) {
    $roleFilter = '';
}
$search = trim($_GET['q'] ?? '');

$where = [];
$params = [];
if ($roleFilter !== '') {
    $where[] = 'role = ?';
    $params[] = $roleFilter;
}
if ($search !== '') {
    $where[] = '(full_name LIKE ? OR email LIKE ?)';
    $params[] = '%' . $search . '%';
    $params[] = '%' . $search . '%';
}
$whereSql = $where ? ('WHERE ' . implode(' AND ', $where)) : '';

$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, has_subscription, is_verified, is_banned, banned_reason,
           two_factor_enabled, locked_until, created_at
    FROM users
    $whereSql
    ORDER BY created_at DESC
");
$stmt->execute($params);
$users = $stmt->fetchAll();

$total_admins = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'admin'")->fetchColumn();
$total_owners = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'owner'")->fetchColumn();
$total_operators = $pdo->query("SELECT COUNT(*) FROM users WHERE role = 'operator'")->fetchColumn();

$flash = $_SESSION['flash'] ?? '';
unset($_SESSION['flash']);
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <title>Пользователи — Админ-панель RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1>👑 Админ-панель</h1>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="nav-admin">
            <a href="/admin/index.php">📋 На модерацию</a>
            <a href="/admin/locations.php">📍 Все локации</a>
            <a href="/admin/users.php">👥 Пользователи</a>
            <a href="/admin/geocode_backfill.php">🌍 Геокодирование</a>
        </div>

        <div class="admin-stats">
            <div class="stat-box">
                <div class="number"><?php echo count($users); ?></div>
                <div class="label"><?php echo $roleFilter !== '' || $search !== '' ? 'Найдено' : 'Всего пользователей'; ?></div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $total_owners; ?></div>
                <div class="label">Владельцев</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $total_operators; ?></div>
                <div class="label">Операторов</div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $total_admins; ?></div>
                <div class="label">Админов</div>
            </div>
        </div>

        <h2>👥 Все пользователи</h2>

        <form method="GET" class="admin-users-filter">
            <input type="text" name="q" placeholder="Имя или email..." value="<?php echo htmlspecialchars($search); ?>">
            <select name="role">
                <option value="">Все роли</option>
                <option value="owner" <?php echo $roleFilter === 'owner' ? 'selected' : ''; ?>>Владельцы</option>
                <option value="operator" <?php echo $roleFilter === 'operator' ? 'selected' : ''; ?>>Операторы</option>
                <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Админы</option>
            </select>
            <button type="submit" class="btn-view">Найти</button>
            <?php if ($roleFilter !== '' || $search !== ''): ?>
                <a href="/admin/users.php" class="btn-view">Сбросить</a>
            <?php endif; ?>
        </form>

        <?php if (count($users) > 0): ?>
            <div class="admin-table">
                <table>
                    <thead>
                        <tr>
                            <th>ID</th>
                            <th>Имя</th>
                            <th>Email</th>
                            <th>Роль</th>
                            <th>Статус</th>
                            <th>Регистрация</th>
                            <th>Действия</th>
                        </tr>
                    </thead>
                    <tbody>
                        <?php foreach ($users as $u): ?>
                            <tr>
                                <td>#<?php echo $u['id']; ?></td>
                                <td><?php echo htmlspecialchars($u['full_name'] ?? '—'); ?></td>
                                <td><?php echo htmlspecialchars($u['email']); ?></td>
                                <td>
                                    <?php
                                    $roleLabels = ['owner' => '🏢 Владелец', 'operator' => '🤝 Оператор', 'admin' => '👑 Админ'];
                                    echo $roleLabels[$u['role']] ?? htmlspecialchars($u['role']);
                                    ?>
                                </td>
                                <td>
                                    <?php if ($u['is_banned']): ?>
                                        <span class="user-status-pill user-status-banned" title="<?php echo htmlspecialchars($u['banned_reason'] ?? ''); ?>">🚫 Заблокирован</span>
                                    <?php endif; ?>
                                    <?php if ($u['locked_until'] && strtotime($u['locked_until']) > time()): ?>
                                        <span class="user-status-pill user-status-locked">⏳ Временная блокировка</span>
                                    <?php endif; ?>
                                    <span class="user-status-pill <?php echo $u['is_verified'] ? 'user-status-verified' : 'user-status-unverified'; ?>">
                                        <?php echo $u['is_verified'] ? '✓ Email подтверждён' : '✉️ Email не подтверждён'; ?>
                                    </span>
                                    <?php if ($u['two_factor_enabled']): ?>
                                        <span class="user-status-pill user-status-2fa">🔐 2FA</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDate($u['created_at']); ?></td>
                                <td class="actions">
                                    <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                        <span style="color: var(--text-muted, #9a9aa5); font-size: 13px;">Это вы</span>
                                    <?php else: ?>
                                        <?php if ($u['is_banned']): ?>
                                            <a href="/admin/user_actions.php?action=unban&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-approve" onclick="return confirm('Разблокировать пользователя?')">✅ Разблокировать</a>
                                        <?php else: ?>
                                            <a href="/admin/user_actions.php?action=ban&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-reject" onclick="return confirm('Заблокировать пользователя? Он не сможет войти в аккаунт.')">🚫 Заблокировать</a>
                                        <?php endif; ?>

                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <a href="/admin/user_actions.php?action=make_admin&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-view" onclick="return confirm('Сделать администратором?')">👑 В админы</a>
                                        <?php elseif ($total_admins > 1): ?>
                                            <a href="/admin/user_actions.php?action=remove_admin&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-view" onclick="return confirm('Снять права администратора?')">👤 Снять админку</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>
        <?php else: ?>
            <div class="empty-pending">
                <h3>😕 Никого не нашлось</h3>
                <p>Попробуйте изменить фильтр.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
