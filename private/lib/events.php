<?php
declare(strict_types=1);

/**
 * events.php — "current event" convention for admin pages:
 * ?event_id=N when given, otherwise the most recently created event.
 */

function selected_event(PDO $pdo): ?array
{
    $id = (int)($_GET['event_id'] ?? $_POST['event_id'] ?? 0);
    if ($id > 0) {
        $stmt = $pdo->prepare('SELECT * FROM events WHERE id = ?');
        $stmt->execute([$id]);
        $ev = $stmt->fetch();
        if ($ev) {
            return $ev;
        }
    }
    $ev = $pdo->query('SELECT * FROM events ORDER BY id DESC LIMIT 1')->fetch();
    return $ev ?: null;
}

/** Small <select> switcher shown at the top of per-event admin pages. */
function event_switcher(PDO $pdo, array $current, string $page): void
{
    $events = $pdo->query('SELECT id, name FROM events ORDER BY id DESC')->fetchAll();
    echo '<form method="get" action="' . e($page) . '" class="muted">Event: <select name="event_id" onchange="this.form.submit()">';
    foreach ($events as $ev) {
        $sel = ((int)$ev['id'] === (int)$current['id']) ? ' selected' : '';
        echo '<option value="' . (int)$ev['id'] . '"' . $sel . '>#' . (int)$ev['id'] . ' '
            . e($ev['name'] ?: 'Unnamed event') . '</option>';
    }
    echo '</select> <noscript><button type="submit">Switch</button></noscript></form>';
}
