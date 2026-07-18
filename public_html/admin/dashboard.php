<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

$admin = require_admin();
$pdo = db();

$counts = [
    'users'    => (int)$pdo->query('SELECT COUNT(*) FROM users')->fetchColumn(),
    'complete' => (int)$pdo->query('SELECT COUNT(*) FROM users WHERE profile_complete = 1')->fetchColumn(),
    'events'   => (int)$pdo->query('SELECT COUNT(*) FROM events')->fetchColumn(),
];
$events = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 10')->fetchAll();

page_header('Admin dashboard', 'admin');
flash_show();
?>
<p class="muted">Signed in as <?= e($admin['email']) ?></p>
<div class="card">
    <p><strong><?= $counts['users'] ?></strong> users (<?= $counts['complete'] ?> with complete profiles) ·
       <strong><?= $counts['events'] ?></strong> events</p>
    <a class="btn" href="<?= APP_BASE ?>/admin/users.php">Manage users</a>
    <a class="btn" href="<?= APP_BASE ?>/admin/events.php">Manage events</a>
</div>

<h2>Recent events</h2>
<?php if (!$events): ?>
    <p>No events yet — <a href="<?= APP_BASE ?>/admin/events.php">create one</a>.</p>
<?php else: ?>
<table>
    <tr><th>ID</th><th>Name</th><th>Status</th><th>Emails go out</th><th>Reveal</th></tr>
    <?php foreach ($events as $ev): ?>
    <tr>
        <td><?= (int)$ev['id'] ?></td>
        <td><?= e($ev['name'] ?: '—') ?></td>
        <td><?= e($ev['status']) ?></td>
        <td><?= e($ev['email_send_at']) ?></td>
        <td><?= e($ev['reveal_at']) ?></td>
    </tr>
    <?php endforeach; ?>
</table>
<?php endif;
page_footer();
