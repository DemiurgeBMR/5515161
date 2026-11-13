<?php
/**
 * Общие документы гостиницы.
 * Читать и скачивать могут сотрудники; загружать и удалять — только владелец.
 * Файлы лежат в storage/documents и выдаются только через эту страницу.
 */
require __DIR__ . '/../../src/bootstrap.php';
require HOTEL_ROOT . '/src/admin/layout.php';
$me = require_staff();
$isOwner = is_owner();

$allowed = [
    'pdf'  => ['application/pdf'],
    'doc'  => ['application/msword', 'application/vnd.ms-office'],
    'docx' => ['application/vnd.openxmlformats-officedocument.wordprocessingml.document', 'application/zip'],
    'xls'  => ['application/vnd.ms-excel', 'application/msexcel', 'application/vnd.ms-office'],
    'xlsx' => ['application/vnd.openxmlformats-officedocument.spreadsheetml.sheet', 'application/zip'],
    'ppt'  => ['application/vnd.ms-powerpoint', 'application/vnd.ms-office'],
    'pptx' => ['application/vnd.openxmlformats-officedocument.presentationml.presentation', 'application/zip'],
    'txt'  => ['text/plain'],
    'csv'  => ['text/plain', 'text/csv', 'application/csv', 'application/vnd.ms-excel'],
];
$dir = HOTEL_ROOT . '/storage/documents';

if (get('download') !== '') {
    $id = (int) get('download');
    $doc = q_one('SELECT * FROM admin_documents WHERE id = ?', [$id]);
    if (!$doc) {
        http_response_code(404);
        exit('Документ не найден');
    }
    $path = $dir . '/' . $doc['stored_name'];
    if (!is_file($path) || basename($doc['stored_name']) !== $doc['stored_name']) {
        http_response_code(404);
        exit('Файл не найден');
    }
    $name = str_replace(["\r", "\n", '"'], '', $doc['original_name']);
    header('Content-Type: ' . $doc['mime_type']);
    header('Content-Length: ' . (string) filesize($path));
    header("Content-Disposition: " . ($doc['mime_type'] === 'application/pdf' ? 'inline' : 'attachment')
        . "; filename=\"document\"; filename*=UTF-8''" . rawurlencode($name));
    header('X-Content-Type-Options: nosniff');
    header('Cache-Control: private, no-store');
    readfile($path);
    exit;
}

if (is_post()) {
    csrf_check();
    if (!$isOwner) {
        http_response_code(403);
        exit('Добавлять и удалять документы может только владелец.');
    }
    $action = post('action');
    if ($action === 'upload') {
        $file = $_FILES['document'] ?? null;
        if (!$file || ($file['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_OK || !is_uploaded_file((string) ($file['tmp_name'] ?? ''))) {
            flash('error', 'Не удалось загрузить файл. Выберите документ ещё раз.');
            redirect('/admin/documents.php');
        }
        $original = trim(str_replace(["\0", "\r", "\n", '/', '\\'], '', (string) $file['name']));
        $ext = strtolower(pathinfo($original, PATHINFO_EXTENSION));
        if (!isset($allowed[$ext])) {
            flash('error', 'Поддерживаются PDF, Word, Excel, PowerPoint, TXT и CSV.');
            redirect('/admin/documents.php');
        }
        if ((int) $file['size'] < 1 || (int) $file['size'] > 25 * 1024 * 1024) {
            flash('error', 'Размер документа должен быть не более 25 МБ.');
            redirect('/admin/documents.php');
        }
        $finfo = new finfo(FILEINFO_MIME_TYPE);
        $mime = $finfo->file((string) $file['tmp_name']);
        if (!in_array($mime, $allowed[$ext], true)) {
            flash('error', 'Тип файла не соответствует расширению. Проверьте документ.');
            redirect('/admin/documents.php');
        }
        $title = trim(post('title'));
        if ($title === '') {
            $title = pathinfo($original, PATHINFO_FILENAME);
        }
        $title = mb_substr($title, 0, 160);
        if (!is_dir($dir) && !mkdir($dir, 0700, true) && !is_dir($dir)) {
            throw new RuntimeException('Не удалось создать закрытую папку документов.');
        }
        $stored = bin2hex(random_bytes(20)) . '.' . $ext;
        if (!move_uploaded_file((string) $file['tmp_name'], $dir . '/' . $stored)) {
            throw new RuntimeException('Не удалось сохранить документ на сервере.');
        }
        try {
            q_insert('admin_documents', [
                'title' => $title,
                'original_name' => mb_substr($original, 0, 255),
                'stored_name' => $stored,
                'mime_type' => $mime,
                'file_size' => (int) $file['size'],
                'uploaded_by' => (int) $me['id'],
                'created_at' => now(),
            ]);
        } catch (Throwable $e) {
            @unlink($dir . '/' . $stored);
            throw $e;
        }
        flash('ok', 'Документ «' . $title . '» добавлен.');
        redirect('/admin/documents.php');
    }
    if ($action === 'delete') {
        $id = (int) post('id');
        $doc = q_one('SELECT * FROM admin_documents WHERE id = ?', [$id]);
        if ($doc) {
            q('DELETE FROM admin_documents WHERE id = ?', [$id]);
            $path = $dir . '/' . $doc['stored_name'];
            if (basename($doc['stored_name']) === $doc['stored_name'] && is_file($path)) {
                @unlink($path);
            }
            flash('ok', 'Документ удалён.');
        }
        redirect('/admin/documents.php');
    }
    http_response_code(400);
    exit('Неизвестное действие');
}

$documents = q_all(
    'SELECT d.*, s.name AS uploader_name FROM admin_documents d LEFT JOIN staff s ON s.id = d.uploaded_by ORDER BY d.created_at DESC, d.id DESC'
);
function document_size_label(int $bytes): string
{
    if ($bytes >= 1024 * 1024) return number_format($bytes / 1024 / 1024, 1, ',', ' ') . ' МБ';
    return max(1, (int) round($bytes / 1024)) . ' КБ';
}

admin_header('Документы', 'documents');
?>
<div class="adm-head">
    <div>
        <h1>Документы</h1>
        <p class="adm-head__sub">Инструкции и рабочие материалы гостиницы «Лофт».</p>
    </div>
</div>

<?php if ($isOwner): ?>
<section class="panel">
    <h2 class="panel__title"><?= icon('upload') ?> Добавить документ</h2>
    <form method="post" enctype="multipart/form-data" class="form-grid">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="upload">
        <div class="field span-all">
            <label for="doc-title">Название документа</label>
            <input id="doc-title" name="title" maxlength="160" placeholder="Например, Инструкция администратора">
        </div>
        <div class="field span-all">
            <label for="doc-file">Файл (до 25 МБ)</label>
            <input id="doc-file" name="document" type="file" required accept=".pdf,.doc,.docx,.xls,.xlsx,.ppt,.pptx,.txt,.csv">
            <small class="hint">PDF, Word, Excel, PowerPoint, TXT или CSV.</small>
        </div>
        <div class="form-actions span-all">
            <button class="btn btn--primary btn--sm" type="submit"><?= icon('upload') ?>Загрузить</button>
        </div>
    </form>
</section>
<?php else: ?>
<p class="hint">Загружать и удалять документы может только владелец гостиницы.</p>
<?php endif; ?>

<section class="panel">
    <h2 class="panel__title"><?= icon('folder-open') ?> Все документы <small><?= count($documents) ?></small></h2>
    <?php if (!$documents): ?>
        <p class="empty">Документов пока нет. Владелец может добавить сюда инструкцию и другие файлы.</p>
    <?php else: ?>
        <div class="blist">
        <?php foreach ($documents as $doc): ?>
            <div class="brow">
                <span class="brow__name"><?= icon('file-text') ?> <?= e($doc['title']) ?></span>
                <span class="status status--confirmed"><?= e(strtoupper(pathinfo($doc['original_name'], PATHINFO_EXTENSION))) ?></span>
                <span class="brow__meta"><?= e($doc['original_name']) ?> · <?= e(document_size_label((int) $doc['file_size'])) ?> · <?= e(ru_datetime($doc['created_at'])) ?><?= $doc['uploader_name'] ? ' · добавил(а) ' . e($doc['uploader_name']) : '' ?></span>
                <span class="brow__meta"><a class="btn btn--outline btn--sm" href="/admin/documents.php?download=<?= (int) $doc['id'] ?>" target="_blank" rel="noopener"><?= icon('download') ?>Открыть / скачать</a></span>
                <?php if ($isOwner): ?>
                    <form method="post" class="form-actions">
                        <?= csrf_field() ?>
                        <input type="hidden" name="action" value="delete">
                        <input type="hidden" name="id" value="<?= (int) $doc['id'] ?>">
                        <button class="btn btn--danger btn--sm" type="submit" data-confirm="Удалить документ «<?= e($doc['title']) ?>»?"><?= icon('trash-2') ?>Удалить</button>
                    </form>
                <?php endif; ?>
            </div>
        <?php endforeach; ?>
        </div>
    <?php endif; ?>
</section>
<?php admin_footer(); ?>
