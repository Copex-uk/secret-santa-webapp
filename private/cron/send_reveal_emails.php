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
        echo "No reveal emails due.\n";
    }

    $siteUrl = rtrim((string)(config()['site_url'] ?? ''), '/');
    $loginUrl = $siteUrl !== '' ? $siteUrl . '/login.php' : 'the Secret Santa site';

    foreach ($events as $ev) {
        $eventName = $ev['name'] ?: 'Secret Santa';

        // Everyone with an assignment in this event gets the nudge email.
        $stmt = $pdo->prepare(
            'SELECT u.email, u.first_name FROM assignments a
             JOIN users u ON u.id = a.buyer_user_id
             WHERE a.event_id = ?'
        );
        $stmt->execute([$ev['id']]);
        $buyers = $stmt->fetchAll();

        $sent = 0;
        $failed = 0;
        $revealTs = (int)strtotime((string)$ev['reveal_at']);
        foreach ($buyers as $buyer) {
            $ok = send_template_email((string)$buyer['email'], 'reveal', [
                'first_name'  => (string)($buyer['first_name'] ?? ''),
                'email'       => (string)$buyer['email'],
                'event_name'  => $eventName,
                'login_url'   => $loginUrl,
                'reveal_time' => date('H:i', $revealTs),
                'reveal_date' => date('j M Y', $revealTs),
            ]);
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
    /*
     * Second pass: events opted into automatic match cards, where the reveal
     * has already happened, every participant has viewed it, and the cards
     * have not gone out yet.
     */
    $auto = $pdo->query(
        "SELECT * FROM events
         WHERE auto_match_email = 1
           AND match_emails_sent_at IS NULL
           AND reveal_at <= NOW()"
    )->fetchAll();

    foreach ($auto as $ev) {
        $prog = reveal_progress($pdo, (int)$ev['id']);
        if ($prog['total'] === 0) {
            continue;                       // nothing generated yet
        }
        if (!$prog['all_seen']) {
            echo "Event #{$ev['id']}: {$prog['seen']}/{$prog['total']} have seen their reveal — waiting.\n";
            continue;
        }
        $res = send_match_cards($pdo, $ev);
        echo "Event #{$ev['id']}: everyone has looked — match cards sent {$res['sent']}, failed {$res['failed']}.\n";
    }
} finally {
    $pdo->query("SELECT RELEASE_LOCK('ssanta_reveal_cron')");
}
