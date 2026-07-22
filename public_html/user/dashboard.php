<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /user/dashboard.php — slot-machine reveal card.
 *
 * The photo circle spins (blurred) through all participants of the event,
 * then lands on:
 *   - the recipient's photo + first name ........ after the reveal moment
 *   - "Come back at HH:MM" ...................... on the reveal day, early
 *   - the "Please Standby" placeholder .......... before the reveal day
 *
 * Recipient data is only queried when reveal_at <= NOW() (enforced in SQL),
 * so nothing can leak through this endpoint early. The spin photos are the
 * event's participants (no names attached) — visible to any member anyway.
 */

$user = require_user();
$pdo = db();

$stmt = $pdo->prepare(
    'SELECT ev.*, eu.status AS my_status,
            (ev.reveal_at <= NOW()) AS revealed
     FROM event_users eu
     JOIN events ev ON ev.id = eu.event_id
     WHERE eu.user_id = ? AND eu.status <> "removed"
     ORDER BY ev.id DESC'
);
$stmt->execute([$user['id']]);
$events = $stmt->fetchAll();

page_header('Dashboard', 'user');
flash_show();

if ((int)$user['profile_complete'] !== 1) {
    echo '<div class="flash err">Your profile is not complete yet — '
        . '<a href="' . APP_BASE . '/user/profile.php">finish it here</a>'
        . ' so you can be included in the draw.</div>';
}

if (!$events) {
    echo '<p>You are not part of any Secret Santa event yet. Sit tight!</p>';
}

foreach ($events as $ev) {
    $revealed = (bool)$ev['revealed'];

    // Photos of everyone in the event (for the spin animation).
    $p = $pdo->prepare(
        'SELECT u.photo_path
         FROM event_users eu
         JOIN users u ON u.id = eu.user_id
         WHERE eu.event_id = ? AND eu.status <> "removed"
           AND u.photo_path IS NOT NULL AND u.photo_path <> ""'
    );
    $p->execute([$ev['id']]);
    $spin = array_map(
        fn($row) => APP_BASE . '/' . $row['photo_path'],
        $p->fetchAll()
    );
    shuffle($spin);

    $revealTs   = (int)strtotime((string)$ev['reveal_at']);
    $revealDay  = date('Y-m-d', $revealTs);
    $revealTime = date('H:i', $revealTs);
    $recipient  = null;
    $comebackAt = '';

    if ($revealed) {
        $finalImg  = APP_BASE . '/assets/standby.webp';
        $finalName = '';
    } elseif (date('Y-m-d') === $revealDay) {
        // Reveal day, but too early: "Come back at HH:MM".
        $finalImg   = APP_BASE . '/assets/comeback.webp';
        $finalName  = 'Too early! Come back at ' . $revealTime;
        $comebackAt = $revealTime;
    } else {
        // Before the reveal day: just stand by.
        $finalImg  = APP_BASE . '/assets/standby.webp';
        $finalName = 'Reveal day is ' . date('j M', $revealTs);
    }

    if ($revealed) {
        // Defensive re-check of the reveal time inside the sensitive query.
        $stmt2 = $pdo->prepare(
            'SELECT r.first_name, r.photo_path
             FROM assignments a
             JOIN users r ON r.id = a.recipient_user_id
             JOIN events ev ON ev.id = a.event_id
             WHERE a.event_id = ? AND a.buyer_user_id = ? AND ev.reveal_at <= NOW()'
        );
        $stmt2->execute([$ev['id'], $user['id']]);
        $recipient = $stmt2->fetch();
        if ($recipient) {
            $finalName = (string)($recipient['first_name'] ?: 'your giftee');
            if (!empty($recipient['photo_path'])) {
                $finalImg = APP_BASE . '/' . (string)$recipient['photo_path'];
            }
            // Record the first viewing — the match emails wait for everyone.
            $pdo->prepare(
                'UPDATE assignments SET seen_at = NOW()
                 WHERE event_id = ? AND buyer_user_id = ? AND seen_at IS NULL'
            )->execute([$ev['id'], $user['id']]);
        }
    }

    echo '<h2 class="ev-title">' . e($ev['name'] ?: ('Event #' . $ev['id'])) . '</h2>';

    if ($revealed && !$recipient) {
        echo '<p class="muted">Assignments have not been generated for this event yet.</p>';
        continue;
    }

    $budget = $ev['budget'] !== null ? number_format((float)$ev['budget'], ((float)$ev['budget'] == (int)$ev['budget']) ? 0 : 2) : '';
    ?>
    <div class="reveal-slot"
         data-photos="<?= e(json_encode($spin)) ?>"
         data-final-img="<?= e($finalImg) ?>"
         data-final-name="<?= e($finalName) ?>"
         data-revealed="<?= $revealed ? '1' : '0' ?>"
         data-comeback="<?= e($comebackAt) ?>">
        <div class="slot-photo"><img src="<?= e($finalImg) ?>" alt="">
            <div class="slot-comeback" hidden><?= e($comebackAt) ?></div></div>
        <div class="slot-name"></div>
        <div class="slot-spend"><?= e($budget) ?></div>
    </div>
    <?php
}
?>
<script>
document.querySelectorAll('.reveal-slot').forEach(function (slot) {
    var img = slot.querySelector('.slot-photo img');
    var nameEl = slot.querySelector('.slot-name');
    var photos = [];
    try { photos = JSON.parse(slot.dataset.photos) || []; } catch (e) {}
    var finalImg = slot.dataset.finalImg;
    var finalName = slot.dataset.finalName;
    var revealed = slot.dataset.revealed === '1';

    // Preload everything so the spin doesn't stutter.
    photos.concat([finalImg]).forEach(function (u) { (new Image()).src = u; });

    var comebackEl = slot.querySelector('.slot-comeback');
    function settle() {
        nameEl.textContent = finalName;
        nameEl.classList.add(revealed ? 'is-final' : 'is-waiting');
        if (slot.dataset.comeback) comebackEl.hidden = false;
    }
    if (photos.length < 2) {           // nothing to spin through
        img.src = finalImg;
        settle();
        return;
    }

    var i = 0, delay = 90, elapsed = 0, total = 3400;
    img.classList.add('spinning');
    nameEl.textContent = '';

    function tick() {
        img.src = photos[i % photos.length];
        i++;
        elapsed += delay;
        delay = Math.round(delay * 1.13);        // ease out like a slot machine
        if (elapsed < total) {
            setTimeout(tick, delay);
        } else {
            img.src = finalImg;
            img.classList.remove('spinning');
            img.classList.add('landed');
            settle();
        }
    }
    setTimeout(tick, 350);
});
</script>
<?php
page_footer();
