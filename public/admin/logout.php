<?php
require __DIR__ . '/../../src/bootstrap.php';

if (is_post()) {
    csrf_check();
    staff_logout();
}
redirect('/admin/login.php');
