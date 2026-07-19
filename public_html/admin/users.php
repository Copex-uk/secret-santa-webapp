<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /admin/users.php — add users by email (defaulted into the current event),
 * list everyone, edit details, re-upload photos, remove from an event.
 */

require_admin();
csrf_verify();
$pdo = db();
$errors = [];
$event = selected_event($pdo);

/* ---- Add user(s) by email --------------------------------------------- */

/** Send a "you're invited" email with the login link. Returns true on success. */
function send_invite_email(array $user, ?array $event): bool
{
    $siteUrl = rtrim((string)(config()['site_url'] ?? ''), '/');
    $login = ($siteUrl !== '' ? $siteUrl : '') . APP_BASE . '/login.php';
    $ok = send_template_email((string)$user['email'], 'invite', [
        'first_name' => (string)($user['first_name'] ?? ''),
        'email'      => (string)$user['email'],
        'event_name' => $event ? ($event['name'] ?: 'our Secret Santa') : 'our Secret Santa',
        'login_url'  => $login,
    ]);
    if ($ok) {
        db()->prepare('UPDATE users SET invited_at = NOW() WHERE id = ?')
            ->execute([(int)$user['id']]);
    }
    return $ok;
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'add') {
    $raw = (string)($_POST['emails'] ?? '');
    $eventId = (int)($_POST['event_id'] ?? 0);
    $emails = preg_split('/[\s,;]+/', $raw, -1, PREG_SPLIT_NO_EMPTY) ?: [];
    $gender = ($_POST['gender'] ?? '') === 'male' ? 'male' : 'female';
    $added = 0;

    foreach ($emails as $email) {
        $email = strtolower(trim($email));
        if (!filter_var($email, FILTER_VALIDATE_EMAIL) || strlen($email) > 190) {
            $errors[] = "Skipped invalid address: $email";
            continue;
        }
        // Create the user record if it does not exist
        $stmt = $pdo->prepare('SELECT id FROM users WHERE email = ?');
        $stmt->execute([$email]);
        $userId = $stmt->fetchColumn();
        if (!$userId) {
            // Default festive avatar by gender — the user only needs to add
            // their name to complete the profile.
            $avatar = $gender === 'male' ? 'assets/avatar-male.webp' : 'assets/avatar-female.webp';
            $pdo->prepare('INSERT INTO users (email, gender, photo_path) VALUES (?, ?, ?)')
                ->execute([$email, $gender, $avatar]);
            $userId = $pdo->lastInsertId();
        }
        // Associate to the chosen event (idempotent, revives "removed")
        if ($eventId > 0) {
            $pdo->prepare(
                'INSERT INTO event_users (event_id, user_id, status) VALUES (?, ?, "invited")
                 ON DUPLICATE KEY UPDATE status = IF(status = "removed", "invited", status)'
            )->execute([$eventId, $userId]);
        }
        $added++;
    }
    if ($added) {
        flash_set('ok', "$added user(s) added" . ($eventId ? ' and attached to the event' : '')
            . '. No emails were sent — use the Invite buttons when you are ready.');
    }
    if (!$errors) {
        redirect('/admin/users.php' . ($eventId ? '?event_id=' . $eventId : ''));
    }
}


/* ---- Attach existing users to an event ---------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'attach') {
    $eventId = (int)($_POST['event_id'] ?? 0);
    $ids = array_map('intval', (array)($_POST['user_ids'] ?? []));
    $ids = array_values(array_filter($ids, fn($v) => $v > 0));
    if ($eventId <= 0) {
        $errors[] = 'Choose an event to attach users to.';
    } elseif (!$ids) {
        $errors[] = 'Tick at least one user to attach.';
    } else {
        $ins = $pdo->prepare(
            'INSERT INTO event_users (event_id, user_id, status)
             SELECT ?, id, IF(profile_complete = 1, "profile_complete", "invited") FROM users WHERE id = ?
             ON DUPLICATE KEY UPDATE status = IF(status = "removed",
                 VALUES(status), event_users.status)'
        );
        foreach ($ids as $uid) {
            $ins->execute([$eventId, $uid]);
        }
        flash_set('ok', count($ids) . ' user(s) attached to the event.');
        redirect('/admin/users.php?event_id=' . $eventId);
    }
}

/* ---- Edit a user -------------------------------------------------------- */
/* ---- (Re)send an invitation --------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'invite') {
    $uid = (int)($_POST['user_id'] ?? 0);
    $sel = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $sel->execute([$uid]);
    if ($u = $sel->fetch()) {
        if (!throttle_allow((string)$u['email'], 'invite', 2, 60)) {
            flash_set('err', 'Invite throttled — that address was emailed recently. Try again later.');
        } elseif (send_invite_email($u, $event)) {
            flash_set('ok', 'Invitation sent to ' . $u['email'] . '.');
        } else {
            flash_set('err', 'Could not send the invitation — check the SMTP settings.');
        }
    }
    redirect('/admin/users.php' . ($event ? '?event_id=' . (int)$event['id'] : ''));
}

/* ---- Invite everyone in the event --------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'invite_all') {
    if (!$event) {
        flash_set('err', 'Select an event first.');
        redirect('/admin/users.php');
    }
    $includeAll = ($_POST['include_complete'] ?? '') === '1';
    $q = $pdo->prepare(
        'SELECT u.* FROM event_users eu
         JOIN users u ON u.id = eu.user_id
         WHERE eu.event_id = ? AND eu.status <> "removed"'
        . ($includeAll ? '' : ' AND u.profile_complete = 0')
    );
    $q->execute([(int)$event['id']]);
    $sent = 0; $failed = 0; $throttled = 0;
    foreach ($q->fetchAll() as $u) {
        if (!throttle_allow((string)$u['email'], 'invite', 2, 60)) {
            $throttled++;
            continue;
        }
        send_invite_email($u, $event) ? $sent++ : $failed++;
    }
    $msg = "Invitations: $sent sent";
    if ($failed)    $msg .= ", $failed failed (check SMTP)";
    if ($throttled) $msg .= ", $throttled skipped (emailed recently — throttle is 2/hour per address)";
    if (!$sent && !$failed && !$throttled) $msg = $includeAll
        ? 'Nobody in this event to invite.'
        : 'Everyone in this event has already completed their profile — tick the box to email them anyway.';
    flash_set($failed ? 'err' : 'ok', $msg . '.');
    redirect('/admin/users.php?event_id=' . (int)$event['id']);
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'edit') {
    $id    = (int)($_POST['id'] ?? 0);
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last  = trim((string)($_POST['last_name'] ?? ''));

    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([$id]);
    $target = $stmt->fetch();

    if (!$target) {
        $errors[] = 'User not found.';
    }
    foreach ([['First name', $first], ['Last name', $last]] as [$labelName, $v]) {
        if (mb_strlen($v) > 100) {
            $errors[] = "$labelName is too long (max 100).";
        }
    }

    $newPhoto = null;
    $hasUpload = !empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if (!$errors && $hasUpload) {
        try {
            $newPhoto = handle_photo_upload($_FILES['photo']);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    }

    if (!$errors && $target) {
        if ($newPhoto !== null && !empty($target['photo_path'])) {
            delete_photo($target['photo_path']);
        }
        $photo = $newPhoto ?? $target['photo_path'];
        $complete = ($first !== '' && $last !== '' && $photo) ? 1 : (int)$target['profile_complete'];
        $pdo->prepare(
            'UPDATE users SET first_name = ?, last_name = ?, photo_path = ?, profile_complete = ? WHERE id = ?'
        )->execute([$first ?: null, $last ?: null, $photo, $complete, $id]);
        flash_set('ok', 'User updated.');
        redirect('/admin/users.php');
    }
}

/* ---- Remove from event --------------------------------------------------- */
/* ---- Delete a user entirely --------------------------------------------- */
if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'delete_user') {
    $id = (int)($_POST['user_id'] ?? 0);
    $sel = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $sel->execute([$id]);
    if ($u = $sel->fetch()) {
        /*
         * Safeguard: deletion is only allowed once the person is fully
         * detached — no active event memberships, no couple pairings, and
         * no generated draw referencing them. This prevents accidentally
         * shredding a live event.
         */
        $blockers = [];
        $q = $pdo->prepare(
            'SELECT GROUP_CONCAT(event_id) FROM event_users
             WHERE user_id = ? AND status <> "removed"'
        );
        $q->execute([$id]);
        if ($evs = $q->fetchColumn()) {
            $blockers[] = "they are in event(s) #$evs — use \"Remove from event\" first";
        }
        $q = $pdo->prepare('SELECT COUNT(*) FROM relationships WHERE user_a_id = ? OR user_b_id = ?');
        $q->execute([$id, $id]);
        if ((int)$q->fetchColumn() > 0) {
            $blockers[] = 'they are marked as part of a couple — remove the pair on the Relationships page first';
        }
        $q = $pdo->prepare('SELECT COUNT(*) FROM assignments WHERE buyer_user_id = ? OR recipient_user_id = ?');
        $q->execute([$id, $id]);
        if ((int)$q->fetchColumn() > 0) {
            $blockers[] = 'they are part of a generated draw — re-run Generate for that event (or delete the event) first';
        }

        if ($blockers) {
            flash_set('err', 'Cannot delete ' . e((string)$u['email']) . ': ' . implode('; ', $blockers) . '.');
        } else {
            // Their uploaded selfie (default avatars in /assets are untouched).
            if (!empty($u['photo_path'])) {
                delete_photo((string)$u['photo_path']);
            }
            $pdo->prepare('DELETE FROM users WHERE id = ?')->execute([$id]);
            flash_set('ok', e((string)$u['email']) . ' deleted, along with their login codes and photo.');
        }
    }
    redirect('/admin/users.php' . ($event ? '?event_id=' . (int)$event['id'] : ''));
}

if ($_SERVER['REQUEST_METHOD'] === 'POST' && ($_POST['action'] ?? '') === 'remove') {
    $id = (int)($_POST['id'] ?? 0);
    $eventId = (int)($_POST['event_id'] ?? 0);
    $pdo->prepare('UPDATE event_users SET status = "removed" WHERE event_id = ? AND user_id = ?')
        ->execute([$eventId, $id]);
    flash_set('ok', 'User removed from the event (record kept).');
    redirect('/admin/users.php?event_id=' . $eventId);
}

/* ---- Render -------------------------------------------------------------- */
$editUser = null;
if (!empty($_GET['edit'])) {
    $stmt = $pdo->prepare('SELECT * FROM users WHERE id = ?');
    $stmt->execute([(int)$_GET['edit']]);
    $editUser = $stmt->fetch();
}

$users = $pdo->query(
    'SELECT u.*, GROUP_CONCAT(CONCAT(eu.event_id, ":", eu.status) SEPARATOR ", ") AS memberships
     FROM users u
     LEFT JOIN event_users eu ON eu.user_id = u.id
     GROUP BY u.id ORDER BY u.id'
)->fetchAll();

page_header('Users', 'admin');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';

if ($editUser): ?>
    <form method="post" enctype="multipart/form-data" class="card">
        <?= csrf_field() ?>
        <input type="hidden" name="action" value="edit">
        <input type="hidden" name="id" value="<?= (int)$editUser['id'] ?>">
        <h2>Edit <?= e($editUser['email']) ?></h2>
        <?php if (!empty($editUser['photo_path'])): ?>
            <p><img class="avatar-lg" src="<?= APP_BASE ?>/<?= e($editUser['photo_path']) ?>" alt="Current photo"></p>
        <?php endif; ?>
        <label>First name</label><input type="text" name="first_name" maxlength="100" value="<?= e($editUser['first_name'] ?? '') ?>">
        <label>Last name</label><input type="text" name="last_name" maxlength="100" value="<?= e($editUser['last_name'] ?? '') ?>">
        <label>Replace photo (JPG/PNG, max <?= e(photo_max_label()) ?>)</label><input type="file" name="photo" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
        <button type="submit">Save</button> <a class="btn" href="<?= APP_BASE ?>/admin/users.php">Cancel</a>
    </form>
<?php endif; ?>


<?php
$attachable = [];
if ($event) {
    $q = $pdo->prepare(
        'SELECT u.id, u.email, u.first_name, u.last_name, u.photo_path
         FROM users u
         LEFT JOIN event_users eu ON eu.user_id = u.id AND eu.event_id = ? AND eu.status <> "removed"
         WHERE eu.user_id IS NULL
         ORDER BY u.email'
    );
    $q->execute([(int)$event['id']]);
    $attachable = $q->fetchAll();
}
if ($event && $attachable): ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="attach">
    <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
    <h2>Add existing users to “<?= e($event['name'] ?: ('Event #' . $event['id'])) ?>”</h2>
    <p class="muted">Everyone already known to the app who is not in this event yet.</p>
    <div class="attach-grid">
        <?php foreach ($attachable as $u): ?>
        <label class="attach-opt">
            <input type="checkbox" name="user_ids[]" value="<?= (int)$u['id'] ?>">
            <?php if (!empty($u['photo_path'])): ?>
                <img src="<?= APP_BASE ?>/<?= e((string)$u['photo_path']) ?>" alt="">
            <?php endif; ?>
            <span><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? '')) ?: $u['email']) ?>
                <small><?= e((string)$u['email']) ?></small></span>
        </label>
        <?php endforeach; ?>
    </div>
    <button type="submit">Attach selected users</button>
</form>
<?php endif; ?>

<?php if ($event): ?>
<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="invite_all">
    <h2>Invite everyone in “<?= e($event['name'] ?: ('Event #' . $event['id'])) ?>”</h2>
    <p class="muted">Emails the login link to members of this event who haven't set up
       their profile yet. Each address is limited to 2 invites per hour.</p>
    <label class="attach-opt" style="max-width:max-content">
        <input type="checkbox" name="include_complete" value="1">
        Also email people who already finished their profile
    </label>
    <button type="submit">Send invitations</button>
</form>
<?php endif; ?>

<form method="post" class="card">
    <?= csrf_field() ?>
    <input type="hidden" name="action" value="add">
    <h2>Add new users by email</h2>
    <label>Email addresses (comma, space or newline separated)</label>
    <input type="text" name="emails" required placeholder="alice@example.com, bob@example.com">
    <label>Default avatar for these users</label>
    <div class="gender-pick">
        <label class="gender-opt"><input type="radio" name="gender" value="female" checked>
            <img src="<?= APP_BASE ?>/assets/avatar-female.webp" alt=""> Female</label>
        <label class="gender-opt"><input type="radio" name="gender" value="male">
            <img src="<?= APP_BASE ?>/assets/avatar-male.webp" alt=""> Male</label>
    </div>
    <p class="muted">The chosen avatar is set as their photo, so they only need to add
       their name to be ready for the draw. They can replace it with a
       real selfie any time. Applies to every address in this batch.</p>
    <label>Attach to event</label>
    <select name="event_id">
        <option value="0">— none —</option>
        <?php foreach ($pdo->query('SELECT id, name FROM events ORDER BY id DESC') as $ev): ?>
            <option value="<?= (int)$ev['id'] ?>" <?= ($event && (int)$ev['id'] === (int)$event['id']) ? 'selected' : '' ?>>
                #<?= (int)$ev['id'] ?> <?= e($ev['name'] ?: 'Unnamed event') ?>
            </option>
        <?php endforeach; ?>
    </select>
    <button type="submit">Add users</button>
</form>

<h2>All users</h2>
<table>
    <tr><th>Photo</th><th>Email</th><th>Name</th><th>Profile</th><th>Invited</th><th>Last login</th><th>Events (id:status)</th><th></th></tr>
    <?php foreach ($users as $u): ?>
    <tr>
        <td><?php if (!empty($u['photo_path'])): ?>
                <img class="avatar" src="<?= APP_BASE ?>/<?= e($u['photo_path']) ?>" alt="">
            <?php else: ?><span class="muted">none</span><?php endif; ?></td>
        <td><?= e($u['email']) ?></td>
        <td><?= e(trim(($u['first_name'] ?? '') . ' ' . ($u['last_name'] ?? ''))) ?: '—' ?></td>
        <td><?= (int)$u['profile_complete'] === 1 ? '✅' : '⏳' ?></td>
        <td class="muted"><?= !empty($u['invited_at']) ? '✉️ ' . e(date('j M H:i', strtotime((string)$u['invited_at']))) : '—' ?></td>
        <td class="muted"><?= !empty($u['last_login_at']) ? '🔑 ' . e(date('j M H:i', strtotime((string)$u['last_login_at']))) : 'never' ?></td>
        <td class="muted"><?= e($u['memberships'] ?? '') ?: '—' ?></td>
        <td>
            <a href="<?= APP_BASE ?>/admin/users.php?edit=<?= (int)$u['id'] ?>">Edit</a>
            <form method="post" class="inline-form">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="invite">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="btn-mini" style="margin:0">Invite</button>
            </form>
            <?php if ($event): ?>
            <form method="post" style="display:inline">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="remove">
                <input type="hidden" name="id" value="<?= (int)$u['id'] ?>">
                <input type="hidden" name="event_id" value="<?= (int)$event['id'] ?>">
                <button type="submit" class="danger" style="margin:0;padding:.2rem .6rem"
                        onclick="return confirm('Remove from event #<?= (int)$event['id'] ?>?')">Remove from #<?= (int)$event['id'] ?></button>
            </form>
            <?php endif; ?>
            <form method="post" class="inline-form"
                  onsubmit="return confirm('PERMANENTLY delete <?= e($u['email']) ?>?\n\nOnly possible once they are not in any event, couple or generated draw. This cannot be undone.');">
                <?= csrf_field() ?>
                <input type="hidden" name="action" value="delete_user">
                <input type="hidden" name="user_id" value="<?= (int)$u['id'] ?>">
                <button type="submit" class="danger btn-mini" style="margin:0">Delete</button>
            </form>
        </td>
    </tr>
    <?php endforeach; ?>
</table>
<?php page_footer();
