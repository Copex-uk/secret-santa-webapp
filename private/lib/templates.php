<?php
declare(strict_types=1);

/**
 * templates.php — festive HTML emails with admin-editable templates.
 *
 * Templates live in the email_templates table keyed by tpl_key; when no row
 * exists the built-in default is used, so fresh installs and "reset to
 * default" both just work. Placeholders use {{name}} syntax and are
 * HTML-escaped on substitution.
 */

/** Built-in defaults: subject + inner HTML body per template key. */
function email_template_defaults(): array
{
    return [
        'invite' => [
            'label'   => 'Invitation (sent when a user is added / Invite buttons)',
            'subject' => 'You are invited to {{event_name}} 🎅',
            'body'    => '<h1 style="margin:0 0 12px;font-size:26px;color:#8e1f17;">Ho ho ho, you\'re in!</h1>'
                . '<p>You have been added to <strong>{{event_name}}</strong>.</p>'
                . '<p>Setting up takes under a minute — no password needed. Click the button, '
                . 'enter this email address and we\'ll send you a 6-digit login code. '
                . 'Then add your name (and a selfie if you fancy) and you\'re in the draw.</p>'
                . '{{button}}'
                . '<p style="color:#7a6f5c;">Shhh... it\'s a secret! 🤫</p>',
            'vars'    => ['first_name', 'email', 'event_name', 'login_url'],
        ],
        'reveal' => [
            'label'   => 'Reveal day (sent by the scheduler on the reveal date)',
            'subject' => '🎁 {{event_name}}: find out who you\'re buying for!',
            'body'    => '<h1 style="margin:0 0 12px;font-size:26px;color:#8e1f17;">The wait is (nearly) over!</h1>'
                . '<p>It\'s reveal day for <strong>{{event_name}}</strong>.</p>'
                . '<p>Log in to spin the wheel and find out who you\'re buying a present for. '
                . 'Recipients appear at <strong>{{reveal_time}}</strong> — any earlier and the elves will tell you to come back later.</p>'
                . '{{button}}'
                . '<p style="color:#7a6f5c;">Remember: don\'t tell a soul. 🤐</p>',
            'vars'    => ['first_name', 'email', 'event_name', 'login_url', 'reveal_time', 'reveal_date'],
        ],
        'match' => [
            'label'   => 'Match card (emails each person their giftee — optional)',
            'subject' => '🎁 {{event_name}}: your Secret Santa match',
            'body'    => '<h1 style="margin:0 0 12px;font-size:26px;color:#8e1f17;">Here it is, {{first_name}}!</h1>'
                . '<p>Your match for <strong>{{event_name}}</strong> is on the card below.</p>'
                . '{{card}}'
                . '<p style="font-size:20px;">You are buying for <strong>{{recipient_name}}</strong>.</p>'
                . '<p style="color:#7a6f5c;">Keep it to yourself — that is rather the point. 🤫</p>',
            'vars'    => ['first_name', 'email', 'event_name', 'recipient_name', 'budget'],
        ],
        'login_code' => [
            'label'   => 'Login code (sent when someone signs in)',
            'subject' => 'Your Secret Santa login code: {{code}}',
            'body'    => '<h1 style="margin:0 0 12px;font-size:24px;color:#8e1f17;">Your login code</h1>'
                . '<p style="font-size:34px;letter-spacing:8px;font-weight:bold;color:#1e5c3f;margin:18px 0;">{{code}}</p>'
                . '<p>It expires in {{ttl_minutes}} minutes and works once. '
                . 'If you didn\'t request this, you can safely ignore this email.</p>',
            'vars'    => ['code', 'ttl_minutes', 'email'],
        ],
    ];
}

/** Fetch a template (DB override or default). Returns [subject, body]. */
function get_email_template(string $key): array
{
    $defaults = email_template_defaults();
    if (!isset($defaults[$key])) {
        throw new InvalidArgumentException('Unknown template: ' . $key);
    }
    try {
        $stmt = db()->prepare('SELECT subject, body_html FROM email_templates WHERE tpl_key = ?');
        $stmt->execute([$key]);
        if ($row = $stmt->fetch()) {
            return [(string)$row['subject'], (string)$row['body_html']];
        }
    } catch (Throwable $t) {
        // Table missing (pre-migration install): fall through to defaults.
    }
    return [$defaults[$key]['subject'], $defaults[$key]['body']];
}

/** The shared festive shell around every HTML email (inline styles only). */
function email_wrap(string $innerHtml): string
{
    return '<!doctype html><html><body style="margin:0;padding:0;background:#0a1226;">'
        . '<table role="presentation" width="100%" cellpadding="0" cellspacing="0" style="background:#0a1226;padding:26px 10px;">'
        . '<tr><td align="center">'
        . '<table role="presentation" width="560" cellpadding="0" cellspacing="0" style="max-width:560px;width:100%;">'
        . '<tr><td align="center" style="padding:8px 0 18px;font-family:Georgia,serif;">'
        . '<span style="font-size:34px;">🎅</span><br>'
        . '<span style="font-size:30px;font-weight:bold;color:#ffffff;">Secret</span> '
        . '<span style="font-size:30px;font-weight:bold;color:#d63a2f;">Santa</span>'
        . '</td></tr>'
        . '<tr><td style="background:#f7f5f0;border-radius:18px;padding:30px 32px;'
        . 'font-family:Georgia,serif;font-size:16px;line-height:1.6;color:#2b2b28;">'
        . $innerHtml
        . '</td></tr>'
        . '<tr><td align="center" style="padding:16px 0;color:#8ea0c0;'
        . 'font-family:Verdana,sans-serif;font-size:11px;">❄ Shhh... it\'s a secret! ❄</td></tr>'
        . '</table></td></tr></table></body></html>';
}

/** Substitute {{vars}} (HTML-escaped), add the login button, wrap, and derive plain text. */
function render_email(string $key, array $vars): array
{
    [$subject, $body] = get_email_template($key);

    $loginUrl = (string)($vars['login_url'] ?? '');
    $cardSrc = (string)($vars['card_src'] ?? 'cid:matchcard');
    $vars['card'] = empty($vars['has_card']) ? '' :
        '<p style="text-align:center;margin:22px 0;">'
        . '<img src="' . htmlspecialchars($cardSrc, ENT_QUOTES) . '" alt="Your Secret Santa card" '
        . 'style="width:100%;max-width:480px;border-radius:12px;"></p>';
    $vars['button'] = $loginUrl === '' ? '' :
        '<p style="text-align:center;margin:26px 0;">'
        . '<a href="' . htmlspecialchars($loginUrl, ENT_QUOTES) . '" '
        . 'style="background:#c62828;color:#ffffff;text-decoration:none;font-weight:bold;'
        . 'font-family:Verdana,sans-serif;font-size:16px;padding:14px 34px;border-radius:10px;display:inline-block;">'
        . '🎁 Log in &amp; get started</a></p>';

    foreach ($vars as $k => $v) {
        $safe = ($k === 'button') ? (string)$v : htmlspecialchars((string)$v, ENT_QUOTES);
        $subject = str_replace('{{' . $k . '}}', (string)$v, $subject);
        $body    = str_replace('{{' . $k . '}}', $safe, $body);
    }
    // Unreplaced placeholders vanish rather than leaking braces.
    $body    = (string)preg_replace('/\{\{[a-z_]+\}\}/', '', $body);
    $subject = (string)preg_replace('/\{\{[a-z_]+\}\}/', '', $subject);

    $html = email_wrap($body);

    // Plain-text alternative for clients that prefer it.
    $text = $body;
    $text = (string)preg_replace('/<br\s*\/?>/i', "\n", $text);
    $text = (string)preg_replace('/<\/(p|h1|h2|h3|li)>/i', "\n\n", $text);
    $text = trim(html_entity_decode(strip_tags($text), ENT_QUOTES, 'UTF-8'));
    if ($loginUrl !== '') {
        $text .= "\n\nLog in here: " . $loginUrl;
    }

    return [$subject, $text, $html];
}

/** Convenience: render a template and send it. */
function send_template_email(string $toEmail, string $key, array $vars): bool
{
    [$subject, $text, $html] = render_email($key, $vars);
    return smtp_send($toEmail, $subject, $text, $html);
}
