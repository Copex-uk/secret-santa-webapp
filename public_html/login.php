<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /login.php — user enters email, receives a 6-digit login code by email.
 * The response is identical whether or not the email is registered.
 */

if (!app_installed()) {
    redirect('/admin/login.php');
}
if (user_logged_in()) {
    redirect('/user/dashboard.php');
}

csrf_verify();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
        $errors[] = 'Enter a valid email address.';
    } else {
        issue_user_login_code($email);          // silently no-ops for unknown/throttled
        $_SESSION['pending_login_email'] = $email;
        flash_set('ok', 'If that address is registered, a login code has been emailed to it.');
        redirect('/code.php');
    }
}

auth_page_header('Please sign in to access your Secret Santa dashboard');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<form method="post">
    <?= csrf_field() ?>
    <?= auth_input(
        '<path d="M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"/><path d="m3 7 9 6 9-6"/>',
        '<input type="email" name="email" placeholder="Email address" required maxlength="190" autofocus>'
    ) ?>
    <button type="submit" class="btn-festive">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        Email me a login code
    </button>
</form>
<p class="auth-alt">Already have a code? <a href="<?= APP_BASE ?>/code.php">Enter it here</a></p>
<?php auth_page_footer();
