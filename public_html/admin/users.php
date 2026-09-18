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

// Пагинация — раньше страница грузила всех пользователей разом одним
// запросом без LIMIT, тот же паттерн, что и в catalog.php (page/per_page/offset).
$page = isset($_GET['page']) ? max(1, (int)$_GET['page']) : 1;
$per_page = 20;
$offset = ($page - 1) * $per_page;
$filterParams = [];
if ($roleFilter !== '') $filterParams['role'] = $roleFilter;
if ($search !== '') $filterParams['q'] = $search;

$countStmt = $pdo->prepare("SELECT COUNT(*) FROM users $whereSql");
$countStmt->execute($params);
$total_matching = (int) $countStmt->fetchColumn();
$total_pages = max(1, (int)ceil($total_matching / $per_page));

$stmt = $pdo->prepare("
    SELECT id, full_name, email, phone, role, has_subscription, is_verified, is_banned, banned_reason,
           two_factor_enabled, locked_until, created_at
    FROM users
    $whereSql
    ORDER BY created_at DESC
    LIMIT $per_page OFFSET $offset
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
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Пользователи — Админ-панель RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
    <?php include __DIR__ . '/../includes/header.php'; ?>

    <div class="admin-container">
        <h1><?php echo rr_icon('shield'); ?> Админ-панель</h1>

        <?php if ($flash): ?>
            <div class="flash-message"><?php echo htmlspecialchars($flash); ?></div>
        <?php endif; ?>

        <div class="nav-admin">
            <a href="/admin/index.php"><?php echo rr_icon('list'); ?> На модерацию</a>
            <a href="/admin/locations.php"><?php echo rr_icon('map-pin'); ?> Все локации</a>
            <a href="/admin/users.php"><?php echo rr_icon('users'); ?> Пользователи</a>
            <a href="/admin/geocode_backfill.php"><?php echo rr_icon('globe'); ?> Геокодирование</a>
        </div>

        <div class="admin-stats">
            <div class="stat-box">
                <div class="number"><?php echo $total_matching; ?></div>
                <div class="label"><?php echo $roleFilter !== '' || $search !== '' ? 'Найдено' : 'Всего пользователей'; ?></div>
            </div>
            <div class="stat-box">
                <div class="number"><?php echo $total_owners; ?></div>
                <div class="label">Собственников</div>
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

        <h2><?php echo rr_icon('users'); ?> Все пользователи</h2>

        <form method="GET" class="admin-users-filter">
            <input type="text" name="q" placeholder="Имя или email..." value="<?php echo htmlspecialchars($search); ?>">
            <select name="role">
                <option value="">Все роли</option>
                <option value="owner" <?php echo $roleFilter === 'owner' ? 'selected' : ''; ?>>Собственники</option>
                <option value="operator" <?php echo $roleFilter === 'operator' ? 'selected' : ''; ?>>Операторы</option>
                <option value="admin" <?php echo $roleFilter === 'admin' ? 'selected' : ''; ?>>Админы</option>
            </select>
            <button type="submit" class="btn-view">Найти</button>
            <?php if ($roleFilter !== '' || $search !== ''): ?>
                <a href="/admin/users.php" class="btn-view">Сбросить</a>
            <?php endif; ?>
        </form>

        <?php if ($total_matching > 0): ?>
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
                                    $roleLabels = ['owner' => rr_icon('building') . ' Собственник', 'operator' => rr_icon('check') . ' Оператор', 'admin' => rr_icon('shield') . ' Админ'];
                                    echo $roleLabels[$u['role']] ?? htmlspecialchars($u['role']);
                                    ?>
                                </td>
                                <td>
                                    <?php if ($u['is_banned']): ?>
                                        <span class="user-status-pill user-status-banned" title="<?php echo htmlspecialchars($u['banned_reason'] ?? ''); ?>"><?php echo rr_icon('ban'); ?> Заблокирован</span>
                                    <?php endif; ?>
                                    <?php if ($u['locked_until'] && strtotime($u['locked_until']) > time()): ?>
                                        <span class="user-status-pill user-status-locked"><?php echo rr_icon('clock'); ?> Временная блокировка</span>
                                    <?php endif; ?>
                                    <span class="user-status-pill <?php echo $u['is_verified'] ? 'user-status-verified' : 'user-status-unverified'; ?>">
                                        <?php echo $u['is_verified'] ? rr_icon('check') . ' Email подтверждён' : rr_icon('mail') . ' Email не подтверждён'; ?>
                                    </span>
                                    <?php if ($u['two_factor_enabled']): ?>
                                        <span class="user-status-pill user-status-2fa"><?php echo rr_icon('lock'); ?> 2FA</span>
                                    <?php endif; ?>
                                </td>
                                <td><?php echo formatDate($u['created_at']); ?></td>
                                <td class="actions">
                                    <?php if ($u['id'] == $_SESSION['user_id']): ?>
                                        <span class="you-note">Это вы</span>
                                    <?php else: ?>
                                        <?php if ($u['is_banned']): ?>
                                            <a href="/admin/user_actions.php?action=unban&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-approve" data-rr-confirm="Разблокировать пользователя?" data-rr-confirm-ok="Разблокировать"><?php echo rr_icon('check'); ?> Разблокировать</a>
                                        <?php else: ?>
                                            <a href="/admin/user_actions.php?action=ban&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-reject" data-rr-confirm="Заблокировать пользователя? Он не сможет войти в аккаунт." data-rr-confirm-ok="Заблокировать" data-rr-confirm-danger><?php echo rr_icon('ban'); ?> Заблокировать</a>
                                        <?php endif; ?>

                                        <?php if ($u['role'] !== 'admin'): ?>
                                            <a href="/admin/user_actions.php?action=make_admin&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-view" data-rr-confirm="Сделать администратором?" data-rr-confirm-ok="Сделать"><?php echo rr_icon('shield'); ?> В админы</a>
                                        <?php elseif ($total_admins > 1): ?>
                                            <a href="/admin/user_actions.php?action=remove_admin&id=<?php echo $u['id']; ?>&csrf=<?php echo urlencode(csrf_token()); ?>" class="btn-view" data-rr-confirm="Снять права администратора?" data-rr-confirm-ok="Снять"><?php echo rr_icon('x'); ?> Снять админку</a>
                                        <?php endif; ?>
                                    <?php endif; ?>
                                </td>
                            </tr>
                        <?php endforeach; ?>
                    </tbody>
                </table>
            </div>

            <?php if ($total_pages > 1): ?>
                <div class="pagination">
                    <?php if ($page > 1): ?>
                        <a href="?page=<?php echo $page-1; ?>&<?php echo http_build_query($filterParams); ?>">←</a>
                    <?php endif; ?>
                    <?php for ($i = 1; $i <= $total_pages; $i++): ?>
                        <?php if ($i == $page): ?>
                            <span class="active"><?php echo $i; ?></span>
                        <?php else: ?>
                            <a href="?page=<?php echo $i; ?>&<?php echo http_build_query($filterParams); ?>"><?php echo $i; ?></a>
                        <?php endif; ?>
                    <?php endfor; ?>
                    <?php if ($page < $total_pages): ?>
                        <a href="?page=<?php echo $page+1; ?>&<?php echo http_build_query($filterParams); ?>">→</a>
                    <?php endif; ?>
                </div>
            <?php endif; ?>
        <?php else: ?>
            <div class="empty-pending">
                <h3><?php echo rr_icon('frown'); ?> Никого не нашлось</h3>
                <p>Попробуйте изменить фильтр.</p>
            </div>
        <?php endif; ?>
    </div>

    <?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
