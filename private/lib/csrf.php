<?php
declare(strict_types=1);

/**
 * csrf.php — per-session CSRF token for all POST forms.
 */

function csrf_token(): string
{
    if (empty($_SESSION['csrf_token'])) {
        $_SESSION['csrf_token'] = bin2hex(random_bytes(32));
    }
    return $_SESSION['csrf_token'];
}

/** Hidden input to drop inside every <form method="post">. */
function csrf_field(): string
{
    return '<input type="hidden" name="csrf_token" value="' . e(csrf_token()) . '">';
}

/**
 * Verify the token on POST requests. Call at the top of every POST handler.
 * Dies with 400 on failure so no state is ever changed.
 */
function csrf_verify(): void
{
    if (($_SERVER['REQUEST_METHOD'] ?? 'GET') !== 'POST') {
        return;
    }
    /*
     * If the request body exceeded post_max_size, PHP silently drops the
     * whole payload ($_POST and $_FILES are empty) — including the CSRF
     * token. Detect that case and explain it, instead of a misleading
     * "invalid token" error. Typical cause: a phone photo bigger than
     * the server's upload limit.
     */
    if (!$_POST && !$_FILES && (int)($_SERVER['CONTENT_LENGTH'] ?? 0) > 0) {
        $limit = ini_get('post_max_size') ?: 'the server limit';
        http_response_code(413);
        exit('Your upload was too large for the server to accept (limit: '
            . htmlspecialchars((string)$limit, ENT_QUOTES)
            . '). Please choose a smaller photo and try again.');
    }
    $sent = (string)($_POST['csrf_token'] ?? '');
    if ($sent === '' || empty($_SESSION['csrf_token']) || !hash_equals($_SESSION['csrf_token'], $sent)) {
        http_response_code(400);
        exit('Invalid CSRF token. Go back, reload the page and try again.');
    }
}
