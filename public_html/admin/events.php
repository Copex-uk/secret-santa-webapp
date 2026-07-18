<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /admin/events.php — create, edit and delete events.
 * Deleting an event removes its participations, relationships and
 * assignments (FK cascade) but NEVER deletes the users themselves.
 */

require_admin();
csrf_verify();
$pdo = db();
$errors = [];

/** Validate shared create/update fields; returns [name, budget, sendTs, revealTs]. */
function validate_event_input(array &$errors): array
{
    $name    = trim((string)($_POST['name'] ?? ''));
    $budget  = trim((string)($_POST['budget'] ?? ''));
    $budgetVal = null;
    if ($budget !== '') {
        if (!is_numeric($budget) || (float)$budget <= 0 || (float)$budget > 999999) {
            $errors[] = 'Max gift spend must be a positive number.';
        } else {
            $budgetVal = round((float)$budget, 2);
        }
    }
    $date = (string)($_POST['reveal_date'] ?? '');
    $time = (string)($_POST['reveal_time'] ?? '');
    if (mb_strlen($name) > 190) $errors[] = 'Event name is too long.';
    if (!preg_match('/^\d{4}-\d{2}-\d{2}$/', $date) || !strtotime($date)) {
        $errors[] = 'Reveal date is invalid.';
    }
    if (!preg_match('/^\d{2}:\d{2}$/', $time)) {
        $errors[] = 'Reveal time is invalid.';
    }
    $revealTs = $errors ? 0 : (int)strtotime("$date $time");
    /*
     * The nudge email goes out ON the reveal date: at 09:00 for an evening
     * reveal, or right after midnight when the reveal itself is that early.
     */
    $sendTs = 0;
    if ($revealTs) {
        $morning = (int)strtotime("$date 09:00");
        $sendTs  = ($revealTs > $morning) ? $morning : (int)strtotime("$date 00:05");
    }
    return [$name !== '' ? $name : null, $budgetVal, $sendTs, $revealTs];
}

/* ---- Create ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'create') {
    [$name, $budgetVal, $sendTs, $revealTs] = validate_event_input($errors);
    if (!$errors) {
        $pdo->prepare('INSERT INTO events (name, budget, email_send_at, reveal_at) VALUES (?, ?, ?, ?)')
            ->execute([$name, $budgetVal, date('Y-m-d H:i:s', $sendTs), date('Y-m-d H:i:s', $revealTs)]);
        $newId = (int)$pdo->lastInsertId();
        flash_set('ok', 'Event created — now add participants to it below.');
        redirect('/admin/users.php?event_id=' . $newId);
    }
}

/* ---- Update ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'update') {
    $id = (int)($_POST['event_id'] ?? 0);
    $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
    $stmt->execute([$id]);
    $ev = $stmt->fetch();
    if (!$ev) {
        $errors[] = 'Event not found.';
    } else {
        [$name, $budgetVal, $sendTs, $revealTs] = validate_event_input($errors);
        if (!$errors) {
            // Rescheduling the send time into the future after emails already
            // went out re-arms the cron for the new time.
            $status = $ev['status'];
            $note = '';
            if ($status === 'emailed' && $sendTs > time()) {
                $status = 'assigned';
                $note = ' Reveal emails were re-armed and will be sent again at the new time.';
            }
            $pdo->prepare('UPDATE events SET name = ?, budget = ?, email_send_at = ?, reveal_at = ?, status = ? WHERE id = ?')
                ->execute([$name, $budgetVal, date('Y-m-d H:i:s', $sendTs), date('Y-m-d H:i:s', $revealTs), $status, $id]);
            flash_set('ok', 'Event updated.' . $note);
            redirect('/admin/events.php');
        }
    }
}

/* ---- Delete ------------------------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete') {
    $id = (int)($_POST['event_id'] ?? 0);
    $stmt = $pdo->prepare('DELETE FROM events WHERE id = ?');
    $stmt->execute([$id]);
    if ($stmt->rowCount() > 0) {
        flash_set('ok', 'Event deleted. Its participations, relationships and assignments were '
            . 'removed with it — the users themselves were kept and can join other events.');
    }
    redirect('/admin/events.php');
}

$events = $pdo->query('SELECT * FROM events ORDER BY id DESC')->fetchAll();

/* Prefill for edit mode */
$editing = null;
$editId = (int)($_GET['edit'] ?? 0);
if ($editId > 0) {
    foreach ($events as $ev) {
        if ((int)$ev['id'] === $editId) { $editing = $ev; break; }
    }
}

page_header('Events', 'admin');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';

?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <?php if ($editing): ?>
        <input type="hidden" name="action" value="update">
        <input type="hidden" name="event_id" value="<?= (int)$editing['id'] ?>">
        <h2>Edit event #<?= (int)$editing['id'] ?></h2>
    <?php else: ?>
        <input type="hidden" name="action" value="create">
        <h2>Create event</h2>
    <?php endif; ?>
    <label>Name (optional)</label>
    <input type="text" name="name" maxlength="190" placeholder="Office Secret Santa 2026"
           value="<?= e((string)($editing['name'] ?? '')) ?>">
    <label>Max gift spend (£, optional — shown on everyone's reveal card)</label>
    <input type="number" name="budget" min="1" max="999999" step="0.01" placeholder="e.g. 25"
           value="<?= $editing && $editing['budget'] !== null ? e((string)(0 + $editing['budget'])) : '' ?>">
    <label>Reveal date — the "log in" email goes out that morning (09:00)</label>
    <input type="date" name="reveal_date" required
           value="<?= $editing ? e(date('Y-m-d', strtotime((string)$editing['reveal_at']))) : '' ?>">
    <label>Reveal time — recipients become visible at this moment</label>
    <input type="time" name="reveal_time" required
           value="<?= $editing ? e(date('H:i', strtotime((string)$editing['reveal_at']))) : '' ?>">
    <button type="submit"><?= $editing ? 'Save changes' : 'Create event' ?></button>
    <?php if ($editing): ?> <a class="btn" href="<?= APP_BASE ?>/admin/events.php">Cancel</a><?php endif; ?>
</form>

<h2>All events</h2>
<?php if (!$events): ?><p>None yet.</p><?php else: ?>
<table>
    <tr><th>ID</th><th>Name</th><th>£</th><th>Status</th><th>Emails</th><th>Reveal</th><th></th></tr>
    <?php foreach ($events as $ev): ?>
    <tr>
        <td><?= (int)$ev['id'] ?></td>
        <td><?= e($ev['name'] ?: '—') ?></td>
        <td><?= $ev['budget'] !== null ? e((string)(0 + $ev['budget'])) : '—' ?></td>
        <td><?= e($ev['status']) ?></td>
        <td><?= e($ev['email_send_at']) ?></td>
        <td><?= e($ev['reveal_at']) ?></td>
        <td>
            <a href="<?= APP_BASE ?>/admin/users.php?event_id=<?= (int)$ev['id'] ?>">Participants</a> ·
            <a href="<?= APP_BASE ?>/admin/relationships.php?event_id=<?= (int)$ev['id'] ?>">Relationships</a> ·
            <a href="<?= APP_BASE ?>/admin/assign.php?event_id=<?= (int)$ev['id'] ?>">Generate</a> ·
            <a href="<?= APP_BASE ?>/admin/assignments.php?event_id=<?= (int)$ev['id'] ?>">Assignments</a> ·
            <a href="<?= APP_BASE ?>/admin/events.php?edit=<?= (int)$ev['id'] ?>">Edit</a>
            <form method="post" class="inline-form"
                  onsubmit="return confirm('Delete this event? Participants, couples and assignments for it are removed. The users themselves are kept.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete">
                <input type="hidden" name="event_id" value="<?= (int)$ev['id'] ?>">
                <button type="submit" class="danger btn-mini">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif;
page_footer();
