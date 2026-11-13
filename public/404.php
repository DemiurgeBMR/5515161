<?php
// Страница «не найдено» (ErrorDocument 404 в .htaccess).
require __DIR__ . '/../src/bootstrap.php';
$errorCode = 404;
require __DIR__ . '/../src/partials/error_page.php';
