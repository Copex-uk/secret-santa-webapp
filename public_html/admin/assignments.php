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
    echo '<p>No events exist yet — <a href="<?= APP_BASE ?>/admin/events.php">create one first</a>.</p>';
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

$unmasked = admin_unmasked();

/* ---- Query: recipient columns are only ever selected when unmasked ------ */
if ($unmasked) {
    $stmt = $pdo->prepare(
        'SELECT b.email AS buyer_email, CONCAT_WS(" ", b.first_name, b.last_name) AS buyer_nick,
                r.email AS recipient_email, CONCAT_WS(" ", r.first_name, r.last_name) AS recipient_nick,
                a.created_at
         FROM assignments a
         JOIN users b ON b.id = a.buyer_user_id
         JOIN users r ON r.id = a.recipient_user_id
         WHERE a.event_id = ? ORDER BY b.email'
    );
} else {
    $stmt = $pdo->prepare(
        'SELECT b.email AS buyer_email, CONCAT_WS(" ", b.first_name, b.last_name) AS buyer_nick, a.created_at
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
    <tr><th>Buyer</th><th>Buys for</th><th>Generated</th></tr>
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
        <td class="muted"><?= e($row['created_at']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif;
page_footer();
