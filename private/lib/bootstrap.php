<?php
declare(strict_types=1);

/**
 * bootstrap.php — include this at the top of every public page.
 * Loads config, libraries, and starts a hardened session.
 */

define('APP_PRIVATE', dirname(__DIR__));                 // .../private
define('APP_CONFIG_FILE', APP_PRIVATE . '/config/app.php');

/*
 * Work out where the app's PUBLIC root lives, both on disk (APP_PUBLIC)
 * and as a URL prefix (APP_BASE). This makes subfolder installs work:
 * the app may sit at the domain root ('' prefix) or in any subdirectory
 * (e.g. '/santa'). Pages live either directly in the public root or one
 * level down in /admin or /user, so we strip that suffix if present.
 */
if (PHP_SAPI === 'cli') {
    define('APP_BASE', '');
    // On CLI (cron) the public path is recorded in the config at install time.
    define('APP_PUBLIC', '');
} else {
    $scriptDirFs  = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_FILENAME'] ?? __DIR__)));
    $scriptDirUrl = str_replace('\\', '/', dirname((string)($_SERVER['SCRIPT_NAME'] ?? '/')));
    if (preg_match('~/(admin|user)$~', $scriptDirFs)) {
        $scriptDirFs  = dirname($scriptDirFs);
        $scriptDirUrl = dirname($scriptDirUrl);
    }
    $scriptDirUrl = ($scriptDirUrl === '/' || $scriptDirUrl === '\\' || $scriptDirUrl === '.') ? '' : rtrim($scriptDirUrl, '/');
    // Keep the URL prefix conservative: path characters only.
    $scriptDirUrl = preg_replace('~[^A-Za-z0-9_\-./]~', '', $scriptDirUrl) ?? '';
    define('APP_BASE', $scriptDirUrl);
    define('APP_PUBLIC', rtrim($scriptDirFs, '/'));
}

/** Is the app installed (config file written by the setup wizard)? */
function app_installed(): bool
{
    if (!is_file(APP_CONFIG_FILE)) {
        return false;
    }
    $cfg = config();
    return !empty($cfg['installed']);
}

/** Read an environment variable (empty string counts as unset). */
function env_str(string $key): ?string
{
    $v = getenv($key);
    return ($v === false || $v === '') ? null : $v;
}

/** Are DB settings provided via environment (Docker / .env)? */
function env_db_configured(): bool
{
    return env_str('DB_HOST') !== null && env_str('DB_NAME') !== null && env_str('DB_USER') !== null;
}

/** Are SMTP settings provided via environment? */
function env_smtp_configured(): bool
{
    return env_str('SMTP_HOST') !== null && env_str('SMTP_FROM_EMAIL') !== null;
}

/** Load the server-side config (kept outside the webroot). */
function config(): array
{
    static $cfg = null;
    if ($cfg === null) {
        $cfg = is_file(APP_CONFIG_FILE) ? (require APP_CONFIG_FILE) : [];
        if (!is_array($cfg)) {
            $cfg = [];
        }
        // Environment variables take precedence over the config file, so a
        // Docker deployment is driven entirely by .env / compose.
        if (env_db_configured()) {
            $cfg['db'] = [
                'host' => env_str('DB_HOST'),
                'name' => env_str('DB_NAME'),
                'user' => env_str('DB_USER'),
                'pass' => env_str('DB_PASS') ?? '',
            ];
        }
        if (env_smtp_configured()) {
            $cfg['smtp'] = [
                'host'       => env_str('SMTP_HOST'),
                'port'       => (int)(env_str('SMTP_PORT') ?? 465),
                'user'       => env_str('SMTP_USER') ?? '',
                'pass'       => env_str('SMTP_PASS') ?? '',
                'from_email' => env_str('SMTP_FROM_EMAIL'),
                'from_name'  => env_str('SMTP_FROM_NAME') ?? 'Secret Santa',
            ];
        }
        if (env_str('SITE_URL') !== null) {
            $cfg['site_url'] = rtrim((string)env_str('SITE_URL'), '/');
        }
    }
    return $cfg;
}

/** Detect HTTPS (directly or behind a proxy). */
function is_https(): bool
{
    return (!empty($_SERVER['HTTPS']) && strtolower((string)$_SERVER['HTTPS']) !== 'off')
        || (($_SERVER['HTTP_X_FORWARDED_PROTO'] ?? '') === 'https')
        || (($_SERVER['SERVER_PORT'] ?? '') == 443);
}

/** Start a session with hardened cookie flags. */
function start_app_session(): void
{
    if (PHP_SAPI === 'cli' || session_status() === PHP_SESSION_ACTIVE) {
        return;
    }
    /*
     * Persistent logins: participant sessions last SESSION_DAYS (default 15).
     * The cookie lifetime alone is not enough — PHP's garbage collector
     * deletes session files after ~24 minutes by default — so sessions are
     * stored in a private directory with a matching gc_maxlifetime. Admin
     * privilege is separately time-boxed in auth.php.
     */
    $sessionDays = (int)(env_str('SESSION_DAYS') ?? 15);
    if ($sessionDays < 1 || $sessionDays > 90) {
        $sessionDays = 15;
    }
    define('SESSION_LIFETIME', $sessionDays * 86400);
    $sessDir = APP_PRIVATE . '/sessions';
    if (!is_dir($sessDir)) {
        @mkdir($sessDir, 0700, true);
    }
    if (is_dir($sessDir) && is_writable($sessDir)) {
        ini_set('session.save_path', $sessDir);
        ini_set('session.gc_maxlifetime', (string)SESSION_LIFETIME);
        ini_set('session.gc_probability', '1');
        ini_set('session.gc_divisor', '100');
    }
    session_name('SSANTA_SESS');
    session_set_cookie_params([
        'lifetime' => SESSION_LIFETIME,
        'path'     => '/',
        'domain'   => '',
        'secure'   => is_https(),   // secure flag whenever we are on HTTPS
        'httponly' => true,
        'samesite' => 'Lax',
    ]);
    ini_set('session.use_strict_mode', '1');
    session_start();

    // Sliding renewal: re-issue the cookie daily so active users never expire.
    if (!isset($_SESSION['cookie_ts']) || time() - (int)$_SESSION['cookie_ts'] > 86400) {
        $_SESSION['cookie_ts'] = time();
        setcookie(session_name(), session_id(), [
            'expires'  => time() + SESSION_LIFETIME,
            'path'     => '/',
            'domain'   => '',
            'secure'   => is_https(),
            'httponly' => true,
            'samesite' => 'Lax',
        ]);
    }
}

/* Graceful fallback if the mbstring extension is missing on the host. */
if (!function_exists('mb_strlen')) {
    function mb_strlen(string $s): int
    {
        return strlen($s);
    }
}

/** HTML-escape helper. */
function e(?string $s): string
{
    return htmlspecialchars((string)$s, ENT_QUOTES, 'UTF-8');
}

/** Redirect and stop. */
function redirect(string $to): void
{
    // App-relative paths get the subfolder prefix; full URLs pass through.
    if ($to !== '' && $to[0] === '/') {
        $to = APP_BASE . $to;
    }
    header('Location: ' . $to);
    exit;
}

/** Client IP (best effort on shared hosting). */
function client_ip(): string
{
    return (string)($_SERVER['REMOTE_ADDR'] ?? '0.0.0.0');
}

require APP_PRIVATE . '/lib/db.php';
require APP_PRIVATE . '/lib/csrf.php';
require APP_PRIVATE . '/lib/mailer.php';
require APP_PRIVATE . '/lib/templates.php';
require APP_PRIVATE . '/lib/codes.php';
require APP_PRIVATE . '/lib/auth.php';
require APP_PRIVATE . '/lib/upload.php';
require APP_PRIVATE . '/lib/assignment.php';
require APP_PRIVATE . '/lib/layout.php';
require APP_PRIVATE . '/lib/events.php';

start_app_session();
