<?php
require_once __DIR__ . '/../includes/session_bootstrap.php';
rr_session_start();
require_once __DIR__ . '/../config.php';

if (!isset($_SESSION['user_id']) || !in_array($_SESSION['user_role'] ?? '', ['operator', 'owner'], true)) {
    header('Location: /pages/login.php');
    exit;
}

$role = $_SESSION['user_role'];
$backLink = ($role === 'operator') ? '/pages/operator_dashboard.php' : '/pages/profile.php';
?>
<!DOCTYPE html>
<html lang="ru">
<head>
    <meta charset="UTF-8">
    <meta name="viewport" content="width=device-width, initial-scale=1.0">
    <title>Документы — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="docs-container">
    <a href="<?php echo $backLink; ?>" class="back-link">← Назад</a>
    <h2>📄 Документы</h2>
    <p class="page-intro spaced">Шаблоны документов, которые могут понадобиться при работе с точками.</p>

    <div class="warning-box">
        <b>⚠ Это черновики-шаблоны, а не готовые юридические документы</b>
        Перед использованием в реальных сделках обязательно проверьте документ у практикующего юриста —
        с учётом вашей юрисдикции, формы собственности сторон и актуального законодательства.
    </div>

    <div class="doc-card">
        <div class="doc-info">
            <div class="icon">📝</div>
            <div class="title">Договор о размещении и обслуживании вендингового автомата</div>
            <div class="desc">
                Типовой договор между владельцем локации и оператором: предмет, порядок оплаты
                (фиксированная / % от выручки / бесплатно), права и обязанности сторон, срок действия,
                ответственность, порядок расторжения. С приложением — акт приёма-передачи оборудования.
            </div>
        </div>
        <a class="btn-download" href="/assets/documents/dogovor_razmeschenie_vendinga.docx" download>
            ⬇️ Скачать .docx
        </a>
    </div>
</div>
<?php include __DIR__ . '/../includes/footer.php'; ?>
</body>
</html>
