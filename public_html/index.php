<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);
if (!app_installed()) {
    redirect('/admin/login.php');
}
redirect(user_logged_in() ? '/user/dashboard.php' : '/login.php');
