Vendored copy of PHPMailer (https://github.com/PHPMailer/PHPMailer), version 7.1.1,
MIT License (see LICENSE in this folder).

The project has no Composer/автозагрузчик, поэтому библиотека подключается
напрямую тремя require_once (см. rr_send_email() в config.php), так же как
tfpdf в includes/tfpdf/.

Обновление: скачать заново PHPMailer.php, SMTP.php, Exception.php из
https://github.com/PHPMailer/PHPMailer/tree/master/src и заменить файлы в
этой папке.
