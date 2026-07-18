<?php
declare(strict_types=1);

/**
 * layout.php — tiny shared page chrome.
 */

function page_header(string $title, ?string $nav = null): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>' . e($title) . ' · Secret Santa</title>'
        . '<link rel="stylesheet" href="' . APP_BASE . '/assets/style.css"></head><body>';
    echo '<header class="topbar"><span class="brand">'
        . '<img src="' . APP_BASE . '/assets/logo.webp" alt="Secret Santa"></span>';
    if ($nav === 'admin') {
        echo '<nav><a href="' . APP_BASE . '/admin/dashboard.php">Dashboard</a>'
            . '<a href="' . APP_BASE . '/admin/users.php">Users</a>'
            . '<a href="' . APP_BASE . '/admin/events.php">Events</a>'
            . '<a href="' . APP_BASE . '/admin/relationships.php">Relationships</a>'
            . '<a href="' . APP_BASE . '/admin/assign.php">Generate</a>'
            . '<a href="' . APP_BASE . '/admin/assignments.php">Assignments</a>'
            . '<a href="' . APP_BASE . '/admin/emails.php">Emails</a>'
            . '<a href="' . APP_BASE . '/admin/logout.php">Log out</a></nav>';
    } elseif ($nav === 'user') {
        echo '<nav><a href="' . APP_BASE . '/user/dashboard.php">Dashboard</a>'
            . '<a href="' . APP_BASE . '/user/profile.php">My profile</a>'
            . '<a href="' . APP_BASE . '/logout.php">Log out</a></nav>';
    }
    echo '</header><main class="wrap"><h1>' . e($title) . '</h1>';
}

function page_footer(): void
{
    echo '</main></body></html>';
}

/** One-off flash messages (success / error banners). */
function flash_set(string $type, string $msg): void
{
    $_SESSION['flash'] = ['type' => $type, 'msg' => $msg];
}

function flash_show(): void
{
    if (!empty($_SESSION['flash'])) {
        $f = $_SESSION['flash'];
        unset($_SESSION['flash']);
        echo '<div class="flash ' . e($f['type']) . '">' . e($f['msg']) . '</div>';
    }
}

/* ---------------------------------------------------------------------------
 * Festive full-screen auth chrome (login / code pages).
 * ------------------------------------------------------------------------- */
function auth_page_header(string $subtitle): void
{
    echo '<!doctype html><html lang="en"><head><meta charset="utf-8">'
        . '<meta name="viewport" content="width=device-width, initial-scale=1">'
        . '<title>Secret Santa</title>'
        . '<link rel="stylesheet" href="' . APP_BASE . '/assets/style.css">'
        . '<link rel="stylesheet" href="' . APP_BASE . '/assets/auth.css"></head>'
        . '<body class="auth-body"><div class="auth-veil"></div>'
        . '<div class="auth-wrap">'
        . '<div class="auth-logo">'
        . '<img src="' . APP_BASE . '/assets/logo.webp" alt="Secret Santa"></div>'
        . '<div class="auth-card">'
        . '<div class="gift">🎁</div>'
        . '<div class="star-rule"><span>★</span></div>'
        . '<h1>Welcome!</h1>'
        . '<p class="auth-sub">' . e($subtitle) . '</p>';
}

function auth_page_footer(): void
{
    echo '<div class="flake-rule"><span>❄</span></div>'
        . '</div></div></body></html>';
}

/** Icon input: wraps a form field with an inline SVG icon. */
function auth_input(string $svgPath, string $inner): string
{
    return '<div class="input-ico">'
        . '<svg viewBox="0 0 24 24" fill="none" stroke="currentColor" stroke-width="1.8" '
        . 'stroke-linecap="round" stroke-linejoin="round" aria-hidden="true">' . $svgPath . '</svg>'
        . $inner . '</div>';
}
