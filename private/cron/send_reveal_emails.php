<?php
declare(strict_types=1);

/**
 * send_reveal_emails.php — cron script.
 *
 * Finds events where email_send_at <= NOW() and status = 'assigned'
 * (assignments generated, emails not yet sent), and emails every eligible
 * participant telling them to log in and see who they're buying for.
 * The recipient's identity is NEVER put in the email — the site enforces
 * the reveal_at gate when they log in.
 *
 * cPanel cron example (every 5 minutes):
 *   /usr/local/bin/php -q /home/YOURUSER/private/cron/send_reveal_emails.php
 *
 * CLI-only: refuses to run over HTTP.
 */

if (PHP_SAPI !== 'cli') {
    http_response_code(403);
    exit("This script can only be run from the command line (cron).\n");
}

require dirname(__DIR__) . '/lib/bootstrap.php';

if (!app_installed()) {
    exit("Not installed yet.\n");
}

$pdo = db();

// Lock so overlapping cron runs don't double-send.
$lock = $pdo->query("SELECT GET_LOCK('ssanta_reveal_cron', 0)")->fetchColumn();
if ((int)$lock !== 1) {
    exit("Another run is in progress.\n");
}

try {
    $events = $pdo->query(
        "SELECT * FROM events WHERE email_send_at <= NOW() AND status = 'assigned'"
    )->fetchAll();

    if (!$events) {
        echo "Nothing due.\n";
        exit;
    }

    $siteUrl = rtrim((string)(config()['site_url'] ?? ''), '/');
    $loginUrl = $siteUrl !== '' ? $siteUrl . '/login.php' : 'the Secret Santa site';

    foreach ($events as $ev) {
        $eventName = $ev['name'] ?: 'Secret Santa';

        // Everyone with an assignment in this event gets the nudge email.
        $stmt = $pdo->prepare(
            'SELECT u.email FROM assignments a
             JOIN users u ON u.id = a.buyer_user_id
             WHERE a.event_id = ?'
        );
        $stmt->execute([$ev['id']]);
        $emails = $stmt->fetchAll(PDO::FETCH_COLUMN);

        $sent = 0;
        $failed = 0;
        foreach ($emails as $email) {
            $ok = smtp_send(
                (string)$email,
                "🎁 {$eventName}: your Secret Santa draw is ready",
                "Ho ho ho!\n\n"
                . "The {$eventName} draw has been made. Log in to see who you're buying a present for:\n\n"
                . "  {$loginUrl}\n\n"
                . "Enter your email address and we'll send you a one-time login code.\n\n"
                . "Reveal time: " . $ev['reveal_at'] . "\n"
            );
            $ok ? $sent++ : $failed++;
        }

        // Only flip status when everyone was reached; otherwise the next run retries.
        if ($failed === 0) {
            $pdo->prepare("UPDATE events SET status = 'emailed' WHERE id = ?")->execute([$ev['id']]);
            echo "Event #{$ev['id']}: sent $sent emails, status -> emailed.\n";
        } else {
            echo "Event #{$ev['id']}: sent $sent, FAILED $failed — will retry next run.\n";
        }
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('ssanta_reveal_cron')");
}
