<?php
declare(strict_types=1);

/**
 * matchcard.php — renders the personalised "Your Secret Santa is …" card by
 * compositing the recipient's name and the event budget onto the artwork.
 *
 * A bundled font is used so this works identically on Docker and on shared
 * hosting where system fonts may be absent.
 */

/** Path to the card artwork (in the public assets so the web UI can show it too). */
function match_card_template_path(): string
{
    $cfgPublic = rtrim((string)(config()['public_path'] ?? ''), '/');
    foreach ([
        ($cfgPublic !== '' ? $cfgPublic . '/assets/match-card.jpg' : ''),
        (defined('APP_PUBLIC') && APP_PUBLIC !== '' ? APP_PUBLIC . '/assets/match-card.jpg' : ''),
        dirname(APP_PRIVATE) . '/public_html/assets/match-card.jpg',
    ] as $p) {
        if ($p !== '' && is_file($p)) {
            return $p;
        }
    }
    return '';
}

/** Bundled serif font used for the card text. */
function match_card_font_path(): string
{
    return APP_PRIVATE . '/card-font.ttf';
}

/**
 * Draw $text centred inside the box, shrinking the point size until it fits.
 */
function mc_fit_text(
    $img,
    string $text,
    string $font,
    int $boxX,
    int $boxY,
    int $boxW,
    int $boxH,
    int $colour,
    int $maxSize
): void {
    if ($text === '') {
        return;
    }
    $size = $maxSize;
    while ($size > 8) {
        $bb = imagettfbbox($size, 0, $font, $text);
        $tw = abs($bb[2] - $bb[0]);
        $th = abs($bb[5] - $bb[1]);
        if ($tw <= $boxW * 0.92 && $th <= $boxH * 0.75) {
            $x = (int)round($boxX + ($boxW - $tw) / 2 - $bb[0]);
            $y = (int)round($boxY + ($boxH + $th) / 2 - ($bb[1] + $bb[7] + $th) / 2 - $th / 2 + $th);
            // Vertical centring from the baseline: place using the bbox top.
            $y = (int)round($boxY + ($boxH - $th) / 2 - $bb[5]);
            imagettftext($img, $size, 0, $x, $y, $colour, $font, $text);
            return;
        }
        $size -= 2;
    }
}

/**
 * Render the personalised card.
 *
 * @param string      $recipientName Name to print in the main panel.
 * @param string|null $budget        Amount without the £ (the artwork has one).
 * @return string JPEG binary, or '' if the artwork/font are unavailable.
 */
function render_match_card(string $recipientName, ?string $budget = null): string
{
    $tplPath  = match_card_template_path();
    $fontPath = match_card_font_path();
    if ($tplPath === '' || !is_file($fontPath) || !function_exists('imagettftext')) {
        return '';
    }
    $img = @imagecreatefromjpeg($tplPath);
    if ($img === false) {
        return '';
    }
    $w = imagesx($img);
    $h = imagesy($img);

    $red   = imagecolorallocate($img, 155, 27, 25);   // matches the title script
    $green = imagecolorallocate($img, 27, 71, 42);    // matches "Max Spend is"

    // Main name panel
    mc_fit_text(
        $img,
        $recipientName,
        $fontPath,
        (int)($w * 0.155),
        (int)($h * 0.375),
        (int)($w * 0.69),
        (int)($h * 0.195),
        $red,
        (int)($h * 0.11)
    );

    // Spend panel — the £ glyph is already printed, so text sits to its right.
    if ($budget !== null && $budget !== '') {
        mc_fit_text(
            $img,
            $budget,
            $fontPath,
            (int)($w * 0.375),
            (int)($h * 0.775),
            (int)($w * 0.30),
            (int)($h * 0.125),
            $green,
            (int)($h * 0.075)
        );
    }

    // JPEG keeps the attachment small (~150KB rather than ~1.5MB for PNG).
    ob_start();
    imagejpeg($img, null, 85);
    $jpg = (string)ob_get_clean();
    imagedestroy($img);
    return $jpg;
}

/**
 * Send every participant of an event their personalised match card.
 * Used by both the admin button and the automatic cron trigger.
 *
 * @return array{sent:int, failed:int, total:int}
 */
function send_match_cards(PDO $pdo, array $event, ?int $onlyAssignmentId = null): array
{
    $sql = 'SELECT a.id AS assignment_id, b.email AS buyer_email, b.first_name AS buyer_first,
                   CONCAT_WS(" ", r.first_name, r.last_name) AS recipient_name
            FROM assignments a
            JOIN users b ON b.id = a.buyer_user_id
            JOIN users r ON r.id = a.recipient_user_id
            WHERE a.event_id = ?';
    $params = [(int)$event['id']];
    if ($onlyAssignmentId !== null) {
        $sql .= ' AND a.id = ?';
        $params[] = $onlyAssignmentId;
    }
    $q = $pdo->prepare($sql);
    $q->execute($params);
    $rows = $q->fetchAll();

    $budget = $event['budget'] !== null
        ? number_format((float)$event['budget'], ((float)$event['budget'] == (int)$event['budget']) ? 0 : 2)
        : null;
    $evName = $event['name'] ?: 'Secret Santa';

    $sent = 0;
    $failed = 0;
    foreach ($rows as $row) {
        $name = trim((string)$row['recipient_name']);
        $card = render_match_card($name !== '' ? $name : 'Your giftee', $budget);
        [$subject, $text, $html] = render_email('match', [
            'first_name'     => (string)($row['buyer_first'] ?? ''),
            'email'          => (string)$row['buyer_email'],
            'event_name'     => $evName,
            'recipient_name' => $name,
            'budget'         => $budget !== null ? '£' . $budget : '',
            'has_card'       => $card !== '' ? '1' : '',
        ]);
        $inline = $card !== '' ? ['matchcard' => ['data' => $card, 'mime' => 'image/jpeg']] : [];
        if (smtp_send((string)$row['buyer_email'], $subject, $text, $html, $inline)) {
            $sent++;
            // Per-person record, so the admin can see exactly who received one.
            $pdo->prepare('UPDATE assignments SET match_email_sent_at = NOW() WHERE id = ?')
                ->execute([(int)$row['assignment_id']]);
        } else {
            $failed++;
        }
    }
    if ($sent > 0 && $onlyAssignmentId === null) {
        $pdo->prepare('UPDATE events SET match_emails_sent_at = NOW() WHERE id = ?')
            ->execute([(int)$event['id']]);
    }
    return ['sent' => $sent, 'failed' => $failed, 'total' => count($rows)];
}

/**
 * Reveal-viewing progress for an event.
 *
 * @return array{seen:int, total:int, all_seen:bool}
 */
function reveal_progress(PDO $pdo, int $eventId): array
{
    $stmt = $pdo->prepare(
        'SELECT COUNT(*) AS total, SUM(seen_at IS NOT NULL) AS seen
         FROM assignments WHERE event_id = ?'
    );
    $stmt->execute([$eventId]);
    $row = $stmt->fetch() ?: ['total' => 0, 'seen' => 0];
    $total = (int)$row['total'];
    $seen  = (int)$row['seen'];
    return ['seen' => $seen, 'total' => $total, 'all_seen' => $total > 0 && $seen === $total];
}
