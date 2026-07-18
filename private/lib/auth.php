<?php
declare(strict_types=1);

/**
 * auth.php — admin + user session helpers.
 */

/* ------------------------------- Admin ---------------------------------- */

function admin_logged_in(): bool
{
    return !empty($_SESSION['is_admin']) && !empty($_SESSION['admin_id']);
}

/** Gate for /admin/* pages (except login). */
function require_admin(): array
{
    if (!app_installed()) {
        redirect('/admin/login.php');
    }
    if (!admin_logged_in()) {
        redirect('/admin/login.php');
    }
    $stmt = db()->prepare('SELECT id, email FROM admin_users WHERE id = ?');
    $stmt->execute([$_SESSION['admin_id']]);
    $admin = $stmt->fetch();
    if (!$admin) {
        admin_logout();
        redirect('/admin/login.php');
    }
    return $admin;
}

/** Establish the admin session after password + MFA both pass. */
function admin_login(int $adminId): void
{
    session_regenerate_id(true);
    $_SESSION['admin_id'] = $adminId;
    $_SESSION['is_admin'] = 1;
    unset($_SESSION['admin_mfa_pending'], $_SESSION['mfa_attempts'], $_SESSION['admin_unmask_until']);
}

function admin_logout(): void
{
    unset($_SESSION['admin_id'], $_SESSION['is_admin'], $_SESSION['admin_mfa_pending'], $_SESSION['admin_unmask_until']);
    session_regenerate_id(true);
}

/**
 * Are assignments currently unmasked for this admin session?
 * Set by the "unmask" password re-check; expires after 60 minutes.
 */
function admin_unmasked(): bool
{
    return admin_logged_in()
        && !empty($_SESSION['admin_unmask_until'])
        && time() < (int)$_SESSION['admin_unmask_until'];
}

/* -------------------------------- User ---------------------------------- */

function user_logged_in(): bool
{
    return !empty($_SESSION['user_id']);
}

/** Gate for /user/* pages. Returns the fresh user row. */
function require_user(): array
{
    if (!app_installed() || !user_logged_in()) {
        redirect('/login.php');
    }
    $stmt = db()->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$_SESSION['user_id']]);
    $user = $stmt->fetch();
    if (!$user) {
        user_logout();
        redirect('/login.php');
    }
    return $user;
}

function user_login(int $userId): void
{
    session_regenerate_id(true);
    $_SESSION['user_id'] = $userId;
}

function user_logout(): void
{
    unset($_SESSION['user_id'], $_SESSION['pending_login_email']);
    session_regenerate_id(true);
}
