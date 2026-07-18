<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /admin/relationships.php — mark pairs as "in a relationship" per event.
 * Stored as an unordered pair: user_a_id < user_b_id, enforced here.
 * During assignment generation neither partner can draw the other.
 */

require_admin();
csrf_verify();
$pdo = db();
$errors = [];
$event = selected_event($pdo);

if (!$event) {
    page_header('Relationships', 'admin');
    echo '<p>No events exist yet — <a href="' . APP_BASE . '/admin/events.php">create one first</a>.</p>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $a = (int)($_POST['user_a'] ?? 0);
    $b = (int)($_POST['user_b'] ?? 0);
    if ($a < 1 || $b < 1 || $a === $b) {
        $errors[] = 'Pick two different people.';
    } else {
        // Normalize to an unordered pair
        [$lo, $hi] = $a < $b ? [$a, $b] : [$b, $a];
        // Both must be members of this event
        $stmt = $pdo->prepare(
            'SELECT COUNT(*) FROM event_users WHERE event_id = ? AND user_id IN (?, ?) AND status <> "removed"'
        );
        $stmt->execute([$event['id'], $lo, $hi]);
        if ((int)$stmt->fetchColumn() !== 2) {
            $errors[] = 'Both people must be members of this event.';
        } else {
            try {
                $pdo->prepare('INSERT INTO relationships (event_id, user_a_id, user_b_id) VALUES (?, ?, ?)')
                    ->execute([$event['id'], $lo, $hi]);
                flash_set('ok', 'Relationship saved — they will not draw each other.');
            } catch (PDOException $ex) {
                $errors[] = 'That pair is already marked.';
            }
            if (!$errors) {
                redirect('/admin/relationships.php?event_id=' . (int)$event['id']);
            }
        }
    }
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $pdo->prepare('DELETE FROM relationships WHERE id = ? AND event_id = ?')
        ->execute([(int)($_POST['id'] ?? 0), $event['id']]);
    flash_set('ok', 'Relationship removed.');
    redirect('/admin/relationships.php?event_id=' . (int)$event['id']);
}

// Event members for the pickers
$stmt = $pdo->prepare(
    'SELECT u.id, u.email, u.first_name, u.last_name FROM event_users eu
     JOIN users u ON u.id = eu.user_id
     WHERE eu.event_id = ? AND eu.status <> "removed" ORDER BY u.email'
);
$stmt->execute([$event['id']]);
$members = $stmt->fetchAll();

$stmt = $pdo->prepare(
    'SELECT r.id, ua.email AS a_email, ub.email AS b_email
     FROM relationships r
     JOIN users ua ON ua.id = r.user_a_id
     JOIN users ub ON ub.id = r.user_b_id
     WHERE r.event_id = ? ORDER BY r.id'
);
$stmt->execute([$event['id']]);
$pairs = $stmt->fetchAll();

page_header('Relationships', 'admin');
event_switcher($pdo, $event, '/admin/relationships.php');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
    <h2>Mark a pair as in a relationship</h2>
    <label>Person A</label>
    <select name="user_a" required>
        <option value="">— choose —</option>
        <?php foreach ($members as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= e($m['email']) ?><?= trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) !== '' ? ' (' . e(trim($m['first_name'] . ' ' . $m['last_name'])) . ')' : '' ?></option>
        <?php endforeach; ?>
    </select>
    <label>Person B</label>
    <select name="user_b" required>
        <option value="">— choose —</option>
        <?php foreach ($members as $m): ?>
            <option value="<?= (int)$m['id'] ?>"><?= e($m['email']) ?><?= trim(($m['first_name'] ?? '') . ' ' . ($m['last_name'] ?? '')) !== '' ? ' (' . e(trim($m['first_name'] . ' ' . $m['last_name'])) . ')' : '' ?></option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Save pair</button>
</form>

<h2>Excluded pairs for this event</h2>
<?php if (!$pairs): ?><p>None yet.</p><?php else: ?>
<table>
    <tr><th>Person A</th><th>Person B</th><th></th></tr>
    <?php foreach ($pairs as $p): ?>
    <tr>
        <td><?= e($p['a_email']) ?></td>
        <td><?= e($p['b_email']) ?></td>
        <td>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="id" value="<?= (int)$p['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <button type="submit" class="danger" style="margin:0;padding:.2rem .6rem">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif;
page_footer();
