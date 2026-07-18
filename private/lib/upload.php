<?php
declare(strict_types=1);

/**
 * upload.php — selfie photo upload: jpg/png only, size-capped, random filename.
 * The cap defaults to 8MB (phone selfies) and can be tuned with the
 * PHOTO_MAX_MB environment variable.
 */

/** Maximum accepted photo size in bytes. */
function photo_max_bytes(): int
{
    $mb = (float)(getenv('PHOTO_MAX_MB') ?: 8);
    if ($mb <= 0 || $mb > 64) {
        $mb = 8;
    }
    return (int)round($mb * 1024 * 1024);
}

/** Human label for the limit, e.g. "8MB". */
function photo_max_label(): string
{
    return rtrim(rtrim(number_format(photo_max_bytes() / 1048576, 1), '0'), '.') . 'MB';
}

/**
 * Validate + store an uploaded photo. Returns the relative path
 * ("uploads/ab12....jpg") to save in users.photo_path.
 * Throws RuntimeException with a user-safe message on any problem.
 *
 * @param array $file One entry from $_FILES.
 */
function handle_photo_upload(array $file): string
{
    if (!isset($file['error']) || is_array($file['error'])) {
        throw new RuntimeException('Invalid upload.');
    }
    switch ($file['error']) {
        case UPLOAD_ERR_OK:
            break;
        case UPLOAD_ERR_NO_FILE:
            throw new RuntimeException('No file was selected.');
        case UPLOAD_ERR_INI_SIZE:
        case UPLOAD_ERR_FORM_SIZE:
            throw new RuntimeException('The photo is larger than the ' . photo_max_label() . ' limit.');
        default:
            throw new RuntimeException('Upload failed, please try again.');
    }

    if ((int)$file['size'] > photo_max_bytes() || (int)$file['size'] === 0) {
        throw new RuntimeException('The photo must be smaller than ' . photo_max_label() . '.');
    }
    if (!is_uploaded_file($file['tmp_name'])) {
        throw new RuntimeException('Invalid upload source.');
    }

    // 1) Extension check on the original filename
    $ext = strtolower(pathinfo((string)$file['name'], PATHINFO_EXTENSION));
    if (!in_array($ext, ['jpg', 'jpeg', 'png'], true)) {
        throw new RuntimeException('Only JPG and PNG photos are accepted.');
    }

    // 2) Real MIME check on the file contents (never trust the client header)
    $finfo = new finfo(FILEINFO_MIME_TYPE);
    $mime = (string)$finfo->file($file['tmp_name']);
    $allowed = ['image/jpeg' => 'jpg', 'image/png' => 'png'];
    if (!isset($allowed[$mime])) {
        throw new RuntimeException('The file is not a valid JPG or PNG image.');
    }

    // 3) Confirm it actually decodes as an image
    if (@getimagesize($file['tmp_name']) === false) {
        throw new RuntimeException('The image could not be read.');
    }

    $dir = uploads_dir();
    if (!is_dir($dir) && !mkdir($dir, 0755, true)) {
        throw new RuntimeException('Uploads directory is missing.');
    }
    $base = bin2hex(random_bytes(16));

    /*
     * Preferred path: re-encode via GD. A 20MB+ phone selfie becomes a
     * ~100KB max-1200px JPEG, EXIF (incl. GPS) is stripped, and any odd
     * embedded content is destroyed by the decode/re-encode round trip.
     */
    if (function_exists('imagecreatefromjpeg')) {
        $img = $mime === 'image/jpeg'
            ? @imagecreatefromjpeg($file['tmp_name'])
            : @imagecreatefrompng($file['tmp_name']);
        if ($img === false) {
            throw new RuntimeException('The image could not be processed.');
        }
        // Respect phone EXIF orientation before it gets stripped.
        if ($mime === 'image/jpeg' && function_exists('exif_read_data')) {
            $exif = @exif_read_data($file['tmp_name']);
            $rot  = ['3' => 180, '6' => -90, '8' => 90][(string)($exif['Orientation'] ?? '')] ?? 0;
            if ($rot !== 0) {
                $rotated = imagerotate($img, $rot, 0);
                if ($rotated !== false) {
                    imagedestroy($img);
                    $img = $rotated;
                }
            }
        }
        $w = imagesx($img);
        $h = imagesy($img);
        $maxSide = 1200;
        if (max($w, $h) > $maxSide) {
            $scale = $maxSide / max($w, $h);
            $nw = max(1, (int)round($w * $scale));
            $nh = max(1, (int)round($h * $scale));
            $small = imagecreatetruecolor($nw, $nh);
            // Flatten PNG transparency onto white (output is always JPEG).
            $white = imagecolorallocate($small, 255, 255, 255);
            imagefill($small, 0, 0, $white);
            imagecopyresampled($small, $img, 0, 0, 0, 0, $nw, $nh, $w, $h);
            imagedestroy($img);
            $img = $small;
        } elseif ($mime === 'image/png') {
            $flat  = imagecreatetruecolor($w, $h);
            $white = imagecolorallocate($flat, 255, 255, 255);
            imagefill($flat, 0, 0, $white);
            imagecopy($flat, $img, 0, 0, 0, 0, $w, $h);
            imagedestroy($img);
            $img = $flat;
        }
        $name = $base . '.jpg';
        $dest = $dir . '/' . $name;
        $ok = imagejpeg($img, $dest, 82);
        imagedestroy($img);
        if (!$ok) {
            throw new RuntimeException('Could not save the photo, check directory permissions.');
        }
    } else {
        // GD unavailable (rare on shared hosting): store the validated original.
        $name = $base . '.' . $allowed[$mime];
        $dest = $dir . '/' . $name;
        if (!move_uploaded_file($file['tmp_name'], $dest)) {
            throw new RuntimeException('Could not save the photo, check directory permissions.');
        }
    }
    @chmod($dest, 0644);
    return 'uploads/' . $name;
}

/** Absolute path to the public uploads directory (app public root/uploads). */
function uploads_dir(): string
{
    // Recorded by the setup wizard — works for any layout, incl. cron/CLI.
    $cfgPublic = rtrim((string)(config()['public_path'] ?? ''), '/');
    if ($cfgPublic !== '' && is_dir($cfgPublic)) {
        return $cfgPublic . '/uploads';
    }
    // Live request: use where the running script actually lives.
    if (defined('APP_PUBLIC') && APP_PUBLIC !== '' && is_dir(APP_PUBLIC)) {
        return APP_PUBLIC . '/uploads';
    }
    // Last-resort fallback for legacy layouts: sibling public_html.
    return dirname(APP_PRIVATE) . '/public_html/uploads';
}

/** Delete a previously stored photo (called when replacing). */
function delete_photo(?string $relPath): void
{
    if (!$relPath) {
        return;
    }
    $name = basename($relPath); // never allow traversal
    $full = uploads_dir() . '/' . $name;
    if (is_file($full)) {
        @unlink($full);
    }
}
