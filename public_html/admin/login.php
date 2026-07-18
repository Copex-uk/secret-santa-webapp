<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /admin/login.php — three modes:
 *   1. Not installed  -> setup wizard (DB + SMTP + first admin)
 *   2. Installed      -> email + password form
 *   3. Password OK    -> MFA code form (code emailed via SMTP)
 */

$errors = [];
$notice = null;

/* =========================================================================
 * MODE 1 — SETUP WIZARD (only reachable while no config file exists)
 * ========================================================================= */
if (!app_installed()) {
    csrf_verify();

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'install') {
        // Environment (Docker) wins; otherwise take the form fields.
        if (env_db_configured()) {
            $dbHost = (string)env_str('DB_HOST');
            $dbName = (string)env_str('DB_NAME');
            $dbUser = (string)env_str('DB_USER');
            $dbPass = env_str('DB_PASS') ?? '';
        } else {
            $dbHost  = trim((string)($_POST['db_host'] ?? ''));
            $dbName  = trim((string)($_POST['db_name'] ?? ''));
            $dbUser  = trim((string)($_POST['db_user'] ?? ''));
            $dbPass  = (string)($_POST['db_pass'] ?? '');
        }
        if (env_smtp_configured()) {
            $smHost = (string)env_str('SMTP_HOST');
            $smPort = (int)(env_str('SMTP_PORT') ?? 465);
            $smUser = env_str('SMTP_USER') ?? '';
            $smPass = env_str('SMTP_PASS') ?? '';
            $smFrom = (string)env_str('SMTP_FROM_EMAIL');
            $smName = env_str('SMTP_FROM_NAME') ?? 'Secret Santa';
        } else {
            $smHost  = trim((string)($_POST['smtp_host'] ?? ''));
            $smPort  = (int)($_POST['smtp_port'] ?? 0);
            $smUser  = trim((string)($_POST['smtp_user'] ?? ''));
            $smPass  = (string)($_POST['smtp_pass'] ?? '');
            $smFrom  = trim((string)($_POST['smtp_from_email'] ?? ''));
            $smName  = trim((string)($_POST['smtp_from_name'] ?? ''));
        }
        $adEmail = strtolower(trim((string)($_POST['admin_email'] ?? '')));
        $adPass  = (string)($_POST['admin_password'] ?? '');

        if ($dbHost === '' || $dbName === '' || $dbUser === '') $errors[] = 'All database fields except password are required.';
        if ($smHost === '' || $smPort < 1 || $smPort > 65535)   $errors[] = 'SMTP host and a valid port are required.';
        if (!filter_var($smFrom, FILTER_VALIDATE_EMAIL))        $errors[] = 'From email is not a valid address.';
        if ($smName === '' || mb_strlen($smName) > 100)         $errors[] = 'From name is required (max 100 chars).';
        if (!filter_var($adEmail, FILTER_VALIDATE_EMAIL))       $errors[] = 'Admin email is not a valid address.';
        if (strlen($adPass) < 10)                               $errors[] = 'Admin password must be at least 10 characters.';

        if (!$errors) {
            // Preflight: refuse to touch the database if we can't finish.
            $cfgDir = dirname(APP_CONFIG_FILE);
            if (!is_dir($cfgDir) || !is_writable($cfgDir)) {
                $errors[] = 'The config directory (' . $cfgDir . ') is not writable — '
                    . 'fix permissions first (owner www-data / UID 33). Nothing was installed.';
            }
        }
        if (!$errors) {
            try {
                $pdo = make_pdo($dbHost, $dbName, $dbUser, $dbPass); // test connection
                run_schema($pdo);                                   // create tables

                $stmt = $pdo->prepare(
                    'INSERT INTO admin_users (email, password_hash) VALUES (?, ?)
                     ON DUPLICATE KEY UPDATE password_hash = VALUES(password_hash)'
                );
                $stmt->execute([$adEmail, password_hash($adPass, PASSWORD_BCRYPT)]);

                // Write config OUTSIDE the webroot, atomically.
                $scheme = is_https() ? 'https' : 'http';
                $hostHeader = preg_replace('/[^a-zA-Z0-9.\-:\[\]]/', '', (string)($_SERVER['HTTP_HOST'] ?? ''));
                $cfg = [
                    'installed'   => true,
                    'site_url'    => $hostHeader !== '' ? "$scheme://$hostHeader" . APP_BASE : '',
                    'public_path' => APP_PUBLIC,
                    'db'   => ['host' => $dbHost, 'name' => $dbName, 'user' => $dbUser, 'pass' => $dbPass],
                    'smtp' => [
                        'host' => $smHost, 'port' => $smPort,
                        'user' => $smUser, 'pass' => $smPass,
                        'from_email' => $smFrom, 'from_name' => $smName,
                    ],
                ];
                $dir = dirname(APP_CONFIG_FILE);
                if (!is_dir($dir) && !mkdir($dir, 0750, true)) {
                    throw new RuntimeException('Cannot create the config directory: ' . $dir);
                }
                $php = "<?php\nreturn " . var_export($cfg, true) . ";\n";
                $tmp = $dir . '/.app.php.tmp';
                if (file_put_contents($tmp, $php, LOCK_EX) === false || !rename($tmp, APP_CONFIG_FILE)) {
                    throw new RuntimeException('Cannot write the config file — check that /private/config is writable.');
                }
                @chmod(APP_CONFIG_FILE, 0640);

                flash_set('ok', 'Installed successfully. You can now log in.');
                redirect('/admin/login.php');
            } catch (PDOException $ex) {
                $errors[] = 'Database error: ' . $ex->getMessage();
            } catch (Throwable $t) {
                $errors[] = $t->getMessage();
            }
        }
    }

    page_header('Setup wizard');
    flash_show();
    foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
    ?>
    <p class="muted">First run — enter your database, SMTP and admin details.
       Configuration is written to <code>private/config/app.php</code>, outside the webroot.</p>
    <form method="post" class="card" autocomplete="off">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="install">
        <h2>1. Database connection</h2>
        <?php if (env_db_configured()): ?>
            <p class="muted">✓ Provided by environment variables
               (<code><?= e((string)env_str('DB_USER')) ?>@<?= e((string)env_str('DB_HOST')) ?>/<?= e((string)env_str('DB_NAME')) ?></code>).</p>
        <?php else: ?>
        <label>DB host</label><input type="text" name="db_host" value="localhost" required maxlength="190">
        <label>DB name</label><input type="text" name="db_name" required maxlength="64">
        <label>DB user</label><input type="text" name="db_user" required maxlength="64">
        <label>DB password</label><input type="password" name="db_pass">
        <?php endif; ?>
        <h2>2. SMTP server</h2>
        <?php if (env_smtp_configured()): ?>
            <p class="muted">✓ Provided by environment variables
               (<code><?= e((string)env_str('SMTP_HOST')) ?></code>).</p>
        <?php else: ?>
        <label>SMTP host</label><input type="text" name="smtp_host" required maxlength="190">
        <label>SMTP port (465 = SSL, 587 = STARTTLS)</label><input type="number" name="smtp_port" value="465" required min="1" max="65535">
        <label>SMTP username</label><input type="text" name="smtp_user" maxlength="190">
        <label>SMTP password</label><input type="password" name="smtp_pass">
        <label>From email</label><input type="email" name="smtp_from_email" required maxlength="190">
        <label>From name</label><input type="text" name="smtp_from_name" value="Secret Santa" required maxlength="100">
        <?php endif; ?>
        <h2>3. Initial admin account</h2>
        <label>Admin email</label><input type="email" name="admin_email" required maxlength="190">
        <label>Admin password (min 10 chars)</label><input type="password" name="admin_password" required minlength="10">
        <button type="submit">Install</button>
    </form>
    <?php
    page_footer();
    exit;
}

/* =========================================================================
 * Already logged in? Straight to the dashboard.
 * ========================================================================= */
if (admin_logged_in()) {
    redirect('/admin/dashboard.php');
}

csrf_verify();

/* =========================================================================
 * MODE 3 — MFA STEP (password already verified this session)
 * ========================================================================= */
if (!empty($_SESSION['admin_mfa_pending'])) {
    $pendingId = (int)$_SESSION['admin_mfa_pending'];

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mfa') {
        $code = preg_replace('/\D/', '', (string)($_POST['code'] ?? ''));
        if (strlen($code) !== 6) {
            $errors[] = 'Enter the 6-digit code from your email.';
        } elseif (verify_admin_mfa($pendingId, $code)) {
            admin_login($pendingId);
            redirect('/admin/dashboard.php');
        } else {
            $errors[] = 'That code is wrong, expired, or you have made too many attempts.';
        }
    }

    if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'cancel') {
        unset($_SESSION['admin_mfa_pending'], $_SESSION['mfa_attempts']);
        redirect('/admin/login.php');
    }

    page_header('Admin verification');
    foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
    ?>
    <p>A 6-digit verification code has been emailed to you. It expires in 10 minutes.</p>
    <form method="post" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="mfa">
        <label>Verification code</label>
        <input type="text" name="code" inputmode="numeric" pattern="\d{6}" maxlength="6" required autofocus>
        <button type="submit">Verify</button>
    </form>
    <form method="post"><?= csrf_field() ?><input type="hidden" name="action" value="cancel">
        <button type="submit" class="danger">Start over</button>
    </form>
    <?php
    page_footer();
    exit;
}

/* =========================================================================
 * MODE 2 — EMAIL + PASSWORD
 * ========================================================================= */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'login') {
    $email = strtolower(trim((string)($_POST['email'] ?? '')));
    $pass  = (string)($_POST['password'] ?? '');

    if (!throttle_allow(client_ip(), 'admin_pw_ip', 10, 15)) {
        $errors[] = 'Too many attempts. Try again in a few minutes.';
    } elseif (!filter_var($email, FILTER_VALIDATE_EMAIL) || $pass === '') {
        $errors[] = 'Enter your email and password.';
    } else {
        $stmt = db()->prepare('SELECT id, email, password_hash FROM admin_users WHERE email = ?');
        $stmt->execute([$email]);
        $admin = $stmt->fetch();
        if ($admin && password_verify($pass, $admin['password_hash'])) {
            if (issue_admin_mfa((int)$admin['id'], $admin['email'])) {
                session_regenerate_id(true);
                $_SESSION['admin_mfa_pending'] = (int)$admin['id'];
                $_SESSION['mfa_attempts'] = 0;
                redirect('/admin/login.php');
            }
            $errors[] = 'Could not send the verification email (rate limited or SMTP problem). Try again shortly.';
        } else {
            $errors[] = 'Invalid email or password.';
        }
    }
}

page_header('Admin login');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="login">
    <label>Email</label><input type="email" name="email" required maxlength="190" autofocus>
    <label>Password</label><input type="password" name="password" required>
    <button type="submit">Continue</button>
</form>
<?php page_footer();
