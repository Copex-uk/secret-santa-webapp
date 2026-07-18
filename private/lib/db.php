<?php
declare(strict_types=1);

/**
 * db.php — single shared PDO connection using config credentials.
 */

/** Get the shared PDO handle. Throws RuntimeException if not installed. */
function db(): PDO
{
    static $pdo = null;
    if ($pdo instanceof PDO) {
        return $pdo;
    }
    $cfg = config();
    if (empty($cfg['db'])) {
        throw new RuntimeException('Application is not installed yet.');
    }
    $pdo = make_pdo(
        (string)$cfg['db']['host'],
        (string)$cfg['db']['name'],
        (string)$cfg['db']['user'],
        (string)$cfg['db']['pass']
    );
    return $pdo;
}

/** Build a PDO connection with safe defaults (used by db() and the wizard). */
function make_pdo(string $host, string $name, string $user, string $pass): PDO
{
    $dsn = sprintf('mysql:host=%s;dbname=%s;charset=utf8mb4', $host, $name);
    return new PDO($dsn, $user, $pass, [
        PDO::ATTR_ERRMODE            => PDO::ERRMODE_EXCEPTION,
        PDO::ATTR_DEFAULT_FETCH_MODE => PDO::FETCH_ASSOC,
        PDO::ATTR_EMULATE_PREPARES   => false,   // real prepared statements
    ]);
}

/** Run schema.sql against a PDO handle (used once by the setup wizard). */
function run_schema(PDO $pdo): void
{
    $sql = file_get_contents(APP_PRIVATE . '/schema.sql');
    if ($sql === false) {
        throw new RuntimeException('schema.sql not found.');
    }
    // Split on semicolons at end-of-statement; schema contains no procedures.
    foreach (array_filter(array_map('trim', explode(';', $sql))) as $statement) {
        // Skip comment-only fragments
        $clean = preg_replace('/^--.*$/m', '', $statement);
        if (trim((string)$clean) === '') {
            continue;
        }
        $pdo->exec($statement);
    }
}
