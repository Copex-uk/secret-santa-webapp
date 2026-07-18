<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /admin/emails.php — edit the HTML email templates, preview them, and send
 * yourself a test. Saved templates override the built-in defaults; Reset
 * deletes the override.
 */

require_admin();
csrf_verify();
$pdo = db();
$defaults = email_template_defaults();

$key = (string)($_GET['tpl'] ?? $_POST['tpl'] ?? 'invite');
if (!isset($defaults[$key])) {
    $key = 'invite';
}

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $action = (string)($_POST['action'] ?? '');
    if ($action === 'save') {
        $subject = trim((string)($_POST['subject'] ?? ''));
        $body    = (string)($_POST['body_html'] ?? '');
        if ($subject === '' || mb_strlen($subject) > 255) {
            flash_set('err', 'Subject is required (max 255 characters).');
        } elseif (trim($body) === '') {
            flash_set('err', 'The body cannot be empty.');
        } else {
            $pdo->prepare(
                'INSERT INTO email_templates (tpl_key, subject, body_html) VALUES (?, ?, ?)
                 ON DUPLICATE KEY UPDATE subject = VALUES(subject), body_html = VALUES(body_html)'
            )->execute([$key, $subject, $body]);
            flash_set('ok', 'Template saved — it now overrides the default.');
        }
        redirect('/admin/emails.php?tpl=' . $key);
    }
    if ($action === 'reset') {
        $pdo->prepare('DELETE FROM email_templates WHERE tpl_key = ?')->execute([$key]);
        flash_set('ok', 'Template reset to the built-in default.');
        redirect('/admin/emails.php?tpl=' . $key);
    }
    if ($action === 'test') {
        $stmt = $pdo->prepare('SELECT email FROM admin_users WHERE id = ?');
        $stmt->execute([(int)($_SESSION['admin_id'] ?? 0)]);
        $adminEmail = (string)($stmt->fetchColumn() ?: '');
        if ($adminEmail === '') {
            flash_set('err', 'Could not determine your admin email address.');
        } else {
            $ok = send_template_email($adminEmail, $key, sample_template_vars($key));
            flash_set($ok ? 'ok' : 'err', $ok
                ? "Test email sent to $adminEmail — check your inbox."
                : 'Test email failed — check the SMTP settings.');
        }
        redirect('/admin/emails.php?tpl=' . $key);
    }
}

/** Realistic sample values for previews and test sends. */
function sample_template_vars(string $key): array
{
    $siteUrl = rtrim((string)(config()['site_url'] ?? ''), '/');
    return [
        'first_name'  => 'Scott',
        'email'       => 'you@example.com',
        'event_name'  => 'Family Secret Santa',
        'login_url'   => ($siteUrl !== '' ? $siteUrl : '') . APP_BASE . '/login.php',
        'reveal_time' => '20:00',
        'reveal_date' => '25 Dec 2026',
        'code'        => '123456',
        'ttl_minutes' => (string)CODE_TTL_MINUTES,
    ];
}

[$subject, $body] = get_email_template($key);
$hasOverride = (bool)$pdo->query(
    'SELECT COUNT(*) FROM email_templates WHERE tpl_key = ' . $pdo->quote($key)
)->fetchColumn();
[, , $previewHtml] = render_email($key, sample_template_vars($key));

page_header('Email templates', 'admin');
flash_show();
?>
<div class="tpl-tabs">
    <?php foreach ($defaults as $k => $def): ?>
        <a class="btn<?= $k === $key ? ' tpl-active' : '' ?>"
           href="<?= APP_BASE ?>/admin/emails.php?tpl=<?= e($k) ?>"><?= e(ucfirst(str_replace('_', ' ', $k))) ?></a>
    <?php endforeach; ?>
</div>

<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="tpl" value="<?= e($key) ?>">
    <input type="hidden" name="action" value="save">
    <h2><?= e($defaults[$key]['label']) ?><?= $hasOverride ? ' <small class="muted">(customised)</small>' : '' ?></h2>
    <label>Subject</label>
    <input type="text" name="subject" required maxlength="255" value="<?= e($subject) ?>">
    <label>Body (HTML — the festive header/footer are added automatically)</label>
    <textarea name="body_html" rows="12" spellcheck="false"><?= e($body) ?></textarea>
    <p class="muted">Placeholders for this template:
        <?php foreach ($defaults[$key]['vars'] as $v): ?><code>{{<?= e($v) ?>}}</code> <?php endforeach; ?>
        — plus <code>{{button}}</code> for the red login button (added automatically at the
        placeholder's position; remove it if you don't want the button).</p>
    <button type="submit">Save template</button>
</form>

<div class="tpl-actions">
    <form method="post" class="inline-form">
        <?= csrf_field() ?>
        <input type="hidden" name="tpl" value="<?= e($key) ?>">
        <input type="hidden" name="action" value="test">
        <button type="submit" class="btn">Send me a test email</button>
    </form>
    <?php if ($hasOverride): ?>
    <form method="post" class="inline-form"
          onsubmit="return confirm('Discard your customised version and go back to the default?');">
        <?= csrf_field() ?>
        <input type="hidden" name="tpl" value="<?= e($key) ?>">
        <input type="hidden" name="action" value="reset">
        <button type="submit" class="danger">Reset to default</button>
    </form>
    <?php endif; ?>
</div>

<h2>Preview (with sample values)</h2>
<iframe class="tpl-preview" sandbox="" srcdoc="<?= e($previewHtml) ?>"></iframe>
<?php
page_footer();
