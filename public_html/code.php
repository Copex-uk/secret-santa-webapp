<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /code.php — user submits the 6-digit login code from their email.
 */

if (!app_installed()) {
    redirect('/admin/login.php');
}
if (user_logged_in()) {
    redirect('/user/dashboard.php');
}

csrf_verify();
$errors = [];
$prefillEmail = (string)($_SESSION['pending_login_email'] ?? '');

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $code  = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));

    if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($code) !== 6) {
        $errors[] = 'Enter your email and the 6-digit code.';
    } else {
        $user = verify_user_login_code($email, $code);
        if ($user) {
            user_login((int)$user['id']);
            unset($_SESSION['pending_login_email']);
            redirect((int)$user['profile_complete'] === 1 ? '/user/dashboard.php' : '/user/profile.php');
        }
        $errors[] = 'That code is wrong, expired, or you have made too many attempts. '
                  . 'You can request a new one from the login page.';
    }
    $prefillEmail = $email;
}

auth_page_header('Enter the 6-digit code we emailed you');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<form method="post">
    <?= csrf_field() ?>
    <?= auth_input(
        '<path d="M4 6h16a1 1 0 0 1 1 1v10a1 1 0 0 1-1 1H4a1 1 0 0 1-1-1V7a1 1 0 0 1 1-1Z"/><path d="m3 7 9 6 9-6"/>',
        '<input type="email" name="email" placeholder="Email address" required maxlength="190" value="' . e($prefillEmail) . '">'
    ) ?>
    <?= auth_input(
        '<rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/>',
        '<input type="text" name="code" placeholder="6-digit code" inputmode="numeric" pattern="\d{6}" maxlength="6" required ' . ($prefillEmail ? 'autofocus' : '') . '>'
    ) ?>
    <button type="submit" class="btn-festive">
        <svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="2" stroke-linecap="round" aria-hidden="true"><rect x="5" y="11" width="14" height="9" rx="2"/><path d="M8 11V8a4 4 0 0 1 8 0v3"/></svg>
        Log in
    </button>
</form>
<p class="auth-alt">No code yet? <a href="<?= APP_BASE ?>/login.php">Request one</a></p>
<?php auth_page_footer();
