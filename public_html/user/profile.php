<?php
declare(strict_types=1);
$d = __DIR__;
while (!is_file($d . '/private/lib/bootstrap.php') && $d !== dirname($d)) {
    $d = dirname($d);
}
require $d . '/private/lib/bootstrap.php';
unset($d);

/**
 * /user/profile.php — first/last name, nickname and selfie upload.
 */

$user = require_user();
csrf_verify();
$errors = [];

if ($_SERVER['REQUEST_METHOD'] === 'POST') {
    $first = trim((string)($_POST['first_name'] ?? ''));
    $last  = trim((string)($_POST['last_name'] ?? ''));
    $nick  = trim((string)($_POST['nickname'] ?? ''));

    if ($first === '' || mb_strlen($first) > 100) $errors[] = 'First name is required (max 100 chars).';
    if ($last === ''  || mb_strlen($last) > 100)  $errors[] = 'Last name is required (max 100 chars).';
    if ($nick === ''  || mb_strlen($nick) > 100)  $errors[] = 'Nickname is required (max 100 chars).';

    // Photo: required the first time, optional (replace) afterwards.
    $newPhoto = null;
    $hasUpload = !empty($_FILES['photo']) && ($_FILES['photo']['error'] ?? UPLOAD_ERR_NO_FILE) !== UPLOAD_ERR_NO_FILE;
    if ($hasUpload) {
        try {
            $newPhoto = handle_photo_upload($_FILES['photo']);
        } catch (RuntimeException $ex) {
            $errors[] = $ex->getMessage();
        }
    } elseif (empty($user['photo_path'])) {
        $errors[] = 'Please upload a selfie-style photo (JPG or PNG, max ' . photo_max_label() . ').';
    }

    if (!$errors) {
        $pdo = db();
        if ($newPhoto !== null && !empty($user['photo_path'])) {
            delete_photo($user['photo_path']);   // replace old file
        }
        $photoPath = $newPhoto ?? $user['photo_path'];
        $stmt = $pdo->prepare(
            'UPDATE users SET first_name = ?, last_name = ?, nickname = ?, photo_path = ?, profile_complete = 1
             WHERE id = ?'
        );
        $stmt->execute([$first, $last, $nick, $photoPath, $user['id']]);

        // Bump per-event status from invited -> profile_complete (leave later states alone)
        $pdo->prepare(
            'UPDATE event_users SET status = "profile_complete" WHERE user_id = ? AND status = "invited"'
        )->execute([$user['id']]);

        flash_set('ok', 'Profile saved.');
        redirect('/user/dashboard.php');
    }
}

page_header('My profile', 'user');
flash_show();
foreach ($errors as $err) echo '<div class="flash err">' . e($err) . '</div>';
?>
<form method="post" enctype="multipart/form-data" class="card">
    <?= csrf_field() ?>
    <?php if (!empty($user['photo_path'])): ?>
        <p><img class="avatar-lg" src="<?= APP_BASE ?>/<?= e($user['photo_path']) ?>" alt="Your current photo"></p>
    <?php endif; ?>
    <label>First name</label>
    <input type="text" name="first_name" required maxlength="100" value="<?= e($user['first_name'] ?? '') ?>">
    <label>Last name</label>
    <input type="text" name="last_name" required maxlength="100" value="<?= e($user['last_name'] ?? '') ?>">
    <label>Nickname (shown to your Secret Santa)</label>
    <input type="text" name="nickname" required maxlength="100" value="<?= e($user['nickname'] ?? '') ?>">
    <label>Selfie photo (JPG/PNG, max <?= e(photo_max_label()) ?>)<?= empty($user['photo_path']) ? ' — required' : ' — leave empty to keep current' ?></label>
    <input type="file" name="photo" id="photo-input" accept=".jpg,.jpeg,.png,image/jpeg,image/png">
    <div class="cam-row">
        <button type="button" class="btn cam-btn" id="cam-start">📷 Take a selfie with your camera</button>
    </div>

    <!-- Hidden fallback: on phones this opens the camera app directly -->
    <input type="file" id="cam-fallback" accept="image/*" capture="user" hidden>

    <div class="cam-box" id="cam-box" hidden>
        <video id="cam-video" autoplay playsinline muted></video>
        <div class="cam-actions">
            <button type="button" class="btn" id="cam-snap">Capture</button>
            <button type="button" class="btn danger" id="cam-cancel">Cancel</button>
        </div>
    </div>
    <p class="muted" id="cam-note" hidden></p>
    <p id="cam-preview-wrap" hidden>
        <img id="cam-preview" class="avatar-lg" alt="Your new selfie">
        <span class="muted">New selfie ready — click “Save profile” to keep it.</span>
    </p>

    <button type="submit">Save profile</button>
</form>
<script>
(function () {
    var input    = document.getElementById('photo-input');
    var fallback = document.getElementById('cam-fallback');
    var startBtn = document.getElementById('cam-start');
    var box      = document.getElementById('cam-box');
    var video    = document.getElementById('cam-video');
    var note     = document.getElementById('cam-note');
    var stream   = null;

    function say(msg) { note.textContent = msg; note.hidden = !msg; }

    function showPreview(file) {
        var img = document.getElementById('cam-preview');
        img.src = URL.createObjectURL(file);
        document.getElementById('cam-preview-wrap').hidden = false;
    }

    function stopCam() {
        if (stream) { stream.getTracks().forEach(function (t) { t.stop(); }); stream = null; }
        box.hidden = true;
    }

    // Any chosen/captured file gets a preview.
    input.addEventListener('change', function () {
        if (input.files && input.files[0]) showPreview(input.files[0]);
    });

    // Phone camera app fallback: move the shot into the real form field.
    fallback.addEventListener('change', function () {
        if (!(fallback.files && fallback.files[0])) return;
        var dt = new DataTransfer();
        dt.items.add(fallback.files[0]);
        input.files = dt.files;
        showPreview(fallback.files[0]);
    });

    startBtn.addEventListener('click', function () {
        say('');
        // getUserMedia needs HTTPS (or localhost); otherwise fall back to the
        // device camera app / file picker.
        if (!(navigator.mediaDevices && navigator.mediaDevices.getUserMedia) || !window.isSecureContext) {
            fallback.click();
            return;
        }
        navigator.mediaDevices.getUserMedia({ video: { facingMode: 'user' }, audio: false })
            .then(function (s) {
                stream = s;
                video.srcObject = s;
                box.hidden = false;
            })
            .catch(function () {
                // Denied or no camera — use the native picker instead.
                fallback.click();
            });
    });

    document.getElementById('cam-cancel').addEventListener('click', stopCam);

    document.getElementById('cam-snap').addEventListener('click', function () {
        if (!stream) return;
        var canvas = document.createElement('canvas');
        canvas.width  = video.videoWidth  || 1280;
        canvas.height = video.videoHeight || 720;
        canvas.getContext('2d').drawImage(video, 0, 0, canvas.width, canvas.height);
        canvas.toBlob(function (blob) {
            if (!blob) { say('Could not capture a frame — try uploading a file instead.'); return; }
            var file = new File([blob], 'selfie.jpg', { type: 'image/jpeg' });
            var dt = new DataTransfer();
            dt.items.add(file);
            input.files = dt.files;
            stopCam();
            showPreview(file);
        }, 'image/jpeg', 0.92);
    });

    // Don't leave the camera running if the user navigates away.
    window.addEventListener('pagehide', stopCam);
})();
</script>
<?php page_footer();
