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
<?php
/* Server clock — also the diagnostic when reveals fire at the wrong hour. */
$phpTz   = date_default_timezone_get();
$phpNow  = new DateTime('now');
$dbNowS  = (string)$pdo->query('SELECT NOW()')->fetchColumn();
$dbNow   = DateTime::createFromFormat('Y-m-d H:i:s', $dbNowS) ?: $phpNow;
$driftS  = abs($phpNow->getTimestamp() - $dbNow->getTimestamp());
$inSync  = $driftS <= 60;
$sysTz   = getenv('TZ') ?: '(TZ not set)';
?>
<div class="card">
    <h2>🕐 Server time</h2>
    <p style="font-size:1.15rem;margin:.2rem 0;">
        <strong><?= e($phpNow->format('l j F Y, H:i:s')) ?></strong>
        <span class="muted">(<?= e($phpTz) ?>)</span>
    </p>
    <p class="muted">
        Database: <?= e($dbNowS) ?> · TZ env: <?= e($sysTz) ?>
        <?php if ($inSync): ?>
            · ✅ app and database agree
        <?php else: ?>
            · ⚠️ <strong>out of sync by <?= (int)round($driftS / 60) ?> minute(s)</strong> —
            event reveals will fire at the wrong time. Set <code>TZ</code> in your
            <code>.env</code> (e.g. <code>TZ=Europe/London</code>) and restart the containers.
        <?php endif; ?>
    </p>
    <p class="muted">Event times you enter are treated as this clock's local time.
       If the time above is not what your watch says, fix it before scheduling a reveal.</p>
</div>

<div class="card">
    <h2>How it all works</h2>
    <ol class="howto">
        <li><strong>Create an event</strong> (Events page): give it a name, an optional
            £ max gift spend, a <em>reveal date</em> and a <em>reveal time</em>.
            On the reveal date everyone automatically gets a "log in to find out who
            you got" email — at 09:00, or just after midnight for early reveals.</li>
        <li><strong>Add people</strong> (Users page): type their email addresses and pick a
            default avatar. Each person is emailed an invitation with the login link —
            they sign in with a 6-digit emailed code (no passwords) and only need to
            enter their name to be ready. Selfies are optional and can be taken with
            a phone camera or webcam. Use the <em>Invite</em> button to resend.</li>
        <li><strong>Mark couples</strong> (Relationships page) so partners never draw
            each other.</li>
        <li><strong>Generate</strong> (Generate page): creates the secret pairings.
            It refuses cleanly if the couples make a valid draw impossible.</li>
        <li><strong>The reveal:</strong> before the reveal date users see
            "Please Standby" on their card; on the day but before the time they see
            "Too early — come back at&nbsp;…"; from the reveal moment the card spins
            and lands on who they're buying for. Assignments here stay masked
            unless you re-enter your admin password, so you won't spoil your own draw.</li>
    </ol>
</div>
<?php

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
