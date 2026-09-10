<?php
session_start();
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
    <title>Документы — RR</title>
    <link rel="stylesheet" href="/assets/css/style.css">
    <style>
        .docs-container { max-width: 800px; margin: 40px auto; padding: 0 20px; }
        .back-link { display: inline-block; margin-bottom: 20px; color: var(--text-muted, #9a9aa5); text-decoration: none; }
        .back-link:hover { text-decoration: underline; }

        .doc-card {
            background: var(--bg-elevated, #16161c);
            border: 1px solid var(--border, #2a2a33);
            border-radius: 14px;
            padding: 24px 26px;
            margin-bottom: 18px;
            box-shadow: 0 2px 10px rgba(0,0,0,0.3);
            display: flex;
            justify-content: space-between;
            align-items: center;
            gap: 20px;
            flex-wrap: wrap;
        }
        .doc-info .icon { font-size: 32px; margin-bottom: 8px; }
        .doc-info .title { font-size: 18px; font-weight: bold; margin-bottom: 6px; color: var(--text, #f2f2f5); }
        .doc-info .desc { color: var(--text-muted, #9a9aa5); font-size: 14px; line-height: 1.5; max-width: 480px; }
        .btn-download {
            background: #e94560;
            color: white;
            text-decoration: none;
            padding: 12px 24px;
            border-radius: 8px;
            font-weight: bold;
            white-space: nowrap;
        }
        .btn-download:hover { background: #c73652; }

        .warning-box {
            background: #fff3cd;
            border: 1px solid #e9a23b;
            border-radius: 10px;
            padding: 14px 18px;
            margin-bottom: 24px;
            font-size: 14px;
            color: #664d03;
            line-height: 1.5;
        }
        .warning-box b { display: block; margin-bottom: 4px; }
    </style>
</head>
<body>
<?php include __DIR__ . '/../includes/header.php'; ?>
<div class="docs-container">
    <a href="<?php echo $backLink; ?>" class="back-link">← Назад</a>
    <h2>📄 Документы</h2>
    <p style="color: #888; margin-bottom: 20px;">Шаблоны документов, которые могут понадобиться при работе с точками.</p>

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
