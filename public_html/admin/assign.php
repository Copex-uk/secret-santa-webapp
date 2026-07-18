<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /admin/assign.php — generate assignments for the selected event.
 * Fails atomically: on any constraint failure nothing is written.
 */

require_admin();
csrf_verify();
$pdo = db();
$errors = [];
$event = selected_event($pdo);

if (!$event) {
    page_header('Generate assignments', 'admin');
    echo '<p>No events exist yet — <a href="<?= APP_BASE ?>/admin/events.php">create one first</a>.</p>';
    page_footer();
    exit;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'generate') {
    try {
        $n = generate_assignments($pdo, (int)$event['id']);
        flash_set('ok', "Done — $n assignments generated for event #{$event['id']}.");
        redirect('/admin/assignments.php?event_id=' . (int)$event['id']);
    } catch (RuntimeException $ex) {
        $errors[] = $ex->getMessage();
    }
}

// Eligibility summary
$stmt = $pdo->prepare(
    'SELECT u.email, u.profile_complete, eu.status FROM event_users eu
     JOIN users u ON u.id = eu.user_id
     WHERE eu.event_id = ? AND eu.status <> "removed" ORDER BY u.email'
);
$stmt->execute([$event['id']]);
$members = $stmt->fetchAll();
$eligible = count(array_filter($members, fn($m) => (int)$m['profile_complete'] === 1));

$stmt = $pdo->prepare('SELECT COUNT(*) FROM assignments WHERE event_id = ?');
$stmt->execute([$event['id']]);
$existing = (int)$stmt->fetchColumn();

page_header('Generate assignments', 'admin');
event_switcher($pdo, $event, '/admin/assign.php');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<div class="card">
    <h2><?= e($event['name'] ?: 'Event #' . $event['id']) ?></h2>
    <p><?= count($members) ?> members · <strong><?= $eligible ?></strong> eligible (profile complete)
       <?php if ($existing): ?> · ⚠️ <?= $existing ?> assignments already exist and will be replaced<?php endif; ?></p>
    <ul>
        <?php foreach ($members as $m): ?>
            <li><?= e($m['email']) ?> — <?= (int)$m['profile_complete'] === 1 ? '✅ eligible' : '⏳ profile incomplete (excluded)' ?></li>
        <?php endforeach; ?>
    </ul>
    <form method="post">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="generate">
        <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
        <button type="submit" <?= $existing ? 'onclick="return confirm(\'Replace the existing assignments?\')"' : '' ?>>
            Generate assignments
        </button>
    </form>
</div>
<?php page_footer();
