<?php
declare(strict_types=1);

/**
 * assignment.php — generate a perfect 1-to-1 Secret Santa matching.
 *
 * Constraints:
 *   - buyer != recipient (no self-gifting)
 *   - buyer may not be assigned their relationship partner (either direction)
 *   - every eligible participant buys for exactly one person, and every
 *     participant receives from exactly one person (a perfect matching)
 *
 * Approach: randomized backtracking with an MRV ("most constrained first")
 * heuristic. For 6–50 participants this resolves instantly; the search only
 * has to work hard when the constraint graph is genuinely tight.
 */

/**
 * Generate and persist assignments for an event.
 * Returns the number of assignments written.
 * Throws RuntimeException with an admin-friendly message on failure —
 * nothing is saved unless a complete matching exists (single transaction).
 */
function generate_assignments(PDO $pdo, int $eventId): int
{
    // Eligible participants: attached to the event, not removed, profile done.
    $stmt = $pdo->prepare(
        'SELECT u.id FROM event_users eu
         JOIN users u ON u.id = eu.user_id
         WHERE eu.event_id = ? AND eu.status <> "removed" AND u.profile_complete = 1'
    );
    $stmt->execute([$eventId]);
    $ids = array_map('intval', $stmt->fetchAll(PDO::FETCH_COLUMN));

    if (count($ids) < 2) {
        throw new RuntimeException('At least 2 participants with complete profiles are required.');
    }

    // Relationship exclusions for this event (stored with user_a_id < user_b_id).
    $stmt = $pdo->prepare('SELECT user_a_id, user_b_id FROM relationships WHERE event_id = ?');
    $stmt->execute([$eventId]);
    $blocked = []; // "buyerId:recipientId" => true, both directions
    foreach ($stmt->fetchAll() as $rel) {
        $a = (int)$rel['user_a_id'];
        $b = (int)$rel['user_b_id'];
        $blocked["$a:$b"] = true;
        $blocked["$b:$a"] = true;
    }

    // Build candidate recipient lists per buyer.
    $candidates = [];
    foreach ($ids as $buyer) {
        $opts = [];
        foreach ($ids as $recipient) {
            if ($recipient === $buyer) {
                continue;                       // no self
            }
            if (isset($blocked["$buyer:$recipient"])) {
                continue;                       // relationship exclusion
            }
            $opts[] = $recipient;
        }
        if (!$opts) {
            throw new RuntimeException(
                "No valid recipient exists for user #$buyer — the relationship "
                . 'exclusions make a complete matching impossible. Remove a '
                . 'relationship or add more participants.'
            );
        }
        shuffle($opts);                          // randomness => different result each run
        $candidates[$buyer] = $opts;
    }

    $matching = backtrack_match($candidates);
    if ($matching === null) {
        throw new RuntimeException(
            'No complete matching satisfies the current constraints. '
            . 'This usually means the relationship exclusions are too tight for '
            . 'the number of participants (e.g. only one couple plus nobody else). '
            . 'Nothing was saved.'
        );
    }

    // Persist atomically: wipe any previous run, insert the new one, flip status.
    $pdo->beginTransaction();
    try {
        $pdo->prepare('DELETE FROM assignments WHERE event_id = ?')->execute([$eventId]);
        $ins = $pdo->prepare(
            'INSERT INTO assignments (event_id, buyer_user_id, recipient_user_id) VALUES (?, ?, ?)'
        );
        foreach ($matching as $buyer => $recipient) {
            $ins->execute([$eventId, $buyer, $recipient]);
        }
        $pdo->prepare('UPDATE event_users SET status = "assigned"
                       WHERE event_id = ? AND status <> "removed"')->execute([$eventId]);
        $pdo->prepare('UPDATE events SET status = "assigned" WHERE id = ?')->execute([$eventId]);
        $pdo->commit();
    } catch (Throwable $t) {
        $pdo->rollBack();
        throw new RuntimeException('Database error while saving assignments — nothing was saved.');
    }
    return count($matching);
}

/**
 * Backtracking search for a perfect matching.
 *
 * @param array<int, int[]> $candidates buyerId => list of allowed recipientIds
 * @return array<int,int>|null buyerId => recipientId, or null if impossible
 */
function backtrack_match(array $candidates): ?array
{
    $assigned = [];        // buyerId => recipientId
    $usedRecipients = [];  // recipientId => true

    /**
     * Recursive step. Picks the *unassigned buyer with the fewest remaining
     * options* (MRV heuristic) — this prunes dead branches early, because a
     * buyer with zero remaining options fails the whole branch immediately.
     */
    $solve = function () use (&$solve, &$assigned, &$usedRecipients, $candidates): bool {
        // Find the most constrained unassigned buyer.
        $bestBuyer = null;
        $bestOptions = null;
        foreach ($candidates as $buyer => $opts) {
            if (isset($assigned[$buyer])) {
                continue;
            }
            $remaining = [];
            foreach ($opts as $r) {
                if (!isset($usedRecipients[$r])) {
                    $remaining[] = $r;
                }
            }
            if (!$remaining) {
                return false; // dead end: this buyer can no longer be matched
            }
            if ($bestOptions === null || count($remaining) < count($bestOptions)) {
                $bestBuyer = $buyer;
                $bestOptions = $remaining;
                if (count($remaining) === 1) {
                    break; // can't get more constrained than a forced move
                }
            }
        }
        if ($bestBuyer === null) {
            return true; // everyone assigned — complete matching found
        }

        foreach ($bestOptions as $recipient) {
            $assigned[$bestBuyer] = $recipient;
            $usedRecipients[$recipient] = true;
            if ($solve()) {
                return true;
            }
            unset($assigned[$bestBuyer], $usedRecipients[$recipient]); // backtrack
        }
        return false;
    };

    return $solve() ? $assigned : null;
}
