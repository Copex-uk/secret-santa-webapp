<?php
declare(strict_types=1);

/**
 * codes.php — one-time codes (user login + admin MFA) and DB-backed throttling.
 */

const CODE_TTL_MINUTES   = 10;  // code lifetime
const CODE_MAX_ATTEMPTS  = 5;   // wrong guesses per code before it's burned
const THROTTLE_WINDOW_MIN = 15; // sliding window for request throttling
const THROTTLE_MAX_SENDS  = 4;  // max codes sent per identifier per window

/** Generate a random 6-digit code as a string ("042318" is valid). */
function generate_code(): string
{
    return str_pad((string)random_int(0, 999999), 6, '0', STR_PAD_LEFT);
}

/**
 * Throttle check: has $identifier performed $kind fewer than $max times
 * in the last $windowMinutes? Records the attempt when allowed.
 */
function throttle_allow(string $identifier, string $kind, int $max = THROTTLE_MAX_SENDS, int $windowMinutes = THROTTLE_WINDOW_MIN): bool
{
    $pdo = db();
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) FROM code_throttle
         WHERE identifier = ? AND kind = ? AND created_at > (NOW() - INTERVAL ? MINUTE)'
    );
    $stmt->execute([$identifier, $kind, $windowMinutes]);
    if ((int)$stmt->fetchColumn() >= $max) {
        return false;
    }
    $pdo->prepare('INSERT INTO code_throttle (identifier, kind) VALUES (?, ?)')
        ->execute([$identifier, $kind]);
    // Opportunistic cleanup of old rows
    if (random_int(1, 20) === 1) {
        $pdo->exec('DELETE FROM code_throttle WHERE created_at < (NOW() - INTERVAL 2 DAY)');
    }
    return true;
}

/* ---------------------------------------------------------------------------
 * User login codes
 * ------------------------------------------------------------------------- */

/**
 * Create + email a login code for a user. Throttled per email and per IP.
 * Always returns void; caller shows a generic message either way so the
 * page never reveals whether an email address is registered.
 */
function issue_user_login_code(string $email): void
{
    $pdo = db();
    $stmt = $pdo->prepare('SELECT id, email FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return; // silently ignore unknown addresses
    }
    if (!throttle_allow(strtolower($email), 'user_code') || !throttle_allow(client_ip(), 'user_code_ip', 10)) {
        return; // silently rate-limited
    }

    $code = generate_code();
    // Invalidate previous outstanding codes for this user
    $pdo->prepare('UPDATE user_login_codes SET used_at = NOW() WHERE user_id = ? AND used_at IS NULL')
        ->execute([$user['id']]);
    $pdo->prepare(
        'INSERT INTO user_login_codes (user_id, code_hash, expires_at)
         VALUES (?, ?, NOW() + INTERVAL ' . CODE_TTL_MINUTES . ' MINUTE)'
    )->execute([$user['id'], password_hash($code, PASSWORD_BCRYPT)]);

    send_template_email((string)$user['email'], 'login_code', [
        'code'        => $code,
        'ttl_minutes' => (string)CODE_TTL_MINUTES,
        'email'       => (string)$user['email'],
    ]);
}

/**
 * Verify a user login code. Returns the user row on success, null on failure.
 * Increments attempt counters and burns the code after too many misses.
 */
function verify_user_login_code(string $email, string $code): ?array
{
    $pdo = db();
    if (!throttle_allow(client_ip(), 'user_verify_ip', 30, 15)) {
        return null;
    }
    $stmt = $pdo->prepare('SELECT * FROM users WHERE email = ?');
    $stmt->execute([$email]);
    $user = $stmt->fetch();
    if (!$user) {
        return null;
    }

    $stmt = $pdo->prepare(
        'SELECT * FROM user_login_codes
         WHERE user_id = ? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$user['id']]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['attempts'] >= CODE_MAX_ATTEMPTS) {
        return null;
    }

    $pdo->prepare('UPDATE user_login_codes SET attempts = attempts + 1 WHERE id = ?')
        ->execute([$row['id']]);

    if (!password_verify($code, $row['code_hash'])) {
        return null;
    }
    $pdo->prepare('UPDATE user_login_codes SET used_at = NOW() WHERE id = ?')
        ->execute([$row['id']]);
    return $user;
}

/* ---------------------------------------------------------------------------
 * Admin MFA codes
 * ------------------------------------------------------------------------- */

/** Create + email an MFA code for an admin. Returns false if throttled/mail failed. */
function issue_admin_mfa(int $adminId, string $adminEmail): bool
{
    $pdo = db();
    if (!throttle_allow('admin:' . $adminId, 'admin_mfa') || !throttle_allow(client_ip(), 'admin_mfa_ip', 8)) {
        return false;
    }
    $code = generate_code();
    $pdo->prepare('UPDATE mfa_codes SET used_at = NOW() WHERE admin_id = ? AND used_at IS NULL')
        ->execute([$adminId]);
    $pdo->prepare(
        'INSERT INTO mfa_codes (admin_id, code_hash, expires_at)
         VALUES (?, ?, NOW() + INTERVAL ' . CODE_TTL_MINUTES . ' MINUTE)'
    )->execute([$adminId, password_hash($code, PASSWORD_BCRYPT)]);

    $ttl = CODE_TTL_MINUTES;
    $html = email_wrap(
        '<h1 style="margin:0 0 12px;font-size:24px;color:#8e1f17;">Admin verification</h1>'
        . '<p style="font-size:34px;letter-spacing:8px;font-weight:bold;color:#1e5c3f;margin:18px 0;">'
        . htmlspecialchars($code, ENT_QUOTES) . '</p>'
        . "<p>Enter this code to finish signing in to the admin area. It expires in {$ttl} minutes and works once.</p>"
        . '<p style="color:#7a6f5c;">If this wasn\'t you, someone knows your admin password — change it.</p>'
    );
    return smtp_send(
        $adminEmail,
        'Your admin verification code: ' . $code,
        "Your Secret Santa admin verification code is: {$code}\n\n"
        . "It expires in {$ttl} minutes and works once.\n"
        . "If this wasn't you, someone knows your admin password — change it.\n",
        $html
    );
}

/** Verify an admin MFA code. Returns true on success. */
function verify_admin_mfa(int $adminId, string $code): bool
{
    $pdo = db();
    // Session-based attempt counter + IP throttle
    $_SESSION['mfa_attempts'] = (int)($_SESSION['mfa_attempts'] ?? 0) + 1;
    if ($_SESSION['mfa_attempts'] > CODE_MAX_ATTEMPTS || !throttle_allow(client_ip(), 'admin_mfa_verify_ip', 20, 15)) {
        return false;
    }
    $stmt = $pdo->prepare(
        'SELECT * FROM mfa_codes
         WHERE admin_id = ? AND used_at IS NULL AND expires_at > NOW()
         ORDER BY id DESC LIMIT 1'
    );
    $stmt->execute([$adminId]);
    $row = $stmt->fetch();
    if (!$row || (int)$row['attempts'] >= CODE_MAX_ATTEMPTS) {
        return false;
    }
    $pdo->prepare('UPDATE mfa_codes SET attempts = attempts + 1 WHERE id = ?')->execute([$row['id']]);
    if (!password_verify($code, $row['code_hash'])) {
        return false;
    }
    $pdo->prepare('UPDATE mfa_codes SET used_at = NOW() WHERE id = ?')->execute([$row['id']]);
    unset($_SESSION['mfa_attempts']);
    return true;
}
