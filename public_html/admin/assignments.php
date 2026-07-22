<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /admin/assignments.php — recipient identities are MASKED by default.
 *
 * The masked view's SQL never selects recipient columns, so nothing can leak
 * through this endpoint. Unmasking requires re-entering the admin password;
 * success sets a session flag valid for 60 minutes.
 */

$admin = require_admin();
csrf_verify();
$pdo = db();
$errors = [];
$event = selected_event($pdo);

if (!$event) {
    page_header('Assignments', 'admin');
    echo '<p>No events exist yet — <a href="' . APP_BASE . '/admin/events.php">create one first</a>.</p>';
    page_footer();
    exit;
}

/* ---- Unmask: server-side password re-check ------------------------------ */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'unmask') {
    if (!throttle_allow(client_ip(), 'admin_unmask_ip', 8, 15)) {
        $errors[] = 'Too many attempts — try again in a few minutes.';
    } else {
        $stmt = $pdo->prepare('SELECT password_hash FROM admin_users WHERE id = ?');
        $stmt->execute([$admin['id']]);
        $hash = (string)$stmt->fetchColumn();
        if ($hash !== '' && password_verify((string)($_POST['password'] ?? ''), $hash)) {
            $_SESSION['admin_unmask_until'] = time() + 3600;   // 60 minutes
            flash_set('ok', 'Assignments unmasked for 60 minutes.');
            redirect('/admin/assignments.php?event_id=' . (int)$event['id']);
        }
        $errors[] = 'Incorrect password.';
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'mask') {
    unset($_SESSION['admin_unmask_until']);
    flash_set('ok', 'Assignments masked again.');
    redirect('/admin/assignments.php?event_id=' . (int)$event['id']);
}

/* ---- Send match cards by email (optional; bypasses login-to-see) -------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'send_matches') {
    if (($_POST['understood'] ?? '') !== '1') {
        $errors[] = 'Tick the confirmation box first — this reveals matches by email.';
    } elseif (!throttle_allow(client_ip(), 'match_send_ip', 3, 60)) {
        $errors[] = 'Too many send attempts — try again later.';
    } else {
        $res = send_match_cards($pdo, $event);
        $sent = $res['sent']; $failed = $res['failed']; $anySent = $res['total'] > 0;

        $msg = "Match cards: $sent sent";
        if ($failed) { $msg .= ", $failed failed (check SMTP)"; }
        if (!$anySent) { $msg = 'No assignments exist for this event yet — run Generate first'; }
        flash_set($failed || !$anySent ? 'err' : 'ok', $msg . '.');
        redirect('/admin/assignments.php?event_id=' . (int)$event['id']);
    }
}

/* ---- Resend one person's match card ------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'resend_match') {
    $aid = (int)($_POST['assignment_id'] ?? 0);
    if (!throttle_allow(client_ip(), 'match_send_ip', 20, 60)) {
        $errors[] = 'Too many send attempts — try again later.';
    } else {
        $res = send_match_cards($pdo, $event, $aid);
        if ($res['total'] === 0) {
            flash_set('err', 'That assignment no longer exists.');
        } else {
            flash_set($res['sent'] ? 'ok' : 'err', $res['sent']
                ? 'Match card re-sent.'
                : 'Could not send — check the SMTP settings.');
        }
        redirect('/admin/assignments.php?event_id=' . (int)$event['id']);
    }
}

$unmasked = admin_unmasked();

/* ---- Query: recipient columns are only ever selected when unmasked ------ */
if ($unmasked) {
    $stmt = $pdo->prepare(
        'SELECT a.id, a.created_at, a.seen_at, a.match_email_sent_at,
                b.email AS buyer_email, CONCAT_WS(" ", b.first_name, b.last_name) AS buyer_nick,
                r.email AS recipient_email, CONCAT_WS(" ", r.first_name, r.last_name) AS recipient_nick
         FROM assignments a
         JOIN users b ON b.id = a.buyer_user_id
         JOIN users r ON r.id = a.recipient_user_id
         WHERE a.event_id = ? ORDER BY b.email'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT a.id, a.created_at, a.seen_at, a.match_email_sent_at,
                b.email AS buyer_email, CONCAT_WS(" ", b.first_name, b.last_name) AS buyer_nick
         FROM assignments a
         JOIN users b ON b.id = a.buyer_user_id
         WHERE a.event_id = ? ORDER BY b.email'
    );
}
$stmt->execute([$event['id']]);
$rows = $stmt->fetchAll();

page_header('Assignments', 'admin');
event_switcher($pdo, $event, '/admin/assignments.php');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<div class="card">
    <?php if ($unmasked): ?>
        <p>🔓 Unmasked until <?= e(date('H:i', (int)$_SESSION['admin_unmask_until'])) ?>.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="mask">
            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
            <button type="submit" class="danger">Mask again now</button>
        </form>
    <?php else: ?>
        <p>🔒 Recipient identities are hidden. Re-enter your admin password to unmask for 60 minutes.</p>
        <form method="post">
            <?= csrf_field() ?>
            <input type="hidden" name="action" value="unmask">
            <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
            <label>Admin password</label>
            <input type="password" name="password" required>
            <button type="submit">Unmask assignments</button>
        </form>
    <?php endif; ?>
</div>

<h2><?= count($rows) ?> assignments for <?= e($event['name'] ?: 'event #' . $event['id']) ?></h2>
<?php if (!$rows): ?><p>No assignments generated yet — <a href="<?= APP_BASE ?>/admin/assign.php?event_id=<?= (int)$event['id'] ?>">generate them</a>.</p>
<?php else: ?>
<table>
    <tr><th>Buyer</th><th>Buys for</th><th>Seen reveal</th><th>Card emailed</th><th></th></tr>
    <?php foreach ($rows as $row): ?>
    <tr>
        <td><?= e($row['buyer_email']) ?><?= $row['buyer_nick'] ? ' (' . e($row['buyer_nick']) . ')' : '' ?></td>
        <td>
            <?php if ($unmasked): ?>
                <?= e($row['recipient_email']) ?><?= $row['recipient_nick'] ? ' (' . e($row['recipient_nick']) . ')' : '' ?>
            <?php else: ?>
                <span class="masked">••••••••</span>
            <?php endif; ?>
        </td>
        <td class="muted"><?= !empty($row['seen_at'])
            ? '👀 ' . e(date('j M H:i', strtotime((string)$row['seen_at'])))
            : 'not yet' ?></td>
        <td class="muted"><?= !empty($row['match_email_sent_at'])
            ? '✉️ ' . e(date('j M H:i', strtotime((string)$row['match_email_sent_at'])))
            : '—' ?></td>
        <td>
            <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="resend_match">
                <input type="hidden" name="assignment_id" value="<?= (int)$row['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <button type="submit" class="btn-mini" style="margin:0"
                        onclick="return confirm('Email this person their match card now?')">
                    <?= !empty($row['match_email_sent_at']) ? 'Resend' : 'Send' ?>
                </button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif; ?>
<?php $prog = reveal_progress($pdo, (int)$event['id']); ?>
<div class="card">
    <h2>📨 Email everyone their match</h2>
    <p><strong><?= (int)$prog['seen'] ?> of <?= (int)$prog['total'] ?></strong> participants have seen their reveal.
       <?php if ($prog['all_seen']): ?>✅ Everyone has looked.<?php endif; ?></p>
    <?php if (!empty($event['auto_match_email'])): ?>
        <p class="muted">🤖 Automatic sending is <strong>on</strong> for this event: the cards go out by
           themselves once every participant has viewed their reveal
           <?= $prog['all_seen'] && empty($event['match_emails_sent_at']) ? '(due on the next cron run)' : '' ?>.
           Turn it off on the <a href="<?= APP_BASE ?>/admin/events.php?edit=<?= (int)$event['id'] ?>">event settings</a>.</p>
    <?php endif; ?>
    <?php if (!empty($event['match_emails_sent_at'])): ?>
        <p class="muted">✅ Already sent <?= e(date('j M Y, H:i', strtotime((string)$event['match_emails_sent_at']))) ?>.
           Sending again will email everyone a fresh copy.</p>
    <?php endif; ?>
    <p class="muted">Sends each person a festive card naming their giftee (and the max spend),
       so they don't have to log in to find out. <strong>This puts the secret in their inbox</strong>
       — shared mailboxes, lock-screen previews and forwarded mail can all spoil a surprise, and
       an email can't be unsent. The normal reveal flow (log in to see) needs none of this.</p>
    <form method="post"
          onsubmit="return confirm('Email every participant their match? The secret leaves the site and cannot be recalled.');">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="send_matches">
        <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
        <label class="attach-opt" style="max-width:max-content">
            <input type="checkbox" name="understood" value="1" required>
            I understand this reveals matches by email
        </label>
        <button type="submit">Send match cards</button>
    </form>
</div>
<?php
page_footer();